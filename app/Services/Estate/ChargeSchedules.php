<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\Account;
use App\Models\Estate\ChargeRun;
use App\Models\Estate\ChargeSchedule;
use App\Models\Estate\Journal;
use App\Models\Estate\Unit;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\MoneyFormatter;
use Brick\Money\Money;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The charge schedule — board 5's "Charge schedule" tab (12 §2, item 17):
 * "recurring run with preview and reversal".
 *
 * A SCHEDULE IS THE STANDING DECISION, A RUN IS ONE MONTH OF IT. The schedule
 * says what every unit in its scope is billed and on which day; a run posts one
 * period of it as ONE entry — a debit line per unit, one credit to income —
 * through the same `Dues::chargeRun()` the estate's month has always been
 * billed with, so a scheduled month and a hand-raised one are the same thing in
 * the ledger.
 *
 * PREVIEW, THEN POST, AND THE TWO MUST AGREE. The screen shows the next
 * period's unit count and total before anything posts; posting sends those two
 * figures back, and a register that has changed in between is refused rather
 * than billed on a list nobody saw.
 *
 * ONE LIVE RUN PER PERIOD, NO SKIPPED MONTH. The next period is the month after
 * the latest run still standing — or this month, for a new schedule — so a
 * month cannot be billed twice or silently passed over, and a run is posted no
 * further ahead than next month.
 *
 * REVERSAL, NEVER DELETION. A wrong run is reversed whole: the ledger posts the
 * mirror entry, the run's charges are marked reversed so they leave the ageing,
 * and the run records who, when and why. That period can then be posted again.
 */
final class ChargeSchedules
{
    public function __construct(
        private readonly Dues $dues,
        private readonly Ledger $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function board(): array
    {
        $schedules = ChargeSchedule::query()->orderByDesc('is_active')->orderBy('description')->get();

        $runs = ChargeRun::query()->with('schedule')->orderByDesc('period')->orderByDesc('id')->limit(24)->get();

        return [
            'schedules' => $schedules->map(function (ChargeSchedule $schedule): array {
                $next = $this->nextPeriod($schedule);
                $preview = null;
                $blocked = null;

                try {
                    $preview = $this->preview($schedule, $next);
                } catch (DomainException $refused) {
                    $blocked = $refused->getMessage();
                }

                return [
                    'id' => $schedule->id,
                    'description' => $schedule->description,
                    'amount' => MoneyFormatter::fromMinor($schedule->amount_minor, $schedule->currency),
                    'scope' => $schedule->scopeLabel(),
                    'due_day' => $schedule->due_day,
                    'account' => $schedule->account_code,
                    'is_active' => $schedule->is_active,
                    'next_period' => $next->format('Y-m'),
                    'next_label' => $next->format('F Y'),
                    'preview' => $preview,
                    'blocked' => $schedule->is_active ? $blocked : 'This schedule is stopped. Nothing further is billed from it.',
                ];
            })->all(),
            'runs' => $runs->map(static fn (ChargeRun $run): array => [
                'id' => $run->id,
                'schedule' => $run->schedule->description,
                'period' => Carbon::createFromFormat('Y-m-d', $run->period.'-01')->format('F Y'),
                'due_on' => $run->due_on->format('M j, Y'),
                'units' => $run->unit_count,
                'total' => MoneyFormatter::fromMinor($run->total_minor, $run->currency),
                'journal_ref' => $run->journal_ref,
                'posted' => $run->posted_at->format('M j, Y').($run->posted_by_name === null ? '' : ' by '.$run->posted_by_name),
                'reversed' => $run->isReversed(),
                'reversal' => $run->isReversed()
                    ? 'Reversed '.$run->reversed_at?->format('M j, Y').' by '.($run->reversed_by_name ?? 'an unrecorded officer').' — '.$run->reversal_reason
                    : null,
            ])->all(),
            'incomeAccounts' => Account::query()
                ->where('type', Account::INCOME)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['code', 'name'])
                ->map(static fn (Account $account): array => ['code' => $account->code, 'label' => $account->code.' — '.$account->name])
                ->all(),
            'phases' => Unit::query()->whereNotNull('block')->distinct()->orderBy('block')->pluck('block')->all(),
        ];
    }

    /**
     * Define a schedule. Nothing is billed until a run is posted.
     *
     * @param  array<string, mixed>  $fields
     */
    public function create(array $fields, User $by): ChargeSchedule
    {
        $description = trim((string) ($fields['description'] ?? ''));
        $amount = Money::of((string) ($fields['amount'] ?? '0'), 'JMD');
        $scope = (string) ($fields['scope'] ?? ChargeSchedule::ESTATE);
        $phase = $scope === ChargeSchedule::PHASE ? trim((string) ($fields['phase'] ?? '')) : null;
        $dueDay = (int) ($fields['due_day'] ?? 1);
        $accountCode = (string) ($fields['account_code'] ?? '4000');

        if ($description === '') {
            throw new DomainException('Name the charge — "Maintenance fee". It is printed on every charge the schedule raises.');
        }

        if ($dueDay < 1 || $dueDay > 28) {
            throw new DomainException('Choose a due day from the 1st to the 28th, so every month has it.');
        }

        $account = Account::query()->where('code', $accountCode)->first();

        if ($account === null || $account->type !== Account::INCOME || ! $account->is_active) {
            throw new DomainException('A recurring charge credits an income account that is in use.');
        }

        // The same checks a whole-phase charge makes: a positive amount, a real
        // phase, and a register with somebody in it to bill.
        $this->dues->bulkPreview($scope, $phase, $amount);

        $schedule = ChargeSchedule::query()->create([
            'description' => mb_substr($description, 0, 120),
            'amount_minor' => $amount->getMinorAmount()->toInt(),
            'currency' => 'JMD',
            'account_code' => $accountCode,
            'scope' => $scope,
            'phase' => $phase,
            'due_day' => $dueDay,
            'is_active' => true,
            'created_by' => $by->getKey(),
            'created_by_name' => $by->name,
        ]);

        $this->audit->record(
            action: 'estate.charge_schedule_created',
            entityType: 'ChargeSchedule',
            entityId: (string) $schedule->id,
            after: $schedule->only(['description', 'amount_minor', 'account_code', 'scope', 'phase', 'due_day']),
        );

        return $schedule;
    }

    /** Stop a schedule. Its runs stand; nothing further is billed from it. */
    public function stop(ChargeSchedule $schedule, User $by): void
    {
        $schedule->forceFill(['is_active' => false])->save();

        $this->audit->record(
            action: 'estate.charge_schedule_stopped',
            entityType: 'ChargeSchedule',
            entityId: (string) $schedule->id,
            after: ['stopped_by' => $by->name],
        );
    }

    /** The period the next run bills: the month after the latest run standing, or this month. */
    public function nextPeriod(ChargeSchedule $schedule): Carbon
    {
        $latest = ChargeRun::query()
            ->where('charge_schedule_id', $schedule->id)
            ->whereNull('reversed_at')
            ->max('period');

        return $latest === null
            ? Carbon::today()->startOfMonth()
            : Carbon::createFromFormat('Y-m-d', $latest.'-01')->startOfDay()->addMonthNoOverflow();
    }

    /**
     * What a run for this period would post. Posts nothing.
     *
     * @return array{period: string, label: string, due_on: string, due_label: string, count: int, total_minor: int, total: string}
     */
    public function preview(ChargeSchedule $schedule, Carbon $period): array
    {
        if ($period->greaterThan(Carbon::today()->startOfMonth()->addMonthNoOverflow())) {
            throw new DomainException($period->format('F Y').' is more than a month ahead. A run is posted for this month or next, never further out.');
        }

        $amount = Money::ofMinor($schedule->amount_minor, $schedule->currency);
        $list = $this->dues->bulkPreview($schedule->scope, $schedule->phase, $amount);
        $due = $period->copy()->day($schedule->due_day);

        return [
            'period' => $period->format('Y-m'),
            'label' => $period->format('F Y'),
            'due_on' => $due->toDateString(),
            'due_label' => $due->format('M j, Y'),
            'count' => $list['count'],
            'total_minor' => $list['total_minor'],
            'total' => MoneyFormatter::fromMinor($list['total_minor'], $schedule->currency),
        ];
    }

    /**
     * Post one period — all of it or none — against the figures the preview showed.
     */
    public function post(ChargeSchedule $schedule, string $period, int $expectedCount, int $expectedTotalMinor, User $by): ChargeRun
    {
        if (! $schedule->is_active) {
            throw new DomainException('This schedule is stopped. Nothing further is billed from it.');
        }

        $next = $this->nextPeriod($schedule);

        if ($period !== $next->format('Y-m')) {
            throw new DomainException('The next period for this schedule is '.$next->format('F Y').'. A month is billed once, in order, and never skipped.');
        }

        $preview = $this->preview($schedule, $next);

        if ($preview['count'] !== $expectedCount || $preview['total_minor'] !== $expectedTotalMinor) {
            throw new DomainException(sprintf(
                'The estate register has changed since this run was previewed — it now bills %d units for %s. Nothing has been posted; check the new figures and post again.',
                $preview['count'],
                $preview['total'],
            ));
        }

        $amount = Money::ofMinor($schedule->amount_minor, $schedule->currency);
        $description = $schedule->description.' — '.$next->format('F Y');

        $units = Unit::query()
            ->when($schedule->scope === ChargeSchedule::PHASE, static fn ($query) => $query->where('block', $schedule->phase))
            ->orderBy('reference')
            ->get();

        return DB::connection('tenant')->transaction(function () use ($schedule, $next, $preview, $amount, $description, $units, $by): ChargeRun {
            $reference = $this->dues->chargeRun(
                rows: $units->map(static fn (Unit $unit): array => [
                    'unit' => $unit,
                    'amount' => $amount,
                    'description' => $description,
                    'type' => 'dues',
                    'period' => $next->format('Y-m'),
                ])->all(),
                dueOn: $preview['due_on'],
                memo: $description,
                account: $schedule->account_code,
                by: $by,
            );

            $run = ChargeRun::query()->create([
                'charge_schedule_id' => $schedule->id,
                'period' => $next->format('Y-m'),
                'due_on' => $preview['due_on'],
                'unit_count' => $preview['count'],
                'total_minor' => $preview['total_minor'],
                'currency' => $schedule->currency,
                'journal_ref' => (string) $reference,
                'posted_by' => $by->getKey(),
                'posted_by_name' => $by->name,
                'posted_at' => Carbon::now(),
            ]);

            $this->audit->record(
                action: 'estate.charge_run_posted',
                entityType: 'ChargeRun',
                entityId: (string) $run->id,
                after: ['schedule' => $schedule->description, 'period' => $run->period, 'units' => $run->unit_count, 'total_minor' => $run->total_minor, 'journal' => $run->journal_ref],
            );

            return $run;
        });
    }

    /**
     * Reverse a whole run: the mirror entry, the charges out of the ageing, and
     * the reason on the record.
     */
    public function reverse(ChargeRun $run, string $reason, User $by): ChargeRun
    {
        if ($run->isReversed()) {
            throw new DomainException('This run was reversed on '.$run->reversed_at?->format('M j, Y').'. Reversing it twice would bill the month back.');
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < 10) {
            throw new DomainException('Say why the run is being reversed. Every household it billed will see the charge and its reversal on their statement.');
        }

        $journal = Journal::query()->where('reference', $run->journal_ref)->first();

        if ($journal === null) {
            throw new DomainException('The entry this run posted cannot be found, so it cannot be reversed from here.');
        }

        return DB::connection('tenant')->transaction(function () use ($run, $journal, $reason, $by): ChargeRun {
            $mirror = $this->ledger->reverse($journal, 'Reversal of '.$journal->memo.' — '.$reason, $by);

            DB::connection('tenant')->table('charges')
                ->where('journal_ref', $run->journal_ref)
                ->update(['status' => 'reversed', 'updated_at' => Carbon::now()]);

            $run->forceFill([
                'reversal_journal_ref' => $mirror->reference,
                'reversed_at' => Carbon::now(),
                'reversed_by' => $by->getKey(),
                'reversed_by_name' => $by->name,
                'reversal_reason' => mb_substr($reason, 0, 300),
            ])->save();

            $this->audit->record(
                action: 'estate.charge_run_reversed',
                entityType: 'ChargeRun',
                entityId: (string) $run->id,
                before: ['journal' => $run->journal_ref],
                after: ['reversal_journal' => $mirror->reference, 'reason' => $reason],
            );

            return $run;
        });
    }
}
