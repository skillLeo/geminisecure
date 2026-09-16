<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\DuressAlert;
use App\Models\Estate\Unit;
use App\Services\Dispatch\AlertIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Duress, from either app, and its ten-second cancel (13 D2, D3).
 *
 * Board guard-app-03 screen 14 and resident-app's panic. The alert goes through
 * `AlertIntake::record` â€” the path every alert takes â€” so dispatch sees a duress
 * from a handset exactly as it sees one from the simulator.
 *
 * SILENT OR AUDIBLE is the handset's choice and changes nothing at dispatch: a
 * silent duress is the one where the guard cannot be seen raising it, and the
 * console treats both as the same emergency.
 *
 * THE CANCEL WINDOW IS TEN SECONDS AND IS THE SERVER'S. A press in a pocket is
 * cancellable for ten seconds from when the server received it, and not once a
 * dispatcher has acknowledged it â€” after that a person is already responding,
 * and "cancel" is a conversation with dispatch, not a button.
 */
class DuressController extends Controller
{
    public const CANCEL_WINDOW_SECONDS = 10;

    public function store(Request $request, DeviceContext $context, AlertIntake $intake): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['nullable', 'string', 'in:silent,audible'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'captured_offline' => ['nullable', 'boolean'],
        ]);

        $guard = $context->guard;
        $resident = $context->resident;

        $alert = $intake->record(
            tenantId: $context->tenantId,
            kind: $guard !== null ? 'duress' : 'panic',
            guardId: $guard?->id,
            raisedByName: $resident?->full_name,
            unitReference: $resident?->unit_id === null ? null : Unit::query()->whereKey($resident->unit_id)->value('reference'),
            latitude: isset($data['latitude']) ? (float) $data['latitude'] : null,
            longitude: isset($data['longitude']) ? (float) $data['longitude'] : null,
            deviceTime: $context->deviceTime,
            capturedOffline: (bool) ($data['captured_offline'] ?? false),
            idempotencyKey: $request->header('Idempotency-Key'),
            isSimulated: $request->boolean('simulated'),
        );

        if ($alert->getAttribute('mode') === null) {
            $alert->forceFill([
                'mode' => $data['mode'] ?? 'silent',
                'raised_by_account_id' => $resident?->id,
            ])->save();
        }

        return response()->json($this->shape($alert), 201);
    }

    public function cancel(int $alert, DeviceContext $context): JsonResponse
    {
        $record = DuressAlert::query()->whereKey($alert)->where('tenant_id', $context->tenantId)
            ->when($context->guard !== null, fn ($q) => $q->where('guard_id', $context->guard?->id))
            ->when($context->resident !== null, fn ($q) => $q->whereNull('guard_id')->where('raised_by_account_id', $context->resident?->id))
            ->first();

        if ($record === null) {
            throw ApiError::notFound('not_found', 'No alert of yours with that id.');
        }

        if ($record->getAttribute('cancelled_at') !== null) {
            return response()->json($this->shape($record));
        }

        if ($record->acknowledged_at !== null) {
            throw ApiError::conflict('already_acknowledged', 'Dispatch has already acknowledged this alert and someone is responding. Call dispatch to stand them down.');
        }

        if ($record->server_time->copy()->addSeconds(self::CANCEL_WINDOW_SECONDS)->lessThan(Carbon::now())) {
            throw ApiError::conflict('cancel_window_closed', 'An alert can be cancelled for '.self::CANCEL_WINDOW_SECONDS.' seconds after it is raised. Call dispatch to stand it down.');
        }

        $record->forceFill([
            'cancelled_at' => Carbon::now(),
            'status' => 'false_alarm',
            'resolved_at' => Carbon::now(),
            'resolution_note' => 'Cancelled from the handset within '.self::CANCEL_WINDOW_SECONDS.' seconds.',
        ])->save();

        return response()->json($this->shape($record));
    }

    /** @return array<string, mixed> */
    private function shape(DuressAlert $alert): array
    {
        $cancelledAt = $alert->getAttribute('cancelled_at');

        return [
            'id' => $alert->id,
            'kind' => $alert->kind,
            'mode' => $alert->getAttribute('mode'),
            'status' => $alert->status,
            'cancellable_until' => $alert->server_time->copy()->addSeconds(self::CANCEL_WINDOW_SECONDS)->toIso8601String(),
            'cancelled_at' => $cancelledAt === null ? null : Carbon::parse((string) $cancelledAt)->toIso8601String(),
            'acknowledged_at' => $alert->acknowledged_at?->toIso8601String(),
            'server_time' => $alert->server_time->toIso8601String(),
            'device_time' => $alert->device_time?->toIso8601String(),
            'clock_skewed' => $alert->clock_skewed,
        ];
    }
}
