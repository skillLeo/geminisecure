<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Devices\DeviceEnrolment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/devices/enrol, and collecting a rebind (13 D1). See `DeviceEnrolment`.
 *
 * Unauthenticated by necessity — this is where a handset gets its token — and
 * throttled per address, because an enrolment code is a secret worth guessing.
 */
class DeviceController extends Controller
{
    public function enrol(Request $request, DeviceEnrolment $enrolment): JsonResponse
    {
        $data = $request->validate([
            'enrolment_code' => ['required', 'string', 'max:32'],
            'device_uid' => ['required', 'string', 'max:120'],
            'platform' => ['required', 'string', 'in:ios,android'],
            'public_key' => ['required', 'string', 'max:64'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        $result = $enrolment->enrolWithCode($data);
        $guard = $result['guard'];

        $body = [
            'status' => $result['status'],
            'guard' => [
                'id' => $guard->id,
                'name' => $guard->full_name,
                'employee_number' => $guard->employee_number,
            ],
            'site' => [
                'id' => (string) $guard->tenant_id,
                'name' => (string) ($guard->estate->name ?? $guard->tenant_id),
            ],
        ];

        if ($result['status'] === 'enrolled') {
            return response()->json([...$body, 'token' => $result['token'], 'abilities' => $result['abilities']], 201);
        }

        return response()->json([
            ...$body,
            'rebind_request_id' => $result['rebind_request_id'],
            'claim_secret' => $result['claim_secret'],
        ], 202);
    }

    public function collect(Request $request, int $rebind, DeviceEnrolment $enrolment): JsonResponse
    {
        $data = $request->validate([
            'claim_secret' => ['required', 'string', 'max:64'],
            'device_uid' => ['required', 'string', 'max:120'],
        ]);

        return response()->json($enrolment->collect($rebind, $data['claim_secret'], $data['device_uid']));
    }
}
