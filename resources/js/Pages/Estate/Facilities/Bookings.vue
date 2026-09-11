<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * Amenity bookings — board screen community-admin-19.
 *
 * THE DIARY, AND NOT ONE FIGURE ON IT IS A HOUSEHOLD'S FINANCIAL POSITION. The
 * persona is the Property Manager, who holds `facilities` in full and holds `-`
 * on `dues_ledger`, `payments` and `accounting_posting` — D-010 split those
 * modules precisely so the person who runs the estate's amenities never reaches
 * what a household owes. The Deposit column is the amount SNAPSHOTTED onto the
 * booking, which is a fact about the booking rather than about the account, and
 * the arrears rule reaches this screen as a boolean or not at all.
 *
 * EVERY STRING IN THE TABLE IS FORMATTED BEFORE IT GETS HERE, and that is the
 * point. `whenLabel()` and `depositLabel()` live on the model because the board
 * prints money in a form the ledger boards do not — a bare "$5,000", no
 * decimals, no J$ — and the amount is stored in minor units like every other
 * amount in this system. A template dividing by a hundred is how a rounding
 * artefact reaches a deposit the estate is actually holding, so nothing here
 * divides by anything.
 *
 * THE DATE CELL IS DERIVED AND THE BOARD IS WHY. Board 19 dates three bookings
 * "Sat, Sep 20", "Sun, Sep 21" and "Sat, Sep 27", and no calendar has all three
 * of those weekdays. So the DATE is stored and the weekday is computed from it,
 * and the screen can never be internally wrong about a Saturday. A past booking
 * collapses to "Aug 15 (past)" for the same reason it is drawn that way: once an
 * event has happened, which two hours of that Saturday it ran for is not what
 * anybody is scanning the list for.
 *
 * "CHARGE FEE" IS INERT FOR THE ROLE THIS BOARD IS DRAWN FOR, DELIBERATELY.
 * Recording a booking fee posts a charge against the unit — Dr 1200 Dues
 * Receivable, Cr 4100 Amenity Booking Fees — which adds to what a household
 * owes, so the route carries `estate.dues_ledger.create` beside the facilities
 * gate. The Property Manager holds the first and not the second. The board names
 * the act among their actions; the platform invariant outranks the board, so the
 * control is drawn and disabled with the real reason on it rather than quietly
 * working. `canCharge` is the answer to that question and `chargeReason` is the
 * sentence.
 *
 * IT IS NOT OFFERED ON A PENDING ROW. Charging a household for a Saturday the
 * estate has not yet agreed to is the same debt-they-never-agreed-to that
 * `Amenities::recordFee()` refuses on a declined booking, one step earlier — so
 * the fee appears against bookings the estate has actually accepted, and a
 * pending row keeps the approve/reject pair the board draws on it.
 *
 * BLOCKING A SLOT IS NOT ON THIS SCREEN, and `canCreate` is deliberately unused
 * — the same shape as `reasons.restrict` on board 5. Taking a period out of an
 * amenity's diary means naming a start, an end and a reason against a calendar
 * somebody can see, so that they can tell it does not sit over a confirmed
 * booking; the route exists and refuses the overlap, but a two-datetime form
 * dropped onto a list board is a screen nobody has approved. The board draws no
 * blocking control either.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    /** The chip row: every bookable amenity, in the estate's own order. */
    amenities: { type: Array, required: true },
    /** Which amenity chip is engaged, or '' for all of them. */
    filter: { type: String, required: true },
    /** How many bookings are waiting on a decision — the amber chip's count. */
    pending: { type: Number, required: true },
    rows: { type: Array, required: true },
    canUpdate: { type: Boolean, required: true },
    canCreate: { type: Boolean, required: true },
    canCharge: { type: Boolean, required: true },
    chargeReason: { type: String, required: true },
    /** Whether the viewer may open the supplier register, which is Accounting's screen. */
    canViewVendors: { type: Boolean, required: true },
    reasons: { type: Object, required: true },
})

/*
 * Which board's stylesheet this page wears. The ten Estate Console boards do
 * NOT share one sheet the way the nine Gemini boards do, so every estate page
 * has to name its own or it renders with no board CSS at all.
 */
useWireframe('community-admin-05-maintenance-amenity-bookings-and-settings')

const page = usePage()

/*
 * All six. `empty-filtered` is genuinely reachable here and by a control the
 * board draws: the Pool Deck is bookable and has no bookings, so its chip
 * produces a list emptied by the view rather than by the estate. Sources are
 * functions, not values — useScreenState runs once during setup, and a value
 * read there would freeze on the first render.
 */
const state = useScreenState({
    rows: () => props.rows.length,
    filtered: () => props.filter !== '',
})

const retry = () => router.reload()

/*
 * Where this console is rooted, read off the page's own URL.
 *
 * Production gives each estate its own hostname and no prefix; local serves
 * every estate from one host with the estate key in the path, as
 * /estate/{key}/facilities/amenities/bookings. The URL that served this page
 * already carries whichever shape this environment uses, so cutting it at
 * /facilities is correct in both — a hard-coded root is correct in exactly one,
 * and unlike a tenant key read off a prop it cannot address an estate other than
 * the one already open.
 */
const root = computed(() => page.url.slice(0, page.url.indexOf('/facilities')))

const facilities = (suffix) => `${root.value}/facilities${suffix}`

/**
 * One chip per bookable amenity, plus the "all" chip in front.
 *
 * GENERATED FROM THE ESTATE'S OWN AMENITIES rather than fixed at the three the
 * board happens to draw. `Amenities::bookingsBoard()` makes that decision and
 * says why; the consequence on this estate is a fourth chip, Pool Deck, which is
 * bookable and currently has nothing booked against it. Drawing three would mean
 * an estate could not filter to an amenity it owns.
 *
 * The chips are LINKS, so the filter lives in the query string: a filtered diary
 * is a URL somebody can send to whoever has to decide, and the list and its
 * pending count are then computed in one place rather than two.
 */
const chips = computed(() => [
    { label: 'All amenities', href: facilities('/amenities/bookings'), active: props.filter === '' },
    ...props.amenities.map((name) => ({
        label: name,
        href: facilities(`/amenities/bookings?amenity=${encodeURIComponent(name)}`),
        active: props.filter === name,
    })),
])

/**
 * The amber chip, which is a COUNT and not a filter.
 *
 * The board draws it in the chip row wearing the same shape as the four beside
 * it, so it is reproduced there — but nothing in the payload filters on status
 * and no route accepts one, so it is drawn as text rather than as a control that
 * would do nothing when pressed. It counts the whole diary and not the filtered
 * view: `bookingsBoard()` tallies before it filters, because "one booking is
 * waiting on you" stays true when somebody clicks Gazebo.
 *
 * Absent at zero rather than drawn as "0 pending approval" — an amber chip
 * announcing nothing is a warning about an empty queue.
 */
const pendingLabel = computed(() =>
    `${props.pending} pending approval${props.pending === 1 ? '' : 's'}`
)

/*
 * The board's own inline style on that chip, kept verbatim. It is what pushes
 * the chip to the right-hand end of the row and makes it amber — the board's
 * .f-chip rule carries neither — and amber here rather than the red board 5 uses
 * for money that is overdue: a booking waiting on a decision is work, not a
 * failure.
 */
const PENDING_CHIP_STYLE = 'margin-left:auto;background:var(--amber-100);color:var(--amber-700);'

const PENDING_CHIP_TITLE =
    'How many bookings across every amenity are waiting on a decision. A count rather than a filter — the diary is filtered by amenity, and a booking nobody has decided is work whichever amenity it is against.'

/* ------------------------------------------------------------------ */
/* the module's own tabs */
/* ------------------------------------------------------------------ */

/**
 * Maintenance, Amenities, Vendors — one module drawn as three tabs.
 *
 * The tab the reader is on is text rather than a control, because there is
 * nowhere for it to lead. Maintenance is a real link: the queue is board 17, it
 * is behind the same `facilities` gate this screen is, and anybody reading this
 * diary can already reach it.
 *
 * VENDORS IS A LINK FOR WHOEVER HOLDS ACCOUNTING, AND ONLY FOR THEM. The
 * supplier register is board 26, in the Accounting module, and it is the same
 * register these bookings' amenities are maintained from. It used to be inert
 * for everybody; it is inert now only for a role without Accounting view — the
 * Property Manager, by D-010 — and `reasons.vendors` says exactly that. Telling
 * them a built screen was unbuilt would send them to ask for the wrong thing.
 */
const maintenanceHref = computed(() => facilities('/maintenance'))

/** Board 26, cut from the same root one module over. */
const vendorsHref = computed(() => `${root.value}/accounting/vendors`)

/* ------------------------------------------------------------------ */
/* deciding a booking */
/* ------------------------------------------------------------------ */

/**
 * Why approving or declining cannot be pressed, or null when it can.
 *
 * AUTHORED, because this payload carries no `blockedReason` — the board is drawn
 * for the role that holds Facilities in full, so the case does not arise on it.
 * It arises for a President or a Treasurer, both of whom hold Facilities as View
 * only, and a screen that renders them a live tick would be lying about what the
 * route will accept.
 */
const decideBlockedBy = computed(() =>
    props.canUpdate
        ? null
        : 'Deciding a booking tells a household whether their Saturday is theirs, so it needs Facilities update access. You are able to read this diary.'
)

/** The booking whose decline panel is open, or null. One at a time. */
const declining = ref(null)

/*
 * A REASON IS REQUIRED AND THE SERVICE SAYS SO. `Amenities::decline()` refuses
 * an empty one, because a household told only "declined" has nothing to act on
 * and will ask a guard at a gate about it — which is the one place the answer
 * cannot be given. So the field is put in front of the decision rather than the
 * press being sent to be bounced.
 */
const form = useForm({ reason: '' })

const openDecline = (row) => {
    declining.value = row.id
    form.clearErrors()
    form.reason = ''
}

const closeDecline = () => {
    declining.value = null
    form.reset()
    form.clearErrors()
}

const approve = (row) => {
    if (decideBlockedBy.value !== null) {
        return
    }

    router.post(facilities(`/amenities/bookings/${row.id}/approve`), {}, { preserveScroll: true })
}

const submitDecline = (row) => {
    /*
     * A form submits on Enter from any field and a disabled button does not stop
     * it. The route is gated and the service refuses an empty reason, so this is
     * only about not sending a decision that will bounce.
     */
    if (decideBlockedBy.value !== null || form.reason.trim() === '') {
        return
    }

    form.post(facilities(`/amenities/bookings/${row.id}/decline`), {
        preserveScroll: true,
        onSuccess: () => closeDecline(),
    })
}

/* ------------------------------------------------------------------ */
/* charging the fee */
/* ------------------------------------------------------------------ */

/**
 * Which rows offer the fee at all.
 *
 * The bookings the estate has actually agreed to. A declined or cancelled
 * booking is a debt the household never agreed to and the service refuses it; a
 * pending one has not been agreed yet, which is the same objection a day earlier.
 */
const chargeable = (row) => row.status === 'confirmed' || row.status === 'completed'

/**
 * Why the fee cannot be recorded on this row, or null when it can.
 *
 * THE PLATFORM INVARIANT COMES FIRST because it is true of every row and it is
 * the one refusal nobody on this screen can do anything about: D-010 locks the
 * Property Manager out of Dues & ledger, and a fee is a charge on a unit. The
 * other three are facts about the booking.
 */
const feeBlockedBy = (row) => {
    if (!props.canCharge) {
        return props.chargeReason
    }

    if (!props.canUpdate) {
        return decideBlockedBy.value
    }

    if (row.is_charged) {
        return `Booking ${row.reference} has already been charged. Billing one Saturday twice is a refund the estate would have to notice before it could make it, so it can happen exactly once.`
    }

    if (row.fee_minor <= 0) {
        return `The ${row.amenity} carries no booking fee, so there is nothing to charge. An entry for nothing is a line in the ledger saying something happened.`
    }

    return null
}

const charge = (row) => {
    if (feeBlockedBy(row) !== null) {
        return
    }

    router.post(facilities(`/amenities/bookings/${row.id}/fee`), {}, { preserveScroll: true })
}

/**
 * What a live fee control promises, in the terms it will actually post in.
 *
 * The entry is named on the hover rather than in the row: the board's Deposit
 * column is the only money it draws, and a fee is a different posting from a
 * deposit — revenue against a refundable liability — which is exactly the pair
 * the board's own accounting note warns must never be conflated.
 */
const FEE_TITLE =
    'Charge the booking fee to the unit — Dr 1200 Dues Receivable, Cr 4100 Amenity Booking Fees. It is a charge like any other and appears on the household’s statement.'

/**
 * A status the board's stylesheet has no badge for.
 *
 * It draws Confirmed, Pending and Completed and defines a rule for each.
 * Declined and Cancelled are real states of a booking and neither is a fault to
 * flag in red — one is a decision the estate made and the other a household's —
 * so they take the neutral navy the boards use for a fact that is simply over.
 * Declared inline for the same reason board 26 does it: borrowing a badge that
 * means something else would say something else.
 */
const NEUTRAL_BADGE = 'background:var(--navy-100);color:var(--slate-600);'

const badgeStyle = (status) =>
    ['confirmed', 'pending', 'completed'].includes(status) ? undefined : NEUTRAL_BADGE
</script>

<template>
    <Head title="Amenity bookings" />

    <EstateConsole title="Amenity bookings" :estate-name="estate.name" active="facilities">
        <template #actions>
            <!--
              An outline button, not the primary blue — the rate card is where
              this screen's figures come from rather than the next thing to do
              on it. The gear is the board's own abbreviated one: two paths
              where the sidebar's has the full ring of teeth. Copied as drawn.
            -->
            <Link :href="facilities('/amenities/settings')" class="btn-outline-sm">
                <svg viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.5" />
                    <path
                        d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83"
                        stroke="currentColor"
                        stroke-width="1.5"
                    />
                </svg>
                <span>Manage amenities</span>
            </Link>
        </template>

        <div class="subnav">
            <Link :href="maintenanceHref" class="subnav-item">Maintenance</Link>
            <div class="subnav-item active" aria-current="page">Amenities</div>
            <Link v-if="canViewVendors" :href="vendorsHref" class="subnav-item">Vendors</Link>
            <button v-else type="button" class="subnav-item" disabled :title="reasons.vendors">Vendors</button>
        </div>

        <!--
          The flash and the refusal. Neither is on the board — a board is a still
          image and nothing has ever been pressed on it — and neither is drawn on
          a fresh GET, so both are free of the screen's geometry. They are here
          because a decision that lands in silence and one refused in silence are
          the same screen to whoever pressed the button.
        -->
        <p v-if="page.props.flash.success" class="bk-flash">{{ page.props.flash.success }}</p>
        <p v-if="page.props.errors.booking" class="bk-refusal">{{ page.props.errors.booking }}</p>
        <p v-if="page.props.errors.fee" class="bk-refusal">{{ page.props.errors.fee }}</p>

        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="6" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Facilities is not part of your role’s access"
            body="The booking diary sits inside Facilities, and your role does not hold it. A committee officer or the estate administrator can grant it from the role access matrix."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The booking diary could not be read"
            body="The estate database did not answer. No booking has been approved, declined or charged — every reservation stands exactly as it did before this screen opened, and re-running this read is safe."
            action-label="Try again"
            @action="retry"
        />

        <template v-else>
            <!--
              The chips stand in every remaining state. Hiding them over an empty
              filtered list would take away the control that produced it, and the
              pending count is about the whole diary rather than the view.
            -->
            <div class="filter-row">
                <Link
                    v-for="chip in chips"
                    :key="chip.label"
                    :href="chip.href"
                    class="f-chip"
                    :class="{ active: chip.active }"
                >
                    {{ chip.label }}
                </Link>

                <div v-if="pending > 0" class="f-chip" :style="PENDING_CHIP_STYLE" :title="PENDING_CHIP_TITLE">
                    {{ pendingLabel }}
                </div>
            </div>

            <EmptyState
                v-if="state.isEmptyFiltered.value"
                variant="filtered"
                title="Nothing is booked against this amenity"
                body="Other amenities have bookings — the chips above still reach them — but this one has none in the diary at all. An amenity with no bookings is offered for booking just the same; it is a diary that is empty rather than one that is missing."
                action-label="Show every amenity"
                @action="router.visit(facilities('/amenities/bookings'))"
            />

            <EmptyState
                v-else-if="state.isEmpty.value"
                variant="first-use"
                title="Nothing is booked yet"
                body="No household has reserved an amenity, so there is nothing to approve and no deposit being held. Residents make these bookings from their own app; this diary fills as they do, and each one arrives waiting on a decision."
            />

            <table v-else class="data-table">
                <thead>
                    <tr>
                        <th>Amenity</th>
                        <th>Booked by</th>
                        <th>Date &amp; time</th>
                        <th>Deposit</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="row in rows" :key="row.id">
                        <tr>
                            <td>
                                <!--
                                  The glyph is the amenity's OWN key, stored on
                                  the record rather than matched from its name:
                                  the board draws a different one per amenity and
                                  the same key has to resolve to the larger card
                                  glyph on board 20, so an estate adding a tennis
                                  court picks an icon instead of inheriting
                                  whichever one a string match landed on.

                                  Drawn here rather than taken from BoardIcon,
                                  which carries the icons the boards use in more
                                  than one place; these four are this pair of
                                  screens' own, at this board's own 1.6.
                                -->
                                <div class="amen-cell">
                                    <div class="amen-icon">
                                        <svg v-if="row.icon === 'gazebo'" viewBox="0 0 24 24" fill="none">
                                            <path
                                                d="M12 3v18M4 9c0-3.3 3.6-6 8-6s8 2.7 8 6"
                                                stroke="currentColor"
                                                stroke-width="1.6"
                                                stroke-linecap="round"
                                            />
                                            <path d="M4 9h16" stroke="currentColor" stroke-width="1.6" />
                                        </svg>
                                        <svg v-else-if="row.icon === 'clubhouse'" viewBox="0 0 24 24" fill="none">
                                            <path
                                                d="M4 21V9l8-6 8 6v12"
                                                stroke="currentColor"
                                                stroke-width="1.6"
                                                stroke-linejoin="round"
                                            />
                                            <path d="M9 21v-7h6v7" stroke="currentColor" stroke-width="1.6" />
                                        </svg>
                                        <svg v-else-if="row.icon === 'pavilion'" viewBox="0 0 24 24" fill="none">
                                            <path
                                                d="M3 10l9-6 9 6"
                                                stroke="currentColor"
                                                stroke-width="1.6"
                                                stroke-linejoin="round"
                                            />
                                            <path
                                                d="M5 10v9M11 10v9M13 10v9M19 10v9M3 19h18"
                                                stroke="currentColor"
                                                stroke-width="1.6"
                                            />
                                        </svg>
                                        <!--
                                          The Pool Deck, which board 19 never
                                          draws because nothing is booked against
                                          it. Its geometry is board 20's card
                                          glyph at this board's own weight —
                                          derived from its three siblings, which
                                          differ between the two screens only in
                                          stroke.
                                        -->
                                        <svg v-else viewBox="0 0 24 24" fill="none">
                                            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" />
                                            <path
                                                d="M12 3v2M12 19v2M3 12h2M19 12h2"
                                                stroke="currentColor"
                                                stroke-width="1.6"
                                                stroke-linecap="round"
                                            />
                                        </svg>
                                    </div>{{ row.amenity }}</div>
                            </td>

                            <td>{{ row.booked_by }}</td>
                            <td>{{ row.when }}</td>

                            <!--
                              The deposit AS THE BOOKING CARRIES IT. Copied off
                              the amenity when the booking was made and never
                              read back through the relation, so the rate card
                              can be edited tomorrow without restating money the
                              estate is already holding. A booking with no
                              deposit due says nothing here rather than "$0",
                              which would claim a deposit of nothing was taken.
                            -->
                            <td>{{ row.deposit }}</td>

                            <td>
                                <div class="status-badge" :class="row.status" :style="badgeStyle(row.status)">
                                    {{ row.status_label }}
                                </div>
                            </td>

                            <td>
                                <!--
                                  The row's controls are its STATUS, not a
                                  preference. A booking waiting on a decision
                                  gets the pair that makes it; everything else
                                  gets the link the board draws.
                                -->
                                <div v-if="row.decidable" class="row-actions">
                                    <button
                                        type="button"
                                        class="icon-btn approve"
                                        :disabled="decideBlockedBy !== null"
                                        :title="
                                            decideBlockedBy ??
                                            `Confirm ${row.reference} — the household is told the ${row.amenity} is theirs for that period.`
                                        "
                                        :aria-label="`Approve booking ${row.reference}`"
                                        @click="approve(row)"
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
                                        :disabled="decideBlockedBy !== null"
                                        :title="
                                            decideBlockedBy ??
                                            `Decline ${row.reference}. The household is given the reason you type, so they know what to do next.`
                                        "
                                        :aria-label="`Decline booking ${row.reference}`"
                                        @click="declining === row.id ? closeDecline() : openDecline(row)"
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

                                <!--
                                  Stacked rather than side by side, so the cell
                                  stays inside the width the approve/reject pair
                                  above already sets and the table's columns
                                  keep the proportions the board drew them in.
                                -->
                                <div v-else class="bk-actions">
                                    <!--
                                      The booking detail, which no board draws
                                      (D-086). The deposit door is there, so this
                                      is where "View" goes.
                                    -->
                                    <Link :href="facilities(`/amenities/bookings/${row.id}`)" class="text-link-sm">
                                        View
                                    </Link>

                                    <button
                                        v-if="chargeable(row)"
                                        type="button"
                                        class="text-link-sm"
                                        :disabled="feeBlockedBy(row) !== null"
                                        :title="feeBlockedBy(row) ?? FEE_TITLE"
                                        @click="charge(row)"
                                    >
                                        Charge fee
                                    </button>
                                </div>
                            </td>
                        </tr>

                        <!--
                          The decline panel, which is authored: the board draws a
                          screen nobody has pressed anything on, so it has no
                          form to copy. It opens INSIDE the table under the row
                          it belongs to, because a refusal is about one booking
                          and a panel floating over the diary would lose which.
                        -->
                        <tr v-if="declining === row.id" class="bk-row">
                            <td colspan="6">
                                <form class="bk-panel" @submit.prevent="submitDecline(row)">
                                    <div class="bk-head">
                                        Decline {{ row.reference }} — {{ row.amenity }}, {{ row.booked_by }},
                                        {{ row.when }}
                                    </div>

                                    <div class="bk-field">
                                        <label for="decline-reason">
                                            Why, in the words the household will be given
                                        </label>
                                        <input
                                            id="decline-reason"
                                            v-model="form.reason"
                                            type="text"
                                            required
                                            maxlength="190"
                                            placeholder="The Community Centre is closed that weekend for resurfacing…"
                                        />
                                    </div>

                                    <div v-if="form.errors.reason" class="bk-error">{{ form.errors.reason }}</div>

                                    <div class="bk-panel-actions">
                                        <button
                                            type="submit"
                                            class="btn-primary-sm"
                                            :disabled="form.processing || form.reason.trim() === ''"
                                            :title="
                                                form.reason.trim() === ''
                                                    ? 'Say why the booking is declined. “Declined” on its own tells the household nothing they can do anything about.'
                                                    : 'Decline the booking and release the period back into the diary.'
                                            "
                                        >
                                            <span>{{ form.processing ? 'Declining…' : 'Decline booking' }}</span>
                                        </button>
                                        <button type="button" class="text-link-sm" @click="closeDecline">
                                            Cancel
                                        </button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only, and each removal names the element that needs it.
 *
 * The board draws every control on this screen as a <div>; here they are real
 * buttons and real links, and a <button> arrives wearing the browser's own font,
 * a border and buttonface grey. The board's own classes supply everything
 * visible. app.css has already taken the UA's underline and blue off every
 * anchor on a board page, so the links need nothing at all.
 *
 * NOTHING BELOW RESETS `border` ON A CLASS THE BOARD GIVES ONE TO. A scoped
 * element selector outranks a single class, so a blanket `button { border: 0 }`
 * would out-specify the board and rub out .btn-outline-sm's 1.5px navy edge —
 * exactly the defect D-045 records, small enough to survive a passing
 * measurement while drawing a control the board does not draw. Every class named
 * here declares no border of its own.
 */
button {
    font-family: inherit;
}

button.subnav-item {
    border: 0;
    background: none;
}

/* .icon-btn and .btn-primary-sm each declare their own background and no border. */
button.icon-btn,
button.btn-primary-sm {
    border: 0;
}

button.text-link-sm {
    border: 0;
    background: none;
    text-align: left;
    cursor: pointer;
}

button[disabled] {
    cursor: not-allowed;
}

/*
 * AUTHORED BELOW THIS LINE. The board is a still image of a screen nobody has
 * pressed anything on, so it draws no decline panel, no flash and no refusal —
 * and all three are things this screen has to be able to say. Kept to the tokens
 * the board defines and to the shapes it already uses: the panel is the navy-100
 * inset the boards put inside a white card, and the type sizes are the ones
 * .data-table and .text-link-sm already set.
 */

/*
 * The row's two links, stacked. Two lines of 11.5px type come to less than the
 * 34px amenity glyph in the first cell, so the row keeps the height the board
 * gives it, and the column keeps the width the 28px action pair already sets.
 */
.bk-actions {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 3px;
}

.bk-flash,
.bk-refusal {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 14px;
}

.bk-flash {
    background: var(--green-100);
    color: var(--green-700);
}

.bk-refusal {
    background: var(--red-100);
    color: var(--red-700);
}

.bk-row td {
    background: var(--navy-100);
    padding: 14px 16px;
}

.bk-panel {
    display: flex;
    flex-direction: column;
    gap: 11px;
}

.bk-head {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--navy-800);
}

.bk-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.bk-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
}

.bk-field input {
    height: 34px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

.bk-field input::placeholder {
    color: var(--slate-300);
    opacity: 1;
}

.bk-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--red-700);
    line-height: 1.5;
}

.bk-panel-actions {
    display: flex;
    align-items: center;
    gap: 14px;
}
</style>
