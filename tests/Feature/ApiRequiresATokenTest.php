<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\DeviceAbilities;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| /api/v1 is not open
|--------------------------------------------------------------------------
|
| POST /api/v1/alerts was briefly unauthenticated while device enrolment was
| unwritten. That made a LIFE-SAFETY path into a denial-of-service surface:
| anyone who could reach the host could flood the dispatch queue, and a real
| panic would have arrived in a queue full of noise.
|
| These tests assert the door is shut and that a token is not a general key —
| a resident's handset must not be able to ask the system to adjudicate
| arrivals at the gate.
|
*/

it('refuses an alert with no token', function () {
    $this->postJson('/api/v1/alerts', [
        'tenant_id' => 'phoenixpark',
        'kind' => 'duress',
    ])->assertUnauthorized();
});

it('refuses a scan verdict with no token', function () {
    $this->postJson('/api/v1/passes/verify', [
        'household_id' => 1,
        'pass_category' => 'guest',
    ])->assertUnauthorized();
});

it('refuses a token that lacks the ability', function () {
    $user = User::factory()->create();

    // A token with SOME ability is not a token with EVERY ability. This is the
    // failure a bare auth:sanctum check would wave through.
    Sanctum::actingAs($user, ['something:else']);

    $this->postJson('/api/v1/alerts', [
        'tenant_id' => 'phoenixpark',
        'kind' => 'duress',
    ])->assertForbidden();
});

it('refuses a resident token at the gate', function () {
    $user = User::factory()->create();

    // A resident may raise an alert and nothing else. If they could call the
    // verify endpoint they could probe which of their neighbours are
    // restricted, one household id at a time.
    Sanctum::actingAs($user, DeviceAbilities::forResident());

    $this->postJson('/api/v1/passes/verify', [
        'household_id' => 1,
        'pass_category' => 'guest',
    ])->assertForbidden();
});

it('lets a guard token reach both endpoints', function () {
    $user = User::factory()->create();

    Sanctum::actingAs($user, DeviceAbilities::forGuard());

    // Past the gate, not necessarily successful: without an initialised tenant
    // there is no household to find. What matters here is that neither call is
    // refused as unauthenticated or forbidden.
    foreach ([
        ['/api/v1/alerts', ['tenant_id' => 'phoenixpark', 'kind' => 'duress']],
        ['/api/v1/passes/verify', ['household_id' => 1, 'pass_category' => 'guest']],
    ] as [$url, $payload]) {
        $status = $this->postJson($url, $payload)->getStatusCode();

        expect($status)->not->toBe(401, "{$url} rejected a guard token as unauthenticated")
            ->and($status)->not->toBe(403, "{$url} rejected a guard token as forbidden");
    }
});
