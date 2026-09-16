<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Guard;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\SecurityIncident;
use App\Models\Shift;
use App\Services\Gemini\IncidentLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A guard's incident reports and their media (13 D2). Board guard-app-03 screen 12.
 *
 * THE SAME TABLE THE CONSOLE LOGS TO. An incident filed from a handset lands in
 * `security_incidents` beside the ones a supervisor logs, so board 30's register
 * and the estate's reports read one record, not two that can disagree. The
 * handset adds what only it knows — where, which shift, and its own clock.
 *
 * MEDIA ON THE ESTATE'S PRIVATE DISK, WITH A HASH. A photograph of an incident
 * is evidence; it is stored where no URL reaches it and hashed on arrival, so a
 * copy produced later can be shown to be the one that was uploaded. The request
 * runs inside the estate's tenancy, which roots the `local` disk in that estate's
 * own storage — so the file lives with the estate, beside its documents, and is
 * read back the same way.
 */
class IncidentsController extends Controller
{
    private const MEDIA_TYPES = ['image/jpeg', 'image/png', 'image/heic', 'image/heif', 'video/mp4', 'video/quicktime'];

    /** 50 MB — a short video from a phone. */
    private const MEDIA_MAX_KB = 51200;

    public function store(Request $request, DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();

        $data = $request->validate([
            'kind' => ['required', 'string', 'max:160'],
            'severity' => ['required', 'string', 'in:'.implode(',', array_keys(IncidentLog::SEVERITIES))],
            'detail' => ['required', 'string', 'min:20', 'max:5000'],
            'location' => ['nullable', 'string', 'max:160'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $occurredAt = isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : $context->serverTime;

        if ($occurredAt->greaterThan($context->serverTime->copy()->addMinutes(5))) {
            throw ApiError::unprocessable('occurred_in_future', 'An incident is reported after it happens. That time is in the future — check the handset\'s clock.');
        }

        $shift = Shift::query()->where('guard_id', $guard->id)->whereNotNull('actual_start')->whereNull('actual_end')->latest('actual_start')->first();

        $incident = new SecurityIncident;
        $incident->forceFill([
            'tenant_id' => $context->tenantId,
            'guard_id' => $guard->id,
            'guard_name' => $guard->full_name,
            'kind' => $data['kind'],
            'detail' => $data['detail'],
            'severity' => $data['severity'],
            'status' => SecurityIncident::OPEN,
            'occurred_at' => $occurredAt,
            'location' => $data['location'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'shift_id' => $shift?->id,
            'device_time' => $context->deviceTime,
            'idempotency_key' => $request->header('Idempotency-Key'),
            'logged_by_name' => $guard->full_name.' (Guard App)',
            'is_simulated' => $request->boolean('simulated'),
        ])->save();

        return response()->json([
            ...$this->shape($incident->fresh() ?? $incident),
            'server_time' => $context->serverTime->toIso8601String(),
            'device_time' => $context->deviceTime?->toIso8601String(),
            'clock_skewed' => $context->clockSkewed(),
        ], 201);
    }

    public function mine(DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();

        $items = SecurityIncident::query()
            ->where('guard_id', $guard->id)
            ->where('occurred_at', '>=', Carbon::now()->subDays(90))
            ->orderByDesc('occurred_at')
            ->limit(100)
            ->get()
            ->map(fn (SecurityIncident $i): array => $this->shape($i))
            ->all();

        return response()->json(['items' => $items]);
    }

    public function media(Request $request, int $incident, DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();

        $record = SecurityIncident::query()->whereKey($incident)->where('guard_id', $guard->id)->first();

        if ($record === null) {
            throw ApiError::notFound('not_found', 'No incident of yours with that id.');
        }

        $request->validate([
            'file' => ['required', 'file', 'max:'.self::MEDIA_MAX_KB, 'mimetypes:'.implode(',', self::MEDIA_TYPES)],
        ]);

        $file = $request->file('file');
        $bytes = (string) file_get_contents((string) $file->getRealPath());
        $sha256 = hash('sha256', $bytes);
        $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $path = 'incident-media/'.$context->tenantId.'/'.$record->id.'/'.$sha256.'.'.$extension;

        Storage::disk('local')->put($path, $bytes);

        $id = DB::connection('mysql')->table('incident_media')->insertGetId([
            'security_incident_id' => $record->id,
            'guard_id' => $guard->id,
            'path' => $path,
            'filename' => mb_substr($file->getClientOriginalName(), 0, 190),
            'content_type' => (string) $file->getMimeType(),
            'bytes' => strlen($bytes),
            'sha256' => $sha256,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return response()->json([
            'media_id' => $id,
            'incident_id' => $record->id,
            'filename' => mb_substr($file->getClientOriginalName(), 0, 190),
            'content_type' => (string) $file->getMimeType(),
            'bytes' => strlen($bytes),
            'sha256' => $sha256,
        ], 201);
    }

    /** @return array<string, mixed> */
    private function shape(SecurityIncident $i): array
    {
        $row = DB::connection('mysql')->table('security_incidents')->where('id', $i->id)->first(['location', 'shift_id']);

        return [
            'id' => $i->id,
            'kind' => $i->kind,
            'severity' => $i->severity,
            'status' => $i->status,
            'detail' => $i->detail,
            'location' => $row?->location,
            'shift_id' => $row?->shift_id === null ? null : (int) $row->shift_id,
            'occurred_at' => $i->occurred_at->toIso8601String(),
            'resolution' => $i->resolution,
            'closed_at' => $i->closed_at?->toIso8601String(),
            'media_count' => DB::connection('mysql')->table('incident_media')->where('security_incident_id', $i->id)->count(),
        ];
    }
}
