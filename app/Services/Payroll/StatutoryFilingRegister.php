<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\PayrollRun;
use App\Models\StatutoryFiling;
use Illuminate\Support\Carbon;

/**
 * The register of returns Gemini Security owes the Jamaican authorities.
 *
 * Everything this screen shows is a fact about a record plus a rule about the
 * calendar, and both live here rather than in the controller: whether a return
 * is owed yet, whether it is late, and whether a new one can be prepared at all.
 *
 * THE ONE RULE THAT GOVERNS THIS MODULE (Build Spec, screen 30):
 *
 *     Amounts derive from APPROVED runs only. A draft run never appears in a
 *     filing.
 *
 * D-021 currently holds every run short of approval, so no return can be
 * prepared today. That is not a gap to be worked around by quietly filing
 * against calculated figures - a remittance is a payment to the tax authority,
 * and paying a number nobody has signed off is exactly the failure D-021 exists
 * to prevent. So `blockedReason()` says so, and the screen's one control is
 * inert with that sentence on it.
 *
 * THERE IS NO EDIT AND NO DELETE HERE, NOT EVEN A DISABLED ONE. A filed return
 * is a posted record (invariant 4). A greyed-out delete would tell the reader
 * that deletion is a thing this system does and they simply lack the right to
 * do it, which is false: it is a thing this system does not do.
 */
final class StatutoryFilingRegister
{
    /**
     * What each return covers, said in the words the return itself uses.
     *
     * A map rather than a column: this is a fact about the FORM, identical on
     * every row that shares a code, and storing it per row would let two
     * September remittances disagree about what a September remittance is.
     *
     * @var array<string, string>
     */
    private const COVERAGE = [
        'S01' => 'NIS, NHT, Education Tax, PAYE',
        'S02' => "reconciles the month's remittance",
        'P24' => 'covers all guards deployed that year',
    ];

    public function __construct(private readonly StatutoryRates $rates) {}

    /**
     * The register, in the order the board reads it.
     *
     * Outstanding first, because a return that is owed is the only thing on
     * this screen anyone has to act on. Then what has been filed, newest
     * obligation first. Then what is not owed yet, which is the annual return
     * sitting quietly at the bottom until the year closes.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(?int $year = null): array
    {
        $today = Carbon::today();

        $query = StatutoryFiling::query();

        if ($year !== null) {
            $query->whereYear('period_start', $year);
        }

        $filings = $query->get();

        $rows = $filings->map(fn (StatutoryFiling $filing): array => $this->row($filing, $today))->all();

        usort($rows, function (array $a, array $b): int {
            /** @var int $bucketA */
            $bucketA = $a['sort_bucket'];
            /** @var int $bucketB */
            $bucketB = $b['sort_bucket'];

            if ($bucketA !== $bucketB) {
                return $bucketA <=> $bucketB;
            }

            /** @var string $dueA */
            $dueA = $a['sort_due'];
            /** @var string $dueB */
            $dueB = $b['sort_due'];

            if ($dueA !== $dueB) {
                return strcmp($dueA, $dueB);
            }

            /** @var string $codeA */
            $codeA = $a['sort_code'];
            /** @var string $codeB */
            $codeB = $b['sort_code'];

            return strcmp($codeA, $codeB);
        });

        return $rows;
    }

    /**
     * Why a new return cannot be prepared, or null when one can.
     *
     * Two separate conditions, and the reader is told which one applies rather
     * than being given a generic refusal. Approval blocked by D-021 is a
     * decision somebody made; having no closed period left to file is simply
     * being up to date, and reads very differently.
     */
    public function blockedReason(): ?string
    {
        $version = $this->rates->current();

        if ($version === null) {
            return 'No statutory rate version is recorded, so nothing can be calculated to file.';
        }

        $blocked = $this->rates->approvalBlockedReason($version);

        if ($blocked !== null) {
            return 'A return is prepared from an approved pay run, and no run can be approved yet. '.$blocked;
        }

        if (! $this->hasApprovedUnfiledRun()) {
            return 'Every approved pay run has already been filed. The next return can be prepared once the '
                .'current period closes and its run is approved.';
        }

        return null;
    }

    /**
     * The years the register holds anything for, newest first.
     *
     * Used by the empty-because-filtered state so it can say which years DO
     * have returns, rather than leaving the reader to guess at the URL.
     *
     * @return list<int>
     */
    public function years(): array
    {
        return StatutoryFiling::query()
            ->orderByDesc('period_start')
            ->pluck('period_start')
            ->map(fn (mixed $date): int => Carbon::parse((string) $date)->year)
            ->unique()
            ->values()
            ->all();
    }

    /* ------------------------------------------------------------------ */

    /**
     * One row, as the board draws it: icon, two lines of text, a date and a
     * badge. Nothing is clickable, because there is nothing a reader may do to
     * a posted record.
     *
     * @return array<string, mixed>
     */
    private function row(StatutoryFiling $filing, Carbon $today): array
    {
        $state = $this->stateOf($filing, $today);

        return [
            'id' => $filing->id,
            'icon' => $state['icon'],
            'warn' => $state['warn'],
            'title' => $filing->form_code.' — '.$filing->form_title,
            'detail' => $this->detail($filing),
            'when' => $this->when($filing, $today),
            'badge_class' => $state['badge_class'],
            'badge_label' => $state['badge_label'],
            'note' => $state['note'],

            // Sorting keys, stripped by the controller before the row is sent.
            'sort_bucket' => $state['bucket'],
            'sort_due' => $filing->due_on->toDateString(),
            'sort_code' => $filing->form_code,
        ];
    }

    /**
     * What state a return is in, which is entirely a question about dates.
     *
     * @return array{bucket: int, icon: string, warn: bool, badge_class: string, badge_label: string, note: string|null}
     */
    private function stateOf(StatutoryFiling $filing, Carbon $today): array
    {
        if ($filing->isFiled()) {
            return [
                'bucket' => 1,
                'icon' => 'document',
                'warn' => false,
                'badge_class' => 'approved',
                'badge_label' => 'Filed',
                'note' => $filing->confirmation_reference === null
                    ? 'Filed. This return is a posted record and cannot be edited or deleted.'
                    : sprintf(
                        'Filed under %s. This return is a posted record and cannot be edited or deleted.',
                        $filing->confirmation_reference,
                    ),
            ];
        }

        if ($filing->isOverdue($today)) {
            return [
                'bucket' => 0,
                'icon' => 'alert',
                'warn' => true,
                'badge_class' => 'exception',
                'badge_label' => 'Overdue',
                'note' => sprintf('This return was due on %s and has not been filed.', $filing->due_on->format('j M Y')),
            ];
        }

        if ($filing->periodHasClosed($today)) {
            return [
                'bucket' => 0,
                'icon' => 'alert',
                'warn' => true,
                'badge_class' => 'pending',
                'badge_label' => 'Due soon',
                'note' => sprintf(
                    'The period closed on %s. This return is due by %s.',
                    $filing->period_end->format('j M Y'),
                    $filing->due_on->format('j M Y'),
                ),
            ];
        }

        return [
            'bucket' => 2,
            'icon' => 'calendar',
            'warn' => false,
            'badge_class' => 'draft',
            'badge_label' => 'Not started',
            'note' => sprintf(
                'The period runs to %s. Nothing is owed until it closes.',
                $filing->period_end->format('j M Y'),
            ),
        ];
    }

    /**
     * The row's second line.
     *
     * A filed return needs only its period: it is settled, and what it covered
     * is on the return itself. An outstanding one says what it will cover and,
     * where the figures are not available yet, why.
     */
    private function detail(StatutoryFiling $filing): string
    {
        if ($filing->isFiled()) {
            return $filing->period_label;
        }

        $parts = [$filing->period_label];

        $coverage = self::COVERAGE[$filing->form_code] ?? null;

        if ($coverage !== null) {
            $parts[] = $coverage;
        }

        if ($filing->payroll_run_id === null && $filing->periodHasClosed(Carbon::today())) {
            $parts[] = 'awaiting an approved pay run';
        } elseif ($filing->employees_covered !== null) {
            $parts[] = $filing->employees_covered.' employees';
        }

        return implode(' · ', $parts);
    }

    /** "Due Sep 14", "Filed Aug 11", "Due Mar 31, 2027". */
    private function when(StatutoryFiling $filing, Carbon $today): string
    {
        if ($filing->filed_on !== null) {
            return 'Filed '.$this->shortDate($filing->filed_on, $today);
        }

        return 'Due '.$this->shortDate($filing->due_on, $today);
    }

    /**
     * A date without its year, unless the year is not this one.
     *
     * "Due Mar 31, 2027" has to carry the year or it reads as three weeks away
     * rather than eighteen months. "Due Sep 14" does not, and spelling it out
     * would be noise on every row.
     */
    private function shortDate(Carbon $date, Carbon $today): string
    {
        return $date->year === $today->year ? $date->format('M j') : $date->format('M j, Y');
    }

    /** Whether any approved run exists that no return has been filed against. */
    private function hasApprovedUnfiledRun(): bool
    {
        return PayrollRun::query()
            ->whereIn('status', ['approved', 'paid'])
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('statutory_filings')
                    ->whereColumn('statutory_filings.payroll_run_id', 'payroll_runs.id');
            })
            ->exists();
    }
}
