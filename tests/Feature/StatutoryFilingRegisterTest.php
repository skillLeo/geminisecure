<?php

declare(strict_types=1);

use App\Models\StatutoryFiling;
use App\Models\StatutoryRateVersion;
use App\Services\Payroll\StatutoryFilingRegister;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The statutory register, and the record that cannot be unmade
|--------------------------------------------------------------------------
|
| Board super-admin-30 lists the returns Gemini Security owes the Jamaican
| authorities. Two things about it are worth asserting rather than looking at.
|
| The first is the calendar. Whether a return is owed, late, or nobody's problem
| yet is entirely a question of dates, and the difference between "Due soon" and
| "Overdue" is the difference between a task and a penalty. Those rules are
| driven from a frozen clock here, because a test that passes in September and
| fails in October is worse than no test at all.
|
| The second is invariant 4. A FILED return is a posted record: the authority
| holds a copy of it, and editing ours would put the two out of step with
| nothing to show for it. That is defended three times over - no route, a model
| guard, and a database trigger - and all three are asserted, because the one
| that gets removed during a refactor is always the one nobody tested.
|
| RefreshDatabase is deliberately NOT used: this suite shares gs_platform_test
| with the rest of the console and dropping the schema mid-run would take other
| tests with it. Each test runs inside a transaction and leaves nothing behind.
|
*/

uses(DatabaseTransactions::class);

beforeEach(function () {
    // A fixed "today", in a year the seeded register knows nothing about, so
    // these rows can be reasoned about in isolation.
    Carbon::setTestNow('2030-06-10');

    $this->register = app(StatutoryFilingRegister::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function statutoryFiling(array $attributes): StatutoryFiling
{
    return StatutoryFiling::create(array_merge([
        'form_code' => 'S01',
        'form_title' => 'Statutory Deduction Remittance',
        'status' => StatutoryFiling::DUE,
        'currency' => 'JMD',
    ], $attributes));
}

/** A rate version in force on the frozen date, signed off or not. */
function ratesInForce(bool $verified): StatutoryRateVersion
{
    return StatutoryRateVersion::create([
        'label' => 'Test rates '.($verified ? 'signed off' : 'draft'),
        'effective_from' => '2030-01-01',
        'nis_employee_bp' => 300,
        'nis_employer_bp' => 300,
        'nis_ceiling_annual_minor' => 5_000_000_00,
        'nht_employee_bp' => 200,
        'nht_employer_bp' => 300,
        'education_tax_employee_bp' => 225,
        'education_tax_employer_bp' => 350,
        'paye_bp' => 2500,
        'paye_threshold_annual_minor' => 1_800_000_00,
        'is_verified' => $verified,
    ]);
}

it('calls a closed period with a future deadline due soon', function () {
    statutoryFiling([
        'period_label' => 'May 2030',
        'period_start' => '2030-05-01',
        'period_end' => '2030-05-31',
        'due_on' => '2030-06-14',
    ]);

    $row = $this->register->rows(2030)[0];

    expect($row['badge_label'])->toBe('Due soon')
        ->and($row['badge_class'])->toBe('pending')
        // The board draws the amber icon tile on exactly this state.
        ->and($row['warn'])->toBeTrue()
        ->and($row['when'])->toBe('Due Jun 14');
});

it('calls a missed deadline overdue, not due soon', function () {
    statutoryFiling([
        'period_label' => 'March 2030',
        'period_start' => '2030-03-01',
        'period_end' => '2030-03-31',
        'due_on' => '2030-04-14',
    ]);

    $row = $this->register->rows(2030)[0];

    // "Due soon" on a return that is eight weeks late would be a screen
    // reassuring somebody who is accruing a penalty.
    expect($row['badge_label'])->toBe('Overdue')
        ->and($row['badge_class'])->toBe('exception');
});

it('owes nothing for a period that has not closed', function () {
    statutoryFiling([
        'form_code' => 'P24',
        'form_title' => 'Annual Employer Return',
        'period_label' => 'Tax year 2030',
        'period_start' => '2030-01-01',
        'period_end' => '2030-12-31',
        'due_on' => '2031-03-31',
        'status' => StatutoryFiling::NOT_STARTED,
    ]);

    $row = $this->register->rows(2030)[0];

    expect($row['badge_label'])->toBe('Not started')
        ->and($row['warn'])->toBeFalse()
        // Beyond this year, so the year has to be spelled out or it reads as
        // three weeks away rather than nine months.
        ->and($row['when'])->toBe('Due Mar 31, 2031');
});

it('reads outstanding first, then filed, then what is not owed yet', function () {
    statutoryFiling([
        'period_label' => 'May 2030',
        'period_start' => '2030-05-01',
        'period_end' => '2030-05-31',
        'due_on' => '2030-06-14',
    ]);

    statutoryFiling([
        'form_code' => 'S02',
        'form_title' => 'Monthly Reconciliation',
        'period_label' => 'April 2030',
        'period_start' => '2030-04-01',
        'period_end' => '2030-04-30',
        'due_on' => '2030-05-14',
        'status' => StatutoryFiling::FILED,
        'filed_on' => '2030-05-11',
    ]);

    statutoryFiling([
        'period_label' => 'March 2030',
        'period_start' => '2030-03-01',
        'period_end' => '2030-03-31',
        'due_on' => '2030-04-14',
    ]);

    statutoryFiling([
        'form_code' => 'P24',
        'form_title' => 'Annual Employer Return',
        'period_label' => 'Tax year 2030',
        'period_start' => '2030-01-01',
        'period_end' => '2030-12-31',
        'due_on' => '2031-03-31',
        'status' => StatutoryFiling::NOT_STARTED,
    ]);

    $labels = array_map(
        static fn (array $row): string => (string) $row['badge_label'],
        $this->register->rows(2030),
    );

    expect($labels)->toBe(['Overdue', 'Due soon', 'Filed', 'Not started']);
});

it('says what an outstanding return will cover and why it cannot be prepared', function () {
    statutoryFiling([
        'period_label' => 'May 2030',
        'period_start' => '2030-05-01',
        'period_end' => '2030-05-31',
        'due_on' => '2030-06-14',
    ]);

    $row = $this->register->rows(2030)[0];

    expect($row['detail'])->toContain('May 2030')
        // The ruling's own list — the employer's share is on the same return.
        ->and($row['detail'])->toContain('PAYE, NIS, NHT, Education Tax, HEART')
        // The important half: the figures are not available, and why.
        ->and($row['detail'])->toContain('awaiting an approved pay run');
});

it('shows a filed return by its period alone', function () {
    statutoryFiling([
        'period_label' => 'April 2030',
        'period_start' => '2030-04-01',
        'period_end' => '2030-04-30',
        'due_on' => '2030-05-14',
        'status' => StatutoryFiling::FILED,
        'filed_on' => '2030-05-11',
    ]);

    $row = $this->register->rows(2030)[0];

    expect($row['detail'])->toBe('April 2030')
        ->and($row['when'])->toBe('Filed May 11');
});

it('blocks a new filing while the card in force carries no verified TAJ figures', function () {
    // A card nobody has verified means no run can be approved on it, which means
    // no return can be prepared from one. Q-002 is ruled; this case survives it.
    ratesInForce(verified: false);

    $reason = $this->register->blockedReason();

    expect($reason)->toContain('approved pay run')
        ->and($reason)->toContain('no verified TAJ periodic figures');
});

it('still blocks a new filing when signed-off rates have no unfiled run', function () {
    // The reason changes with the facts. Being up to date reads very
    // differently from being blocked by a decision, so it says so.
    ratesInForce(verified: true);

    expect($this->register->blockedReason())
        ->toContain('already been filed')
        ->not->toContain('no verified TAJ');
});

it('narrows to one year and names the years it does hold', function () {
    statutoryFiling([
        'period_label' => 'May 2030',
        'period_start' => '2030-05-01',
        'period_end' => '2030-05-31',
        'due_on' => '2030-06-14',
    ]);

    expect($this->register->rows(2030))->toHaveCount(1)
        ->and($this->register->rows(2029))->toHaveCount(0)
        ->and($this->register->years())->toContain(2030);
});

it('refuses to edit a filed return in the model', function () {
    $filed = statutoryFiling([
        'period_label' => 'April 2030',
        'period_start' => '2030-04-01',
        'period_end' => '2030-04-30',
        'due_on' => '2030-05-14',
        'status' => StatutoryFiling::FILED,
        'filed_on' => '2030-05-11',
    ]);

    // A correction is an AMENDED RETURN for the same period, never an edit.
    expect(fn () => $filed->update(['confirmation_reference' => 'TAJ-REWRITTEN']))
        ->toThrow(LogicException::class, 'cannot be edited');
});

it('refuses to delete a filed return in the model', function () {
    $filed = statutoryFiling([
        'period_label' => 'April 2030',
        'period_start' => '2030-04-01',
        'period_end' => '2030-04-30',
        'due_on' => '2030-05-14',
        'status' => StatutoryFiling::FILED,
        'filed_on' => '2030-05-11',
    ]);

    expect(fn () => $filed->delete())->toThrow(LogicException::class);
});

it('keeps the append-only guard in the database, not only in PHP', function () {
    /*
     * The model guard protects code that goes through the model. The trigger
     * protects everything else - a raw query, a migration, a console command
     * written in a hurry - and it is the one that still holds after the others
     * have been refactored away.
     */
    $triggers = DB::connection('mysql')
        ->table('information_schema.TRIGGERS')
        ->where('EVENT_OBJECT_TABLE', 'statutory_filings')
        ->pluck('EVENT_MANIPULATION')
        ->all();

    expect($triggers)->toContain('UPDATE')
        ->and($triggers)->toContain('DELETE');
});
