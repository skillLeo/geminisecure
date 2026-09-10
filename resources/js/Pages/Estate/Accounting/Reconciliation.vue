<script setup>
import { computed } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * Bank reconciliation — board screen community-admin-28.
 *
 * TWO INDEPENDENT RECORDS OF ONE ACCOUNT, SET SIDE BY SIDE. The left column is
 * the bank's own statement, transcribed; the right is what the estate has posted
 * and not yet accounted for. The whole exercise is finding the money one side
 * knows about and the other does not.
 *
 * A RECONCILIATION CANNOT BE COMPLETED WHILE A DIFFERENCE REMAINS, and that
 * difference is this screen's entire subject. It is the movement the bank
 * reports — closing less opening — less every statement line the estate has
 * claimed as one of its own entries. Zero means the month is accounted for;
 * anything else is exactly what a reconciliation exists to surface. So the
 * figure is DRAWN rather than implied, it moves as lines are matched, and the
 * Complete control is inert with the outstanding amount in its own title until
 * it reaches zero. `Payables::complete()` refuses it as well — but a control
 * that can only be pressed hopefully is a control that has already failed.
 *
 * THE BOARD DRAWS NO DIFFERENCE AT ALL, and this page adds one. That is a
 * deliberate departure, recorded here: the board has no opening balance, no
 * closing balance and no summary, so a reader of the board cannot tell whether
 * the month reconciles — and the one rule this screen exists to enforce would be
 * invisible on it. The summary sits BELOW the two columns so everything the
 * board does draw stays where the board draws it.
 *
 * MATCHING IS STORED ON THE STATEMENT SIDE, AND UNMATCHING IS ALWAYS POSSIBLE. A
 * posted entry is immutable, so nothing on the ledger side is written; the claim
 * lives on the bank line, and a treasurer who pairs the wrong two items takes the
 * claim back rather than reversing a bookkeeping event that never happened.
 *
 * A MATCH IS A CLAIM, AND A PROPOSAL IS NOT ONE. The server offers an unclaimed
 * entry on this account, in this period, for exactly this amount and in the same
 * direction — a strong candidate and nothing more, because an estate can pay two
 * suppliers the same amount in a month. Where there is no candidate the line is a
 * bank-only item: it needs a journal entry raised before it can be matched to
 * anything, and that entry is a JOURNAL, never an edit to something posted.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    reconciliation: { type: Object, default: null },
    lines: { type: Array, required: true },
    ledger_side: { type: Array, required: true },
    counts: { type: Object, required: true },
    canMatch: { type: Boolean, required: true },
    canComplete: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
    scope: { type: Object, required: true },
    reasons: { type: Object, required: true },
})

/*
 * Which board's stylesheet this page wears. The ten Estate Console boards do
 * NOT share one sheet the way the nine Gemini boards do, so every estate page
 * has to name its own or it renders with no board CSS at all.
 */
useWireframe('community-admin-07-chart-of-accounts-vendors-bills-and-bank-rec')

const page = usePage()

/**
 * All six, and two of them are genuinely different empties.
 *
 * `empty` is an estate with NO STATEMENT AT ALL — nothing has been imported, so
 * there is no period to reconcile and the next act is importing one.
 * `empty-filtered` is a statement that exists and carries no lines: the period is
 * the filter this screen applies, and a month with nothing in it is a different
 * fact from a bank the estate has never heard from. Offering to import on the
 * first and explaining the second is the whole reason the two states are kept
 * apart.
 */
const state = useScreenState({
    rows: () => props.lines.length,
    filtered: () => props.reconciliation !== null,
})

const retry = () => router.reload()

/*
 * Where this estate's accounting lives, in whichever shape the environment
 * serves. Production gives each estate its own hostname and the path starts at
 * /accounting; local serves them all from one host with the estate in the path
 * (/estate/phoenixpark/accounting/reconciliation). Cutting the current URL at
 * /accounting is right in both.
 */
const accountingPath = computed(() => {
    const cut = page.url.indexOf('/accounting')

    return cut === -1 ? '/accounting' : `${page.url.slice(0, cut)}/accounting`
})

/** The four Accounting tabs. All four are built, so three are links. */
const tabs = computed(() => [
    { key: 'chart', label: 'Chart of accounts', href: `${accountingPath.value}/chart-of-accounts` },
    { key: 'vendors', label: 'Vendors', href: `${accountingPath.value}/vendors` },
    { key: 'bills', label: 'Bills & payments', href: `${accountingPath.value}/bills` },
    { key: 'reconciliation', label: 'Bank reconciliation', href: null },
])

/* ------------------------------------------------------------------ */
/* money */
/* ------------------------------------------------------------------ */

/**
 * TWO FORMATS ON ONE SCREEN, and the difference is the design's.
 *
 * `board` is what screen 28 draws on every statement and ledger row — "$612,400",
 * a bare dollar sign, whole units, AND NO SIGN. The board prints a deposit and a
 * withdrawal identically even though the two are mixed down one column, which is
 * how a statement reads: the direction is stored, not drawn. It is reproduced
 * rather than tidied, and the direction the board leaves out is put on each
 * amount's own title so nothing is actually lost.
 *
 * `exact` is J$ and the currency named in full, and it appears only in the
 * summary beneath. That block is the figure a period is SIGNED OFF on — or
 * refused on — and a difference quoted in a bare "$" beside a Jamaican bank
 * statement is the kind of ambiguity an audit finds. No decimals, because these
 * are whole-unit statement figures and a run of ".00" would sit between the eye
 * and the digits that differ.
 *
 * Both take MINOR UNITS. The divide by 100 is the last thing that happens to a
 * figure, and nothing is added up after it.
 */
const board = (minor) => `$${(Math.abs(minor) / 100).toLocaleString('en-JM', { maximumFractionDigits: 0 })}`

const exact = (minor) =>
    new Intl.NumberFormat('en-JM', {
        style: 'currency',
        currency: 'JMD',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(minor / 100)

/** What the bank did on this line, in words — the sign the board does not draw. */
const direction = (line) =>
    line.is_withdrawal ? 'Money out of the account' : 'Money into the account'

/* ------------------------------------------------------------------ */
/* the difference, and what it is made of */
/* ------------------------------------------------------------------ */

const difference = computed(() => props.reconciliation?.difference_minor ?? 0)

/** Everything the estate has claimed as one of its own entries, so far. */
const claimed = computed(() => (props.reconciliation?.movement_minor ?? 0) - difference.value)

const unmatchedCount = computed(() => props.lines.filter((line) => !line.matched).length)

const isCompleted = computed(() => props.reconciliation?.status === 'completed')

/*
 * The period, and a word that is still a sentence when there is no statement at
 * all. Every one of the six states can be FORCED in local, including onto a
 * payload that carries no reconciliation, and a screen that reads "The undefined
 * statement has no lines" has stopped being a state and become a defect.
 */
const periodLabel = computed(() => props.reconciliation?.period ?? 'current')

/** The proposal the server offers for one bank line, or null when there is none. */
const proposalFor = (lineId) => props.ledger_side.find((entry) => entry.line_id === lineId) ?? null

/**
 * Why a line cannot be matched or unmatched, or null when it can.
 *
 * A COMPLETED PERIOD IS CLOSED TO BOTH. Unpicking a match inside a signed-off
 * month would change a figure somebody has already put their name to, and
 * re-opening it is a decision rather than a correction — so the refusal says so
 * instead of offering a control that the service will reject.
 */
const matchBlockedBy = computed(() => {
    if (isCompleted.value) {
        return `The ${props.reconciliation.period} reconciliation is completed and its matching is closed. Re-opening a signed-off period is a decision, not a correction.`
    }

    return props.canMatch ? null : props.blockedReason
})

/** Why an unmatched line cannot be matched YET — the proposal, or the lack of one. */
const proposalBlockedBy = (line) => {
    if (matchBlockedBy.value !== null) {
        return matchBlockedBy.value
    }

    const proposal = proposalFor(line.id)

    if (proposal === null || proposal.entry_ref === null) {
        return 'No posted entry on this account matches this line, so there is nothing to claim it as. A bank-only item — a fee, a charge — needs a journal entry raised for it first, and an adjusting entry posts as a journal rather than as a change to anything already posted.'
    }

    return null
}

/**
 * Why the period cannot be signed off, or null when it can.
 *
 * THE OUTSTANDING FIGURE IS IN THE SENTENCE, because "there is a difference"
 * tells a treasurer only that something is wrong and nothing about what — and the
 * amount on its own is usually enough to say which line it is. The same sentence
 * the service refuses with, said before the press rather than after it.
 *
 * The permission clause is AUTHORED. The payload carries one `blockedReason` and
 * it is about matching, which needs Accounting create; completing needs approve,
 * because a signed-off period is one nobody re-opens (D-013). Naming the wrong
 * permission would send somebody to ask for access they already have.
 */
const completeBlockedBy = computed(() => {
    if (isCompleted.value) {
        return `The ${props.reconciliation.period} reconciliation is already completed. Completing it again would restate a period that is signed off.`
    }

    if (!props.canComplete) {
        return 'Completing a reconciliation signs off a period nobody re-opens, so it needs Accounting approve access. You are able to read this screen and to match its lines.'
    }

    if (difference.value !== 0) {
        return `The ${props.reconciliation.period} reconciliation is out by ${exact(Math.abs(difference.value))} across ${unmatchedCount.value} unmatched line${unmatchedCount.value === 1 ? '' : 's'} and cannot be completed. The difference is the movement the bank reports less every line the estate has claimed as one of its own entries; signing that off is exactly what a reconciliation exists to prevent.`
    }

    return null
})

/* ------------------------------------------------------------------ */
/* the acts */
/* ------------------------------------------------------------------ */

/** Claim that this bank line and this posted entry are the same event. */
const match = (line) => {
    const proposal = proposalFor(line.id)

    if (proposalBlockedBy(line) !== null || proposal === null) {
        return
    }

    router.post(
        `${accountingPath.value}/reconciliation/lines/${line.id}/match`,
        { entry_ref: proposal.entry_ref },
        { preserveScroll: true }
    )
}

/** Take the claim back. The entry is untouched, because it always was. */
const unmatch = (line) => {
    if (matchBlockedBy.value !== null) {
        return
    }

    router.post(`${accountingPath.value}/reconciliation/lines/${line.id}/unmatch`, {}, { preserveScroll: true })
}

const complete = () => {
    if (completeBlockedBy.value !== null) {
        return
    }

    router.post(
        `${accountingPath.value}/reconciliation/${props.reconciliation.id}/complete`,
        {},
        { preserveScroll: true }
    )
}
</script>

<template>
    <Head title="Bank reconciliation" />

    <EstateConsole title="Bank reconciliation" :estate-name="estate.name" active="accounting">
        <template #actions>
            <button type="button" class="btn-outline-sm" disabled :title="reasons.import">
                <svg viewBox="0 0 24 24" fill="none">
                    <path
                        d="M12 16V4m0 0L8 8m4-4l4 4M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        style="transform: rotate(180deg); transform-origin: center"
                    />
                </svg>
                <span>Import statement</span>
            </button>
        </template>

        <div class="subnav">
            <template v-for="tab in tabs" :key="tab.key">
                <Link v-if="tab.href" :href="tab.href" class="subnav-item">{{ tab.label }}</Link>
                <div v-else class="subnav-item active" aria-current="page">{{ tab.label }}</div>
            </template>
        </div>

        <!--
          Neither is on the board, and neither is drawn on a fresh GET — a board
          is a still image and nothing has ever been pressed on it. They are here
          because a match that lands in silence and one refused in silence are
          the same screen to whoever pressed the button.
        -->
        <p v-if="page.props.flash.success" class="recon-flash">{{ page.props.flash.success }}</p>
        <p v-if="page.props.errors.entry_ref" class="recon-refusal">{{ page.props.errors.entry_ref }}</p>
        <p v-if="page.props.errors.line" class="recon-refusal">{{ page.props.errors.line }}</p>
        <p v-if="page.props.errors.reconciliation" class="recon-refusal">
            {{ page.props.errors.reconciliation }}
        </p>

        <SkeletonRows v-if="state.isLoading.value" :rows="5" :columns="3" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Accounting is not part of your role’s access"
            body="A reconciliation is an assertion about the estate’s bank account, so the module opens only to roles that hold Accounting. A Property Manager sees the vendors they commission and not the money, by platform rule rather than estate preference."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The statement could not be read"
            body="The estate’s accounts did not answer. No match has been made and none has been undone — every line stands exactly as it did before this screen opened."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isEmptyFiltered.value"
            variant="filtered"
            :title="`The ${periodLabel} statement has no lines`"
            body="A statement was opened for this period and nothing was transcribed into it, so there is nothing to match and nothing to reconcile against. An empty month is a statement that did not arrive, not an account that did not move."
        />

        <!--
          Also the fallback when there is no statement at all, however this
          screen was reached: forcing a state in local can put `populated` in
          front of a payload that carries no reconciliation, and a page that
          renders half a summary off a null is worse than the empty it is
          standing in for.
        -->
        <EmptyState
            v-else-if="state.isEmpty.value || reconciliation === null"
            variant="first-use"
            title="No bank statement has been entered"
            body="A reconciliation compares two independent records of one account, and only one of them is in this system. Until a statement is entered there is nothing to set the ledger against — and nothing to sign off."
        />

        <template v-else>
            <div class="scope-banner">
                <svg viewBox="0 0 24 24" fill="none">
                    <path
                        d="M9 12l2 2 4-4"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" />
                </svg>
                <div>
                    <div class="sb1">{{ scope.title }}</div>
                    <div class="sb2">{{ scope.body }}</div>
                </div>
            </div>

            <div class="recon-layout">
                <div>
                    <div class="recon-col-head">
                        Bank statement — {{ reconciliation.period }}
                        <div class="cnt">{{ counts.unmatched }} unmatched</div>
                    </div>

                    <div style="background: var(--white); border: 1px solid var(--navy-100); border-radius: 16px; overflow: hidden">
                        <div v-for="line in lines" :key="line.id" class="recon-row">
                            <div class="rr-txt">
                                <div class="rr1">{{ line.description }}</div>

                                <!--
                                  The bank's own second clause, appended with a
                                  middot exactly as the board draws "Aug 5 · prior
                                  invoice". The entry reference joins it on a
                                  matched line — the board's matched rows carry
                                  only the date, and a claim the reader cannot see
                                  is a claim they cannot check.
                                -->
                                <div class="rr2">
                                    {{ [line.date, line.note, line.matched_entry_ref].filter(Boolean).join(' · ') }}
                                </div>
                            </div>

                            <div class="rr-amt" :title="direction(line)">{{ board(line.amount_minor) }}</div>

                            <!--
                              MATCHED: navy, a tick, and pressing it takes the
                              claim back. The board draws no undo at all, and
                              there has to be one — a treasurer will pair the
                              wrong two items, and the match is stored on this
                              side precisely so it can be removed without
                              touching anything posted.
                            -->
                            <button
                                v-if="line.matched"
                                type="button"
                                class="rr-check"
                                :disabled="matchBlockedBy !== null"
                                :title="
                                    matchBlockedBy ??
                                    `Matched to entry ${line.matched_entry_ref}. Press to unmatch — the entry itself is not touched.`
                                "
                                @click="unmatch(line)"
                            >
                                <svg viewBox="0 0 24 24" fill="none">
                                    <polyline
                                        points="20 6 9 17 4 12"
                                        stroke="currentColor"
                                        stroke-width="3"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                    />
                                </svg>
                            </button>

                            <!--
                              UNMATCHED: the board's amber plus, kept as the
                              board's own inline override rather than turned into
                              a class the stylesheet does not have.
                            -->
                            <button
                                v-else
                                type="button"
                                class="rr-check"
                                style="background: var(--amber-100)"
                                :disabled="proposalBlockedBy(line) !== null"
                                :title="
                                    proposalBlockedBy(line) ??
                                    `Claim this line as entry ${proposalFor(line.id)?.entry_ref} — ${proposalFor(line.id)?.label}.`
                                "
                                @click="match(line)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" style="color: var(--amber-700)">
                                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div>
                    <div class="recon-col-head">
                        GL transactions — unmatched
                        <div class="cnt">{{ counts.to_match }} to match</div>
                    </div>

                    <div style="background: var(--white); border: 1px solid var(--navy-100); border-radius: 16px; overflow: hidden">
                        <div v-for="entry in ledger_side" :key="entry.line_id" class="recon-row">
                            <div class="rr-txt">
                                <div class="rr1">{{ entry.label }}</div>
                                <div class="rr2">{{ entry.hint }}</div>
                            </div>

                            <div class="rr-amt">{{ board(entry.amount_minor) }}</div>

                            <!--
                              The same act as the plus opposite, from the other
                              side of the pair: attach this entry to the bank line
                              it is proposed against. Inert where the server found
                              no entry to propose, because there is nothing to
                              attach — that row is a bank-only item and its next
                              act is a journal.
                            -->
                            <button
                                type="button"
                                class="rr-check"
                                :disabled="matchBlockedBy !== null || entry.entry_ref === null"
                                :title="
                                    matchBlockedBy ??
                                    (entry.entry_ref === null
                                        ? 'There is no posted entry behind this line, so there is nothing to link. It needs a journal entry raised for it first.'
                                        : `Link entry ${entry.entry_ref} to its bank line.`)
                                "
                                @click="match({ id: entry.line_id })"
                            >
                                <svg viewBox="0 0 24 24" fill="none">
                                    <path
                                        d="M14.7 6.3a3 3 0 1 0-4.2 4.2l-7 7 2.3 2.3 7-7a3 3 0 0 0 4.2-4.2l-2.1 2.1-2-2z"
                                        stroke="currentColor"
                                        stroke-width="1.6"
                                        stroke-linejoin="round"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!--
              AUTHORED, and it is the point of the screen. The board draws no
              opening balance, no closing balance and no difference, so a reader
              of the board cannot tell whether the month reconciles — and the one
              rule this screen enforces would be invisible. It sits below the two
              columns so nothing the board does draw is moved.
            -->
            <div class="recon-summary" :class="{ 'recon-summary--clear': difference === 0 }">
                <div class="rs-figures">
                    <div class="rs-item">
                        <div class="rs-v">{{ exact(reconciliation.opening_minor) }}</div>
                        <div class="rs-l">Statement opening</div>
                    </div>
                    <div class="rs-item">
                        <div class="rs-v">{{ exact(reconciliation.closing_minor) }}</div>
                        <div class="rs-l">Statement closing</div>
                    </div>
                    <div class="rs-item">
                        <div class="rs-v">{{ exact(reconciliation.movement_minor) }}</div>
                        <div class="rs-l">Movement the bank reports</div>
                    </div>
                    <div class="rs-item">
                        <div class="rs-v">{{ exact(claimed) }}</div>
                        <div class="rs-l">Claimed as estate entries</div>
                    </div>
                    <div class="rs-item rs-item--diff">
                        <div class="rs-v">{{ exact(Math.abs(difference)) }}</div>
                        <div class="rs-l">Difference</div>
                    </div>
                </div>

                <div class="rs-foot">
                    <p class="rs-note">
                        <template v-if="isCompleted">
                            {{ reconciliation.period }} is signed off. Its matching is closed, and re-opening a
                            completed period is a decision rather than a correction.
                        </template>
                        <template v-else-if="difference === 0">
                            Every line the bank reports is accounted for by a posted entry. {{ reconciliation.period }}
                            can be signed off.
                        </template>
                        <template v-else>
                            {{ exact(Math.abs(difference)) }} of what the bank reports is not yet claimed by any
                            posted entry, across {{ unmatchedCount }} unmatched line{{ unmatchedCount === 1 ? '' : 's' }}.
                            The period cannot be completed until that reaches zero.
                        </template>
                    </p>

                    <button
                        type="button"
                        class="btn-primary-sm"
                        :disabled="completeBlockedBy !== null"
                        :title="completeBlockedBy ?? `Sign off the ${reconciliation.period} reconciliation.`"
                        @click="complete"
                    >
                        <svg viewBox="0 0 24 24" fill="none">
                            <polyline
                                points="20 6 9 17 4 12"
                                stroke="currentColor"
                                stroke-width="3"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                        <span>{{ isCompleted ? 'Completed' : 'Complete reconciliation' }}</span>
                    </button>
                </div>
            </div>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only, and the removals name the elements that need them.
 *
 * The board draws every control on this screen as a <div>; here they are links
 * and buttons, which arrive wearing an underline, a border, buttonface grey and
 * the browser's own font.
 *
 * `border` IS DELIBERATELY NOT RESET ON .btn-outline-sm. The board gives it a
 * 1.5px navy-200 edge, and a scoped element selector outranks a single class —
 * so `button.btn-outline-sm { border: 0 }` would strip the outline that IS the
 * variant. The browser's own border never needed removing: an author rule
 * already beats the user agent's. See the note in Gemini/Reports/ReportShell.vue.
 * .rr-check and .subnav-item declare no border of their own, so those two bring
 * one back and say so here.
 */
a.subnav-item {
    text-decoration: none;
}

button.subnav-item {
    border: 0;
    background: none;
    font-family: inherit;
}

button.btn-outline-sm {
    font: inherit;
    cursor: pointer;
}

button.btn-primary-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.rr-check {
    border: 0;
    padding: 0;
    cursor: pointer;
}

button[disabled] {
    cursor: not-allowed;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws no summary, no flash and no refusal,
 * and this screen has to be able to say all three. Kept to the tokens the boards
 * define and to shapes they already use — the card is .kpi-card's white surface
 * and the figure/label pair is .k-val over .k-lbl at the strip's smaller size.
 */
.recon-flash,
.recon-refusal {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 14px;
}

.recon-flash {
    background: var(--green-100);
    color: var(--green-700);
}

.recon-refusal {
    background: var(--red-100);
    color: var(--red-700);
}

.recon-summary {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 15px 18px;
    margin-top: 16px;
}

.rs-figures {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 14px;
}

.rs-v {
    font-family: 'Poppins', sans-serif;
    font-size: 15px;
    font-weight: 700;
    color: var(--navy-900);
}

.rs-l {
    font-size: 10.5px;
    color: var(--slate-500);
    font-weight: 600;
    margin-top: 2px;
}

/* The difference is the figure the period turns on, so it is the one that is
 * coloured — amber while it stands, and left in the navy of a settled figure
 * once the month is accounted for. */
.rs-item--diff .rs-v {
    color: var(--amber-700);
}

.recon-summary--clear .rs-item--diff .rs-v {
    color: var(--green-700);
}

.rs-foot {
    display: flex;
    align-items: center;
    gap: 18px;
    margin-top: 14px;
    padding-top: 13px;
    border-top: 1px solid var(--navy-100);
}

.rs-note {
    flex: 1;
    font-size: 11px;
    color: var(--slate-600);
    line-height: 1.55;
    margin: 0;
}
</style>
