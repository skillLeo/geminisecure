<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Resident;

use App\Api\ApiError;
use App\Api\AppMatrix;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\Estate\UnitClaim;
use App\Models\Tenant;
use App\Services\ResidentApp\ResidentAccounts;
use App\Services\ResidentApp\ResidentSignIn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Signing in to the Resident App, and claiming a unit (13 D3). Boards resident-app-01, -04.
 *
 * Request a code, prove it, receive a token. The token's account is PENDING until
 * the estate approves a unit claim; a pending token reaches its claim and `GET /me`
 * and nothing else. See `ResidentSignIn` and `ResidentAccounts`.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly ResidentSignIn $signIn,
        private readonly ResidentAccounts $accounts,
    ) {}

    public function requestCode(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $estate = $this->estate($data['estate']);

        $sent = $this->signIn->requestCode($estate, $data['channel'], $data['destination']);

        return response()->json([
            'sent' => true,
            'channel' => $data['channel'],
            'destination_hint' => ResidentSignIn::mask(ResidentSignIn::normalise($data['channel'], $data['destination'])),
            'expires_at' => $sent['expires_at']->toIso8601String(),
            'resend_after' => $sent['resend_after']->toIso8601String(),
        ], 202);
    }

    public function verifyCode(Request $request): JsonResponse
    {
        $data = $this->validated($request, [
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
            'device_uid' => ['required', 'string', 'max:120'],
            'platform' => ['required', 'string', 'in:ios,android'],
        ]);

        $estate = $this->estate($data['estate']);
        $account = $this->signIn->verify($estate, $data['channel'], $data['destination'], $data['code']);

        if ($account->status === 'suspended') {
            throw ApiError::forbidden('account_suspended', 'The estate has withdrawn this account\'s access. Contact the estate office.');
        }

        // One token per install: signing in again on the same phone replaces its token.
        $name = 'resident-app:'.$data['device_uid'];
        $account->tokens()->where('name', $name)->delete();
        $abilities = AppMatrix::abilitiesFor(AppMatrix::RESIDENT);
        $token = $account->createToken($name, $abilities)->plainTextToken;

        $account->forceFill(['device_uid' => $data['device_uid'], 'device_platform' => $data['platform']])->save();

        $claim = null;

        $estate->run(function () use ($account, &$claim): void {
            $this->accounts->reconcile($account);
            $claim = $account->claim_id === null ? null : ResidentAccounts::claimShape(UnitClaim::query()->find($account->claim_id));
        });

        return response()->json([
            'token' => $token,
            'abilities' => $abilities,
            'account' => [
                'id' => $account->id,
                'status' => $account->status,
                'full_name' => $account->full_name,
            ],
            'estate' => ['id' => $estate->getTenantKey(), 'name' => (string) $estate->name],
            'claim' => $claim,
            'next' => $account->isActive() ? 'home' : ($claim === null ? 'claim_unit' : 'await_approval'),
        ]);
    }

    public function claimUnit(Request $request, DeviceContext $context): JsonResponse
    {
        $account = $context->residentOrFail();

        $data = $request->validate([
            'full_name' => ['required', 'string', 'min:2', 'max:160'],
            'lot' => ['required', 'string', 'max:32'],
            'phase' => ['nullable', 'string', 'max:64'],
            'phone' => ['nullable', 'string', 'max:40'],
            'relationship' => ['nullable', 'string', 'in:owner,tenant,spouse,child,parent,relative,other'],
        ]);

        $result = $this->accounts->claim($account, $data, $request->boolean('simulated'));

        return response()->json([
            'account' => ['id' => $account->id, 'status' => $account->status, 'full_name' => $account->full_name],
            'claim' => ResidentAccounts::claimShape($result['claim']),
        ], $result['created'] ? 201 : 200);
    }

    /**
     * @param  array<string, list<string>>  $extra
     * @return array<string, string>
     */
    private function validated(Request $request, array $extra = []): array
    {
        $channel = (string) $request->input('channel');

        return $request->validate([
            'estate' => ['required', 'string', 'max:64'],
            'channel' => ['required', 'string', 'in:email,sms'],
            'destination' => $channel === 'email'
                ? ['required', 'string', 'email', 'max:190']
                : ['required', 'string', 'regex:/^\+?[\d\s\-()]{7,20}$/'],
            ...$extra,
        ]);
    }

    private function estate(string $id): Tenant
    {
        $estate = Tenant::query()->find($id);

        if (! $estate instanceof Tenant || $estate->status !== 'active') {
            throw ApiError::notFound('estate_not_found', 'No estate on this platform by that name is taking sign-ins.');
        }

        return $estate;
    }
}
