<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\DunningNotice;
use App\Models\Estate\DunningTemplate;
use App\Models\Estate\DunningTemplateDraft;
use App\Models\Estate\PaymentPlan;
use App\Models\Estate\PaymentPlanInstalment;
use App\Models\Estate\Unit;
use App\Models\Estate\UnitCollectionFlag;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Payment plans and dunning — boards 7 and 8.
 *
 * TWO THINGS AN ESTATE DOES WITH A DEBT IT CANNOT SIMPLY DEMAND: agree a way to
 * clear it, or chase it. They sit in one class because they are the same
 * decision seen from two ends, and because the first switches the second off.
 *
 * NOTHING HERE POSTS A JOURNAL ENTRY, and that is not an omission. The
 * receivable was recognised when the charges were raised; scheduling a debt and
 * writing to a household about it change nothing about what is owed. The money
 * moves when an instalment is actually paid, and it moves through
 * `Dues::receive` like any other receipt. A payment plan that posted an entry
 * would restate the arrears on every board without anyone paying anything.
 *
 * WHAT IT DOES CHANGE IS A GATE. While an agreed plan is active and being met,
 * `isProtected()` returns true and `RestrictionPolicy` admits guest passes for a
 * household whose `access_restricted` flag is still set. Miss an instalment and
 * the plan defaults, the shield goes, and the flag underneath it is exactly
 * where it was. That is why the flag is never cleared on activation: the arrears
 * did not go away, they were given a schedule, and a module that erased the fact
 * would have nothing to reinstate.
 *
 * AND A NOTICE IS FROZEN AT SEND. `send()` renders the merge fields against the
 * unit NOW and writes the finished subject and body onto the notice row. Nothing
 * stores a reference and re-renders later, because the log exists to settle a
 * dispute about what a resident was actually told.
 */
class Collections
{
    /** Board 7's default instalment count. */
    public const DEFAULT_INSTALMENTS = 4;

    /**
     * Which day of the month a plan is offered to start on.
     *
     * Board 7 defaults to the 28th. Dues fall on the first, so the 28th gives a
     * household the whole month to find the instalment and keeps the plan's
     * payment from arriving in the same week as the bill it is clearing.
     */
    public const DEFAULT_START_DAY = 28;

    /** A plan longer than this is a write-off with extra steps. */
    private const MAX_INSTALMENTS = 36;

    public function __construct(private readonly Dues $dues) {}

    /* ------------------------------------------------------------------ */
    /* payment plans — board 7 */
    /* ------------------------------------------------------------------ */

    /**
     * The schedule board 7 draws before anything is agreed. WRITES NOTHING.
     *
     * The total comes from the unit's CURRENT LEDGER BALANCE and from nowhere
     * else — a plan schedules the debt the accounts say exists, not a figure
     * typed into a modal. Once agreed it is snapshotted onto the plan, because
     * from that moment it is what the household consented to rather than what
     * they happen to owe today.
     *
     * @return array{
     *     total_minor: int,
     *     currency: string,
     *     instalments: int,
     *     frequency: string,
     *     starts_on: string,
     *     starts_on_label: string,
     *     instalment_minor: int,
     *     first_instalment_minor: int,
     *     is_even: bool,
     *     rows: list<array{sequence: int, amount_minor: int, due_on: string, due_label: string}>
     * }
     *
     * @throws DomainException when the unit owes nothing to schedule
     */
    public function schedule(Unit $unit, int $instalments = self::DEFAULT_INSTALMENTS, Carbon|string|null $startsOn = null): array
    {
        $total = $this->dues->balanceOf($unit)->getMinorAmount()->toInt();

        if ($total <= 0) {
            throw new DomainException(
                'There is nothing to schedule: '.$unit->reference.' owes nothing on the ledger. A payment '.
                'plan clears an existing balance and cannot be drawn against a debt that has not been billed.'
            );
        }

        if ($instalments < 1 || $instalments > self::MAX_INSTALMENTS) {
            throw new DomainException(
                'A plan runs between 1 and '.self::MAX_INSTALMENTS.' instalments. Anything longer is a '.
                'write-off the committee has not voted on, dressed as a schedule.'
            );
        }

        $start = $this->asDate($startsOn ?? $this->defaultStart());

        $base = intdiv($total, $instalments);
        $remainder = $total - ($base * $instalments);

        $rows = [];

        for ($i = 0; $i < $instalments; $i++) {
            /*
             * THE ROUNDING REMAINDER GOES ON THE FIRST INSTALMENT, NOT THE LAST.
             *
             * Two reasons, and the second is the one that decides it. The last
             * instalment is the figure a resident checks against the agreement
             * on the day they finish paying, and an odd amount there reads as a
             * fee added at the end — which is the argument a payment plan exists
             * to stop having. And a plan is a shield over a gate restriction
             * that only lasts as long as the household keeps to it: putting the
             * odd cents at the front means that at every point in the schedule
             * the household has paid at least its pro-rata share, so a plan
             * abandoned half way leaves the estate no worse off than the
             * arithmetic promised.
             */
            $amount = $base + ($i === 0 ? $remainder : 0);

            $due = $start->copy()->addMonthsNoOverflow($i);

            $rows[] = [
                'sequence' => $i + 1,
                'amount_minor' => $amount,
                'due_on' => $due->toDateString(),
                'due_label' => $due->format('M j, Y'),
            ];
        }

        return [
            'total_minor' => $total,
            'currency' => 'JMD',
            'instalments' => $instalments,
            'frequency' => PaymentPlan::FREQUENCY,
            'starts_on' => $start->toDateString(),
            'starts_on_label' => $start->format('M j, Y'),

            /*
             * The repeating figure board 7 prints as "$3,100.00 per instalment",
             * and the first one beside it. They are the same number whenever the
             * division is clean, which is the case the board drew; where it is
             * not, the screen has both and can say so rather than printing an
             * average nobody will ever be asked to pay.
             */
            'instalment_minor' => $base,
            'first_instalment_minor' => $base + $remainder,
            'is_even' => $remainder === 0,
            'rows' => $rows,
        ];
    }

    /**
     * Write the plan and its instalments as a DRAFT.
     *
     * Draft, never active: the household has not agreed to it yet, and a plan
     * that lifted a gate restriction the moment a treasurer pressed a button
     * would be the estate deciding on their behalf.
     *
     * NO ACTOR IS TAKEN. `payment_plans` records who APPROVED a plan, and
     * drawing one up is not approving it — there is nowhere for a drafter to
     * go, and a parameter with nowhere to go reads as an attribution the row
     * does not carry.
     */
    public function draft(
        Unit $unit,
        int $instalments = self::DEFAULT_INSTALMENTS,
        Carbon|string|null $startsOn = null,
        ?string $terms = null,
    ): PaymentPlan {
        $preview = $this->schedule($unit, $instalments, $startsOn);

        return DB::connection('tenant')->transaction(function () use ($unit, $preview, $terms): PaymentPlan {
            $plan = PaymentPlan::create([
                'unit_id' => $unit->id,
                'reference' => $this->nextReference(),
                'total_minor' => $preview['total_minor'],
                'currency' => $preview['currency'],
                'instalments' => $preview['instalments'],
                'starts_on' => $preview['starts_on'],
                'status' => PaymentPlan::DRAFT,
                'terms' => $terms,
            ]);

            foreach ($preview['rows'] as $row) {
                $plan->schedule()->create([
                    'sequence' => $row['sequence'],
                    'amount_minor' => $row['amount_minor'],
                    'currency' => $preview['currency'],
                    'due_on' => $row['due_on'],
                    'status' => PaymentPlanInstalment::DUE,
                ]);
            }

            return $plan->fresh(['schedule']) ?? $plan;
        });
    }

    /**
     * Record that the household agreed, and who said so.
     *
     * A FACT WITH A TIME ON IT, which is what the migration reserved the two
     * columns for. It is the only thing that makes the next step lawful.
     */
    public function agree(PaymentPlan $plan, string $agreedByName): PaymentPlan
    {
        $name = trim($agreedByName);

        if ($name === '') {
            throw new DomainException(
                'Record who agreed to this plan. An agreement with no name on it cannot be shown to the '.
                'household it is supposed to bind.'
            );
        }

        if ($plan->status !== PaymentPlan::DRAFT && $plan->status !== PaymentPlan::ACTIVE) {
            throw new DomainException(
                'Plan '.$plan->reference.' is '.$plan->status.' and cannot take a new agreement. A plan that '.
                'has run its course or been defaulted is history; agreeing terms again means a new plan.'
            );
        }

        $plan->forceFill([
            'agreed_at' => now(),
            'agreed_by_name' => $name,
        ])->save();

        return $plan;
    }

    /**
     * Approve an agreed plan, and let it lift the arrears restriction.
     *
     * THIS IS THE DECISION, WHICH IS WHY THE ROUTE NEEDS `approve` AND NOT
     * `create`. Everything before it is paperwork; this is the moment a
     * household in arrears can have guests through the gate again.
     *
     * `$agreedByName` is required even though `agree()` already recorded one,
     * and it must match. The approver is asserting whose agreement they are
     * acting on at the instant the restriction comes off — activating "Andrea
     * Fletcher's agreement" against a plan that records somebody else's is
     * approving something other than what is on the screen, and it is refused
     * rather than quietly overwritten.
     *
     * @throws DomainException when the household has not agreed, or agreed to something else
     */
    public function activate(PaymentPlan $plan, string $agreedByName, User $by): PaymentPlan
    {
        if (! $plan->isAgreed()) {
            throw new DomainException(
                'Plan '.$plan->reference.' cannot be activated: the household has not agreed to it. An '.
                'unagreed plan is a demand, and activating one would lift a gate restriction on terms '.
                'nobody in the household ever accepted.'
            );
        }

        $claimed = trim($agreedByName);

        if (mb_strtolower($claimed) !== mb_strtolower((string) $plan->agreed_by_name)) {
            throw new DomainException(
                'Plan '.$plan->reference.' records an agreement by '.$plan->agreed_by_name.', not '.
                $claimed.'. Activate the agreement that exists or record a new one; do not overwrite '.
                'whose word the estate is relying on.'
            );
        }

        if ($plan->status !== PaymentPlan::DRAFT && $plan->status !== PaymentPlan::ACTIVE) {
            throw new DomainException(
                'Plan '.$plan->reference.' is '.$plan->status.' and cannot be activated. A finished or '.
                'defaulted plan is a record of what happened; a household getting a second chance gets a '.
                'second plan, so that the first one still says what went wrong.'
            );
        }

        $plan->forceFill([
            'status' => PaymentPlan::ACTIVE,
            'approved_by' => $by->getKey(),
            'approved_by_name' => $by->name,
        ])->save();

        return $plan;
    }

    /**
     * Accept an instalment as met.
     *
     * A DECISION, NOT AN OBSERVATION. A treasurer may accept a short payment as
     * meeting an instalment, and no rule over the payments table could express
     * that without being asked — see the instalment model.
     *
     * IT POSTS NOTHING, and must not. The money is recorded where every other
     * receipt is, through `Dues::receive`, which raises the entry. If this
     * posted the instalment's face value, a short payment accepted as meeting
     * an instalment would credit the unit with money it never sent.
     *
     * NO ACTOR IS TAKEN, and the schema is why: `payment_plan_instalments`
     * records `settled_at` and nothing else. A `User` parameter with nowhere to
     * go would read as an attribution the row does not carry, and the first
     * person to ask who accepted a short payment would be promised an answer by
     * the signature and refused one by the table.
     */
    public function recordInstalmentMet(PaymentPlanInstalment $instalment): PaymentPlanInstalment
    {
        $plan = $instalment->plan;

        if ($instalment->status === PaymentPlanInstalment::MET) {
            return $instalment;
        }

        DB::connection('tenant')->transaction(function () use ($instalment, $plan): void {
            $instalment->forceFill([
                'status' => PaymentPlanInstalment::MET,
                'settled_at' => now(),
            ])->save();

            $outstanding = $plan->schedule()
                ->where('status', '!=', PaymentPlanInstalment::MET)
                ->count();

            if ($outstanding > 0) {
                return;
            }

            $plan->forceFill(['status' => PaymentPlan::COMPLETED])->save();

            /*
             * THE LAST INSTALMENT ENDS THE RESTRICTION, not just the shield.
             *
             * `isProtected()` stops being true the moment a plan completes, so
             * without this a household would finish paying and be restricted
             * again by the flag the plan had been standing over — which is the
             * exact opposite of what completing a plan means.
             *
             * Conditional on the LEDGER, not on the plan: a household that met
             * every instalment while falling behind on the months that accrued
             * during the plan is in fresh arrears, and fresh arrears get their
             * own eligibility and their own notice period rather than being
             * cleared by an unrelated agreement finishing.
             */
            if ($this->dues->balanceOf($plan->unit)->getMinorAmount()->toInt() <= 0) {
                $plan->unit->household?->forceFill(['access_restricted' => false])->save();
            }
        });

        return $instalment;
    }

    /**
     * Rule an instalment missed, which defaults the plan.
     *
     * AND THIS IS WHAT PUTS THE RESTRICTION BACK. It is a ruling somebody makes,
     * never an elapsed timer: a bank holding a transfer over a weekend must not
     * be able to stop a household's visitors at a gate, for the same reason
     * restriction never follows a declined card (D-024).
     *
     * The household's `access_restricted` flag is not touched — it was never
     * cleared. Defaulting removes the shield and what was underneath it is
     * exactly what was there before.
     */
    public function markMissed(PaymentPlanInstalment $instalment): PaymentPlanInstalment
    {
        DB::connection('tenant')->transaction(function () use ($instalment): void {
            $instalment->forceFill(['status' => PaymentPlanInstalment::MISSED])->save();

            $instalment->plan->forceFill(['status' => PaymentPlan::DEFAULTED])->save();
        });

        return $instalment;
    }

    /* ------------------------------------------------------------------ */
    /* what the gate asks */
    /* ------------------------------------------------------------------ */

    /**
     * Does an active, non-defaulted plan currently shield this unit?
     *
     * THE ONE METHOD `RestrictionPolicy` CONSULTS, and the whole reason a plan
     * is a row rather than a note. It is deliberately a question about the unit
     * and not about the household: the unit is what owes the dues, households
     * come and go, and a plan agreed by the family living there in March is
     * still a plan over that property's debt in September.
     */
    public function isProtected(Unit $unit): bool
    {
        return $this->activePlanFor($unit) !== null;
    }

    /**
     * The plan currently shielding this unit, if any.
     *
     * TWO INDEPENDENT CONDITIONS, and the redundancy is deliberate. `status`
     * says the plan is live; the absence of a missed instalment says the
     * household is keeping to it. `markMissed` sets both, so in a healthy
     * system the second never bites — but the one it exists for is the day a
     * status is written by hand, or by a future path nobody has read this file
     * before adding, and a gate restriction is not something to leave resting
     * on a single mutable string.
     */
    public function activePlanFor(Unit $unit): ?PaymentPlan
    {
        return PaymentPlan::query()
            ->where('unit_id', $unit->id)
            ->where('status', PaymentPlan::ACTIVE)
            ->whereNotNull('agreed_at')
            ->whereDoesntHave('schedule', fn ($query) => $query->where('status', PaymentPlanInstalment::MISSED))
            ->orderByDesc('id')
            ->first();
    }

    /* ------------------------------------------------------------------ */
    /* dunning — board 8 */
    /* ------------------------------------------------------------------ */

    /**
     * Send one notice, and log it AS SENT.
     *
     * The merge fields are resolved against the unit at this instant and the
     * finished text is written onto the notice. The template id is kept as a
     * trail back to the wording that was in force, and nothing ever reads the
     * body through it — that is the entire guarantee this module carries.
     *
     * DELIVERY STARTS AT `queued`, not `sent`. Nothing in this application has
     * yet handed anything to a push, mail or SMS provider, and a log that
     * claimed delivery on the strength of a database insert would be the first
     * thing a dispute disproved.
     */
    public function send(Unit $unit, DunningTemplate $template, ?User $by = null): DunningNotice
    {
        $rendered = $this->render($template, $unit);

        return DunningNotice::create([
            'unit_id' => $unit->id,
            'dunning_template_id' => $template->id,

            // Copied, not joined. Asked in a year which step went out today,
            // this row has to answer on its own.
            'template_label' => $template->label,
            'stage' => $template->stage,
            'channel' => $template->channel,

            'subject' => $rendered['subject'],
            'body' => $rendered['body'],

            'delivery_state' => DunningNotice::QUEUED,
            'sent_at' => now(),
            'sent_by' => $by?->getKey(),
            'sent_by_name' => $by?->name,
        ]);
    }

    /**
     * The template's merge fields, resolved against this unit right now.
     *
     * @return array{subject: string, body: string}
     */
    public function render(DunningTemplate $template, Unit $unit): array
    {
        $merge = $this->mergeFields($unit);

        return [
            'subject' => strtr($template->subject, $merge),
            'body' => strtr($template->body, $merge),
        ];
    }

    /**
     * What each of board 8's five chips resolves to for one unit.
     *
     * AN UNKNOWN TOKEN IS LEFT STANDING. Blanking it would put a hole in a
     * sentence that nobody could trace back to a template, and a resident
     * reading "your fee of  is overdue" cannot tell whether the estate means
     * nothing or means everything. Left visible, the mistake is legible in the
     * log and fixable in the template.
     *
     * @return array<string, string>
     */
    public function mergeFields(Unit $unit): array
    {
        $balance = $this->dues->balanceOf($unit)->getMinorAmount()->toInt();
        $oldest = $this->dues->oldestOpenChargeDate($unit);

        return [
            '{resident_first_name}' => $this->residentFirstName($unit),

            // Board 8 prints the lot without its phase prefix.
            '{unit_label}' => (string) $unit->reference,

            '{amount_due}' => $this->formatMoney($balance),
            '{due_date}' => $oldest?->format('M j, Y') ?? '—',
            '{days_overdue}' => (string) ($oldest === null ? 0 : (int) $oldest->diffInDays(Carbon::today(), absolute: false)),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* what the screens read */
    /* ------------------------------------------------------------------ */

    /**
     * Everything board 7 draws for one unit.
     *
     * @return array<string, mixed>
     */
    public function planBoard(Unit $unit): array
    {
        $balance = $this->dues->balanceOf($unit)->getMinorAmount()->toInt();
        $plan = $this->latestPlanFor($unit);

        return [
            'unit' => $this->dues->unitHeader($unit),
            'balance_minor' => $balance,

            // Board 7's callout names the resident — "Andrea will see this plan
            // and its due dates in her Dues statement" — so the screen needs the
            // first name on its own, not the full name it already has.
            'resident_first_name' => $this->residentFirstName($unit),

            /*
             * Null rather than an empty schedule when there is nothing owing.
             * `schedule()` refuses a non-positive balance, and a modal offering
             * to spread a debt of nothing over four months is a screen that
             * should not have opened.
             */
            'preview' => $balance > 0 ? $this->schedule($unit) : null,

            'plan' => $plan === null ? null : $this->planRow($plan),
            'protected' => $this->isProtected($unit),

            // One entry, because there is one. See PaymentPlan::FREQUENCY.
            'frequencies' => [PaymentPlan::FREQUENCY],
            'maxInstalments' => self::MAX_INSTALMENTS,
        ];
    }

    /**
     * Everything board 8 draws: the recent sends and the template editor.
     *
     * @return array<string, mixed>
     */
    public function dunningBoard(int $limit = 5): array
    {
        /*
         * Balances read from the LEDGER at draw time, in one query for the
         * whole log. No notice stores an amount — see the model — so the
         * Balance column cannot drift from the arrears board beside it.
         */
        $balances = $this->dues->unitBalances();

        /*
         * Newest batch first, and within a batch the order the notices went
         * out. Board 8 draws its five rows in an order that is neither — a send
         * from three weeks ago sits below yesterday's, but one of today's sits
         * below it — and there is no column for a hand-set position. A delivery
         * log that is not chronological cannot be read at all, so it is
         * chronological, and the drawn sequence is a mock's arrangement rather
         * than a rule.
         */
        $notices = DunningNotice::query()
            ->with('unit')
            ->orderByDesc('sent_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $sends = [];

        foreach ($notices as $notice) {
            $sends[] = [
                'id' => $notice->id,
                'unit' => (string) $notice->unit->reference,
                'unit_id' => $notice->unit_id,
                'step' => $notice->template_label,
                'channel' => $notice->channelLabel(),
                'sent' => $this->sentLabel($notice->sent_at),
                'balance_minor' => $balances[$notice->unit_id] ?? 0,
                'status' => $notice->statusLabel(),
                'tone' => $notice->statusTone(),
            ];
        }

        $templates = DunningTemplate::query()
            ->where('is_active', true)
            ->orderBy('stage')
            ->get()
            ->map(static fn (DunningTemplate $template): array => [
                'id' => $template->id,
                'key' => $template->key,
                'label' => $template->label,
                'stage' => $template->stage,
                'channel' => $template->channel,
                'channel_label' => DunningTemplate::channelLabel($template->channel),
                'subject' => $template->subject,
                'body' => $template->body,
                'days_overdue' => $template->days_overdue,
            ])
            ->all();

        return [
            'sends' => $sends,
            'templates' => $templates,
            'mergeFields' => DunningTemplate::MERGE_FIELDS,
            'channels' => DunningTemplate::CHANNEL_LABELS,
            'drafts' => $this->draftBoard(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* hardship and dispute (12 §1) */
    /* ------------------------------------------------------------------ */

    /** The flag in force on a unit, or null. */
    public function flagFor(Unit $unit): ?UnitCollectionFlag
    {
        return UnitCollectionFlag::query()
            ->where('unit_id', $unit->id)
            ->whereNull('lifted_at')
            ->latest('raised_at')
            ->first();
    }

    /**
     * Flag a household. THE DEBT DOES NOT MOVE.
     *
     * No journal is raised, no charge reversed, no ageing bucket changed. The
     * arrears board goes on reporting this household among those who owe,
     * because it does. What stops is the estate's own automated chasing — see
     * `automatedQueue()`, which is the only thing that reads this.
     */
    public function flag(Unit $unit, string $kind, string $reason, string $minute, User $by): UnitCollectionFlag
    {
        if (! array_key_exists($kind, UnitCollectionFlag::KINDS)) {
            throw new DomainException('A flag is a hardship or a dispute. They end differently, so the estate has to say which this is.');
        }

        $reason = trim($reason);
        $minute = trim($minute);

        if ($reason === '') {
            throw new DomainException('Say why, in the estate\'s own words. Next year\'s committee has to be able to read why this household stopped being chased.');
        }

        if ($minute === '') {
            throw new DomainException('A flag needs the committee minute that agreed it. Without one it is an office decision wearing a committee\'s clothes.');
        }

        if ($this->flagFor($unit) !== null) {
            throw new DomainException($unit->reference.' is already flagged. Lift the flag in force before raising another, so the record reads as one episode after another rather than two at once.');
        }

        return UnitCollectionFlag::create([
            'unit_id' => $unit->id,
            'kind' => $kind,
            'reason' => $reason,
            'minute_reference' => $minute,
            'raised_by_id' => $by->getKey(),
            'raised_by_name' => (string) $by->name,
            'raised_at' => Carbon::now(),
        ]);
    }

    /** Lift a flag. The row stays; nothing here is deleted. */
    public function liftFlag(UnitCollectionFlag $flag, string $reason, User $by): UnitCollectionFlag
    {
        if (! $flag->isInForce()) {
            throw new DomainException('That flag has already been lifted.');
        }

        $flag->forceFill([
            'lifted_at' => Carbon::now(),
            'lifted_by_name' => (string) $by->name,
            'lifted_reason' => trim($reason) === '' ? null : trim($reason),
        ])->save();

        return $flag;
    }

    /**
     * The units an AUTOMATED dunning run would chase, and the ones it skips.
     *
     * THIS IS WHERE THE SUPPRESSION LIVES, and it is deliberately not inside
     * `send()`: a person pressing "Send reminder now" on a flagged household is
     * making a decision with their name against it, and the estate is allowed
     * to make it. What a flag stops is the machine sending a final demand at
     * 09:00 on Tuesday to a household that is in front of the committee.
     *
     * @return array{due: list<int>, suppressed: list<array{unit_id: int, reference: string, headline: string}>}
     */
    public function automatedQueue(): array
    {
        $owing = array_keys(array_filter($this->dues->unitBalances(), static fn (int $minor): bool => $minor > 0));

        $flags = UnitCollectionFlag::query()
            ->with('unit')
            ->whereNull('lifted_at')
            ->whereIn('unit_id', $owing)
            ->get();

        $suppressedIds = $flags->pluck('unit_id')->all();

        return [
            'due' => array_values(array_diff($owing, $suppressedIds)),
            'suppressed' => $flags->map(static fn (UnitCollectionFlag $flag): array => [
                'unit_id' => $flag->unit_id,
                'reference' => (string) $flag->unit->reference,
                'headline' => $flag->headline(),
            ])->all(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* the template editor (12 §1) */
    /* ------------------------------------------------------------------ */

    /**
     * The drafts waiting on the committee, oldest first.
     *
     * ADOPTED DRAFTS ARE NOT HERE. Once a draft is in force, what it says is
     * readable from the ladder itself, and leaving it on a "waiting" list would
     * make a decided thing look undecided. Its resolution reference survives on
     * the row, which is where an audit of why the wording changed reads it.
     *
     * @return list<array<string, mixed>>
     */
    public function draftBoard(): array
    {
        return DunningTemplateDraft::query()
            ->whereNull('adopted_at')
            ->orderBy('stage')
            ->orderBy('id')
            ->get()
            ->map(static fn (DunningTemplateDraft $draft): array => [
                'id' => $draft->id,
                'template_id' => $draft->dunning_template_id,
                'target' => $draft->targetLabel(),
                'label' => $draft->label,
                'stage' => $draft->stage,
                'channel' => $draft->channel,
                'channel_label' => DunningTemplate::channelLabel($draft->channel),
                'subject' => $draft->subject,
                'body' => $draft->body,
                'days_overdue' => $draft->days_overdue,
                'resolution_reference' => $draft->resolution_reference,
                'drafted_by' => $draft->drafted_by_name,
                'drafted_at' => $draft->created_at?->format('M j, Y'),
            ])
            ->all();
    }

    /**
     * Save a proposed step. NOTHING THE ESTATE SENDS CHANGES.
     *
     * This is where the ruling lands: a new or edited stage is a draft, full
     * stop. A treasurer can reword a final demand at four in the afternoon and
     * the four-o'clock collections run still sends what the committee agreed.
     *
     * The merge tokens are checked HERE, against the closed catalogue, because
     * this is the last moment a mistake is cheap. A token nobody can resolve is
     * left standing when the notice renders, and it arrives on a resident's
     * phone as its own literal text.
     *
     * @param  array<string, mixed>  $fields
     */
    public function saveDraft(array $fields, User $by, ?DunningTemplate $against = null): DunningTemplateDraft
    {
        $label = trim((string) ($fields['label'] ?? ''));
        $subject = trim((string) ($fields['subject'] ?? ''));
        $body = (string) ($fields['body'] ?? '');
        $stage = (int) ($fields['stage'] ?? 0);
        $days = (int) ($fields['days_overdue'] ?? 0);
        $channel = (string) ($fields['channel'] ?? 'email');

        if ($label === '' || $subject === '' || trim($body) === '') {
            throw new DomainException('A step has a name, a subject line and a body. A notice with any of them missing is one a resident cannot act on.');
        }

        if (! array_key_exists($channel, DunningTemplate::CHANNEL_LABELS)) {
            throw new DomainException('Choose one of the channel combinations this estate can actually send on.');
        }

        /*
         * ZERO IS A REAL STAGE and not an empty one: the estate's pre-due
         * courtesy — "Day 20" — fires BEFORE anything is overdue, which is why
         * it carries stage 0 and `days_overdue` 0. Refusing it here would make
         * the one step every estate on this platform already has uneditable.
         */
        if ($stage < 0 || $stage > 20) {
            throw new DomainException('A stage is a rung on the ladder: 0 for a courtesy note before anything is due, then 1 upward.');
        }

        if ($days < 0 || $days > 365) {
            throw new DomainException('A step fires between the due date and a year past it.');
        }

        $unknown = [];

        if (preg_match_all('/\{[a-z0-9_]+\}/i', $body.' '.$subject, $found) === false) {
            $found = [[]];
        }

        foreach (array_unique($found[0]) as $token) {
            if (! in_array(strtolower($token), DunningTemplate::MERGE_FIELDS, true)) {
                $unknown[] = $token;
            }
        }

        if ($unknown !== []) {
            throw new DomainException(sprintf(
                '%s cannot be resolved against a household, so it would arrive on a resident\'s phone as its own literal text. The fields this estate can fill are %s.',
                implode(', ', $unknown),
                implode(', ', DunningTemplate::MERGE_FIELDS),
            ));
        }

        /*
         * ONE OPEN DRAFT PER STEP. A second proposal against the same rung is
         * the first one rewritten — two of them would put the committee in
         * front of a choice nobody meant to offer them.
         */
        $draft = DunningTemplateDraft::query()
            ->whereNull('adopted_at')
            ->when(
                $against !== null,
                static fn ($query) => $query->where('dunning_template_id', $against?->id),
                static fn ($query) => $query->whereNull('dunning_template_id')->where('stage', $stage),
            )
            ->first() ?? new DunningTemplateDraft;

        $draft->fill([
            'dunning_template_id' => $against?->id,
            'label' => $label,
            'stage' => $stage,
            'channel' => $channel,
            'subject' => $subject,
            'body' => $body,
            'days_overdue' => $days,

            // Cleared on every save: a reference belongs to the wording the
            // committee actually saw, and a reworded draft is not that wording.
            'resolution_reference' => null,
            'drafted_by_id' => $by->getKey(),
            'drafted_by_name' => (string) $by->name,
        ])->save();

        return $draft;
    }

    /**
     * Put a draft in force, against a committee resolution.
     *
     * THE REFERENCE IS THE WHOLE GATE. What a household is told about its debt
     * is a decision the committee makes, not the office, and the reference is
     * how the notice that goes out next week ties back to the minute that
     * agreed it.
     *
     * NO NOTICE ALREADY SENT MOVES. `dunning_notices` holds the subject and
     * body as sent and nothing here touches that table — a log that followed
     * the wording would show a resident a message they never received, which is
     * the one thing it exists to prevent.
     */
    public function adoptDraft(DunningTemplateDraft $draft, string $resolution, User $by): DunningTemplate
    {
        $resolution = trim($resolution);

        if ($resolution === '') {
            throw new DomainException('A wording change needs the committee resolution that agreed it — the minute or resolution number from the estate\'s own book. Without it, nobody can say later who decided what a household was told.');
        }

        if ($draft->isAdopted()) {
            throw new DomainException('That draft is already in force. Reword the step again to propose a further change.');
        }

        return DB::connection('tenant')->transaction(function () use ($draft, $resolution, $by): DunningTemplate {
            $template = $draft->dunning_template_id === null
                ? new DunningTemplate(['key' => $this->draftKey($draft)])
                : DunningTemplate::query()->findOrFail($draft->dunning_template_id);

            $template->fill([
                'label' => $draft->label,
                'stage' => $draft->stage,
                'channel' => $draft->channel,
                'subject' => $draft->subject,
                'body' => $draft->body,
                'days_overdue' => $draft->days_overdue,
                'is_active' => true,
            ])->save();

            $draft->forceFill([
                'dunning_template_id' => $template->id,
                'resolution_reference' => $resolution,
                'adopted_at' => Carbon::now(),
                'adopted_by_name' => (string) $by->name,
            ])->save();

            return $template;
        });
    }

    /** A stable key for a step the estate is inventing: stage-3, stage-3-2 if taken. */
    private function draftKey(DunningTemplateDraft $draft): string
    {
        $base = 'stage-'.$draft->stage;
        $key = $base;
        $n = 1;

        while (DunningTemplate::query()->where('key', $key)->exists()) {
            $n++;
            $key = $base.'-'.$n;
        }

        return $key;
    }

    /**
     * One unit's dunning history, newest first — the tab board 6 links from.
     *
     * @return list<array<string, mixed>>
     */
    public function noticesFor(Unit $unit, int $limit = 20): array
    {
        return DunningNotice::query()
            ->where('unit_id', $unit->id)
            ->orderByDesc('sent_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (DunningNotice $notice): array => [
                'id' => $notice->id,
                'step' => $notice->template_label,
                'channel' => $notice->channelLabel(),
                'sent' => $this->sentLabel($notice->sent_at),
                'status' => $notice->statusLabel(),
                'tone' => $notice->statusTone(),
                'subject' => $notice->subject,

                // The body AS SENT. This is the field a dispute is settled from.
                'body' => $notice->body,
            ])
            ->all();
    }

    /**
     * The latest plan on a unit, whatever state it is in.
     *
     * Board 7 has to show a defaulted plan as well as a live one — a household
     * asking to be put on a plan when they broke the last one is the case the
     * screen most needs to make visible.
     */
    public function latestPlanFor(Unit $unit): ?PaymentPlan
    {
        return PaymentPlan::query()
            ->with('schedule')
            ->where('unit_id', $unit->id)
            ->orderByDesc('id')
            ->first();
    }

    /* ------------------------------------------------------------------ */
    /* internals */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function planRow(PaymentPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'reference' => $plan->reference,
            'total_minor' => $plan->total_minor,
            'instalments' => $plan->instalments,
            'frequency' => PaymentPlan::FREQUENCY,
            'starts_on_label' => $plan->starts_on->format('M j, Y'),
            'status' => $plan->status,
            'agreed_by_name' => $plan->agreed_by_name,
            'agreed_on_label' => $plan->agreed_at?->format('M j, Y'),
            'approved_by_name' => $plan->approved_by_name,
            'terms' => $plan->terms,
            'rows' => $plan->schedule->map(static fn (PaymentPlanInstalment $i): array => [
                'id' => $i->id,
                'sequence' => $i->sequence,
                'amount_minor' => $i->amount_minor,
                'due_label' => $i->due_on->format('M j, Y'),
                'status' => $i->status,
                'settled_on_label' => $i->settled_at?->format('M j, Y'),
            ])->all(),
        ];
    }

    /**
     * The name a letter opens with.
     *
     * Falls back to the household and then to the unit rather than to an empty
     * greeting: nine of Phoenix Park's 450 units are vacant, they are billed
     * like every other unit, and "Hi ," is not a letter.
     */
    private function residentFirstName(Unit $unit): string
    {
        $resident = $unit->household?->residents()->where('is_primary', true)->first();

        $firstName = explode(' ', trim((string) ($resident->full_name ?? '')))[0];

        return $firstName !== ''
            ? $firstName
            : (string) ($unit->household->name ?? $unit->reference);
    }

    /** Board 8's Sent column: "Today, 9:00 AM", "Yesterday", "Sep 20, 9:00 AM". */
    private function sentLabel(Carbon $sentAt): string
    {
        return match (true) {
            $sentAt->isToday() => 'Today, '.$sentAt->format('g:i A'),
            $sentAt->isYesterday() => 'Yesterday',
            default => $sentAt->format('M j, g:i A'),
        };
    }

    /**
     * "$12,400.00", composed from the minor units by integer arithmetic.
     *
     * Never through a float. This string goes into a demand letter that is then
     * frozen on the notice, and a rounding artefact in it is a figure a resident
     * can hold the estate to.
     */
    private function formatMoney(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $minor = abs($minor);

        return $sign.'$'.number_format(intdiv($minor, 100)).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    /** The 28th of this month, or of next month once it has passed. */
    private function defaultStart(): Carbon
    {
        $candidate = Carbon::today()->startOfMonth()->addDays(self::DEFAULT_START_DAY - 1);

        return $candidate->isFuture() ? $candidate : $candidate->addMonthNoOverflow();
    }

    private function asDate(Carbon|string $date): Carbon
    {
        return $date instanceof Carbon ? $date->copy()->startOfDay() : Carbon::parse($date)->startOfDay();
    }

    /**
     * "PLAN-2026-09-0001" — sequential within the month, under a lock.
     *
     * The same shape as a charge or a receipt reference, because a resident
     * reads it back over the phone and a treasurer looks it up beside the
     * others.
     */
    private function nextReference(): string
    {
        $stem = 'PLAN-'.Carbon::today()->format('Y-m').'-';

        $last = PaymentPlan::query()
            ->where('reference', 'like', $stem.'%')
            ->lockForUpdate()
            ->orderByDesc('reference')
            ->value('reference');

        $next = $last === null ? 1 : ((int) substr((string) $last, strlen($stem))) + 1;

        return $stem.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
