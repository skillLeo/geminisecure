<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Guard;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A site's patrol checkpoints, and scanning them (13 D2). Board guard-app-02 screen 7.
 *
 * THE SCAN PROVES PRESENCE ONLY IF IT CARRIES THE CODE. A checkpoint's `code` is
 * what its QR tag or NFC tag encodes; the handset sends what it read, and a scan
 * whose code does not match the checkpoint is refused — otherwise a guard could
 * "scan" a checkpoint from the gatehouse by tapping its row.
 */
class PatrolController extends Controller
{
    public function checkpoints(string $site, DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();

        if ($site !== $context->tenantId) {
            throw ApiError::forbidden('wrong_site', 'A handset reads its own site\'s checkpoints.');
        }

        $today = Carbon::today();

        $lastScans = DB::connection('mysql')->table('checkpoint_scans')
            ->where('guard_id', $guard->id)
            ->where('server_time', '>=', $today)
            ->selectRaw('patrol_checkpoint_id, MAX(server_time) AS last_scanned')
            ->groupBy('patrol_checkpoint_id')
            ->pluck('last_scanned', 'patrol_checkpoint_id');

        $items = DB::connection('mysql')->table('patrol_checkpoints')
            ->where('tenant_id', $site)
            ->where('is_active', true)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->map(static fn (object $c): array => [
                'id' => (int) $c->id,
                'label' => (string) $c->label,
                'sequence' => (int) $c->sequence,
                'post_id' => $c->post_id === null ? null : (int) $c->post_id,
                'last_scanned_at' => isset($lastScans[$c->id]) ? Carbon::parse((string) $lastScans[$c->id])->toIso8601String() : null,
            ])->all();

        return response()->json([
            'site_id' => $site,
            'items' => $items,
            'tour' => [
                'total' => count($items),
                'scanned_today' => count(array_filter($items, static fn (array $i): bool => $i['last_scanned_at'] !== null)),
            ],
        ]);
    }

    public function scan(Request $request, int $checkpoint, DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();

        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'captured_offline' => ['nullable', 'boolean'],
        ]);

        $row = DB::connection('mysql')->table('patrol_checkpoints')->where('id', $checkpoint)->where('tenant_id', $context->tenantId)->first();

        if ($row === null) {
            throw ApiError::notFound('not_found', 'No such checkpoint at your site.');
        }

        if (! hash_equals((string) $row->code, (string) $data['code'])) {
            throw ApiError::unprocessable('code_mismatch', 'The tag read does not belong to '.$row->label.'. Scan the tag at the checkpoint.');
        }

        $id = DB::connection('mysql')->table('checkpoint_scans')->insertGetId([
            'patrol_checkpoint_id' => $row->id,
            'guard_id' => $guard->id,
            'device_time' => $context->deviceTime,
            'server_time' => $context->serverTime,
            'clock_skewed' => $context->clockSkewed(),
            'captured_offline' => (bool) ($data['captured_offline'] ?? false),
            'idempotency_key' => $request->header('Idempotency-Key'),
            'is_simulated' => $request->boolean('simulated'),
        ]);

        $total = DB::connection('mysql')->table('patrol_checkpoints')->where('tenant_id', $context->tenantId)->where('is_active', true)->count();
        $scanned = DB::connection('mysql')->table('checkpoint_scans')->where('guard_id', $guard->id)->where('server_time', '>=', Carbon::today())->distinct()->count('patrol_checkpoint_id');

        return response()->json([
            'scan_id' => $id,
            'checkpoint_id' => (int) $row->id,
            'label' => (string) $row->label,
            'tour' => ['total' => $total, 'scanned_today' => $scanned],
        ], 201);
    }
}
