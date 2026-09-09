<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Dispatch\AlertIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/alerts - raise a panic, duress, medical, fire or intrusion alert.
 *
 * Consumed by the Guard App and the Resident App. The simulator calls this
 * same endpoint, so nothing built against simulated data has to be rewritten
 * when the real apps arrive.
 *
 * NOTHING here returns a monetary amount, and nothing it touches holds one.
 * That is invariant 2 enforced structurally rather than by remembering to omit
 * a field.
 */
class AlertController extends Controller
{
    public function store(Request $request, AlertIntake $intake): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'string', 'max:64'],
            'kind' => ['required', 'string', 'in:panic,duress,medical,fire,intrusion'],
            'guard_id' => ['nullable', 'integer', 'exists:guards,id'],
            'raised_by_name' => ['nullable', 'string', 'max:160'],
            'unit_reference' => ['nullable', 'string', 'max:32'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'device_time' => ['nullable', 'date'],
            'captured_offline' => ['nullable', 'boolean'],

            /*
             * Client-generated, and the whole point of it is that the client
             * chooses it before the first attempt. Required, because an
             * unkeyed retry from a flaky connection duplicates a panic alert.
             */
            'idempotency_key' => ['required', 'string', 'max:64'],
        ]);

        $alert = $intake->record(
            tenantId: $data['tenant_id'],
            kind: $data['kind'],
            guardId: $data['guard_id'] ?? null,
            raisedByName: $data['raised_by_name'] ?? null,
            unitReference: $data['unit_reference'] ?? null,
            latitude: isset($data['latitude']) ? (float) $data['latitude'] : null,
            longitude: isset($data['longitude']) ? (float) $data['longitude'] : null,
            deviceTime: isset($data['device_time']) ? now()->parse($data['device_time']) : null,
            capturedOffline: (bool) ($data['captured_offline'] ?? false),
            idempotencyKey: $data['idempotency_key'],
            isSimulated: $request->boolean('simulated'),
        );

        return response()->json([
            'id' => $alert->id,
            'status' => $alert->status,
            'server_time' => $alert->server_time->toIso8601String(),
            'clock_skewed' => $alert->clock_skewed,
        ], 201);
    }
}
