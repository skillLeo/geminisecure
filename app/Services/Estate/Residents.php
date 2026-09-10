<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\AmenityBooking;
use App\Models\Estate\Household;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Phase;
use App\Models\Estate\Resident;
use App\Models\Estate\ResidentInvite;
use App\Models\Estate\Unit;
use App\Models\Estate\UnitClaim;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The estate's structure and the people in it — boards 3, 4, 31, 34 and 38.
 *
 * ONE FUNCTION DECIDES WHAT A SCREEN MAY SAY ABOUT A HOUSEHOLD'S STANDING, and
 * it is `standingOf()`. Boards 4 and 38 both draw a balance where a household's
 * standing goes and board 31 draws an ageing bucket, so there were three places
 * a figure could have escaped and there is now one. It is a whole-object method
 * for the same reason `Household::guardVisibleStanding()` is: a serialiser
 * concern spread across three payload builders is three places to audit and
 * three places to forget.
 *
 * IT REFUSES IN TWO DIRECTIONS AND THE ORDER MATTERS.
 *
 *   1. A RESTRICTED HOUSEHOLD GETS AMBER AND THE WORD. Never the balance, never
 *      the number of days, never an ageing bucket, never a payment history —
 *      nothing a guard or a neighbour standing at a desk could read off a
 *      screen. D-025 fixed the wording and it is the same wording here as at the
 *      gate, so a committee member and a guard cannot be told different things
 *      about the same household. This is checked FIRST, before the ledger is
 *      consulted at all, so no future edit to the balance arithmetic can reach a
 *      restricted household by accident.
 *
 *   2. A VIEWER WITHOUT `estate.dues_ledger.view` GETS NO FIGURE EITHER. The
 *      Property Manager is locked out of the ledger by platform invariant rather
 *      than estate preference (D-010) — "whoever commissions work must never be
 *      able to pay for it, nor see a resident's financial position" — and a
 *      resident detail screen that printed a balance would be a side door into
 *      exactly that. The caller passes the answer in; this class does not read
 *      the gate itself, because a service that consulted the container for a
 *      permission would be a second copy of the route matrix.
 *
 * EVERY BALANCE IS THE LEDGER READ BACK. Nothing here sums `charges` less
 * `payments`: a household's balance is `Dues::unitBalances()`, which is the sum
 * of the unit's own lines on 1200 Dues Receivable. A residents screen with its
 * own arithmetic over the billing tables would agree with the arrears board by
 * coincidence, and the coincidence is what fails when somebody reconciles them.
 *
 * A CLAIM APPROVAL POSTS NOTHING. Binding a person to a household decides whose
 * guest passes they may issue and whose gate they may be admitted at; it changes
 * nothing about what the unit owes and it does not clear a restriction. Board
 * 31's own note says so, and `EstateResidentsTest` proves it by counting journal
 * entries either side of an approval.
 */
class Residents
{
    /**
     * The only thing a screen ever says about a restricted household.
     *
     * Byte-for-byte what `RestrictionPolicy` returns to a guard (D-025). Two
     * different sentences for the same fact is how a resident comes to be told
     * one thing at the gate and another at the office.
     */
    public const RESTRICTED_LABEL = 'Access restricted — contact management';

    /** What a Balance cell reads for a viewer the ledger is closed to. */
    public const WITHHELD_LABEL = 'Balance not shown';

    /**
     * ASSUMPTION Q-014 — may a role locked out of the ledger be told a unit is
     * in arrears at all?
     *
     * Board 31's own persona is the Property Manager and its third card prints
     * "Tanya Simms — 90+ days arrears" in the On-record column. D-010 locks that
     * role out of a resident's financial position entirely. Both cannot hold, and
     * an ageing bucket IS a financial position — it is the label the arrears
     * ageing report is built out of.
     *
     * TRUE is the safe reading: the bucket is appended only for a viewer who
     * holds `estate.dues_ledger.view`, and the Property Manager reviewing a claim
     * sees the identity flag and nothing about money. Flip this to false only on
     * a client ruling that the flag is a risk signal rather than a ledger fact;
     * `EstateResidentsTest` asserts the safe behaviour and is the test that will
     * be rewritten when the ruling lands.
     */
    public const ARREARS_FLAG_NEEDS_LEDGER_ACCESS = true;

    /**
     * How many household members board 38's panel names before it rolls the
     * rest into a count.
     *
     * TWO, which is what the board draws: Andrea, Marlon, then "1 additional
     * member" for a household of three. The cap is a privacy affordance and not
     * a layout one — a detail screen naming every occupant of a house is a
     * roster of who lives where, and the panel exists to tell a reviewer who
     * holds the household rather than who sleeps in it.
     */
    public const MEMBERS_NAMED = 2;

    /**
     * How many households a list screen ships at once.
     *
     * Board 4 draws six rows, a search box and five phase chips, and draws no
     * pagination control at all — so there is no affordance for a second page
     * and inventing one would be inventing a screen. Four hundred and thirty
     * three rows in a page's props is not a list, it is a download, so the
     * filters narrow and this caps. The total is carried beside the rows, so a
     * page can say what it is showing out of what.
     */
    public const LIST_LIMIT = 50;

    public function __construct(private readonly Dues $dues) {}

    /* ------------------------------------------------------------------ */
    /* the one place a household's standing is decided */
    /* ------------------------------------------------------------------ */

    /**
     * What a screen may say about this household's standing, and nothing more.
     *
     * `$balances` and `$buckets` are passed in by the list screens, which have
     * already read every unit's balance in one query — asking per row would put
     * four hundred round trips behind a table. A caller with one household in
     * front of it passes neither and this reads them.
     *
     * @param  bool  $maySeeMoney  whether the VIEWER holds `estate.dues_ledger.view`
     * @param  array<int, int>|null  $balances  unit id => minor units owed
     * @param  array<int, string>|null  $buckets  unit id => ageing bucket key
     * @return array{
     *     restricted: bool,
     *     tone: string,
     *     label: string,
     *     balance_minor: int|null,
     *     bucket: string|null,
     *     bucket_label: string|null,
     *     withheld_reason: string|null
     * }
     */
    public function standingOf(
        Household $household,
        bool $maySeeMoney,
        ?array $balances = null,
        ?array $buckets = null,
    ): array {
        /*
         * RESTRICTION IS ANSWERED FIRST, and before the ledger is touched.
         *
         * Ordering it this way is the guarantee: the restricted path returns
         * before a balance has been looked up, so no later edit to the
         * arithmetic below — a new column, a different bucket rule, a caching
         * layer — can put a figure in front of somebody who may not have one.
         * The same ordering, for the same reason, as the exemption check in
         * `RestrictionPolicy::decide()`.
         */
        if ($household->access_restricted) {
            return [
                'restricted' => true,
                'tone' => 'amber',
                'label' => self::RESTRICTED_LABEL,
                'balance_minor' => null,
                'bucket' => null,
                'bucket_label' => null,
                /*
                 * EVEN THIS SENTENCE CARRIES NO FIGURE AND NO MONEY WORD. It
                 * ships to the browser with the rest of the payload, so a
                 * reason that named what was being withheld would be the leak
                 * describing itself. `EstateResidentsTest` scans the whole
                 * standing for a digit and for the vocabulary of money, and
                 * this string is inside what it scans.
                 */
                'withheld_reason' => 'A restricted household shows the verdict and nothing else. '
                    .'Amber and the words, the same words a guard is given at the gate — no figure, '
                    .'no ageing, no history, nothing a person at a desk could read off the screen.',
            ];
        }

        if (! $maySeeMoney) {
            return [
                'restricted' => false,
                'tone' => 'neutral',
                'label' => self::WITHHELD_LABEL,
                'balance_minor' => null,
                'bucket' => null,
                'bucket_label' => null,
                'withheld_reason' => 'A resident\'s financial position needs Dues & ledger view access. '
                    .'You are able to read everything else on this screen.',
            ];
        }

        $unitId = $household->unit_id;

        $balances ??= $this->dues->unitBalances();
        $balance = $balances[$unitId] ?? 0;

        if ($balance <= 0) {
            // Zero renders as "$0" and never as a blank or an em dash — board 4
            // is explicit, and a household that owes nothing has to be
            // distinguishable from one nobody has billed.
            return [
                'restricted' => false,
                'tone' => 'clear',
                'label' => '$0',
                'balance_minor' => 0,
                'bucket' => null,
                'bucket_label' => null,
                'withheld_reason' => null,
            ];
        }

        $buckets ??= $this->dues->unitBuckets();
        $bucket = $buckets[$unitId] ?? 'current';

        return [
            'restricted' => false,
            'tone' => 'owing',
            'label' => $this->money($balance),
            'balance_minor' => $balance,
            'bucket' => $bucket,
            'bucket_label' => Dues::BUCKET_LABELS[$bucket] ?? $bucket,
            'withheld_reason' => null,
        ];
    }

    /**
     * A unit with nobody in it — board 38 drawn against a vacant lot.
     *
     * Not a standing of zero, and not a call to `standingOf()` with an invented
     * household: a vacant unit HAS a ledger and may well owe money (nine of
     * Phoenix Park's 450 are vacant and every one of them is billed), but there
     * is no household whose standing this could be. The arrears belong on the
     * unit ledger screen, where they are addressed to a lot rather than to a
     * family that is not there.
     *
     * @return array{
     *     restricted: bool,
     *     tone: string,
     *     label: string,
     *     balance_minor: int|null,
     *     bucket: string|null,
     *     bucket_label: string|null,
     *     withheld_reason: string|null
     * }
     */
    private function vacantStanding(): array
    {
        return [
            'restricted' => false,
            'tone' => 'neutral',
            'label' => 'Vacant unit',
            'balance_minor' => null,
            'bucket' => null,
            'bucket_label' => null,
            'withheld_reason' => 'No household occupies this unit. What the lot owes is on its own '
                .'ledger, addressed to the address rather than to a family that is not there.',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* board 3 — estate structure */
    /* ------------------------------------------------------------------ */

    /**
     * The phase cards, with every count derived from the units themselves.
     *
     * FOUR OF THE SIX FIGURES ON A CARD ARE NOT STORED. The unit count, the
     * occupied count, the vacant count and the occupancy bar are one GROUP BY
     * over `units`; only the block count and the officer count are columns,
     * because nothing in this database can answer those. A phase row that
     * carried its own unit total would be free to say 92 about a phase somebody
     * had added a lot to.
     *
     * CONTENT RESIDUAL — board 3 gives Phase 5 seventy units, seventy-eight
     * occupied and no vacancies, which is not something one estate can be. The
     * occupancy is clamped where the estate is built (`EstateFinanceSeeder`), so
     * this derives 70 / 70 / 0 and the bar reads 100%. Recorded rather than
     * reproduced: a card that printed more households than houses would be a
     * screen contradicting itself in public.
     *
     * @return array<string, mixed>
     */
    public function structureBoard(): array
    {
        $counts = $this->unitCountsByPhase();
        $cards = [];

        foreach (Phase::query()->orderBy('sequence')->get() as $phase) {
            $count = $counts[$phase->name] ?? ['units' => 0, 'occupied' => 0];
            $units = $count['units'];
            $occupied = $count['occupied'];

            $cards[] = [
                'id' => $phase->id,
                'name' => $phase->name,
                'sequence' => $phase->sequence,
                'tag' => $units.' unit'.($units === 1 ? '' : 's'),
                'unit_count' => $units,
                'occupied' => $occupied,
                'vacant' => $units - $occupied,

                /*
                 * Rounded to a whole percent, which is what the board's inline
                 * widths are, and clamped so a phase whose occupancy somehow
                 * exceeded its units could never draw a bar past its track.
                 */
                'occupancy_pct' => $units === 0 ? 0 : min(100, (int) round($occupied / $units * 100)),
                'status' => $phase->status,
                'foot' => $phase->footLine(),
                'block_count' => $phase->block_count,
                'officers_assigned' => $phase->officers_assigned,
            ];
        }

        $units = (int) Unit::query()->count();
        $occupied = (int) Unit::query()->where('status', 'occupied')->count();

        return [
            'phases' => $cards,
            'totals' => [
                'units' => $units,
                'occupied' => $occupied,
                'vacant' => $units - $occupied,
            ],

            /*
             * Units standing in a block no phase row claims. Zero on a healthy
             * estate, and drawn rather than swallowed because the alternative is
             * a structure screen whose cards quietly sum to less than the estate
             * — which is exactly the arithmetic a committee checks it against.
             */
            'unplaced_units' => $units - array_sum(array_column($cards, 'unit_count')),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* board 4 — the residents list */
    /* ------------------------------------------------------------------ */

    /**
     * Every household the filters admit, with its standing decided once.
     *
     * @param  bool  $maySeeMoney  whether the viewer holds `estate.dues_ledger.view`
     * @return array<string, mixed>
     */
    public function listBoard(bool $maySeeMoney, string $phase = '', string $search = ''): array
    {
        $balances = $this->dues->unitBalances();
        $buckets = $this->dues->unitBuckets();
        $claimed = $this->unitsUnderClaim();

        $query = Household::query()
            ->with(['unit', 'residents'])
            ->withCount('residents');

        if ($phase !== '') {
            $query->whereHas('unit', fn (Builder $unit) => $unit->where('block', $phase));
        }

        if ($search !== '') {
            /*
             * Name, lot and phase — the three the board's own placeholder
             * names, and no more. A search that also matched an email would
             * quietly make an address book of a screen whose purpose is to find
             * a household.
             */
            $query->where(function (Builder $outer) use ($search): void {
                $outer->where('name', 'like', '%'.$search.'%')
                    ->orWhereHas('residents', fn (Builder $r) => $r->where('full_name', 'like', '%'.$search.'%'))
                    ->orWhereHas('unit', function (Builder $unit) use ($search): void {
                        $unit->where('reference', 'like', '%'.$search.'%')
                            ->orWhere('block', 'like', '%'.$search.'%');
                    });
            });
        }

        $total = (int) $query->clone()->count();

        /*
         * Newest active first, which is the order board 4 draws — today,
         * yesterday, two days, three, five, a week. A household nobody has ever
         * seen sorts last rather than first: a null is not the most recent
         * activity there has ever been.
         */
        $households = $query
            ->orderByRaw('last_active_at IS NULL')
            ->orderByDesc('last_active_at')
            ->orderBy('id')
            ->limit(self::LIST_LIMIT)
            ->get();

        $rows = [];

        foreach ($households as $household) {
            // The person who holds the household, and whoever is there where
            // nobody has been marked — a row with no name reads as a broken
            // screen rather than as a household nobody has filled in.
            $resident = $household->residents->firstWhere('is_primary', true)
                ?? $household->residents->first();

            $unit = $household->unit;
            $pending = $this->householdIsPending($household, $claimed);

            $rows[] = [
                'id' => $household->id,
                'unit_id' => $household->unit_id,
                'unit_slug' => $unit?->slug(),
                'household' => $household->name,
                'name' => $resident->full_name ?? $household->name,
                'initials' => $resident?->initials() ?? '??',
                'email' => $resident->email ?? null,
                'unit' => $unit?->addressLabel() ?? '',
                'phase' => $unit->block ?? '',
                'members' => (int) $household->residents_count,
                'standing' => $this->standingOf($household, $maySeeMoney, $balances, $buckets),
                'verification' => $pending ? Resident::PENDING : Resident::VERIFIED,
                'verification_label' => Resident::STATUS_LABELS[$pending ? Resident::PENDING : Resident::VERIFIED],
                'last_active' => $this->lastActiveLabel($household->last_active_at),
            ];
        }

        $pendingClaims = (int) UnitClaim::query()->where('status', UnitClaim::PENDING)->count();

        return [
            /*
             * The amber banner, and its count is the same query board 31's
             * heading runs. Two counts of the same thing is how a banner comes
             * to promise three claims to a screen holding two.
             */
            'pending' => [
                'count' => $pendingClaims,
                'title' => $pendingClaims.' unit claim'.($pendingClaims === 1 ? '' : 's').' need'
                    .($pendingClaims === 1 ? 's' : '').' review',
                'body' => 'Self-claimed units that didn\'t match our records exactly',
            ],

            // "All phases" is synthetic and always first; the rest are the
            // phases the estate actually has, in their own order.
            'phase_chips' => [
                ['value' => '', 'label' => 'All phases'],
                ...Phase::query()
                    ->orderBy('sequence')
                    ->get()
                    ->map(fn (Phase $p): array => ['value' => $p->name, 'label' => $p->name])
                    ->all(),
            ],
            'filters' => ['phase' => $phase, 'search' => $search],
            'rows' => $rows,
            'total' => $total,
            'shown' => count($rows),
            'money_visible' => $maySeeMoney,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* board 31 — unit claim review */
    /* ------------------------------------------------------------------ */

    /**
     * The pending claims, each as two columns a reviewer compares.
     *
     * THE COMPARISON IS THE SCREEN. Every row is a pair — what the claimant
     * typed against what the estate holds — and which column is amber is derived
     * from the difference rather than stored, because a register that is
     * corrected between submission and review should stop being flagged.
     *
     * @param  bool  $maySeeMoney  whether the viewer holds `estate.dues_ledger.view`
     * @return array<string, mixed>
     */
    public function claimsBoard(bool $maySeeMoney): array
    {
        $claims = UnitClaim::query()
            ->with(['unit.household.residents', 'household.residents'])
            ->where('status', UnitClaim::PENDING)
            ->orderBy('id')
            ->get();

        /*
         * Read once, and only where they can be read at all. A viewer without
         * ledger access never reaches past the second refusal in
         * `standingOf()`, so walking the whole receivable to build maps it will
         * not consult would be a scan of the estate's ledger performed for
         * somebody who is not allowed to see it.
         */
        $balances = $maySeeMoney ? $this->dues->unitBalances() : [];
        $buckets = $maySeeMoney ? $this->dues->unitBuckets() : [];

        $cards = [];

        foreach ($claims as $claim) {
            $household = $claim->household ?? $claim->unit?->household;
            $standing = $household === null
                ? null
                : $this->standingOf($household, $maySeeMoney, $balances, $buckets);

            $cards[] = [
                'id' => $claim->id,
                'title' => $claim->titleLine(),
                'claim_type' => $claim->claim_type,
                'status' => $claim->status,
                'status_label' => UnitClaim::STATUS_LABELS[$claim->status] ?? $claim->status,
                'submitted' => $this->submittedColumn($claim),
                'record' => $this->recordColumn($claim, $household, $standing, $maySeeMoney),

                /*
                 * Carried whole so a page can draw the amber verdict without
                 * deriving anything. It is the same shape every other screen
                 * gets, and for a restricted household it holds the word and no
                 * figure — which is the point of there being one function.
                 */
                'standing' => $standing,
                'actions' => $this->claimActions($claim),
            ];
        }

        $count = count($cards);

        return [
            'title' => 'Unit claims — '.$count.' pending',
            'pending_count' => $count,
            'claims' => $cards,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* board 34 — add resident */
    /* ------------------------------------------------------------------ */

    /**
     * What the form needs to be drawn, and nothing about a person.
     *
     * @return array<string, mixed>
     */
    public function newResidentBoard(): array
    {
        return [
            'phases' => Phase::query()
                ->orderBy('sequence')
                ->get()
                ->map(fn (Phase $p): array => ['value' => $p->name, 'label' => $p->name])
                ->all(),

            'verification' => [
                ['value' => 'invite', 'label' => 'Send self-verification invite'],
                ['value' => 'manual', 'label' => 'Mark verified now'],
            ],

            // The board draws the invite segment active, and it is the right
            // default: the estate asserting somebody's identity on their behalf
            // is the exception, not the ordinary path.
            'default_verification' => 'invite',

            /*
             * SHIPS OFF (D-022, Q-003). The form may collect it; nothing enrols
             * a fingerprint without it, and the refusal is in
             * `enrolBiometrics()` rather than in a settings table.
             */
            'biometric_consent' => [
                'default' => false,
                'label' => 'Resident consents to biometric enrolment',
                'note' => 'Off unless the resident says otherwise. Biometric enrolment is refused '
                    .'without it, and no estate setting grants it on anybody\'s behalf.',
            ],

            /*
             * The preview copy, with the two things it interpolates left as
             * tokens. The board's own wording is gendered — "she", "her" —
             * because it was drawn against one named person; a template cannot
             * know, so this reads "they". Recorded in DECISIONS.md rather than
             * guessed at per resident.
             */
            'preview' => [
                'head' => 'What happens next',
                'template' => '{first} will get an SMS and email with a link to download the Resident '
                    .'App and claim {lot}. Once they verify with their ID, their status changes from '
                    .'"Pending" to "Verified" automatically — no further action needed from you unless '
                    .'their details don\'t match.',
            ],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* board 38 — one household in full */
    /* ------------------------------------------------------------------ */

    /**
     * Everything board 38 draws for one unit.
     *
     * THE HERO STAT IS THE STANDING, WHOLE. "Balance owed" is not a separate
     * figure from the amber word: it is one cell that reads as an amount when
     * the viewer may have one and reads as the verdict when they may not, which
     * is why it is built from `standingOf()` rather than beside it.
     *
     * @param  bool  $maySeeMoney  whether the viewer holds `estate.dues_ledger.view`
     * @return array<string, mixed>
     */
    public function residentBoard(Unit $unit, bool $maySeeMoney): array
    {
        $household = $unit->household;

        /*
         * A plain array rather than a collection, and the primary first. Every
         * panel below walks it in that order — who holds the household is the
         * first thing a reviewer reads — and an ordering decided once here
         * cannot be re-decided differently by the two helpers that consume it.
         */
        $members = $household === null
            ? []
            : $household->residents()->orderByDesc('is_primary')->orderBy('id')->get()->all();

        $primary = $members[0] ?? null;

        $standing = $household === null
            ? $this->vacantStanding()
            : $this->standingOf($household, $maySeeMoney);

        $links = $this->linkedActivity($unit, $standing);
        $pending = $household !== null && $this->householdIsPending($household, $this->unitsUnderClaim());

        return [
            'resident' => [
                'unit_id' => $unit->id,
                'unit_slug' => $unit->slug(),
                'name' => $primary->full_name ?? 'No resident on record',
                'initials' => $primary?->initials() ?? '??',

                // "Phase 2 · Lot 47 · Fletcher household"
                'sub' => implode(' · ', array_filter([
                    $unit->block,
                    $unit->reference,
                    $household?->name,
                ])),
                'verification' => $pending ? Resident::PENDING : Resident::VERIFIED,
                'verification_label' => Resident::STATUS_LABELS[$pending ? Resident::PENDING : Resident::VERIFIED],
            ],

            'standing' => $standing,

            'stats' => [
                ['key' => 'members', 'value' => count($members), 'label' => 'Household members'],

                /*
                 * One cell, two readings. `value` is what the tile prints in
                 * either case and `value_minor` is null whenever the figure is
                 * withheld, so a page that reached for the number instead of the
                 * label would print nothing rather than something.
                 */
                [
                    'key' => 'balance',
                    'value' => $standing['label'],
                    'value_minor' => $standing['balance_minor'],
                    'tone' => $standing['tone'],
                    'label' => 'Balance owed',
                ],

                /*
                 * Open items is a count of the cross-links that need somebody to
                 * act — an arrears balance and an unfinished ticket. A booking
                 * with a deposit held is not one: the money is where it should
                 * be and the date is in the diary, so there is nothing for a
                 * reviewer to do about it. Counted off the links this viewer can
                 * actually see, so it cannot become a way of learning that a
                 * withheld balance was non-zero.
                 */
                [
                    'key' => 'open_items',
                    'value' => count(array_filter($links, static fn (array $l): bool => $l['is_open_item'])),
                    'label' => 'Open items',
                ],

                [
                    'key' => 'since',
                    'value' => $this->residentSince($members)?->format('M j, Y'),
                    'label' => 'Resident since',
                ],
            ],

            'members' => $this->memberPanel($members),

            'contact' => [
                ['label' => 'Email', 'value' => $primary->email ?? 'Not on file'],
                ['label' => 'Phone', 'value' => $primary->phone ?? 'Not on file'],
            ],

            'linked' => $links,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* the acts */
    /* ------------------------------------------------------------------ */

    /**
     * Put a person on the register at a unit — board 34.
     *
     * THE UNIT HAS TO EXIST. An estate's units are its physical addresses and
     * this form does not create one: a lot typed in error would otherwise become
     * a house, and a household would be filed at an address the estate does not
     * have. The refusal names the phase and the lot, because that is what the
     * person can fix.
     *
     * ADDING A RESIDENT OCCUPIES THE UNIT. A lot with somebody living at it is
     * not vacant, and leaving the status alone would leave board 3's occupancy
     * counting a household that exists as an empty house.
     *
     * @param  string  $verification  'invite' — they prove who they are; 'manual' — the estate asserts it
     */
    public function add(
        string $phase,
        string $lot,
        string $fullName,
        ?string $email = null,
        ?string $phone = null,
        string $verification = 'invite',
        bool $biometricConsent = false,
        ?User $by = null,
    ): Resident {
        $reference = $this->lotReference($lot);

        $unit = Unit::query()
            ->where('block', $phase)
            ->where('reference', $reference)
            ->first();

        if ($unit === null) {
            throw new DomainException(sprintf(
                'No %s in %s. A resident is filed at an address the estate actually has, and this '.
                'form does not create one — a lot typed in error would otherwise become a house. '.
                'Check the phase and the lot number, or add the unit to the estate structure first.',
                $reference,
                $phase,
            ));
        }

        return DB::connection('tenant')->transaction(function () use (
            $unit, $fullName, $email, $phone, $verification, $biometricConsent, $by
        ): Resident {
            $household = $unit->household;

            if ($household === null) {
                $household = Household::create([
                    'unit_id' => $unit->id,
                    'name' => $this->householdNameFor($fullName, $unit),
                    'access_restricted' => false,
                ]);
            }

            $manual = $verification === 'manual';

            $resident = Resident::create([
                'household_id' => $household->id,
                'full_name' => $fullName,
                'email' => $email,
                'phone' => $phone,
                'relationship' => 'owner',

                // The first person on a household holds it. A second is added
                // beside them, and which of them is primary is a decision
                // somebody makes rather than a race between two form
                // submissions.
                'is_primary' => $household->residents()->where('is_primary', true)->doesntExist(),

                'status' => $manual ? Resident::VERIFIED : Resident::PENDING,
                'verified_at' => $manual ? now() : null,
                'verified_by' => $manual ? $by?->getKey() : null,
                'verified_by_name' => $manual ? $by?->name : null,
                'biometric_consent' => $biometricConsent,
                'moved_in_on' => Carbon::today()->toDateString(),
            ]);

            if (! $manual) {
                /*
                 * `sent_at` is left NULL. There is no mail driver and no SMS
                 * gateway on this path yet, and a row claiming a message had
                 * gone would be a lie the moment somebody read it while chasing
                 * a resident who never got one.
                 */
                ResidentInvite::create([
                    'resident_id' => $resident->id,
                    'unit_id' => $unit->id,
                    'token' => (string) Str::uuid(),
                    'channels' => 'sms,email',
                ]);
            }

            if ($unit->status !== 'occupied') {
                $unit->forceFill(['status' => 'occupied'])->save();
            }

            return $resident;
        });
    }

    /**
     * Enrol this resident's biometrics — REFUSED WITHOUT CONSENT.
     *
     * The flag ships off and nothing turns it on for a person but that person
     * (D-022, Q-003). This refusal lives here rather than on the screen that
     * collects the consent, because a second enrolment path — an import, an API,
     * the guard handset — would otherwise each need to remember, and the one
     * that forgets enrols somebody who never agreed.
     *
     * There is no enrolment yet. This is the gate in front of it, written now so
     * that whatever arrives behind it has to come through here.
     *
     * // ASSUMPTION Q-003 — consent ships off, and the ruling (D-022) landed on
     * // this refusal rather than in a settings table.
     * // ASSUMPTION Q-015 — whether a member of STAFF may give that consent on a
     * // resident's behalf is still open. Board 34 draws the checkbox on a form a
     * // Property Manager fills in, and the person it binds is not in the room.
     * // Nothing here can be reached without an explicit tick either way.
     */
    public function enrolBiometrics(Resident $resident): void
    {
        if (! $resident->biometric_consent) {
            throw new DomainException(sprintf(
                '%s has not consented to biometric enrolment, so nothing may be enrolled. Consent is '.
                'recorded per person and defaults to off; no estate setting grants it on a resident\'s '.
                'behalf, and no import may assume it.',
                $resident->full_name,
            ));
        }
    }

    /**
     * Approve a claim — board 31's primary action.
     *
     * `approve`, NOT `update` (D-013). This binds a person to a household, which
     * decides whose guest passes they may issue and whose gate they may be
     * admitted at, and no edit afterwards unbinds the night somebody was let
     * through. The route carries the gate; this records who decided.
     *
     * NO JOURNAL IS RAISED AND NO RESTRICTION IS TOUCHED. A unit in arrears that
     * gains an authorised occupant still owes exactly what it owed, and a
     * restricted household stays restricted — approving a claim is not a
     * decision about money and must not become one by side effect.
     */
    public function approveClaim(UnitClaim $claim, ?User $by = null): UnitClaim
    {
        if (! $claim->isPending()) {
            throw new DomainException(sprintf(
                'Claim [%s] is already %s. Deciding it a second time would overwrite the record of who '.
                'decided it the first time.',
                $claim->submitted_name,
                $claim->status,
            ));
        }

        $unit = $claim->unit;

        if ($unit === null) {
            throw new DomainException(sprintf(
                'The claim by %s is not matched to a unit, so approving it would authorise a person '.
                'against nothing. Match it to a lot first — that is the decision, and it is not one '.
                'an approval button should make silently.',
                $claim->submitted_name,
            ));
        }

        return DB::connection('tenant')->transaction(function () use ($claim, $unit, $by): UnitClaim {
            $household = $claim->household ?? $unit->household;

            if ($household === null) {
                $household = Household::create([
                    'unit_id' => $unit->id,
                    'name' => $this->householdNameFor($claim->submitted_name, $unit),
                    'access_restricted' => false,
                ]);
            }

            /*
             * The person the claim resolves to. An existing member is verified
             * where one matches by name — board 31's second card is exactly
             * that, a member already added on Sep 4 asking to be recognised —
             * and a new resident is created otherwise. Matching on the name is
             * as far as this goes deliberately: a fuzzier rule would silently
             * bind a claim to the wrong person, and the whole screen exists
             * because the estate's matcher was not sure.
             */
            $resident = $household->residents()
                ->where('full_name', $claim->submitted_name)
                ->first();

            $resident ??= Resident::create([
                'household_id' => $household->id,
                'full_name' => $claim->submitted_name,
                'phone' => $claim->submitted_phone,
                'relationship' => $claim->submitted_relationship ?? 'owner',
                'is_primary' => $household->residents()->where('is_primary', true)->doesntExist(),
                'status' => Resident::PENDING,
                'moved_in_on' => Carbon::today()->toDateString(),
            ]);

            $resident->forceFill([
                'status' => Resident::VERIFIED,
                'verified_at' => now(),
                'verified_by' => $by?->getKey(),
                'verified_by_name' => $by?->name,
            ])->save();

            $claim->forceFill([
                'status' => UnitClaim::APPROVED,
                'resolved_resident_id' => $resident->id,
                'household_id' => $household->id,
                'reviewed_by' => $by?->getKey(),
                'reviewed_by_name' => $by?->name,
                'reviewed_at' => now(),
            ])->save();

            return $claim;
        });
    }

    /**
     * Refuse a claim, with the reason on the record.
     *
     * A REJECTION WITHOUT A REASON IS REFUSED. The claimant is a person who will
     * ask why, and an estate that recorded only "no" has nothing to answer with
     * — nor anything to defend the decision with if they come back.
     */
    public function rejectClaim(UnitClaim $claim, string $reason, ?User $by = null): UnitClaim
    {
        if (! $claim->isPending()) {
            throw new DomainException(sprintf(
                'Claim [%s] is already %s and cannot be refused again.',
                $claim->submitted_name,
                $claim->status,
            ));
        }

        if (trim($reason) === '') {
            throw new DomainException(
                'A rejected claim needs a reason. The claimant is a person who will ask why, and an '.
                'estate that recorded only "no" has nothing to answer them with.'
            );
        }

        $claim->forceFill([
            'status' => UnitClaim::REJECTED,
            'decision_reason' => mb_substr(trim($reason), 0, 190),
            'reviewed_by' => $by?->getKey(),
            'reviewed_by_name' => $by?->name,
            'reviewed_at' => now(),
        ])->save();

        return $claim;
    }

    /**
     * Ask the claimant for an identity document.
     *
     * The KIND is recorded and not just the fact. "We asked" and "we asked for a
     * passport" are different things to somebody trying to work out what to
     * send, and a claim that is chased twice for different documents is how a
     * review stalls.
     */
    public function requestDocument(UnitClaim $claim, string $kind = 'photo ID', ?User $by = null): UnitClaim
    {
        if (! $claim->isPending()) {
            throw new DomainException(sprintf(
                'Claim [%s] is already %s. There is nothing left to verify.',
                $claim->submitted_name,
                $claim->status,
            ));
        }

        $claim->forceFill([
            'document_requested_at' => now(),
            'document_requested_kind' => mb_substr(trim($kind) ?: 'photo ID', 0, 64),
            'document_requested_by' => $by?->getKey(),
            'document_requested_by_name' => $by?->name,
        ])->save();

        return $claim;
    }

    /* ------------------------------------------------------------------ */
    /* internals */
    /* ------------------------------------------------------------------ */

    /**
     * Units and occupancy per phase, in one query.
     *
     * @return array<string, array{units: int, occupied: int}>
     */
    private function unitCountsByPhase(): array
    {
        $rows = DB::connection('tenant')
            ->table('units')
            ->selectRaw("block, COUNT(*) as units, SUM(status = 'occupied') as occupied")
            ->groupBy('block')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row->block] = [
                'units' => (int) $row->units,
                'occupied' => (int) $row->occupied,
            ];
        }

        return $counts;
    }

    /**
     * Units with an unanswered claim about WHO HOLDS THE HOUSEHOLD.
     *
     * A member claim is deliberately not one of these. Board 4 draws Andrea
     * Fletcher's row "Verified" while her brother's membership claim sits in the
     * queue above it, and it is right to: nobody is disputing that Lot 47 is
     * hers. What board 4 flags amber is Keith Walters's row, where somebody the
     * register does not recognise is claiming to be the resident — and Tanya
     * Simms's, where the estate could not place the claimant at all.
     *
     * @return list<int>
     */
    private function unitsUnderClaim(): array
    {
        return UnitClaim::query()
            ->where('status', UnitClaim::PENDING)
            ->whereIn('claim_type', [UnitClaim::TYPE_UNIT, UnitClaim::TYPE_UNVERIFIED])
            ->whereNotNull('unit_id')
            ->pluck('unit_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Whether board 4 draws this household's badge amber.
     *
     * TWO WAYS INTO IT AND BOTH ARE ABOUT WHO HOLDS THE HOUSEHOLD, not about
     * everybody in it. A household is under review while a claim on its unit is
     * unanswered, and while the person the register says holds it has not been
     * verified.
     *
     * AN UNVERIFIED MEMBER DOES NOT FLAG THE HOUSEHOLD, and board 38 is what
     * settles it: Marlon Fletcher reads "Added Sep 4" in the members panel while
     * the hero pill above him reads "Verified". A rule that flagged the whole
     * household for any unverified occupant would contradict that screen and
     * would put an amber badge on every household that had just added a teenager.
     *
     * @param  list<int>  $claimedUnitIds  from `unitsUnderClaim()`
     */
    private function householdIsPending(Household $household, array $claimedUnitIds): bool
    {
        if (in_array($household->unit_id, $claimedUnitIds, true)) {
            return true;
        }

        $primary = $household->residents->firstWhere('is_primary', true);

        return $primary !== null && ! $primary->isVerified();
    }

    /**
     * Board 4's "Last active" column, in each of the forms it draws.
     *
     * Same day gives the time, because "Today" alone loses the only thing that
     * distinguishes nine in the morning from a minute ago. Beyond a fortnight it
     * counts weeks, which is the resolution the question is actually asked at.
     */
    private function lastActiveLabel(?Carbon $at): ?string
    {
        if ($at === null) {
            return null;
        }

        $days = (int) $at->copy()->startOfDay()->diffInDays(Carbon::today(), absolute: true);

        return match (true) {
            $days === 0 => 'Today, '.$at->format('g:i A'),
            $days === 1 => 'Yesterday',
            $days < 7 => $days.' days ago',
            $days < 14 => '1 week ago',
            $days < 30 => intdiv($days, 7).' weeks ago',
            default => $at->format('M j, Y'),
        };
    }

    /**
     * What the claimant submitted, as board 31's left column.
     *
     * The left column is flagged only where the estate could not resolve the
     * submission at all. A claim that matched a lot is a claim whose submission
     * is at least addressable; what differs about it belongs on the right, next
     * to the record it differs from.
     *
     * @return array{label: string, mismatch: bool, rows: list<array{label: string, value: string}>}
     */
    private function submittedColumn(UnitClaim $claim): array
    {
        $rows = [['label' => 'Name', 'value' => $claim->submitted_name]];

        if ($claim->claim_type === UnitClaim::TYPE_MEMBER) {
            $rows[] = ['label' => 'Relationship', 'value' => $claim->submitted_relationship ?? 'Not given'];
            $rows[] = [
                'label' => 'Claims to',
                'value' => ($claim->household->name ?? 'a household').'\'s household',
            ];
        } else {
            $rows[] = ['label' => 'Phase / Lot', 'value' => $claim->submittedUnitLabel() ?: 'Not given'];

            if ($claim->claim_type !== UnitClaim::TYPE_UNVERIFIED) {
                $rows[] = ['label' => 'Phone', 'value' => $claim->submitted_phone ?? 'Not given'];
            }
        }

        return [
            'label' => 'Resident submitted',
            'mismatch' => $claim->claim_type === UnitClaim::TYPE_UNVERIFIED,
            'rows' => $rows,
        ];
    }

    /**
     * What the estate holds, as board 31's right column.
     *
     * THE ARREARS SUFFIX IS THE ONLY MONEY ON THIS SCREEN AND IT IS GATED TWICE.
     * A restricted household never carries it — the amber verdict travels in
     * `standing` instead — and a viewer without ledger access never carries it
     * either, which is `ARREARS_FLAG_NEEDS_LEDGER_ACCESS` and Q-014. Board 31's
     * own persona is the Property Manager, so on the drawn screen it is absent;
     * that is D-010 outranking a board, the same way the sidebar does.
     *
     * @param  array{restricted: bool, tone: string, label: string, balance_minor: int|null, bucket: string|null, bucket_label: string|null, withheld_reason: string|null}|null  $standing
     * @return array{label: string, mismatch: bool, rows: list<array{label: string, value: string}>}
     */
    private function recordColumn(UnitClaim $claim, ?Household $household, ?array $standing, bool $maySeeMoney): array
    {
        $onRecord = $household?->primaryResident();
        $rows = [];
        $mismatch = false;

        if ($claim->claim_type === UnitClaim::TYPE_MEMBER) {
            $existing = $household?->residents
                ->firstWhere('full_name', $claim->submitted_name);

            $rows[] = ['label' => 'Primary resident', 'value' => $onRecord->full_name ?? 'None on record'];
            $rows[] = [
                'label' => 'Existing member',
                'value' => $existing === null
                    ? 'Not on file'
                    : $existing->full_name.' · added '.($existing->created_at?->format('M j') ?? 'unknown'),
            ];
            $rows[] = [
                'label' => 'Match',
                'value' => UnitClaim::MATCH_LABELS[$claim->match_result] ?? $claim->match_result,
            ];

            $mismatch = $existing === null || $claim->match_result !== 'exact';
        } elseif ($claim->claim_type === UnitClaim::TYPE_UNVERIFIED) {
            $rows[] = [
                'label' => 'Primary resident',
                'value' => $this->recordNameWithStanding($onRecord?->full_name, $standing, $maySeeMoney),
            ];
            $rows[] = [
                'label' => 'Flag',
                'value' => $claim->review_flag ?? 'Verify identity before approving',
            ];

            // An unresolved claimant is a mismatch on the record side by
            // definition: the estate is being asked to accept somebody it
            // cannot place.
            $mismatch = true;
        } else {
            $recordName = $onRecord->full_name ?? 'None on record';
            $recordPhone = $onRecord?->phone;

            $rows[] = ['label' => 'Name', 'value' => $recordName];
            $rows[] = [
                'label' => 'Phase / Lot',
                'value' => $claim->unit?->addressLabel() ?? 'No unit matched',
            ];
            $rows[] = ['label' => 'Phone', 'value' => $recordPhone ?? 'Not on file'];

            /*
             * Amber when anything the claimant gave differs from what the
             * estate holds. Derived, and never stored: a register corrected
             * between submission and review should stop being flagged, and a
             * stored flag would go on accusing a claim that was fixed.
             */
            $mismatch = $recordName !== $claim->submitted_name
                || ($claim->submitted_phone !== null && $recordPhone !== $claim->submitted_phone)
                || $recordPhone === null;
        }

        return ['label' => 'On record', 'mismatch' => $mismatch, 'rows' => $rows];
    }

    /**
     * A name on the register, with an ageing bucket only where one is allowed.
     *
     * @param  array{restricted: bool, tone: string, label: string, balance_minor: int|null, bucket: string|null, bucket_label: string|null, withheld_reason: string|null}|null  $standing
     */
    private function recordNameWithStanding(?string $name, ?array $standing, bool $maySeeMoney): string
    {
        $name ??= 'None on record';

        if ($standing === null || $standing['restricted']) {
            // A restricted household says the word or nothing. Never the days.
            return $name;
        }

        /*
         * ASSUMPTION Q-014, and `ARREARS_FLAG_NEEDS_LEDGER_ACCESS` is the flag
         * that names it. It stands at true, which is this branch: the ageing
         * bucket is a financial position and needs ledger access, so board 31's
         * own persona — the Property Manager, whom D-010 locks out — sees the
         * identity flag and nothing about money. A ruling the other way deletes
         * these three lines and the constant with them, and
         * `EstateResidentsTest` is the test that will be rewritten with it.
         */
        if (! $maySeeMoney) {
            return $name;
        }

        return $standing['bucket_label'] === null
            ? $name
            : $name.' — '.$standing['bucket_label'].' arrears';
    }

    /**
     * The two buttons a claim carries, and which of them is drawn.
     *
     * A claim the estate matched EXACTLY offers a refusal, because the only
     * question left is whether the estate agrees. Anything less offers a
     * document request, because the reviewer has something to resolve first and
     * refusing a claim they have not investigated is the wrong door. Approve is
     * always last and always primary — board 31 draws it that way on all three
     * cards, and a destructive action sitting where the confirming one usually
     * does is how the wrong button gets pressed.
     *
     * @return list<array{key: string, label: string, style: string}>
     */
    private function claimActions(UnitClaim $claim): array
    {
        $first = $claim->match_result === 'exact'
            ? ['key' => 'reject', 'label' => 'Reject', 'style' => 'outline']
            : ['key' => 'document', 'label' => 'Request ID document', 'style' => 'outline'];

        return [
            $first,
            ['key' => 'approve', 'label' => 'Approve claim', 'style' => 'primary'],
        ];
    }

    /**
     * Board 38's household panel — named members, then a rolled-up count.
     *
     * THE ROLL-UP IS A PRIVACY AFFORDANCE, NOT A LAYOUT ONE. A detail screen
     * naming every occupant of a house is a roster of who lives where; the panel
     * exists to tell a reviewer who holds the household. The rolled row reports
     * verified only when every member inside it is, because "Verified" over a
     * count that hides an unverified person is the one reading it must not have.
     *
     * @param  array<int, Resident>  $members  primary first
     * @return list<array{label: string, value: string}>
     */
    private function memberPanel(array $members): array
    {
        $rows = [];

        foreach (array_slice($members, 0, self::MEMBERS_NAMED) as $member) {
            $rows[] = [
                'label' => $member->panelName(),

                /*
                 * A union of two facts, exactly as the board draws it: a
                 * verified member says so, and one who is not says when they
                 * were added — which is what a reviewer chasing them needs.
                 */
                'value' => $member->isVerified()
                    ? Resident::STATUS_LABELS[Resident::VERIFIED]
                    : 'Added '.($member->created_at?->format('M j') ?? 'recently'),
            ];
        }

        $rest = array_slice($members, self::MEMBERS_NAMED);

        if ($rest !== []) {
            $allVerified = true;

            foreach ($rest as $member) {
                $allVerified = $allVerified && $member->isVerified();
            }

            $rows[] = [
                'label' => count($rest).' additional member'.(count($rest) === 1 ? '' : 's'),
                'value' => Resident::STATUS_LABELS[$allVerified ? Resident::VERIFIED : Resident::PENDING],
            ];
        }

        return $rows;
    }

    /**
     * When this household became residents.
     *
     * The OLDEST member's move-in date, because a household has been here as
     * long as its longest-standing member has — a brother added last week did
     * not make the family newer.
     *
     * @param  array<int, Resident>  $members
     */
    private function residentSince(array $members): ?Carbon
    {
        $earliest = null;

        foreach ($members as $member) {
            $date = $member->moved_in_on ?? $member->created_at;

            if ($date === null) {
                continue;
            }

            if ($earliest === null || $date->lessThan($earliest)) {
                $earliest = $date;
            }
        }

        return $earliest;
    }

    /**
     * Board 38's "Linked activity" — the arrears, the ticket and the booking.
     *
     * THE LEDGER ROW IS ABSENT RATHER THAN BLANKED where the balance may not be
     * shown. A row reading "arrears" with the figure struck out still says there
     * are arrears, and for a restricted household the whole point is that the
     * screen says the word and nothing else. Absent, not disabled — the same
     * rule the sidebar follows.
     *
     * `is_open_item` is what the hero count adds up, and a booking is not one: a
     * deposit that is held is money in the right place and a date that is in the
     * diary, so there is nothing for anybody to do about it.
     *
     * @param  array{restricted: bool, tone: string, label: string, balance_minor: int|null, bucket: string|null, bucket_label: string|null, withheld_reason: string|null}  $standing
     * @return list<array{type: string, title: string, sub: string, is_open_item: bool}>
     */
    private function linkedActivity(Unit $unit, array $standing): array
    {
        $links = [];

        if (($standing['balance_minor'] ?? 0) > 0) {
            $links[] = [
                'type' => 'ledger',
                'title' => ($standing['bucket_label'] ?? 'Outstanding').' arrears — '.$standing['label'],
                'sub' => 'Dues & ledger',
                'is_open_item' => true,
            ];
        }

        $ticket = MaintenanceTicket::query()
            ->where('unit_id', $unit->id)
            ->whereNotIn('status', [
                MaintenanceTicket::COMPLETED,
                MaintenanceTicket::VERIFIED,
                MaintenanceTicket::CANCELLED,
            ])
            ->orderByDesc('reported_at')
            ->first();

        if ($ticket !== null) {
            $links[] = [
                'type' => 'ticket',
                'title' => 'Ticket #'.$ticket->number.' — '.$ticket->title,
                'sub' => 'Submitted '.$ticket->reported_at->format('M j').' · '
                    .(MaintenanceTicket::STATUS_LABELS[$ticket->boardStatus()] ?? $ticket->status),
                'is_open_item' => true,
            ];
        }

        $booking = AmenityBooking::query()
            ->with('amenity')
            ->where('unit_id', $unit->id)
            ->whereIn('status', [AmenityBooking::PENDING, AmenityBooking::CONFIRMED])
            ->orderBy('starts_at')
            ->first();

        if ($booking !== null) {
            $deposit = $booking->depositLabel();

            $links[] = [
                'type' => 'booking',
                'title' => ($booking->amenity->name ?? 'Amenity').' booked — '
                    .$booking->starts_at->format('M j'),
                'sub' => implode(' · ', array_filter(['Facilities', $deposit])),
                'is_open_item' => false,
            ];
        }

        return $links;
    }

    /**
     * What a household with no name of its own gets called.
     *
     * The SURNAME plus "household", which is how every board names one —
     * "Fletcher household", "Brown household". Falling back to the lot where
     * there is no surname to take, because a household called " household" is
     * worse on a screen than one called after its address.
     */
    private function householdNameFor(string $personName, Unit $unit): string
    {
        $parts = preg_split('/\s+/', trim($personName)) ?: [];
        $surname = trim((string) end($parts));

        return ($surname === '' ? (string) $unit->reference : $surname).' household';
    }

    /**
     * "Lot 112" from whatever the form sent.
     *
     * A person typing into a field labelled "Lot number" types "112"; a person
     * pasting from a spreadsheet sends "Lot 112". Both mean the same address and
     * neither is wrong, so both resolve here rather than one of them failing a
     * lookup that would read as a missing unit.
     */
    private function lotReference(string $lot): string
    {
        $lot = trim($lot);

        return Str::startsWith(strtolower($lot), 'lot ') ? 'Lot '.trim(substr($lot, 4)) : 'Lot '.$lot;
    }

    /**
     * Minor units as every estate board prints them — "$12,400".
     *
     * Whole dollars with a thousands separator and no decimals, which is what
     * board 4's column draws and what `AmenityBooking::depositLabel()` already
     * does. The cents are truncated rather than rounded, so a label can never
     * report more than the ledger holds; `balance_minor` beside it is the exact
     * figure and is what any arithmetic uses.
     */
    private function money(int $minor): string
    {
        return '$'.number_format(intdiv($minor, 100));
    }
}
