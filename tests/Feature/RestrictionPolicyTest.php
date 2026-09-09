<?php

declare(strict_types=1);

use App\Models\Estate\EstateSetting;
use App\Models\Estate\Household;
use App\Services\Restriction\RestrictionPolicy;

/**
 * Arrears restriction (D-024, D-025).
 *
 * These assertions are the reason the policy exists in one class. The
 * exemptions in particular must fail loudly if anyone ever edits them: a
 * misconfigured estate leaving an ambulance at a gate is not recoverable, so
 * the guarantee cannot rest on review.
 */
beforeEach(function () {
    $this->policy = new RestrictionPolicy;

    $this->restricted = new Household(['name' => 'Restricted household', 'access_restricted' => true]);
    $this->clear = new Household(['name' => 'Clear household', 'access_restricted' => false]);

    $this->settings = new EstateSetting([
        'arrears_restriction_days' => 90,
        'arrears_notice_days' => 14,
        'arrears_restriction_enabled' => true,
    ]);
});

it('never restricts an emergency or medical vehicle', function (string $category) {
    $decision = $this->policy->decide($this->restricted, $category);

    expect($decision['admitted'])->toBeTrue()
        ->and($decision['verdict'])->toBe('admit')
        ->and($decision['category_exempt'])->toBeTrue();
})->with(['emergency', 'medical', 'fire', 'utility_emergency']);

it('never restricts a resident\'s own entry', function () {
    // Restriction applies to guest passes only. A household in arrears can
    // always come home.
    $decision = $this->policy->decide($this->restricted, 'resident');

    expect($decision['admitted'])->toBeTrue()
        ->and($decision['category_exempt'])->toBeTrue();
});

it('restricts guest passes for a restricted household', function (string $category) {
    $decision = $this->policy->decide($this->restricted, $category);

    expect($decision['admitted'])->toBeFalse()
        ->and($decision['verdict'])->toBe('restricted');
})->with(['guest', 'visitor', 'delivery', 'contractor']);

it('admits guests for a household that is not restricted', function () {
    $decision = $this->policy->decide($this->clear, 'guest');

    expect($decision['admitted'])->toBeTrue()
        ->and($decision['verdict'])->toBe('admit');
});

it('tells the guard nothing about money', function () {
    $decision = $this->policy->decide($this->restricted, 'guest');

    expect($decision['reason'])->toBe('Access restricted — contact management');

    // Invariant 2, asserted rather than trusted: no amount, no ageing bucket,
    // no payment history, and no wording that implies any of them.
    $serialised = strtolower(json_encode($decision));

    foreach (['balance', 'owed', 'arrear', 'amount', 'overdue', 'debt', 'payment', 'invoice', 'jmd', 'j$'] as $forbidden) {
        expect($serialised)->not->toContain($forbidden);
    }
});

it('admits an unrecognised category rather than denying by default', function () {
    // An unknown pass type must not become a denial. Failing closed here would
    // turn every future category into an outage at the gate.
    $decision = $this->policy->decide($this->restricted, 'something_new');

    expect($decision['admitted'])->toBeTrue();
});

it('makes a household eligible only at the configured threshold', function () {
    expect($this->policy->isEligible(89, $this->settings))->toBeFalse()
        ->and($this->policy->isEligible(90, $this->settings))->toBeTrue()
        ->and($this->policy->isEligible(200, $this->settings))->toBeTrue();
});

it('makes nobody eligible when an estate switches restriction off', function () {
    $off = new EstateSetting([
        'arrears_restriction_days' => 90,
        'arrears_notice_days' => 14,
        'arrears_restriction_enabled' => false,
    ]);

    expect($this->policy->isEligible(365, $off))->toBeFalse();
});

it('requires the notice period to elapse before restriction', function () {
    // Serving notice and restricting the same day is the failure this guards.
    expect($this->policy->noticePeriodHasElapsed(null, $this->settings))->toBeFalse()
        ->and($this->policy->noticePeriodHasElapsed(now(), $this->settings))->toBeFalse()
        ->and($this->policy->noticePeriodHasElapsed(now()->subDays(13), $this->settings))->toBeFalse()
        ->and($this->policy->noticePeriodHasElapsed(now()->subDays(14), $this->settings))->toBeTrue();
});
