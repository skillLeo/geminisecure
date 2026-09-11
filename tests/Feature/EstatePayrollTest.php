<?php

declare(strict_types=1);

use App\Models\Estate\Employee;
use App\Models\Estate\PayrollException;
use App\Models\Estate\PayrollRun;
use App\Models\Estate\StatutoryFiling;
use App\Models\StatutoryRateVersion;
use App\Models\User;
use App\Services\Estate\Payroll;
use App\Services\Payroll\PayrollFileFormats;
use Database\Seeders\Estate\EstateFinanceSeeder;
use Database\Seeders\RbacMatrixSeeder;
use Database\Seeders\StatutoryRatesSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| The estate's own payroll, traced to the lines it is made of
|--------------------------------------------------------------------------
|
| The money-module rule, on the third set of books: a screen figure is not
| proven by rendering, it is proven by equalling the sum of specific posted
| journal lines. Every total board 15 draws is summed here in raw SQL written
| out longhand, so the expectation and the application reach the same number by
| two different routes.
|
| THE PAYE COLUMN IS THE ONE FIGURE THIS FILE DELIBERATELY DOES NOT MATCH TO
| ITS BOARD, and the client has now ruled why (Q-002, D-082). Board 15 draws
| PAYE as 25% of gross-less-all-three-deductions, charged on the whole once TAJ's
| FORTNIGHTLY threshold is passed. The ruling: 25% of the amount above the
| MONTHLY threshold, a band on the excess. Board 15 would withhold J$85,392 a
| month where the ruling asks J$5,230. `it computes PAYE on statutory income
| above the threshold, not on the whole` stops anybody changing it back.
|
| EMPLOYER CONTRIBUTIONS ARE POSTED AND REMITTED (D-083), so every tie below
| carries them: 5010 holds the employer's share, 2100 holds both halves, and the
| S01 remits both.
|
| APPROVAL IS OPEN, AND ITS FIRST USE ASKS FOR A TICK. The last test in this
| file approves a fresh run on the verified 2026-04 card — refused without the
| first-live-run acknowledgement, paid in four balanced lines with it — and it
| is last on purpose: every tie above reads the ledger as the seeder left it.
|
*/

/**
 * The estate these tests read, built once per process, in a database of its own.
 *
 * Built by the same seeder that builds Phoenix Park, because what is being
 * proven is that the seeder's arithmetic and the ledger's agree.
 */
function payrollEstate(): string
{
    static $built = false;

    $database = 'gs_estate_payrolltest';

    config([
        'database.connections.tenant' => array_merge(
            config('database.connections.mysql'),
            ['database' => $database],
        ),
        'database.default' => 'tenant',
    ]);

    DB::purge('tenant');

    if ($built) {
        return $database;
    }

    $owner = DB::connection('mysql_owner');
    $owner->statement("DROP DATABASE IF EXISTS `{$database}`");
    $owner->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    DB::purge('tenant');

    /*
     * The rate card is CENTRAL. The Jamaica-wide TAJ cards are not a fact about
     * one estate — the tenant migration deliberately does not duplicate the
     * table — so a payroll run points at a row in `gs_platform`.
     *
     * Seeded from the real seeder, and ALWAYS rather than only when the table is
     * empty: it is idempotent, and a test database left holding the provisional
     * card from before the ruling would otherwise quietly compute every payslip
     * here on the wrong threshold.
     */
    Artisan::call('db:seed', [
        '--class' => StatutoryRatesSeeder::class,
        '--force' => true,
    ]);

    payrollRoles();

    Artisan::call('migrate', [
        '--path' => 'database/migrations/tenant',
        '--database' => 'tenant',
        '--force' => true,
    ]);

    Artisan::call('db:seed', ['--class' => EstateFinanceSeeder::class, '--force' => true]);

    $built = true;

    return $database;
}

/**
 * The estate roles, and one signed-up person holding each one this file needs.
 *
 * SEEDED HERE RATHER THAN ASSUMED, because the central test database is not
 * this suite's to rely on. `AuthenticationTest` uses `RefreshDatabase`, which
 * migrates `gs_platform_test` fresh and leaves it empty for everything that runs
 * after it — so a payroll test that looked for "whoever is already the
 * Treasurer" passed on its own and failed in the full suite, which is the worst
 * shape a test can have. Nothing here depends on demo data existing.
 */
function payrollRoles(): void
{
    Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (['estate.community_super_admin', 'estate.property_manager', 'estate.treasurer'] as $role) {
        $email = str_replace('.', '-', $role).'@payrolltest.local';

        $user = User::on('mysql')->firstOrNew(['email' => $email]);

        $user->forceFill([
            'name' => 'Payroll fixture — '.$role,
            'password' => bcrypt(bin2hex(random_bytes(16))),
            'status' => 'active',
        ])->save();

        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/**
 * What one account holds, summed in raw SQL from the lines themselves.
 *
 * Written out longhand and signed by account type: an expense is debits less
 * credits, a liability is credits less debits, an asset is debits less credits.
 * This is the second, independent route to every payroll figure the screens
 * show, and it must not share an implementation with the first.
 */
function payrollAccountMinor(string $code, bool $creditNormal = false): int
{
    $sql = $creditNormal
        ? 'SUM(l.credit_minor - l.debit_minor)'
        : 'SUM(l.debit_minor - l.credit_minor)';

    return (int) DB::connection('tenant')->selectOne("
        SELECT COALESCE({$sql}, 0) AS total
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ?
    ", [$code])->total;
}

/** Every payslip figure in one run, summed straight out of the lines table. */
function payrollRunTotals(string $slug): object
{
    return DB::connection('tenant')->selectOne('
        SELECT COUNT(*) AS staff,
               COALESCE(SUM(l.gross_minor), 0) AS gross,
               COALESCE(SUM(l.nis_minor), 0) AS nis,
               COALESCE(SUM(l.nht_minor), 0) AS nht,
               COALESCE(SUM(l.education_tax_minor), 0) AS edu,
               COALESCE(SUM(l.paye_minor), 0) AS paye,
               COALESCE(SUM(l.net_minor), 0) AS net,
               COALESCE(SUM(l.employer_nis_minor + l.employer_nht_minor
                          + l.employer_education_tax_minor + l.employer_heart_minor), 0) AS employer
          FROM payroll_run_lines l
          JOIN payroll_runs r ON r.id = l.payroll_run_id
         WHERE r.slug = ?
    ', [$slug]);
}

/**
 * Somebody who can actually approve a run, found by permission not by title.
 *
 * NOT HARDCODED TO A ROLE NAME, and that is the point. The estate matrix gives
 * Payroll Full · Approver to exactly one role, and which one it is is a ruling
 * that has already moved once — board 24 draws the row with no Approver tag at
 * all, which made board 15's own approval flow unreachable. A test that named
 * the role would have to be edited every time the matrix is corrected, and a
 * test that has to be edited to keep passing is a test nobody trusts.
 */
function payrollApprover(): User
{
    /*
     * ON THE CENTRAL CONNECTION EXPLICITLY. This fixture points
     * `database.default` at the estate under test, and users, roles and
     * permissions all live in `gs_platform` — pinned there by D-012 precisely so
     * an estate cannot hold its own copy of who may do what. Reading them off
     * the default connection passes on its own and fails in the full suite,
     * where whichever estate fixture ran last decides what "default" means.
     */
    $user = User::on('mysql')
        ->get()
        ->first(fn (User $candidate): bool => $candidate->can('estate.payroll.approve'));

    expect($user)->not->toBeNull(
        'No role on this platform can approve a pay run. Board 15 draws an approval flow, so the '.
        'Payroll row of the matrix needs a Full · Approver cell — see RbacMatrixSeeder.'
    );

    return $user;
}

beforeEach(function () {
    payrollEstate();
});

/* ------------------------------------------------------------------ */
/* the arithmetic every payslip has to hold */
/* ------------------------------------------------------------------ */

it('leaves every payslip adding up to its own net', function () {
    $lines = DB::connection('tenant')->select('
        SELECT l.*, e.full_name
          FROM payroll_run_lines l
          JOIN employees e ON e.id = l.employee_id
    ');

    expect($lines)->not->toBeEmpty();

    foreach ($lines as $line) {
        $deductions = $line->nis_minor + $line->nht_minor + $line->education_tax_minor + $line->paye_minor;

        expect($line->gross_minor - $deductions)
            ->toBe($line->net_minor, $line->full_name.'\'s payslip does not add up to its own net.');
    }
});

it('computes PAYE on statutory income above the threshold, not on the whole', function () {
    /*
     * The one assertion that stops board 15's own arithmetic being copied back
     * in. Patricia Morgan is the only employee on this payroll who pays any
     * PAYE at all: her statutory income — gross less NIS, and NOT less NHT and
     * Education Tax — is the only one above the monthly threshold.
     */
    $morgan = DB::connection('tenant')->selectOne('
        SELECT l.*, r.statutory_rate_version_id FROM payroll_run_lines l
          JOIN employees e ON e.id = l.employee_id
          JOIN payroll_runs r ON r.id = l.payroll_run_id
         WHERE e.full_name = ? AND r.slug = ?
    ', ['Patricia Morgan', 'aug-2026']);

    // The card the run was actually calculated on, re-fetched centrally — and it
    // must be the 2026-04 card, because August's pay date falls after 1 April.
    $rates = StatutoryRateVersion::on('mysql')->findOrFail($morgan->statutory_rate_version_id);

    expect($rates->effective_from->format('Y-m'))->toBe('2026-04');

    $statutoryIncome = $morgan->gross_minor - $morgan->nis_minor;

    // TAJ's PUBLISHED monthly threshold — never the annual figure divided.
    $threshold = $rates->thresholdPerPeriod(Payroll::PERIODS_PER_YEAR);

    expect($threshold)->toBe(158_530_00);
    $expected = intdiv(max(0, $statutoryIncome - $threshold) * $rates->paye_bp + 5_000, 10_000);

    expect($morgan->paye_minor)->toBe($expected);

    // And the shape board 15 draws — 25% of gross less all three deductions,
    // charged on the whole — is NOT what came out. If this ever passes, the
    // calculation has been changed to match the picture.
    $boardsFormula = intdiv(
        ($morgan->gross_minor - $morgan->nis_minor - $morgan->nht_minor - $morgan->education_tax_minor)
        * $rates->paye_bp + 5_000,
        10_000,
    );

    expect($morgan->paye_minor)->not->toBe($boardsFormula);
});

it('pays nobody for a month before they were employed', function () {
    $thomas = Employee::query()->where('full_name', 'Wayne Thomas')->firstOrFail();

    expect($thomas->employed_since->format('Y-m'))->toBe('2026-06');

    $may = payrollRunTotals('may-2026');
    $june = payrollRunTotals('jun-2026');

    expect((int) $may->staff)->toBe(3)
        ->and((int) $june->staff)->toBe(4);

    // Board 13's own variance: the two months differ by exactly one person's
    // gross and one person's net, not by a rounding.
    $line = DB::connection('tenant')->selectOne('
        SELECT l.* FROM payroll_run_lines l
          JOIN payroll_runs r ON r.id = l.payroll_run_id
         WHERE l.employee_id = ? AND r.slug = ?
    ', [$thomas->id, 'jun-2026']);

    expect((int) $june->gross - (int) $may->gross)->toBe($line->gross_minor)
        ->and((int) $june->net - (int) $may->net)->toBe($line->net_minor);
});

/* ------------------------------------------------------------------ */
/* every figure board 15 draws, traced to the lines it is made of */
/* ------------------------------------------------------------------ */

it('draws board 15 from the payslips rather than from its own header', function () {
    $run = PayrollRun::query()->where('slug', 'aug-2026')->firstOrFail();
    $board = app(Payroll::class)->runBoard($run);
    $totals = payrollRunTotals('aug-2026');

    $box = fn (string $key) => collect($board['summary'])->firstWhere('key', $key);

    expect($box('employees')['value'])->toBe((string) $totals->staff)
        ->and($box('gross')['value_minor'])->toBe((int) $totals->gross)
        ->and($box('net')['value_minor'])->toBe((int) $totals->net)
        ->and($box('deductions')['value_minor'])
        ->toBe((int) $totals->nis + (int) $totals->nht + (int) $totals->edu + (int) $totals->paye);

    // And the run header agrees with its own lines, which is what makes board
    // 13's Gross and Net columns the same figures as board 15's summary boxes.
    expect($run->gross_minor)->toBe((int) $totals->gross)
        ->and($run->net_minor)->toBe((int) $totals->net);
});

it('ties the payroll expense account to the gross of every run it has paid', function () {
    $paid = DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.gross_minor), 0) AS gross,
               COALESCE(SUM(l.net_minor), 0) AS net
          FROM payroll_run_lines l
          JOIN payroll_runs r ON r.id = l.payroll_run_id
         WHERE r.status = ?
    ', [PayrollRun::PAID]);

    expect(payrollAccountMinor(Payroll::EXPENSE))->toBe((int) $paid->gross);
});

it('ties the statutory payable to what every paid run withheld and contributed, and nothing else', function () {
    $paid = DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.gross_minor - l.net_minor), 0) AS withheld,
               COALESCE(SUM(l.employer_nis_minor + l.employer_nht_minor
                          + l.employer_education_tax_minor + l.employer_heart_minor), 0) AS employer
          FROM payroll_run_lines l
          JOIN payroll_runs r ON r.id = l.payroll_run_id
         WHERE r.status = ?
    ', [PayrollRun::PAID]);

    /*
     * The control account, read from its own posted lines, against the payslips
     * that produced it. The liability the estate reports is exactly what it took
     * off four people PLUS what it owes on top as their employer (D-083) — both
     * halves the S01 remits — and nothing has drifted between them.
     */
    $owed = (int) $paid->withheld + (int) $paid->employer;

    expect((int) $paid->employer)->toBeGreaterThan(0)
        ->and(payrollAccountMinor(Payroll::STATUTORY_PAYABLE, creditNormal: true))->toBe($owed)
        ->and(app(Payroll::class)->outstandingStatutoryMinor())->toBe($owed);
});

it('ties employer contributions expense to what every paid run owed as employer', function () {
    $paid = DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.employer_nis_minor + l.employer_nht_minor
                          + l.employer_education_tax_minor + l.employer_heart_minor), 0) AS employer
          FROM payroll_run_lines l
          JOIN payroll_runs r ON r.id = l.payroll_run_id
         WHERE r.status = ?
    ', [PayrollRun::PAID]);

    // D-072 found three employer rates nothing read. D-083 posts them: 5010 is
    // the estate's real cost of employment beyond gross, to the cent.
    expect(payrollAccountMinor(Payroll::EMPLOYER_CONTRIBUTIONS))->toBe((int) $paid->employer);
});

it('posts one balanced entry per paid run and nothing outside it', function () {
    $runs = PayrollRun::query()->where('status', PayrollRun::PAID)->get();

    expect($runs)->not->toBeEmpty();

    foreach ($runs as $run) {
        expect($run->journal_ref)->not->toBeNull();

        $lines = DB::connection('tenant')->select('
            SELECT a.code, l.debit_minor, l.credit_minor
              FROM journal_lines l
              JOIN accounts a ON a.id = l.account_id
             WHERE l.entry_ref = ?
        ', [$run->journal_ref]);

        // Four lines: gross, the employer's share, the payable for both halves,
        // and net pay out of the bank (D-083).
        expect($lines)->toHaveCount(4);

        $debits = array_sum(array_map(static fn ($l) => $l->debit_minor, $lines));
        $credits = array_sum(array_map(static fn ($l) => $l->credit_minor, $lines));

        expect($debits)->toBe($credits, $run->period_label.' does not balance.');

        $by = [];

        foreach ($lines as $line) {
            $by[$line->code] = $line;
        }

        $totals = payrollRunTotals($run->slug);

        expect($by[Payroll::EXPENSE]->debit_minor)->toBe((int) $totals->gross)
            ->and($by[Payroll::EMPLOYER_CONTRIBUTIONS]->debit_minor)->toBe((int) $totals->employer)
            ->and($by[Payroll::BANK]->credit_minor)->toBe((int) $totals->net)
            ->and($by[Payroll::STATUTORY_PAYABLE]->credit_minor)
            ->toBe((int) $totals->gross - (int) $totals->net + (int) $totals->employer);
    }
});

it('remits on the S01 exactly what the run it names withheld', function () {
    $filing = StatutoryFiling::query()
        ->where('form_code', StatutoryFiling::S01)
        ->where('period_label', 'August 2026')
        ->firstOrFail();

    $totals = payrollRunTotals('aug-2026');

    expect($filing->nis_minor)->toBe((int) $totals->nis)
        ->and($filing->nht_minor)->toBe((int) $totals->nht)
        ->and($filing->education_tax_minor)->toBe((int) $totals->edu)
        ->and($filing->paye_minor)->toBe((int) $totals->paye)
        ->and($filing->deductionsMinor())->toBe((int) $totals->gross - (int) $totals->net);

    // And the employer's share on the same return — the ruling's own list, PAYE,
    // NIS, NHT, Education Tax AND HEART — so filing clears 2100 to nil (D-083).
    expect($filing->employerContributionsMinor())->toBe((int) $totals->employer)
        ->and((int) $filing->heart_minor)->toBeGreaterThan(0)
        ->and($filing->remittanceMinor())
        ->toBe((int) $totals->gross - (int) $totals->net + (int) $totals->employer)
        ->and((int) $filing->total_minor)->toBe($filing->remittanceMinor());
});

/* ------------------------------------------------------------------ */
/* the refusals, each proven rather than assumed */
/* ------------------------------------------------------------------ */

it('refuses to approve a run calculated on a card with no verified TAJ figures', function () {
    /*
     * THIS TEST USED TO ASSERT THAT EVERY RUN WAS BLOCKED, and it was written to
     * fail the day the rates were verified — which it did, when Q-002 was ruled.
     *
     * The block survives for the one case it still guards. The 2027-04 card was
     * ruled on its annual threshold alone, with no TAJ periodic figures, so it is
     * seeded unverified; a run calculated on it must stop at `calculated` with a
     * sentence that says what is missing. Built in memory, so nothing here moves
     * the estate every other test in this file reads.
     */
    $unverified = StatutoryRateVersion::on('mysql')
        ->whereNull('superseded_at')
        ->where('is_verified', false)
        ->orderByDesc('effective_from')
        ->firstOrFail();

    $run = new PayrollRun([
        'status' => PayrollRun::CALCULATED,
        'statutory_rate_version_id' => $unverified->id,
        'period_label' => 'April 2027',
    ]);

    $approver = payrollApprover();

    expect(app(Payroll::class)->approvalRefusal($run, $approver))->toContain('no verified TAJ periodic figures')
        ->and(app(Payroll::class)->mayApprove($run, $approver))->toBeFalse();
});

it('refuses to let whoever prepared a run also approve it', function () {
    $run = PayrollRun::query()->where('slug', 'aug-2026')->firstOrFail();

    /*
     * The second-person rule is only reachable by somebody who HOLDS the
     * approval, and that is the whole point of it: the estate's own seeded
     * preparer is the Treasurer, who has Payroll Full and no Approver tag, so
     * they are already refused on permission alone. Stacking a second reason on
     * a person who is blocked anyway would prove nothing.
     *
     * The case that matters is the administrator who prepares a run and then
     * approves it — one person, both ends, nobody checking. So the approver is
     * made the preparer here and the refusal must still stand.
     */
    $approver = payrollApprover();
    $originalPreparer = $run->prepared_by;

    $run->forceFill(['prepared_by' => $approver->getKey()])->save();

    try {
        $refusal = app(Payroll::class)->approvalRefusal($run->refresh(), $approver);

        expect($refusal)->toContain('cannot also approve it')
            ->and(app(Payroll::class)->mayApprove($run, $approver))->toBeFalse();

        expect(fn () => app(Payroll::class)->approve($run, $approver))
            ->toThrow(DomainException::class);

        // And it is checked BEFORE the draft-rates block, because the two
        // refusals are different sentences and a screen prints the one that
        // applies. Being told the rates are draft, when the real problem is
        // that you are approving your own work, sends somebody to the wrong
        // person.
        expect($refusal)->not->toContain('still marked draft');
    } finally {
        $run->forceFill(['prepared_by' => $originalPreparer])->save();
    }

    expect($run->refresh()->status)->toBe(PayrollRun::CALCULATED);
});

it('refuses to approve a run for a role that does not hold payroll approval', function () {
    $run = PayrollRun::query()->where('slug', 'aug-2026')->firstOrFail();

    $manager = User::on('mysql')
        ->whereHas('roles', fn ($q) => $q->where('name', 'estate.property_manager'))
        ->firstOrFail();

    expect(app(Payroll::class)->approvalRefusal($run, $manager))
        ->toContain('does not hold')
        ->and(app(Payroll::class)->mayApprove($run, $manager))->toBeFalse();
});

it('refuses to calculate a run while an exception is unresolved', function () {
    $run = PayrollRun::query()->where('slug', 'sep-2026')->firstOrFail();

    expect($run->hasUnresolvedExceptions())->toBeTrue()
        ->and($run->lines()->count())->toBe(0);

    expect(fn () => app(Payroll::class)->calculate($run))
        ->toThrow(DomainException::class);

    // Still nothing calculated, so nothing could have been paid.
    expect($run->refresh()->lines()->count())->toBe(0);
});

it('refuses to recalculate or re-approve a run that has already been paid', function () {
    $run = PayrollRun::query()->where('slug', 'jul-2026')->firstOrFail();

    expect($run->isPosted())->toBeTrue();

    expect(fn () => app(Payroll::class)->calculate($run))->toThrow(DomainException::class);

    $approver = payrollApprover();

    expect(app(Payroll::class)->approvalRefusal($run, $approver))->toContain('already been approved');
});

it('refuses to file a return twice', function () {
    $filed = StatutoryFiling::query()->where('status', StatutoryFiling::FILED)->firstOrFail();
    $approver = payrollApprover();

    expect(fn () => app(Payroll::class)->fileReturn($filed, $approver))
        ->toThrow(DomainException::class);
});

it('refuses to send a run back without saying what is wrong with it', function () {
    $run = PayrollRun::query()->where('slug', 'aug-2026')->firstOrFail();
    $approver = payrollApprover();

    expect(fn () => app(Payroll::class)->requestChanges($run, '   ', $approver))
        ->toThrow(DomainException::class);

    expect($run->refresh()->status)->toBe(PayrollRun::CALCULATED);
});

/* ------------------------------------------------------------------ */
/* what a payroll screen may show about a person */
/* ------------------------------------------------------------------ */

it('never sends a whole bank account number or NIS number to a screen', function () {
    $board = app(Payroll::class)->employeesBoard();
    $payload = json_encode($board, JSON_THROW_ON_ERROR);

    expect($board['rows'])->not->toBeEmpty();

    foreach (Employee::query()->get() as $employee) {
        expect($board['rows'])->toContain(
            ...array_filter($board['rows'], static fn (array $row): bool => $row['name'] === $employee->full_name),
        );
    }

    /*
     * The masked forms are what the board draws — "NCB •••• 3315". What must
     * never appear is a number a bank would accept, so the assertion is on the
     * SHAPE: every row's bank field carries the mask, and no row carries a bare
     * run of digits long enough to be an account.
     */
    foreach ($board['rows'] as $row) {
        expect($row['bank'])->toContain('••••')
            ->and($row['nis'])->toContain('•••');
    }

    // And no field on any row carries a bare run of digits long enough to be
    // an account number. Checked on the identity fields only: a monthly rate in
    // minor units is legitimately eight digits, and asserting over the whole
    // payload would fail on the one figure the board is supposed to print.
    foreach ($board['rows'] as $row) {
        expect(preg_match('/\d{5,}/', $row['bank'].' '.$row['nis']))->toBe(0);
    }
});

it('records an excluded employee as excluded rather than resolved', function () {
    /*
     * The distinction is a person's wages. An excluded employee is left out of
     * the run entirely — no payslip, no gross, no net — and a run that treated
     * exclusion as resolution would pay somebody nothing and report itself
     * complete.
     */
    $exception = PayrollException::query()
        ->where('status', PayrollException::UNRESOLVED)
        ->firstOrFail();

    expect($exception->blocksCalculation())->toBeTrue();

    $unexcluded = new PayrollException(['status' => PayrollException::EXCLUDED]);
    $resolved = new PayrollException(['status' => PayrollException::RESOLVED]);

    expect($unexcluded->blocksCalculation())->toBeFalse()
        ->and($resolved->blocksCalculation())->toBeFalse()
        ->and(PayrollException::EXCLUDED)->not->toBe(PayrollException::RESOLVED);
});

/* ------------------------------------------------------------------ */
/* the first live approval — LAST IN THIS FILE, because it pays a run */
/* ------------------------------------------------------------------ */

it('approves a run on a verified card, asking for the acknowledgement on the first live one', function () {
    /*
     * Q-002 is ruled and payroll is unblocked. This pays a fresh October run
     * end to end, and it is the last test here on purpose: the estate under test
     * is built once per process, and every tie above reads the ledger as the
     * seeder left it.
     */
    $treasurer = User::on('mysql')
        ->whereHas('roles', fn ($q) => $q->where('name', 'estate.treasurer'))
        ->firstOrFail();
    $approver = payrollApprover();

    $run = PayrollRun::create([
        'reference' => 'PR-OCT2026',
        'slug' => 'oct-2026',
        'period_label' => 'October 2026',
        'period_start' => '2026-10-01',
        'period_end' => '2026-10-31',
        'periods_per_year' => Payroll::PERIODS_PER_YEAR,
        'statutory_rate_version_id' => StatutoryRateVersion::forPayDate('2026-10-31')->id,
        'currency' => 'JMD',
        'prepared_by' => $treasurer->getKey(),
        'prepared_by_name' => $treasurer->name,
    ]);

    $payroll = app(Payroll::class);
    $payroll->calculate($run);

    expect($payroll->approvalRefusal($run->refresh(), $approver))->toBeNull()
        ->and($payroll->needsReconciliationAcknowledgement())->toBeTrue();

    // Without the tick: refused, naming the card — and nothing posted.
    $refusal = null;

    try {
        $payroll->approve($run, $approver);
    } catch (DomainException $e) {
        $refusal = $e->getMessage();
    }

    expect($refusal)->toContain('first live pay run')
        ->and($refusal)->toContain('TAJ 2026/27 (2026-04)')
        ->and($run->refresh()->status)->toBe(PayrollRun::CALCULATED)
        ->and($run->journal_ref)->toBeNull();

    // With it: paid, in four balanced lines, with the acknowledgement on the record.
    $paid = $payroll->approve($run, $approver, reconciled: true);

    expect($paid->status)->toBe(PayrollRun::PAID)
        ->and($paid->reconciliation_acknowledged_by_name)->toBe($approver->name)
        ->and($paid->reconciliation_rate_version)->toBe('TAJ 2026/27 (2026-04)');

    $totals = payrollRunTotals('oct-2026');

    $lines = DB::connection('tenant')->select('
        SELECT a.code, l.debit_minor, l.credit_minor
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE l.entry_ref = ?
    ', [$paid->journal_ref]);

    $by = [];

    foreach ($lines as $line) {
        $by[$line->code] = $line;
    }

    expect($lines)->toHaveCount(4)
        ->and($by[Payroll::EXPENSE]->debit_minor)->toBe((int) $totals->gross)
        ->and($by[Payroll::EMPLOYER_CONTRIBUTIONS]->debit_minor)->toBe((int) $totals->employer)
        ->and($by[Payroll::STATUTORY_PAYABLE]->credit_minor)
        ->toBe((int) $totals->gross - (int) $totals->net + (int) $totals->employer)
        ->and($by[Payroll::BANK]->credit_minor)->toBe((int) $totals->net);
});

/* ------------------------------------------------------------------ */
/* the payroll calendar — AFTER the approval, because it closes months */
/* ------------------------------------------------------------------ */

it('starts the next month on the calendar, one open run at a time', function () {
    /*
     * 12 §2, Wave 1: "Start new payroll run — payroll calendar of periods and
     * close dates." The calendar is calendar months, one open at a time, read
     * from the runs themselves: the next period is the month after the latest
     * run, whatever its state. October was created above, so November is next
     * — and August is still open, so November cannot start yet.
     */
    $treasurer = User::on('mysql')
        ->whereHas('roles', fn ($q) => $q->where('name', 'estate.treasurer'))
        ->firstOrFail();

    $payroll = app(Payroll::class);
    $calendar = $payroll->calendar();

    expect($calendar['slug'])->toBe('nov-2026')
        ->and($calendar['label'])->toBe('November 2026')
        ->and($calendar['period_start'])->toBe('2026-11-01')
        ->and($calendar['period_end'])->toBe('2026-11-30')
        ->and($calendar['rate_card'])->toBe('2026-04')
        ->and($calendar['blocked_by'])->toContain('still open');

    expect(fn () => $payroll->startRun($treasurer))->toThrow(DomainException::class, 'still open');

    /*
     * Close the two open months by hand — this is the end of the file and the
     * estate is rebuilt per process, so the ledger ties above have already
     * been read. With nothing open, November starts as a draft prepared by
     * whoever pressed the button, on the card in force on its pay date, with
     * nothing calculated and nothing posted.
     */
    PayrollRun::query()->where('status', '!=', PayrollRun::PAID)->update(['status' => PayrollRun::PAID]);

    expect($payroll->calendar()['blocked_by'])->toBeNull();

    $run = $payroll->startRun($treasurer);

    expect($run->slug)->toBe('nov-2026')
        ->and($run->reference)->toBe('PR-NOV2026')
        ->and($run->status)->toBe(PayrollRun::DRAFT)
        ->and($run->prepared_by_name)->toBe($treasurer->name)
        ->and($run->period_end->toDateString())->toBe('2026-11-30')
        ->and($run->statutory_rate_version_id)->toBe(StatutoryRateVersion::forPayDate('2026-11-30')->id)
        ->and($run->lines()->count())->toBe(0)
        ->and($run->journal_ref)->toBeNull();

    // A month is paid once: starting again is refused, and the calendar has
    // moved on to December — blocked, because November is now the open one.
    expect(fn () => $payroll->startRun($treasurer))->toThrow(DomainException::class, 'still open')
        ->and($payroll->calendar()['slug'])->toBe('dec-2026');

    // And it is never asked again.
    expect($payroll->needsReconciliationAcknowledgement())->toBeFalse();
});

/* ------------------------------------------------------------------ */
/* the two files a run leaves in — 12 §1, Wave 3 */
/* ------------------------------------------------------------------ */

it('exports an approved run as two different documents, and refuses a draft', function () {
    /*
     * "Payroll export XLSX + bank CSV behind a PaymentGateway-style adapter."
     * They are DIFFERENT DOCUMENTS: one is an instruction to move money and
     * carries account numbers, the other a record of what was paid and carries
     * deductions. Neither is a view of the other.
     */
    $treasurer = User::on('mysql')
        ->whereHas('roles', fn ($q) => $q->where('name', 'estate.treasurer'))
        ->firstOrFail();

    $formats = app(PayrollFileFormats::class);

    expect(array_column($formats->catalogue(), 'key'))->toBe(['summary', 'bank']);
    expect(fn () => $formats->find('whatever'))->toThrow(DomainException::class);

    $paid = PayrollRun::query()->where('status', PayrollRun::PAID)->orderByDesc('period_start')->firstOrFail();
    $lines = $paid->lines()->with('employee')->orderByDesc('gross_minor')->get();

    expect($lines)->not->toBeEmpty();

    // THE BANK FILE: net only, and an employee with no account is IN it with a
    // note — dropping them would hand the bank a file that pays fewer people
    // than the run approved.
    $bank = $formats->find('bank')->build($paid, $lines);

    expect(str_starts_with($bank, "\xEF\xBB\xBF"))->toBeTrue();

    $bankRows = array_values(array_filter(explode("\n", trim($bank))));

    expect(count($bankRows) - 1)->toBe($lines->count())
        ->and($bankRows[0])->toContain('Account number')
        ->and($bankRows[0])->not->toContain('PAYE')
        ->and($bank)->toContain(number_format($lines->first()->net_minor / 100, 2, '.', ''));

    // THE SUMMARY: a real xlsx — a zip whose parts are a valid workbook — with
    // the deductions in it and no account numbers anywhere.
    $xlsx = $formats->find('summary')->build($paid, $lines);

    $tmp = tempnam(sys_get_temp_dir(), 'gstest');
    file_put_contents($tmp, $xlsx);

    $zip = new ZipArchive;

    expect($zip->open($tmp))->toBeTrue();

    $names = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }

    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    unlink($tmp);

    expect($names)->toContain('[Content_Types].xml')
        ->and($names)->toContain('xl/workbook.xml')
        ->and($names)->toContain('xl/worksheets/sheet1.xml')
        ->and($sheet)->toContain('PAYE')

        // Numbers as numbers, so an accountant can total a column.
        ->and($sheet)->toContain('<v>'.($lines->first()->gross_minor / 100).'</v>')

        // The total row is summed here, never a formula that recomputes in the
        // reader's copy — this file is a record, not a working model.
        ->and($sheet)->toContain('<v>'.($lines->sum('net_minor') / 100).'</v>')
        ->and($sheet)->not->toContain('<f>');

    // No account number reaches the accountant's file.
    foreach ($lines as $line) {
        if ($line->employee->bank_account_number !== null) {
            expect($sheet)->not->toContain($line->employee->bank_account_number);
        }
    }
});
