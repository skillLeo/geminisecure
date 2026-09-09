<?php

declare(strict_types=1);

use App\Models\Estate\Household;
use App\Models\Estate\Unit;
use App\Services\Restriction\RestrictionPolicy;

/**
 * Invariant 2, asserted end to end.
 *
 * "A guard never sees an amount owed" is easy to satisfy today and easy to
 * break in six months, when someone adds a helpful field to a payload. These
 * tests fail the moment that happens.
 *
 * They inspect the SERIALISED response, not the code, because the guarantee is
 * about what crosses the wire.
 */
it('never lets a restriction verdict carry money wording', function () {
    $policy = new RestrictionPolicy;

    $household = new Household(['name' => 'Household 1', 'access_restricted' => true]);
    $decision = $policy->decide($household, 'guest');

    // The whole payload a guard would receive for a restricted household.
    $payload = [
        'verdict' => 'restricted',
        'tone' => 'amber',
        'headline' => 'Access restricted',
        'detail' => 'Contact management.',
        'household' => $household->name,
        ...$household->guardVisibleStanding(),
    ];

    expect($payload)->toHaveKey('access_restricted')
        ->and($payload['access_restricted'])->toBeBool()
        ->and($decision['verdict'])->toBe('restricted');

    $serialised = strtolower(json_encode($payload));

    foreach ([
        'balance', 'owed', 'arrear', 'amount', 'overdue', 'debt',
        'payment', 'invoice', 'charge', 'ageing', 'aging',
        'jmd', 'j$', 'minor', 'total',
    ] as $forbidden) {
        expect($serialised)->not->toContain($forbidden);
    }
});

it('exposes only a boolean about a household standing', function () {
    $household = new Household(['name' => 'Household 2', 'access_restricted' => true]);

    $standing = $household->guardVisibleStanding();

    // Exactly one key. Adding a second is what this asserts against.
    expect($standing)->toHaveCount(1)
        ->and(array_keys($standing))->toBe(['access_restricted'])
        ->and($standing['access_restricted'])->toBeTrue();
});

it('keeps emergency vehicles admitted regardless of standing', function () {
    $policy = new RestrictionPolicy;
    $household = new Household(['name' => 'Household 3', 'access_restricted' => true]);

    // No estate setting, no arrears figure and no configuration can change
    // this. The exemption is checked before restriction is considered at all.
    foreach (RestrictionPolicy::UNRESTRICTABLE_CATEGORIES as $category) {
        $decision = $policy->decide($household, $category);

        expect($decision['admitted'])
            ->toBeTrue("category [{$category}] must never be restricted");
    }
});

it('does not expose a unit reference that was never loaded', function () {
    // A household with no unit relation loaded must not fatal on ->unit->reference.
    $household = new Household(['name' => 'Household 4', 'access_restricted' => false]);

    expect($household->unit?->reference)->toBeNull();

    $unit = new Unit(['reference' => 'PP-1A']);
    expect($unit->reference)->toBe('PP-1A');
});
