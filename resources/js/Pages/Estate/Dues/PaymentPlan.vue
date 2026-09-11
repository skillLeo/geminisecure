<script setup>
import { computed } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * Place a unit on a payment plan — board screen community-admin-07.
 *
 * A PLAN POSTS NOTHING. The receivable was recognised when the charges were
 * raised, so scheduling the debt raises no debit and no credit — `Collections`
 * says so at length and this screen must not imply otherwise. The total is the
 * unit's CURRENT LEDGER BALANCE and nothing else: `Collections::schedule`
 * refuses to read a figure from anywhere but the accounts, which is why the
 * first field is read-only here even though the board draws it as a text input.
 *
 * WHAT IT DOES CHANGE IS A GATE. While an agreed plan is active and being met,
 * `Collections::isProtected` returns true and `RestrictionPolicy` admits guest
 * passes for a household whose arrears flag is still set. That is the whole
 * reason activation is a separate act behind `estate.dues_ledger.approve` while
 * drafting and recording an agreement are merely `create` — and why this screen
 * is a SEQUENCE rather than one button:
 *
 *   1  draw the schedule up      POST .../payment-plan          create
 *   2  record who agreed to it   POST .../payment-plans/{}/agree create
 *   3  activate it               POST .../{}/activate           approve
 *
 * Step 3 is refused by the service until step 2 has happened, and refused again
 * if the name being activated is not the name that agreed. Both refusals are
 * drawn here as a disabled control with the reason on it, so the order is
 * legible before anything is pressed rather than after.
 *
 * THE BOARD IS A MODAL OVER THE LEDGER and this is a route, so the ledger is
 * reproduced behind it exactly as the board draws it: blurred, dimmed and
 * hidden from assistive technology, with three real ways out — the chevron,
 * the close X and Cancel — all of them the unit ledger this was opened from.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    unit: { type: Object, required: true },
    balance_minor: { type: Number, required: true },
    resident_first_name: { type: String, required: true },

    /** The schedule the server would draw today. Null when nothing is owed. */
    preview: { type: Object, default: null },

    /** The latest plan on this unit, in whatever state it is in. */
    plan: { type: Object, default: null },

    /** Whether an active, non-defaulted plan is currently shielding the unit. */
    protected: { type: Boolean, required: true },

    frequencies: { type: Array, required: true },
    maxInstalments: { type: Number, required: true },

    /*
     * This unit's dunning history. Board 7 draws no log — board 8 does — so it
     * is declared and not rendered: the controller sends it for the "Dunning
     * log" tab on the unit ledger, and an undeclared prop on a fragment root
     * would fall through as an HTML attribute rather than be ignored.
     */
    notices: { type: Array, required: true },

    canCreate: { type: Boolean, required: true },
    canApprove: { type: Boolean, required: true },
    note: { type: String, required: true },
    approveReason: { type: String, required: true },
})

/*
 * The screen names its board, because the layout cannot. Ten Community Admin
 * stylesheets sit behind the Estate Console and they disagree with each other,
 * so each is scoped to its own body class and the page that reproduces a board
 * is the only thing that knows which one it is. Without this line the screen
 * renders with no board CSS at all.
 */
useWireframe('community-admin-02-arrears-ledger-payment-plan-and-dunning')

const page = usePage()

/*
 * Where this estate lives, in whichever shape the environment serves.
 *
 * Production gives every estate its own hostname and the path starts at
 * /finance; local serves them all from one host with the estate in the path
 * (/estate/phoenixpark/finance/units/47/payment-plan). Cutting the current URL
 * at /finance yields the right prefix in both, and — unlike a tenant id read
 * off a prop — it cannot address an estate other than the one already open.
 */
const estatePath = computed(() => {
    const cut = page.url.indexOf('/finance')

    return cut === -1 ? '' : page.url.slice(0, cut)
})

const ledgerPath = computed(() => `${estatePath.value}/finance/units/${props.unit.id}`)
const planPath = computed(() => `${ledgerPath.value}/payment-plan`)
const arrearsPath = computed(() => `${estatePath.value}/finance/arrears`)
const agreePath = (id) => `${estatePath.value}/finance/payment-plans/${id}/agree`
const activatePath = (id) => `${estatePath.value}/finance/payment-plans/${id}/activate`

/*
 * Five of the six. A plan screen carries no filter — there is no query that
 * could have excluded anything — so `empty-filtered` cannot happen here, and
 * rendering a "clear the filter" panel on a screen with no filter would invent
 * a control to explain a state that does not exist.
 *
 * `empty` is the one that matters: a unit with no arrears cannot be put on a
 * plan at all. `Collections::schedule` throws rather than returning a schedule
 * for nothing, which is why `preview` arrives null, and this screen says so
 * with the arrears list as the next action instead of drawing a form whose
 * every figure would be zero.
 */
const state = useScreenState({
    rows: () => (props.preview === null ? 0 : 1),
})

const retry = () => router.reload()

/* ------------------------------------------------------------------ */
/* money */
/* ------------------------------------------------------------------ */

/**
 * ONE FORMAT ON THIS SCREEN, unlike the unit ledger beside it.
 *
 * Board 6 mixes two — a hero to the cent and columns to the nearest dollar —
 * because its columns are read DOWN and a run of ".00" is noise between the eye
 * and the digits that differ. Board 7 has no columns. Every figure on it is a
 * figure somebody will be asked to pay on a date: the balance being scheduled,
 * and the instalment that lands each month. Both are quoted to the cent, and
 * rounding either of them here would put a number on an agreement that the
 * bank transfer will not match.
 *
 * Every amount arrives as minor units — integer cents — because that is the
 * only form that survives arithmetic. The divide by 100 happens here, at the
 * last possible moment, and nothing is ever added up after it.
 */
const money = (minor) =>
    new Intl.NumberFormat('en-JM', {
        style: 'currency',
        currency: 'JMD',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(minor / 100)

/* ------------------------------------------------------------------ */
/* which step of the sequence this is */
/* ------------------------------------------------------------------ */

/**
 * The plan that governs this screen, or null when there is none to govern it.
 *
 * A completed, defaulted or cancelled plan is HISTORY: the service refuses to
 * take a new agreement on one and refuses to activate it, and a household that
 * broke the last plan asking for another gets a new plan rather than a second
 * go at the old one. So only a draft or an active plan holds the card; anything
 * else leaves the form free to draw a fresh schedule.
 */
const livePlan = computed(() =>
    props.plan !== null && (props.plan.status === 'draft' || props.plan.status === 'active') ? props.plan : null
)

const isAgreed = computed(
    () => livePlan.value !== null && livePlan.value.agreed_on_label !== null && !!livePlan.value.agreed_by_name
)

/** 'draft' → 'agree' → 'activate' → 'done'. */
const step = computed(() => {
    if (livePlan.value === null) {
        return 'draft'
    }

    if (livePlan.value.status === 'active') {
        return 'done'
    }

    return isAgreed.value ? 'activate' : 'agree'
})

/** Only the first step has anything to type into the schedule. */
const editable = computed(() => step.value === 'draft')

/* ------------------------------------------------------------------ */
/* the schedule */
/* ------------------------------------------------------------------ */

/**
 * The schedule the card draws, from whichever side is authoritative.
 *
 * Before a plan exists that is `preview`, the server's own working. Once one
 * exists it is the plan's STORED instalment rows, because from the moment a
 * household agrees, the schedule is what they consented to rather than what the
 * arithmetic would produce today — the balance moves the next time anything is
 * charged, and an agreement that silently restated itself would be an agreement
 * to nothing.
 *
 * NEITHER SIDE IS RECOMPUTED HERE. The remainder of an uneven division sits on
 * the FIRST instalment, for the two reasons `Collections::schedule` sets out,
 * and a second implementation of that rule in JavaScript is a second thing to
 * get wrong — one that would disagree with the resident's statement and with
 * the rows in the database while looking perfectly reasonable on screen. So the
 * repeating figure and the odd first one are both read off the server: from
 * `instalment_minor` / `first_instalment_minor` on a preview, and from the ends
 * of the stored row list on a plan.
 */
const schedule = computed(() => {
    const live = livePlan.value

    if (live !== null) {
        const rows = live.rows
        const first = rows[0]?.amount_minor ?? live.total_minor
        const repeat = rows[rows.length - 1]?.amount_minor ?? first

        return {
            totalMinor: live.total_minor,
            instalments: live.instalments,
            frequency: live.frequency,
            startLabel: live.starts_on_label,
            firstMinor: first,
            repeatMinor: repeat,
            isEven: first === repeat,
        }
    }

    if (props.preview === null) {
        return null
    }

    return {
        totalMinor: props.preview.total_minor,
        instalments: props.preview.instalments,
        frequency: props.preview.frequency,
        startLabel: props.preview.starts_on_label,
        firstMinor: props.preview.first_instalment_minor,
        repeatMinor: props.preview.instalment_minor,
        isEven: props.preview.is_even,
    }
})

/**
 * The board's read-only sixth field: "$3,100.00 per instalment".
 *
 * Verbatim when the division is clean, which is the case the board was drawn
 * from — J$12,400.00 over four is J$3,100.00 exactly. When it is not, the field
 * says BOTH figures rather than an average nobody will ever be asked to pay:
 * the odd cents are on the first instalment and a household that reads
 * "$3,100.08 per instalment" and transfers that four times has overpaid.
 */
const instalmentText = computed(() => {
    const s = schedule.value

    if (s === null) {
        return ''
    }

    return s.isEven
        ? `${money(s.repeatMinor)} per instalment`
        : `${money(s.firstMinor)} first, then ${money(s.repeatMinor)} × ${s.instalments - 1}`
})

/** The board draws only two middot parts here — no household suffix. */
const backdropMeta = computed(() => [props.unit.phase, props.unit.reference].filter(Boolean).join(' · '))

/* ------------------------------------------------------------------ */
/* the three writes */
/* ------------------------------------------------------------------ */

/*
 * `terms` is deliberately not a field. The service accepts one and the board
 * draws no control for it, and a free-text clause that binds a household is not
 * something to slip onto a screen the design never gave it room on.
 */
const draft = useForm({
    instalments: props.preview?.instalments ?? '',
    starts_on: props.preview?.starts_on ?? '',
})

/*
 * ONE FORM FOR TWO ROUTES, because they take the same field for the same
 * reason. `agree` records whose word the estate is relying on; `activate`
 * requires it again and refuses if it does not match, so that activating
 * "Andrea Fletcher's agreement" against a plan recording somebody else's is a
 * refusal rather than a silent overwrite. Two forms holding one name would be
 * two places for that name to drift.
 */
const agreement = useForm({
    agreed_by_name: props.plan?.agreed_by_name ?? '',
})

/**
 * Ask the SERVER for the schedule again when an input changes.
 *
 * The same GET that served this screen, asked for `preview` alone, with the
 * form's state preserved. It is a round trip for arithmetic a browser could do
 * in a line, and that is the point: the rounding rule — remainder on the first
 * instalment — lives in `Collections::schedule`, is what the draft will
 * actually write, and is what the resident's statement will show. A second copy
 * of it here would be right until the day somebody changed one of them.
 *
 * KNOWN GAP, and it is the backend's rather than this screen's: the GET does
 * not read these two parameters yet — `CollectionsController@plan` hands
 * `planBoard()` the unit alone, and `planBoard()` calls `schedule()` on its
 * defaults — so the preview holds at four monthly instalments until they are
 * threaded through. Nothing here pretends otherwise: the fields keep what was
 * typed, because that is what `Create payment plan` posts and the POST honours
 * it in full, and the note under the derived figure says plainly which schedule
 * is on screen while the two disagree.
 */
const refreshPreview = () => {
    const instalments = Number(draft.instalments)

    if (!Number.isInteger(instalments) || instalments < 1 || instalments > props.maxInstalments) {
        return
    }

    if (draft.starts_on === '') {
        return
    }

    router.reload({
        only: ['preview'],
        data: { instalments, starts_on: draft.starts_on },
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}

/** Whether the schedule on screen is the one the fields describe. */
const previewIsStale = computed(
    () =>
        editable.value &&
        props.preview !== null &&
        (Number(draft.instalments) !== props.preview.instalments || draft.starts_on !== props.preview.starts_on)
)

const createBlockedBy = computed(() => {
    if (livePlan.value !== null) {
        return (
            `${props.unit.reference} already has plan ${livePlan.value.reference} on file. Finish or default ` +
            'that one before drawing up another: two live plans over one balance is two schedules a household ' +
            'can be held to.'
        )
    }

    if (!props.canCreate) {
        return (
            'Drawing a plan up writes a schedule against this unit, so it needs Dues & ledger create access. ' +
            'Your role can read this ledger and not schedule against it.'
        )
    }

    return null
})

const recordBlockedBy = computed(() =>
    props.canCreate
        ? null
        : 'Recording an agreement writes whose word the estate is relying on, so it needs Dues & ledger ' +
          'create access.'
)

/**
 * Why "Activate plan" cannot be pressed, or null when it can.
 *
 * THE VIEWER'S OWN ACCESS IS ASKED FIRST, because it is the refusal that will
 * not change no matter what anyone does on this screen. Telling a committee
 * member to go and get the household's agreement, and only then telling them
 * they were never allowed to activate anything, wastes a phone call.
 */
const activateBlockedBy = computed(() => {
    if (!props.canApprove) {
        return props.approveReason
    }

    const live = livePlan.value

    if (live === null) {
        return (
            'There is no plan to activate yet. A schedule has to exist before a household can agree to it, ' +
            'and an agreement has to exist before anything is lifted at the gate.'
        )
    }

    if (live.status === 'active') {
        return (
            `Plan ${live.reference} is already active. Dunning is paused and the arrears restriction is ` +
            'lifted while every instalment is met; a household that needs different terms gets a new plan ' +
            'once this one has completed or defaulted.'
        )
    }

    if (!isAgreed.value) {
        return (
            `Plan ${live.reference} cannot be activated: the household has not agreed to it. An unagreed plan ` +
            'is a demand, and activating one would lift a gate restriction on terms nobody in the household ' +
            'ever accepted.'
        )
    }

    return null
})

const createPlan = () => {
    if (createBlockedBy.value !== null) {
        return
    }

    draft.post(planPath.value, { preserveScroll: true })
}

const recordAgreement = () => {
    if (livePlan.value === null || recordBlockedBy.value !== null) {
        return
    }

    agreement.post(agreePath(livePlan.value.id), { preserveScroll: true })
}

const activate = () => {
    if (activateBlockedBy.value !== null) {
        return
    }

    agreement.post(activatePath(livePlan.value.id), { preserveScroll: true })
}

/**
 * What Enter in a field does, which is whatever this step's primary act is.
 *
 * A form submits on Enter from any field and a disabled button does not stop
 * it, so each branch re-asks the same question its button asks. The server
 * refuses all three independently — the routes carry `create`, `create` and
 * `approve` — so this is only about not sending a write that will bounce.
 */
const submit = () => {
    if (step.value === 'draft') {
        createPlan()

        return
    }

    if (step.value === 'agree' || step.value === 'activate') {
        recordAgreement()
    }
}

/**
 * The callout the board draws under the fields.
 *
 * Its sentence is kept where it is still the truest thing on the screen — it
 * describes what an active plan DOES — and replaced only where a plan already
 * on file is the thing the reader most needs to know. Both stay inside two
 * lines at this width, because the card is centred: a third line moves every
 * figure above it half a line up.
 *
 * The board writes "her Dues statement". This application knows a name and does
 * not know a pronoun, and guessing one from a name is the kind of thing that is
 * wrong about one resident in twenty and unforgivable about that one — so the
 * possessive goes and the sentence keeps its meaning, exactly as board 35's
 * preview note does.
 */
const planNote = computed(() => {
    const live = livePlan.value

    if (live !== null && live.status === 'active') {
        return (
            `${props.unit.reference} is on plan ${live.reference}, agreed by ${live.agreed_by_name}. ` +
            (props.protected
                ? 'Dunning is paused and the arrears restriction is lifted while it is met.'
                : 'An instalment has been missed, so the restriction is back.')
        )
    }

    if (live !== null) {
        return (
            `Plan ${live.reference} is drafted and lifts nothing yet. It pauses no reminder until ` +
            `${props.resident_first_name} agrees to it and a treasurer activates it.`
        )
    }

    return (
        `${props.resident_first_name} will see this plan and its due dates in the Dues statement. ` +
        'If an instalment is missed, dunning resumes automatically.'
    )
})

const emptyBody = computed(() =>
    props.plan === null
        ? `${props.unit.reference} owes nothing on the ledger today. A payment plan schedules an existing ` +
          'balance and cannot be drawn against a debt that has not been billed — there is nothing here to ' +
          'spread over instalments.'
        : `${props.unit.reference} owes nothing on the ledger today, so there is no balance to schedule. Plan ` +
          `${props.plan.reference} is on file as ${props.plan.status} and stays on the unit's record.`
)
</script>

<template>
    <Head :title="`Payment plan — ${unit.reference}`" />

    <EstateConsole :title="unit.title" :estate-name="estate.name" active="dues_ledger">
        <!--
          The back chevron. The board's stage draws no chevron at all — it is a
          modal over a screen the reader never left — but this is a route, and a
          route with no way back is a dead end. Drawn the way board 06 draws
          its own: a .btn-outline-sm with its border taken off inline, NOT the
          34px navy circle the Gemini detail boards use. That inconsistency is
          the board's, reproduced rather than normalised to its neighbours.

          No disabled twin is needed. The unit's id is on the payload that drew
          this screen, so the ledger it came from is always addressable.
        -->
        <template #lead>
            <Link
                :href="ledgerPath"
                class="btn-outline-sm"
                style="border: none; padding: 0 8px 0 0"
                :title="`Back to ${unit.reference}’s ledger`"
                :aria-label="`Back to ${unit.reference}'s ledger`"
            >
                <svg viewBox="0 0 24 24" fill="none">
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

        <!-- The whole screen is one payload, so nothing on it arrives before the rest. -->
        <SkeletonRows v-if="state.isLoading.value" :rows="6" :columns="2" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="This unit’s plans are not part of your role’s access"
            body="A payment plan names a household, what it owes and what the estate has agreed to accept, so it opens only to roles that hold the Dues & ledger module. Yours does not — a Property Manager, for one, holds neither the ledger nor the money by platform rule rather than estate preference."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="This unit’s balance could not be read"
            body="The estate database did not answer, so there is no balance to schedule against. Nothing has been drafted, nothing has been agreed and nothing at the gate has moved."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="There is nothing to schedule"
            :body="emptyBody"
            action-label="Open the arrears list"
            @action="router.get(arrearsPath)"
        />

        <template v-else>
            <!--
              The ledger behind the modal, blurred and dimmed exactly as the
              board draws it, and hidden from assistive technology: it is a
              picture of where the reader was, not content — every figure on it
              is repeated sharp inside the card.

              The board puts the filter on .content itself. That element belongs
              to the shell, and blurring the one wrapper that holds everything
              .content contains renders the same pixels.
            -->
            <div aria-hidden="true" style="filter: blur(1.5px); opacity: 0.5">
                <div class="ledger-head">
                    <div class="unit-summary">
                        <div class="us-top">
                            <div>
                                <div class="us-name">{{ unit.resident }}</div>
                                <div class="us-unit">{{ backdropMeta }}</div>
                            </div>
                        </div>

                        <div class="us-bal">{{ money(balance_minor) }}</div>
                        <div class="us-bal-lbl">TOTAL OUTSTANDING</div>
                    </div>
                </div>
            </div>

            <!--
              The overlay, scoped to the main column so the sidebar stays sharp.

              The board reaches that by putting position:relative on .main-col
              inline. This page cannot: .main-col belongs to the shell, and even
              if it could, .content sits between the two carrying overflow:hidden
              and would clip the overlay to itself — leaving the topbar undimmed
              and the card 32px low. Left unpositioned, the overlay resolves
              against .app-shell instead, which is an ANCESTOR of the clipping
              box and therefore does not clip it, and one inline offset — the
              sidebar's own 236px — puts it back over exactly the main column
              the board scopes it to.
            -->
            <div class="modal-overlay" style="left: 236px">
                <form class="modal-card" @submit.prevent="submit">
                    <div class="modal-head">
                        <h2>Place {{ unit.reference }} on a payment plan</h2>

                        <Link
                            :href="ledgerPath"
                            class="modal-close"
                            title="Close without scheduling anything"
                            aria-label="Close without scheduling anything"
                        >
                            <svg viewBox="0 0 24 24" fill="none">
                                <path
                                    d="M6 6l12 12M18 6L6 18"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                />
                            </svg>
                        </Link>
                    </div>

                    <div class="modal-sub">{{ note }}</div>

                    <!--
                      What the last write did. The board draws no flash, because
                      a board has never had anything pressed on it — and a
                      sequence whose steps each end in a redirect owes the reader
                      a sentence saying which step just landed. It is only ever
                      on screen immediately after a POST, never on a cold load.
                    -->
                    <p v-if="page.props.flash?.success" class="plan-flash">{{ page.props.flash.success }}</p>

                    <!--
                      READ-ONLY, and the board's own note says why it should not
                      be. The board draws this as an editable field defaulted
                      from the balance; `Collections::draft` takes no total at
                      all, and `schedule()` reads the figure off the ledger,
                      because a plan schedules the debt the accounts say exists
                      rather than one typed into a modal. A field that accepted
                      a different number and scheduled this one would be worse
                      than a field that cannot be typed into.

                      It carries .focused because the board's screenshot was
                      taken with the cursor in it, bound to the field having a
                      value exactly as board 35 binds its own.
                    -->
                    <div class="m-field">
                        <label for="total">Total amount to schedule</label>
                        <div class="m-input" :class="{ focused: schedule.totalMinor > 0 }">
                            <span>
                                <input
                                    id="total"
                                    type="text"
                                    :value="money(schedule.totalMinor)"
                                    readonly
                                    title="The balance this unit's ledger says is outstanding. A plan schedules what the accounts hold, so this figure is read from them rather than typed."
                                />
                            </span>
                        </div>
                    </div>

                    <div class="m-two-col">
                        <div class="m-field">
                            <label for="instalments">Number of instalments</label>
                            <div class="m-input">
                                <span>
                                    <input
                                        v-if="editable"
                                        id="instalments"
                                        v-model="draft.instalments"
                                        type="number"
                                        min="1"
                                        :max="maxInstalments"
                                        required
                                        @change="refreshPreview"
                                    />
                                    <input
                                        v-else
                                        id="instalments"
                                        type="text"
                                        :value="schedule.instalments"
                                        readonly
                                        title="The count the household agreed to. Changing it means a new plan, not an edit to this one."
                                    />
                                </span>
                            </div>
                            <div v-if="draft.errors.instalments" class="field-error">
                                {{ draft.errors.instalments }}
                            </div>
                        </div>

                        <!--
                          Stated, not chosen. `frequencies` carries one entry
                          because the schema records a start date and a count and
                          has nowhere for a frequency to live — see
                          PaymentPlan::FREQUENCY — so a picker here would be a
                          control that cannot change anything.
                        -->
                        <div class="m-field">
                            <label for="frequency">Frequency</label>
                            <div class="m-input">
                                <span>
                                    <input
                                        id="frequency"
                                        type="text"
                                        :value="schedule.frequency"
                                        readonly
                                        :title="`Instalments fall ${frequencies[0].toLowerCase()}, and the schedule records only a start date and a count — there is nowhere for another frequency to live, and inventing one would make the due dates on a resident's statement unreproducible from the record.`"
                                    />
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="m-field">
                        <label for="starts_on">First instalment date</label>
                        <div class="m-input">
                            <span>
                                <input
                                    v-if="editable"
                                    id="starts_on"
                                    v-model="draft.starts_on"
                                    type="date"
                                    required
                                    @change="refreshPreview"
                                />
                                <!--
                                  Once a plan exists the date is printed in the
                                  board's own "MMM D, YYYY", because it is being
                                  read rather than picked and that is the form it
                                  appears in on the resident's statement.
                                -->
                                <input
                                    v-else
                                    id="starts_on"
                                    type="text"
                                    :value="schedule.startLabel"
                                    readonly
                                    title="The day the first instalment falls due. Every later one falls on the same day of the month after it."
                                />
                            </span>
                        </div>
                        <div v-if="draft.errors.starts_on" class="field-error">{{ draft.errors.starts_on }}</div>
                    </div>

                    <!--
                      THE SERVER'S ARITHMETIC, PRINTED. Derived and read-only, so
                      it is drawn as the board draws it — a plain span on a
                      navy-100 ground — rather than as a control nobody can use.
                    -->
                    <div class="m-field">
                        <label>Instalment amount</label>
                        <div class="m-input" style="background: var(--navy-100)">
                            <span>{{ instalmentText }}</span>
                        </div>
                        <div v-if="previewIsStale" class="field-note">
                            The schedule above is still the one the server drew: {{ preview.instalments }} instalments
                            from {{ preview.starts_on_label }}. Creating the plan uses what is typed here.
                        </div>
                    </div>

                    <!--
                      Step 2 of the sequence, and the only field the board has no
                      room for — because the board draws step 1. The service
                      refuses a blank name: an agreement with nobody's name on it
                      cannot be shown to the household it is supposed to bind.
                    -->
                    <div v-if="step === 'agree' || step === 'activate'" class="m-field">
                        <label for="agreed_by_name">Agreed by, for the household</label>
                        <div class="m-input" :class="{ focused: agreement.agreed_by_name !== '' }">
                            <span>
                                <input
                                    id="agreed_by_name"
                                    v-model="agreement.agreed_by_name"
                                    type="text"
                                    required
                                    maxlength="160"
                                    :placeholder="unit.resident"
                                    title="Who in the household accepted these terms. Activation is refused unless the name being activated is the name that agreed."
                                />
                            </span>
                        </div>
                        <div v-if="agreement.errors.agreed_by_name" class="field-error">
                            {{ agreement.errors.agreed_by_name }}
                        </div>
                    </div>

                    <div class="plan-note">
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
                        <p>{{ planNote }}</p>
                    </div>

                    <!--
                      The sequence, drawn as a sequence. Cancel is always the way
                      out; the middle button is whichever write this step is for;
                      and Activate is present from the moment a plan exists —
                      inert with the refusal on it until the household has
                      agreed, so that the order is legible before anything is
                      pressed rather than after.
                    -->
                    <div class="modal-btns">
                        <Link :href="ledgerPath" class="stack-btn outline" title="Leave without scheduling anything">
                            <span>Cancel</span>
                        </Link>

                        <button
                            v-if="step === 'draft'"
                            type="submit"
                            class="stack-btn amber"
                            :disabled="createBlockedBy !== null || draft.processing"
                            :title="createBlockedBy ?? 'Draw the schedule up as a draft. Nothing is agreed and nothing at the gate moves until it is activated.'"
                        >
                            <span>{{ draft.processing ? 'Drawing up…' : 'Create payment plan' }}</span>
                        </button>

                        <button
                            v-if="step === 'agree' || step === 'activate'"
                            type="submit"
                            class="stack-btn primary"
                            :disabled="recordBlockedBy !== null || agreement.processing"
                            :title="recordBlockedBy ?? 'Record that the household accepted these terms, and whose word that is.'"
                        >
                            <span>{{ agreement.processing ? 'Recording…' : 'Record the agreement' }}</span>
                        </button>

                        <button
                            v-if="step !== 'draft'"
                            type="button"
                            class="stack-btn amber"
                            :disabled="activateBlockedBy !== null || agreement.processing"
                            :title="activateBlockedBy ?? 'Activate the plan: dunning pauses and the arrears restriction on this household’s guest passes is lifted while every instalment is met.'"
                            @click="activate"
                        >
                            <span>Activate plan</span>
                        </button>
                    </div>
                </form>
            </div>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only.
 *
 * The board draws every control on this screen as a <div> or a <span>; here
 * they are two links, three buttons, a form and five fields, and each arrives
 * wearing the browser's own border, background, font and chrome. Nothing below
 * introduces a colour, a size or a weight: the board's .modal-card, .m-input,
 * .modal-close and .stack-btn rules are what is seen.
 *
 * THE BORDER RESETS NAME THE VARIANTS THAT HAVE NO BORDER. A scoped element
 * selector outranks a single class, so `button.stack-btn { border: 0 }` would
 * beat .stack-btn.outline and rub out the 1.5px navy-200 edge that is the whole
 * difference between the outline variant and the solid ones — a real defect
 * that survives measurement, because 1.5px on one button is well under any
 * threshold. See the note in Gemini/Reports/ReportShell.vue. Cancel is a link
 * here and brings no border of its own, so only the two solid variants are
 * named.
 */
form.modal-card {
    margin: 0;
}

button.stack-btn.amber,
button.stack-btn.primary {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.stack-btn[disabled] {
    cursor: not-allowed;
}

/*
 * The controls sit INSIDE the board's own <span>, so `.m-input span` supplies
 * the type and `font: inherit` picks it up.
 */
.m-input span {
    /* The board's span wraps a string and needs no width; this one wraps a
     * control that has to fill the pill. */
    flex: 1;
    min-width: 0;
}

.m-input input {
    width: 100%;
    border: 0;
    background: transparent;
    font: inherit;
    color: inherit;
    padding: 0;
    appearance: none;
    -webkit-appearance: none;
}

.m-input input::placeholder {
    color: var(--slate-500);
    opacity: 1;
}

/* Chrome paints spinners on a number input. The board draws none, and they
 * would sit outside the field's own padding. */
.m-input input[type='number']::-webkit-outer-spin-button,
.m-input input[type='number']::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

/*
 * A date input draws its own calendar button. The board draws no glyph in this
 * field at all, and the date stays fully typeable with the picker still
 * reachable from the keyboard.
 */
.m-input input[type='date']::-webkit-calendar-picker-indicator {
    display: none;
}

/*
 * Read-only, not disabled. The total, the frequency and an agreed plan's own
 * figures are all things a treasurer reads out over the phone, so they stay
 * selectable and reachable; only the pointer says they are not for typing in.
 */
.m-input input[readonly] {
    cursor: default;
}

/*
 * AUTHORED BELOW THIS LINE, and only for states the board has none of: it draws
 * one filled-in happy path, with nothing refused, nothing just saved and no
 * disagreement between a field and the server. Kept to the tokens the boards do
 * define, and each placed beside the thing it is about rather than in a summary
 * four fields away.
 */
.field-error,
.field-note,
.plan-flash {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.45;
}

.field-error {
    color: var(--red-700);
    margin-top: 5px;
}

.field-note {
    color: var(--amber-700);
    margin-top: 5px;
}

.plan-flash {
    /* The green the board uses for money received, on .stmt-payment. */
    color: var(--success-700);
    margin: 0 0 18px;
}
</style>
