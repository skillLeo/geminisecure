<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * Vetting who may stand — board screen community-admin-10.
 *
 * THIS IS A DECISION ABOUT A MEMBER'S RIGHT TO STAND FOR OFFICE IN THEIR OWN
 * COMMUNITY, which is why every refusal on it carries a reason and why the
 * reason is required by the service rather than by this form. "Rejection always
 * carries a recorded reason" — the Build Spec — and a rejection with nothing
 * after it cannot be explained to the member it is about.
 *
 * THREE OUTCOMES, NOT TWO. Board 10 draws a tick and a cross, and the service
 * carries a third: more information requested. A candidate waiting on a
 * proposer's signature has not been refused, and folding that into `rejected`
 * would put a refusal on a member's permanent record for a missing form. It is
 * offered inside the refusal panel rather than as a third icon, because the
 * board draws two controls and because the two outcomes take the same field —
 * what is missing, or what is wrong.
 *
 * THE PHASE IS READ OFF THE PROPERTY AND NOT OFF THE NOMINATION. Board 10 puts
 * Sonia Campbell at "Phase 4 · Lot 88" and Keith Walters at "Phase 2 · Lot 63";
 * the estate's own map puts both lots in Phase 1, and board 4 puts two different
 * households at Lot 12 in two different phases, so the boards' phase labels
 * cannot all be true of one estate. One estate has one map. Recorded as D-051's
 * third residual and reproduced here rather than resolved in the page.
 *
 * "REJECTED — ARREARS >90 DAYS" IS A RECORDED DECISION, NOT A COMPUTED ONE. The
 * badge prints `status_label`, which is the reason the returning officer wrote
 * down; the arrears ageing that justified it is snapshotted onto the nomination
 * when the decision is taken, so a candidate who clears their balance next week
 * cannot make the record say they were never in arrears. The sub-ledger and the
 * badge are allowed to disagree, and D-051's fourth residual says which wins.
 *
 * NOTHING HERE TOUCHES A BALLOT PAPER. A nomination is who may appear on it; a
 * mark is what a household chose, and the two are separate tables that share no
 * column. This screen names candidates and never voters.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    year: { type: Number, required: true },

    /** The position filter in force, or '' for the whole set. */
    filter: { type: String, required: true },

    /** One chip per position that has a nomination against it. */
    positions: { type: Array, required: true },

    /** Spans the UNFILTERED set — board 10 draws "5 pending review" over a slice holding two. */
    pending: { type: Number, required: true },
    rows: { type: Array, required: true },

    canDecide: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
})

/*
 * Boards 9 to 12 share this sheet, and the ten Estate Console sheets share
 * nothing with each other — naming the wrong one renders this screen with no
 * table, no status badges and no filter chips at all.
 */
useWireframe('community-admin-03-elections-nominations-results-and-meetings')

const page = usePage()

/*
 * All six. This is the one governance screen with a real filter on it, so
 * `empty-filtered` is a state a reader can actually reach — by picking a
 * position whose only nomination has just been withdrawn — and it needs its own
 * sentence: "nothing has been lodged yet" and "nothing matches this seat" are
 * different facts, and showing the first to somebody who has clicked a chip
 * tells them their data is gone.
 */
const state = useScreenState({
    rows: () => props.rows.length,
    filtered: () => props.filter !== '',
})

const retry = () => router.reload()

/*
 * Where this console is rooted, read off the page's own URL. Production gives
 * each estate its own hostname and no prefix; local serves them all from one
 * host with the estate key in the path, so a hard-coded root is correct in
 * exactly one of the two — and cutting the URL that served this page cannot
 * address an estate other than the one already open.
 */
const root = computed(() => {
    const cut = page.url.indexOf('/governance')

    return cut === -1 ? '' : page.url.slice(0, cut)
})

const governance = (suffix) => `${root.value}/governance${suffix}`

/** The control room, which is the screen the reader pressed "Review nominations" on. */
const controlRoomHref = computed(() => governance(`/elections/${props.year}`))

const listHref = computed(() => governance(`/elections/${props.year}/nominations`))

/** This list, filtered to one seat — or back to the whole set. */
const chipHref = (position) =>
    position === '' ? listHref.value : `${listHref.value}?position=${encodeURIComponent(position)}`

const nominationPath = (id, suffix) => governance(`/nominations/${id}${suffix}`)

/* ------------------------------------------------------------------ */
/* the three decisions */
/* ------------------------------------------------------------------ */

/**
 * Why a decided row's "View" leads nowhere yet.
 *
 * Board 10 draws it as a link and there is no screen behind it. What such a
 * screen would hold is real and is not on this row — the proposer's form, the
 * eligibility snapshot the decision was taken against, the seconder's
 * confirmation — and inventing a destination for it would be worse than saying
 * plainly that it is not built.
 */
const NO_NOMINATION_SCREEN_YET =
    'Not built yet — a nomination’s own screen would show the proposer’s form, the seconder’s confirmation and the arrears ageing snapshotted at the moment the decision was taken. The decision itself is recorded here, and the reason for it is printed in the badge beside this link.'

/** The viewer's own access, which is true of all three decisions. */
const accessBlockedBy = computed(() => (props.canDecide ? null : props.blockedReason))

/** Which row's refusal panel is open, by nomination id, or null. */
const deciding = ref(null)

/*
 * ONE FORM FOR BOTH REASONED OUTCOMES. `reject` and `query` take the same field
 * — what is wrong, or what is missing — and the service refuses an empty one on
 * either. Two forms holding one sentence would be two places for it to drift,
 * and the officer is writing the same note whichever button they end on.
 */
const decision = useForm({ reason: '' })

/** The row the panel is about, so the heading can name the candidate. */
const decidingRow = computed(() => props.rows.find((row) => row.id === deciding.value) ?? null)

const openDecision = (id) => {
    deciding.value = deciding.value === id ? null : id
    decision.reason = ''
    decision.clearErrors()
}

const closeDecision = () => {
    deciding.value = null
}

/**
 * Put a candidate on the paper.
 *
 * No panel and no reason, because there is nothing to explain: the eligibility
 * snapshot is written on an acceptance too, exactly as it is on a refusal. A
 * record that only kept the arithmetic behind refusals could not answer the more
 * awkward question — why this candidate was let through — and that is the one a
 * losing candidate asks.
 */
const accept = (id) => {
    if (accessBlockedBy.value !== null) {
        return
    }

    router.post(nominationPath(id, '/accept'), {}, { preserveScroll: true })
}

/**
 * Refuse, or ask for more, with the same sentence.
 *
 * A form submits on Enter from any field and a disabled button does not stop
 * it, so both branches re-ask what their buttons ask. The routes are gated on
 * `estate.governance.update` and the service refuses an empty reason
 * independently — this is only about not sending a press that will bounce.
 */
const submitDecision = (verb) => {
    if (accessBlockedBy.value !== null || deciding.value === null) {
        return
    }

    decision.post(nominationPath(deciding.value, verb === 'query' ? '/query' : '/reject'), {
        preserveScroll: true,
        onSuccess: () => closeDecision(),
    })
}
</script>

<template>
    <Head :title="`Nominations — ${year} Election`" />

    <EstateConsole :title="`Nominations — ${year} Election`" :estate-name="estate.name" active="governance">
        <!--
          The back chevron, drawn the way this board draws it: a 34px navy
          circle whose declarations live inline because the sheet has no class
          for it. Copied verbatim onto a real link, so it can be clicked,
          focused and opened in its own tab.

          `flex:0 0 auto` is the one addition: the board's <div> sits in a flex
          topbar with room to spare, and an anchor in the same row would shrink
          below 34px on a narrow viewport the board never had to survive.
        -->
        <template #lead>
            <Link
                :href="controlRoomHref"
                style="
                    width: 34px;
                    height: 34px;
                    border-radius: 50%;
                    background: var(--navy-100);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    flex: 0 0 auto;
                "
                title="Back to the election control room"
                aria-label="Back to the election control room"
            >
                <svg viewBox="0 0 24 24" fill="none" style="width: 16px; height: 16px; color: var(--navy-700)">
                    <polyline
                        points="15 18 9 12 15 6"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                </svg>
            </Link>
        </template>

        <SkeletonRows v-if="state.isLoading.value" :rows="5" :columns="6" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Nominations are not part of your role’s access"
            body="A nomination names a household, who proposed them and the arrears ageing their eligibility was judged against, so it opens only to roles that hold Governance. A committee officer or the estate administrator can grant it from the role access matrix."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The nominations could not be read"
            body="The estate database did not answer. Nobody has been approved, nobody has been refused and no candidate has been put on or taken off a paper — this is a read that failed, and re-running it is safe."
            action-label="Try again"
            @action="retry"
        />

        <template v-else>
            <p v-if="page.props.flash?.success" class="gov-flash">{{ page.props.flash.success }}</p>
            <p v-if="page.props.errors?.nomination" class="gov-refusal">{{ page.props.errors.nomination }}</p>

            <div style="display: flex; gap: 10px; margin-bottom: 16px">
                <!--
                  The chip row, generated from the positions that actually have
                  a nomination against them — which is the rule brief-10 states
                  for it, and it produces thirteen chips where the board draws
                  two. Board 10's own slice holds five rows covering two seats;
                  the estate has fourteen positions and twenty-two candidates.

                  It scrolls rather than wrapping. Wrapping would put the table
                  a line lower than the approved design, and clipping would
                  leave a filter an estate cannot use on a seat it is actually
                  electing. Every chip is a real link, so tabbing to one scrolls
                  it into view; the bar itself is hidden because a visible one
                  would add its own height to the row and move the table anyway.
                -->
                <div class="chip-scroll">
                    <Link
                        :href="chipHref('')"
                        class="f-chip2"
                        :class="{ active: filter === '' }"
                        title="Every nomination lodged for this election, whatever seat it is for."
                    >
                        All positions
                    </Link>
                    <Link
                        v-for="position in positions"
                        :key="position"
                        :href="chipHref(position)"
                        class="f-chip2"
                        :class="{ active: filter === position }"
                        :title="`Show only the candidates standing for ${position}.`"
                    >
                        {{ position }}
                    </Link>
                </div>

                <!--
                  The counter, and it is a readout rather than a control: it
                  spans the unfiltered set, so pressing it could only ever show
                  what "All positions" already shows. The board draws it as a
                  chip in the same row, and it stays one.
                -->
                <div
                    class="f-chip2"
                    style="margin-left: auto; background: var(--amber-100); color: var(--amber-700)"
                    :title="`${pending} nomination${pending === 1 ? ' has' : 's have'} been lodged and not yet decided, across every seat on this election.`"
                >
                    {{ pending }} pending review
                </div>
            </div>

            <!--
              The refusal panel, authored because the board draws a screen
              nobody has pressed anything on. Closed on a fresh GET, so it
              touches none of this screen's geometry. One at a time: each
              decision is about one candidate, and two open forms would ask
              which of them the Enter key belongs to.
            -->
            <form v-if="decidingRow !== null" class="act-panel" @submit.prevent="submitDecision('reject')">
                <div class="act-head">
                    Refusing {{ decidingRow.name }} for {{ decidingRow.position }}, or asking for more before deciding
                    either way. A refusal goes on a member’s record and has to be explainable to them; a request for
                    more information does not, and can still become either of the other two.
                </div>

                <div class="act-field">
                    <label for="decision-reason">
                        The reason, in the words the candidate will be given — "arrears &gt;90 days", "proposer is not
                        a registered householder", "seconder’s signature missing"
                    </label>
                    <input
                        id="decision-reason"
                        v-model="decision.reason"
                        type="text"
                        required
                        maxlength="190"
                        placeholder="arrears >90 days"
                    />
                </div>

                <div v-if="decision.errors.reason" class="act-error">{{ decision.errors.reason }}</div>

                <div class="act-actions">
                    <button
                        type="submit"
                        class="btn-primary-sm"
                        :disabled="accessBlockedBy !== null || decision.processing"
                        :title="
                            accessBlockedBy ??
                            'Refuse this nomination and record why. The arrears ageing the estate holds today is snapshotted alongside it, so the decision can be reproduced later.'
                        "
                    >
                        <span>{{ decision.processing ? 'Recording…' : 'Reject' }}</span>
                    </button>

                    <button
                        type="button"
                        class="btn-outline-sm"
                        :disabled="accessBlockedBy !== null || decision.processing"
                        :title="
                            accessBlockedBy ??
                            'Ask the candidate for what is missing. This is not a refusal and does not go on their record as one — it can still become an approval or a rejection.'
                        "
                        @click="submitDecision('query')"
                    >
                        <span>Ask for more information</span>
                    </button>

                    <button type="button" class="text-link-sm" @click="closeDecision">Cancel</button>
                </div>
            </form>

            <!--
              Empty because a chip excluded everything, which is a different
              screen from an election nobody has been nominated for: one offers
              the whole list back, the other says nothing has been lodged.
            -->
            <EmptyState
                v-if="state.isEmptyFiltered.value"
                variant="filtered"
                :title="`No candidate is standing for ${filter}`"
                body="Nominations have been lodged for this election, but none of them is for this seat — which is a fact the returning officer needs before the window shuts, because a seat with no candidate cannot be filled by the ballot."
                action-label="Show every position"
                @action="router.get(listHref)"
            />

            <EmptyState
                v-else-if="state.isEmpty.value"
                variant="first-use"
                title="No nominations have been lodged"
                body="Nobody has been put forward for any seat on this election yet. Nominations are lodged by householders while the window is open, and the control room shows how long that is — there is nothing here to vet until one arrives."
            />

            <table v-else class="data-table">
                <thead>
                    <tr>
                        <th>Candidate</th>
                        <th>Position sought</th>
                        <th>Nominator</th>
                        <th>Seconder</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id">
                        <td>
                            <div class="res-cell">
                                <div class="res-avatar">{{ row.initials }}</div>
                                <div>
                                    <div class="res-name">{{ row.name }}</div>
                                    <!-- The phase is the PROPERTY's, not the
                                         nomination's. See the note at the top. -->
                                    <div class="res-sub">{{ row.sub }}</div>
                                </div>
                            </div>
                        </td>

                        <td>{{ row.position }}</td>
                        <td>{{ row.nominator }}</td>
                        <td>{{ row.seconder }}</td>

                        <td>
                            <!-- "Rejected — arrears >90 days": the status and
                                 the recorded reason in one badge, composed on
                                 the server so the sentence is the one that was
                                 written down. -->
                            <div class="status-badge" :class="row.status">{{ row.status_label }}</div>
                        </td>

                        <td>
                            <!--
                              Status-driven, which is the board's own rule: a
                              pending row gets the tick and the cross, a decided
                              one gets a link. The tick posts outright; the cross
                              opens the panel, because a refusal without a reason
                              is refused by the service and the officer should
                              find that out before they press rather than after.
                            -->
                            <div v-if="row.decidable" class="row-actions">
                                <button
                                    type="button"
                                    class="icon-btn approve"
                                    :disabled="accessBlockedBy !== null"
                                    :title="
                                        accessBlockedBy ??
                                        `Approve ${row.name} onto the ballot paper for ${row.position}. The arrears ageing the estate holds today is recorded alongside, so the estate can explain why this candidate was let through.`
                                    "
                                    :aria-label="`Approve ${row.name}`"
                                    @click="accept(row.id)"
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

                                <button
                                    type="button"
                                    class="icon-btn reject"
                                    :disabled="accessBlockedBy !== null"
                                    :title="
                                        accessBlockedBy ??
                                        `Refuse ${row.name}, or ask for more before deciding. Either way it needs a reason the candidate can be given.`
                                    "
                                    :aria-label="`Refuse ${row.name}, with a reason`"
                                    @click="openDecision(row.id)"
                                >
                                    <svg viewBox="0 0 24 24" fill="none">
                                        <path
                                            d="M6 6l12 12M18 6L6 18"
                                            stroke="currentColor"
                                            stroke-width="2.4"
                                            stroke-linecap="round"
                                        />
                                    </svg>
                                </button>
                            </div>

                            <div v-else class="row-actions">
                                <button
                                    type="button"
                                    class="text-link-sm"
                                    disabled
                                    :title="NO_NOMINATION_SCREEN_YET"
                                >
                                    View
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only, and each removal names the element that needs it.
 *
 * The board draws its chips, its row actions and its back affordance as <div>s;
 * here they are links and buttons, which arrive wearing a border, buttonface
 * grey and the browser's own font. The board's .f-chip2, .icon-btn,
 * .btn-primary-sm and .text-link-sm supply everything visible, and app.css has
 * already taken the UA's underline and blue off every anchor on a board page.
 *
 * THE BORDER RESET NAMES ONLY WHAT THE BOARD GIVES NO BORDER TO.
 * `.btn-outline-sm` carries `border:1.5px solid var(--navy-200)` and a scoped
 * element selector would out-specify that and rub the outline off the control —
 * a real difference from the approved design, small enough to survive a passing
 * measurement. See D-045.
 */
button {
    font: inherit;
}

button.btn-primary-sm,
button.icon-btn,
button.text-link-sm {
    border: 0;
    cursor: pointer;
}

button.text-link-sm {
    background: none;
    padding: 0;
}

button.btn-outline-sm {
    cursor: pointer;
}

button[disabled] {
    cursor: not-allowed;
}

/*
 * The chip row, which the board fits in one line with four chips and this
 * screen has to fit with fifteen.
 *
 * Same height, same gap, same left edge and the same right-aligned counter
 * beside it — the only difference is that the overflow scrolls instead of being
 * cut off. The bar is hidden because a visible one adds its own height to the
 * row on Windows, which would push the table down and move every figure in it;
 * the chips stay reachable because each is a real link and the browser scrolls
 * a focused one into view.
 */
.chip-scroll {
    display: flex;
    gap: 10px;
    flex: 1;
    min-width: 0;
    overflow-x: auto;
    scrollbar-width: none;
}

.chip-scroll::-webkit-scrollbar {
    display: none;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws no flash, no refusal and no panel,
 * because nothing has ever been pressed on it. Kept to the tokens the boards
 * define and to shapes they already use — the panel is the white card with the
 * navy-100 edge that .data-table already is, and the type sizes are .res-sub's
 * and .status-badge's.
 */
.gov-flash,
.gov-refusal {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 14px;
}

.gov-flash {
    background: var(--green-100);
    color: var(--green-700);
}

.gov-refusal {
    background: var(--red-100);
    color: var(--red-700);
}

.act-panel {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 18px;
    margin-bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 11px;
}

.act-head {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-800);
    line-height: 1.5;
    max-width: 760px;
}

.act-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    max-width: 620px;
}

.act-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.act-field input {
    height: 34px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

.act-field input::placeholder {
    color: var(--slate-300);
    opacity: 1;
}

.act-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--red-700);
    line-height: 1.5;
}

.act-actions {
    display: flex;
    align-items: center;
    gap: 14px;
}
</style>
