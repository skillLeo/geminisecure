<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * The election control room — board screen community-admin-09.
 *
 * ONE ELECTION, TWO PAPERS, ONE LIFECYCLE BADGE. Board 9's own subtitle says an
 * election is "Ballot A (Community Executive) & Ballot B (Phase Leadership)",
 * and the stepper above it draws a single stage. `Governance::leadBallot()`
 * resolves that by speaking for the LEAST advanced paper: an election is not at
 * "Voting Open" while one of its ballots is still taking nominations. So this
 * screen runs Ballot B while board 11 tallies Ballot A, which is exactly the
 * pair of moments D-051 seeded.
 *
 * NOT ONE FIGURE HERE IS ARITHMETIC THIS FILE DOES. The four stat boxes, the
 * nine stepper states, the seat labels and the per-position nomination counts
 * all arrive formatted from `Governance::controlRoom()`, which counts them from
 * rows. A screen that recomputed any of them would be a second opinion about an
 * election, and the two would disagree the first time somebody changed one.
 *
 * THE PRIMARY BUTTON IS WHATEVER THIS STAGE'S NEXT ACT IS, and there is only
 * ever one of it. Board 9 catches the election at "Nominations Open", so it
 * draws "Close nominations early" — but the same slot carries "Open voting"
 * once candidates are vetted and "Close voting" once the poll is running,
 * because those are the acts a returning officer takes from this screen and the
 * service will refuse every other one. Where a stage has no wired act, the
 * button says so rather than disappearing: a control room with no control on it
 * reads as a screen that failed to load.
 *
 * THERE IS NO CONTROL HERE THAT CASTS A VOTE, and there is no route that could
 * back one. Voting is a resident act in the resident app; this console runs the
 * election and never marks a paper. Nothing on this screen reads
 * `ballot_receipts` or `ballot_marks` at all — the closest it comes is
 * "ELIGIBLE HOUSEHOLDS", which is a denominator and not a record of anybody
 * having voted.
 *
 * CERTIFICATION IS NOT ON THIS SCREEN even though the payload says whether the
 * viewer holds it. It is board 11's single button, taken beside the tally it
 * declares final, and a second door to an irreversible act — on a screen with
 * no tally on it — is the kind of button somebody presses by mistake once.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    year: { type: Number, required: true },

    /** "2026 Election" — the estate's name is prefixed onto it below. */
    heading: { type: String, required: true },
    subtitle: { type: String, required: true },
    stage: { type: String, required: true },
    stage_label: { type: String, required: true },

    /** Nine steps, each done / current / upcoming. */
    stepper: { type: Array, required: true },
    stats: { type: Array, required: true },
    positions: { type: Array, required: true },
    pending: { type: Number, required: true },

    /** Null for a year with no ballots at all, which is what `empty` draws. */
    ballotId: { type: Number, default: null },
    canCloseEarly: { type: Boolean, required: true },
    certified: { type: Boolean, required: true },

    canRun: { type: Boolean, required: true },

    /*
     * Declared and deliberately not rendered — see the note above. An
     * undeclared prop would fall through as an HTML attribute rather than be
     * ignored, so it is named here with its reason instead of dropped.
     */
    canCertify: { type: Boolean, required: true },

    blockedReason: { type: String, required: true },
    reasons: { type: Object, required: true },
})

/*
 * Which board's stylesheet this page wears. The ten Estate Console boards do
 * NOT share one sheet the way the nine Gemini boards do, so a page that names
 * the wrong one renders with no lifecycle card, no stepper and no stat row at
 * all. Boards 9 to 12 live on this sheet; board 36 lives on another.
 */
useWireframe('community-admin-03-elections-nominations-results-and-meetings')

const page = usePage()

/*
 * Five of the six. The board draws no search box and no filter chips — the
 * position list is the whole position list — so `empty-filtered` cannot occur
 * here, and rendering a "clear the filter" panel on a screen with no filter
 * would invent a control in order to explain a state that does not exist. The
 * composable still carries it and `?_state=` still forces the other five.
 *
 * `empty` is a real state and not a hypothetical: a year nobody has drafted a
 * ballot for returns no positions, no lead ballot and a stepper stuck at Draft.
 */
const state = useScreenState({
    rows: () => props.positions.length,
})

const retry = () => router.reload()

/*
 * Where this console is rooted, read off the page's own URL.
 *
 * Production gives each estate its own hostname and no prefix; local serves
 * every estate from one host with the estate key in the path, as
 * /estate/phoenixpark/governance/elections/2026. The URL that served this page
 * already carries whichever shape this environment uses, so cutting it at
 * /governance is correct in both — a hard-coded root is correct in exactly one,
 * and cutting the current URL cannot address an estate other than the one
 * already open.
 */
const root = computed(() => {
    const cut = page.url.indexOf('/governance')

    return cut === -1 ? '' : page.url.slice(0, cut)
})

const governance = (suffix) => `${root.value}/governance${suffix}`

const nominationsHref = computed(() => governance(`/elections/${props.year}/nominations`))
const resultsHref = computed(() => governance(`/elections/${props.year}/results`))
const meetingsHref = computed(() => governance('/meetings'))

const ballotPath = (suffix) => governance(`/ballots/${props.ballotId}${suffix}`)

/**
 * The board's own card title: the estate, then the election.
 *
 * "Phoenix Park Village 1 — 2026 Election". Composed here rather than on the
 * server because `heading` is the election's name and the estate's name is the
 * shell's — joining them is this card's own presentation of two facts it is
 * already given, not a third fact.
 */
const cardTitle = computed(() => `${props.estate.name} — ${props.heading}`)

/**
 * The board's outline button, with the count inside its label.
 *
 * The parenthetical goes when nothing is pending, rather than reading "(0
 * pending)". A queue with nothing in it is still worth opening — every decided
 * nomination is there — but advertising a nought is how a screen tells somebody
 * there is work waiting when there is not.
 */
const reviewLabel = computed(() =>
    props.pending > 0 ? `Review nominations (${props.pending} pending)` : 'Review nominations'
)

/* ------------------------------------------------------------------ */
/* the one act this stage allows */
/* ------------------------------------------------------------------ */

/*
 * The transitions that have a route behind them, and the ones that do not.
 *
 * `Governance::TRANSITIONS` is the whole lifecycle and this console wires four
 * of its steps: close nominations, open the poll, close the poll, extend it.
 * Two more — putting a draft into nominations, and moving a closed nomination
 * window into vetting — have a service method and no route, and they are drawn
 * inert with that fact on them rather than as a button that answers 404.
 *
 * The remaining two are not omissions at all. Certification is board 11's, and
 * "Tally" advances to it from there; "Certified & Published" is the end.
 */
const NO_OPEN_NOMINATIONS_ROUTE =
    'Not wired yet — putting a drafted ballot into nominations is what starts an election, and it needs the nomination window agreed and set before a household can be told when to lodge one. `Governance::openNominations()` exists and no console route reaches it.'

const NO_VETTING_ROUTE =
    'Not wired yet — moving a closed nomination window into vetting is the step that hands the papers to the returning officer, and this console vets a candidate at a time from the nominations screen rather than moving the whole ballot.'

const CERTIFIED_IS_FINAL =
    'This election is certified. Certification is irreversible — a certified result that turns out to be wrong is put right by running another ballot, not by reopening this one — so there is no stage left for this screen to move it to.'

/**
 * What the primary button does here, and why it cannot when it cannot.
 *
 * ONE ACT PER STAGE, taken from the lifecycle rather than from a preference.
 * The service refuses everything else with a sentence; this reads the same map
 * a step earlier so the refusal is legible before anything is pressed rather
 * than after.
 */
const act = computed(() => {
    if (props.ballotId === null) {
        return { key: 'none', label: 'Close nominations early', blocked: 'There is no ballot for this year yet.' }
    }

    if (props.certified) {
        return { key: 'none', label: 'Certified & published', blocked: CERTIFIED_IS_FINAL }
    }

    switch (props.stage) {
        case 'draft':
            return { key: 'none', label: 'Open nominations', blocked: NO_OPEN_NOMINATIONS_ROUTE }

        case 'nominations_open':
            /*
             * The board's own button. `canCloseEarly` is the service's answer
             * to the same question — the ballot is at Nominations Open and is
             * not certified — and it is asked rather than assumed, because it
             * is the flag `controlRoom()` computes for exactly this control.
             */
            return props.canCloseEarly
                ? { key: 'close_nominations', label: 'Close nominations early' }
                : { key: 'none', label: 'Close nominations early', blocked: CERTIFIED_IS_FINAL }

        case 'nominations_closed':
            return { key: 'none', label: 'Start candidate vetting', blocked: NO_VETTING_ROUTE }

        case 'candidate_vetting':
        case 'campaign_period':
            return { key: 'open', label: 'Open voting' }

        case 'voting_open':
            return { key: 'close', label: 'Close voting' }

        default:
            /*
             * Voting Closed and Tally. Neither is an act taken here: closing
             * the poll already advanced the ballot to the tally in one step —
             * "there is nothing a human does between shutting the poll and
             * counting it" — and what comes next is certification, which is a
             * second pair of hands on a different screen.
             */
            return {
                key: 'results',
                label: 'Review the tally',
                blocked: null,
            }
    }
})

/** The viewer's own access, which is true of every act on this screen. */
const accessBlockedBy = computed(() => (props.canRun ? null : props.blockedReason))

/**
 * Why the primary cannot be pressed, or null when it can.
 *
 * ACCESS IS ASKED FIRST, because it is the refusal that will not change
 * whatever anybody does here. Telling a committee member which stage the ballot
 * has to reach, and only then telling them they were never able to move it,
 * wastes their afternoon.
 */
const primaryBlockedBy = computed(() => {
    if (act.value.key === 'results') {
        return null
    }

    if (accessBlockedBy.value !== null) {
        return accessBlockedBy.value
    }

    return act.value.blocked ?? null
})

/** Which authored panel is open: 'open', 'extend', or none. */
const panel = ref(null)

/*
 * ONE FORM FOR BOTH DATE ACTS, because they take the same field for the same
 * reason: when the poll shuts. `open` sets it and `extend` may only move it
 * later — never earlier, because bringing a closing time forward takes the vote
 * away from every household relying on the date it was given, and the service
 * says so in as many words.
 */
const closing = useForm({ closes_at: '' })

const openPanel = (which) => {
    panel.value = panel.value === which ? null : which
    closing.clearErrors()
}

const closePanel = () => {
    panel.value = null
}

const closeNominations = () => {
    if (primaryBlockedBy.value !== null) {
        return
    }

    router.post(ballotPath('/close-nominations'), {}, { preserveScroll: true })
}

const closeVoting = () => {
    if (primaryBlockedBy.value !== null) {
        return
    }

    router.post(ballotPath('/close'), {}, { preserveScroll: true })
}

/** Press the primary: post outright, or open the panel the act needs a date in. */
const primary = () => {
    if (primaryBlockedBy.value !== null) {
        return
    }

    if (act.value.key === 'close_nominations') {
        closeNominations()

        return
    }

    if (act.value.key === 'open') {
        openPanel('open')

        return
    }

    if (act.value.key === 'close') {
        closeVoting()
    }
}

/**
 * Send the closing time, to whichever of the two routes the panel is for.
 *
 * A form submits on Enter from any field and a disabled button does not stop
 * it. Both routes are gated on `estate.governance.update` and the service
 * refuses an extension that moves the closing time earlier, so this is only
 * about not sending a press that is going to bounce.
 */
const submitClosing = () => {
    if (accessBlockedBy.value !== null || props.ballotId === null) {
        return
    }

    closing.post(ballotPath(panel.value === 'extend' ? '/extend' : '/open'), {
        preserveScroll: true,
        onSuccess: () => closePanel(),
    })
}
</script>

<template>
    <Head :title="`${heading} — control room`" />

    <EstateConsole title="Governance" :estate-name="estate.name" active="governance">
        <template #actions>
            <!--
              The board's topbar action, and the one governance control still
              inert. It is a DATA GAP and not a missing feature: minutes are
              issued as a PDF from the meeting register, but a ballot records no
              meeting, so this screen cannot say whose minutes to issue.
              Guessing would make the estate's formal record of a meeting a
              record of the wrong one.
            -->
            <button type="button" class="btn-outline-sm" disabled :title="reasons.minutes">
                <svg viewBox="0 0 24 24" fill="none">
                    <path
                        d="M12 3v13m0 0l-4-4m4 4l4-4M5 21h14"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                </svg>
                <span>Export minutes</span>
            </button>
        </template>

        <!--
          Three tabs, one module. The tab for the screen the reader is already on
          is text rather than a control, because there is nowhere for it to lead.
          Notices is board 32 — built since this tab was drawn inert — and behind
          the same governance gate.
        -->
        <div class="subnav">
            <Link :href="governance('/notices')" class="subnav-item">Notices</Link>
            <Link :href="meetingsHref" class="subnav-item">Meetings</Link>
            <div class="subnav-item active" aria-current="page">Elections</div>
        </div>

        <!-- The whole screen is one payload, so nothing on it arrives before the rest. -->
        <SkeletonRows v-if="state.isLoading.value" :rows="5" :columns="3" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Elections are not part of your role’s access"
            body="Governance covers the estate’s elections, its meetings and the record of both, so it opens only to roles that hold it. A committee officer or the estate administrator can grant it from the role access matrix."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The election could not be read"
            body="The estate database did not answer. No nomination has been decided, no poll has moved and no ballot has been certified — this is a read that failed, and re-running it is safe."
            action-label="Try again"
            @action="retry"
        />

        <!--
          A first-use empty. A year with no ballot is not a broken election, it
          is an election nobody has drafted yet — and there is no route on this
          console that drafts one, so the panel says where the act actually
          lives rather than offering a button that cannot exist.
        -->
        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            :title="`No ballot has been drafted for ${year}`"
            body="An election is one or more ballot papers against a year, and this year has none — so there are no positions to configure, no nominations to take and no lifecycle to run. Drafting the paper is the first act, and it is not one this console takes yet."
        />

        <template v-else>
            <!--
              What the last act did, and what it was refused for. Neither is on
              the board — a board is a still image and nothing has ever been
              pressed on it — and both are absent on a fresh GET, so neither
              touches this screen's geometry.
            -->
            <p v-if="page.props.flash?.success" class="gov-flash">{{ page.props.flash.success }}</p>
            <p v-if="page.props.errors?.stage" class="gov-refusal">{{ page.props.errors.stage }}</p>

            <div class="lifecycle-card">
                <div class="lc-top">
                    <div>
                        <div class="lc-title">{{ cardTitle }}</div>
                        <!-- "Ballot A (Community Executive) & Ballot B (Phase
                             Leadership) · Returning Officer: Delroy Samuels",
                             composed on the server out of only the parts this
                             election has. -->
                        <div class="lc-sub">{{ subtitle }}</div>
                    </div>

                    <div class="stage-badge">{{ stage_label }}</div>
                </div>

                <!--
                  Nine steps, always, in lifecycle order and never in the order
                  things happened. A stage nobody has reached still occupies its
                  place — the point of the row is that a committee can see what
                  is left, and a stepper that grew as the election ran would hide
                  exactly that.
                -->
                <div class="stepper">
                    <div
                        v-for="step in stepper"
                        :key="step.key"
                        class="step"
                        :class="{ done: step.state === 'done', current: step.state === 'current' }"
                    >
                        <div class="step-dot" :class="step.state">
                            <svg v-if="step.state === 'done'" viewBox="0 0 24 24" fill="none">
                                <polyline
                                    points="20 6 9 17 4 12"
                                    stroke="currentColor"
                                    stroke-width="3"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />
                            </svg>
                            <svg v-else-if="step.state === 'current'" viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="12" r="5" fill="currentColor" />
                            </svg>
                        </div>
                        <div class="step-lbl">{{ step.label }}</div>
                    </div>
                </div>

                <div class="stat-row">
                    <div v-for="stat in stats" :key="stat.label" class="stat-box">
                        <div class="sv">{{ stat.value }}</div>
                        <div class="sl">{{ stat.label }}</div>
                    </div>
                </div>
            </div>

            <div style="display: flex; gap: 12px; margin-bottom: 16px">
                <!-- The board draws this one with no glyph. -->
                <Link :href="nominationsHref" class="btn-outline-sm">
                    <span>{{ reviewLabel }}</span>
                </Link>

                <!--
                  Offered only while the poll is running, because it is the one
                  stage that has two acts: shut it now, or give it longer. The
                  board catches the election three stages earlier and draws
                  neither, so this control is absent from the measured screen.
                -->
                <button
                    v-if="act.key === 'close'"
                    type="button"
                    class="btn-outline-sm"
                    :disabled="accessBlockedBy !== null"
                    :title="
                        accessBlockedBy ??
                        'Give the poll longer. An extension can only move the closing time later — bringing it forward would take the vote away from every household relying on the date it was given, and stopping a poll now is a different decision with its own button.'
                    "
                    @click="openPanel('extend')"
                >
                    <span>Extend voting</span>
                </button>

                <!--
                  The tally is read on board 11 and certified there. This is a
                  link and not the primary act, because the primary act at that
                  stage belongs to a different officer.
                -->
                <Link
                    v-if="act.key === 'results'"
                    :href="resultsHref"
                    class="btn-primary-sm"
                    style="margin-left: auto"
                    title="Read the tally. Certifying it is the President or Vice President's act, and it is taken beside the figures it declares final."
                >
                    <span>{{ act.label }}</span>
                </Link>

                <button
                    v-else
                    type="button"
                    class="btn-primary-sm"
                    style="margin-left: auto"
                    :disabled="primaryBlockedBy !== null"
                    :title="
                        primaryBlockedBy ??
                        (act.key === 'close_nominations'
                            ? 'Stop taking nominations now. The advertised closing date is rewritten to today, so the screen stops counting down to a window that has already shut.'
                            : act.key === 'open'
                              ? 'Open the poll. This is where the eligible-household count stops moving: from here it is the denominator of a turnout figure that ends up on a certificate.'
                              : 'Shut the poll and move straight to the tally. There is nothing a returning officer does in between, and a stage nobody can act on is a stage a screen sits in looking broken.')
                    "
                    @click="primary"
                >
                    <span>{{ act.label }}</span>
                </button>
            </div>

            <!--
              The panel the two date acts need, authored because the board draws
              a screen nobody has pressed anything on. Closed on a fresh GET, so
              it touches none of this screen's geometry.
            -->
            <form v-if="panel !== null" class="act-panel" @submit.prevent="submitClosing">
                <div class="act-head">
                    {{
                        panel === 'extend'
                            ? 'Give the poll longer. It may only ever move later — an extension that brought the closing time forward would be an early close, and an early close is a decision the record should show as one.'
                            : 'Open the poll and say when it shuts. From this moment the eligible-household count is frozen, because it is the denominator of a turnout figure that will end up on a certificate.'
                    }}
                </div>

                <div class="act-field">
                    <label for="closes-at">Voting closes</label>
                    <input id="closes-at" v-model="closing.closes_at" type="datetime-local" required />
                </div>

                <div v-if="closing.errors.closes_at" class="act-error">{{ closing.errors.closes_at }}</div>

                <div class="act-actions">
                    <button
                        type="submit"
                        class="btn-primary-sm"
                        :disabled="closing.processing"
                        :title="
                            panel === 'extend'
                                ? 'Move the closing time later and tell every household that has not voted yet.'
                                : 'Open the poll. Households vote in the resident app; nothing on this console marks a paper.'
                        "
                    >
                        <span>{{ closing.processing ? 'Saving…' : panel === 'extend' ? 'Extend' : 'Open voting' }}</span>
                    </button>
                    <button type="button" class="text-link-sm" @click="closePanel">Cancel</button>
                </div>
            </form>

            <!--
              Every configured position, which is more than the board draws.
              Board 9 shows five rows against its own "14 POSITIONS CONFIGURED",
              and brief-09 concludes from that gap that the list is a partial
              view. The whole set is drawn instead, for D-047's reason: a
              position with no candidate at all is the one thing a returning
              officer has to act on before nominations close, and a list that
              hid it would conceal an empty seat behind a stat box. The card is
              clipped by the shell rather than paged, exactly as the board's own
              .content is.
            -->
            <div
                style="
                    background: var(--white);
                    border: 1px solid var(--navy-100);
                    border-radius: 16px;
                    padding: 6px 18px;
                "
            >
                <div v-for="position in positions" :key="position.id" class="position-row">
                    <div class="pos-name">{{ position.name }}</div>

                    <!--
                      A phase-scoped seat draws the amber scope chip INSTEAD of
                      the seat count, which is the board's own substitution: its
                      name already carries the phase ("Phase 2 · Phase Lead"),
                      so the chip says what kind of seat it is rather than
                      repeating where.
                    -->
                    <div v-if="position.seat_label" class="pos-seats">{{ position.seat_label }}</div>
                    <div v-else class="pos-scope">Phase-scoped</div>

                    <div class="pos-cand">{{ position.nominated_label }}</div>
                </div>
            </div>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only, and each removal names the element that needs it.
 *
 * The board draws its topbar action, its three tabs, its two row buttons and
 * its position rows as <div>s; here they are five buttons and three links. A
 * real button arrives wearing a border, buttonface grey and the browser's own
 * font, and the board's classes supply everything visible. app.css has already
 * taken the UA's underline and blue off every anchor on a board page, so the
 * links need nothing.
 *
 * THE BORDER RESETS NAME ONLY THE CLASSES THE BOARD GIVES NO BORDER TO.
 * `.btn-outline-sm` carries `border:1.5px solid var(--navy-200)` on every board
 * and a scoped element selector would out-specify it and rub the outline off
 * the control entirely — a real difference from the approved design, small
 * enough to survive a passing measurement. See D-045. `.btn-primary-sm`,
 * `.subnav-item` and `.text-link-sm` declare a face and no border, which is why
 * those three are safe to reset.
 */
button {
    font: inherit;
}

button.btn-primary-sm,
button.subnav-item,
button.text-link-sm {
    border: 0;
}

button.subnav-item,
button.text-link-sm {
    background: none;
    padding: 0;
}

/* The tab keeps the board's own padding; only its face is the browser's. */
button.subnav-item {
    padding: 9px 16px;
}

button.btn-primary-sm,
button.btn-outline-sm,
button.subnav-item,
button.text-link-sm {
    cursor: pointer;
}

button[disabled] {
    cursor: not-allowed;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws no flash, no refusal and no panel,
 * because nothing has ever been pressed on it — and every one of those is
 * something this screen has to be able to say. Kept to the tokens the boards
 * define and to shapes they already use: the panel is the white card with the
 * navy-100 edge that the position list already is, and the type sizes are
 * .lc-sub's and .pos-cand's.
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
    max-width: 260px;
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
