<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * The maintenance queue — board screen community-admin-17.
 *
 * NOTHING ON THIS SCREEN IS ADDED UP HERE, AND THE OVERDUE FIGURE IS THE ONE
 * THAT MATTERS. `Maintenance::queueBoard()` walks every ticket once and derives
 * the four tiles, the red chip and each row's badge from the SAME arithmetic —
 * the time reported plus the service target for the priority. So the chip and
 * the tile cannot disagree, and no row can show "In progress" while the tile
 * counts it overdue. A page that re-counted the rows it was given would report a
 * different number the moment the queue paginated, which it already has: twelve
 * tickets are open and the board draws five.
 *
 * THE BADGE COLUMN IS NOT THE STATUS COLUMN. The stored lifecycle is submitted,
 * acknowledged, assigned, in_progress, completed, verified — "Overdue" is none
 * of them. It is an open ticket past its deadline, computed at draw time, which
 * is why #1041 shows as Overdue while its record says a vendor was assigned and
 * nobody has started. Both statements are true and only one of them is stored.
 *
 * NOT ONE FIGURE HERE IS A HOUSEHOLD'S FINANCIAL POSITION. The persona is the
 * Property Manager, who holds Facilities in full and holds nothing on Dues &
 * ledger, payments or accounting posting (D-010). The Location cell prints a
 * unit REFERENCE — "Phase 1 · Lot 9" — as a place, and there is no balance, no
 * bucket and no arrears age anywhere on the payload behind it.
 *
 * TRIAGE HAPPENS ON THE ROW, WHICH IS WHY `canUpdate` IS HERE. Pressing the
 * priority raises or lowers the service target, and the deadline moves with it
 * while the clock does not reset — a three-day-old medium ticket escalated to
 * high is overdue the instant it is escalated, because high means a day from the
 * report and the report was three days ago. That is the point of escalating one.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    /** Four tiles in board order: open, in progress, overdue, average resolution. */
    kpis: { type: Array, required: true },
    /** The chip set — All, Open, In progress, Completed — with its labels. */
    filters: { type: Array, required: true },
    filter: { type: String, required: true },
    /** The same count as the Overdue tile, from the same walk. */
    overdue: { type: Number, required: true },
    rows: { type: Array, required: true },
    /** The priority ladder as {key: label}, so the page never spells the enum. */
    priorities: { type: Object, required: true },
    canUpdate: { type: Boolean, required: true },
    canCreate: { type: Boolean, required: true },
    /** Whether the viewer may open the supplier register, which is Accounting's screen. */
    canViewVendors: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
    reasons: { type: Object, required: true },
})

/*
 * Which board's stylesheet this page wears. The ten Estate Console boards do NOT
 * share one sheet, so every estate page has to name its own or it renders with
 * no board CSS at all. Boards 17 to 20 are all in the batch-5 sheet.
 */
useWireframe('community-admin-05-maintenance-amenity-bookings-and-settings')

const page = usePage()

/**
 * All six, and every one of them says something true of a maintenance queue.
 *
 * `empty` is an estate that has never had a fault reported — a real first day,
 * not an error. `empty-filtered` is the chip set having excluded everything: an
 * estate with nothing completed this quarter still has tickets, and telling that
 * reader "no tickets yet" would suggest the queue had been wiped.
 */
const state = useScreenState({
    rows: () => props.rows.length,
    filtered: () => props.filter !== 'all',
})

const retry = () => router.reload()

/*
 * Where this estate's Facilities module lives, in whichever shape the
 * environment serves. Production gives each estate its own hostname and the path
 * starts at /facilities; local serves them all from one host with the estate in
 * the path (/estate/phoenixpark/facilities/maintenance). Cutting the URL that
 * served this page at /facilities is right in both, and — unlike a tenant key
 * read off a prop — it cannot address an estate other than the one already open.
 */
const facilitiesPath = computed(() => {
    const cut = page.url.indexOf('/facilities')

    return cut === -1 ? '/facilities' : `${page.url.slice(0, cut)}/facilities`
})

/*
 * THE CHIPS FILTER IN THE QUERY, NOT IN THE BROWSER. Filtering the rows already
 * sent would leave the four tiles reporting the whole estate above a table
 * reporting one status, and a screen that computes its summary and its list in
 * two places eventually reports two different numbers. Links, so a filtered
 * queue is a URL a manager can bookmark and reload.
 */
const queueHref = computed(() => `${facilitiesPath.value}/maintenance`)

const chipHref = (key) => (key === 'all' ? queueHref.value : `${queueHref.value}?filter=${key}`)

const ticketHref = (row) => `${queueHref.value}/${row.number}`

/**
 * The booking diary — board 19, and the second half of this module.
 *
 * A real link, because the screen is built. "Vendors" is a link only for a
 * viewer holding Accounting view: the supplier register is the Accounting
 * module's screen, and the persona this board is drawn for does not hold it —
 * `reasons.vendors` says so on the inert twin, and it is the same register
 * these tickets are assigned from.
 */
const amenitiesHref = computed(() => `${facilitiesPath.value}/amenities/bookings`)

/** Board 26, one module over from the same root. */
const vendorsHref = computed(() => facilitiesPath.value.replace(/\/facilities$/, '/accounting/vendors'))

/**
 * The board's own class name for a priority, which is not the stored value.
 *
 * The sheet defines `.high`, `.med` and `.low`; the enum is high, medium, low.
 * Mapping the one that differs is cheaper than teaching the server the board's
 * abbreviation, and it keeps `priority` readable in the payload.
 */
const dotClass = (priority) => (priority === 'medium' ? 'med' : priority)

/**
 * The board draws four badge colours and the lifecycle has five states.
 *
 * `cancelled` is the one the sheet has no rule for — the board never drew a
 * cancelled ticket — so it is declared inline in the board's own manner, neutral
 * rather than red. A ticket somebody withdrew is a decision, not a failure, and
 * colouring it like a breach would put it in the same bucket as #1041.
 */
const badgeStyle = (status) =>
    status === 'cancelled' ? 'background:var(--navy-100);color:var(--slate-600);' : undefined

/**
 * The tiles print what the server sent them as.
 *
 * Three are counts and the fourth is a DURATION — the board draws "3.2 days"
 * beside "12", "7" and "2" — so the unit word belongs to that tile alone. One
 * decimal always, including a flat 3.0: a second place would suggest an accuracy
 * an average over a dozen jobs has not got, and dropping the place would make
 * the tile disagree with itself between quarters.
 */
const tile = (kpi) => (kpi.key === 'avg_resolution' ? `${kpi.value.toFixed(1)} days` : kpi.value)

/* ------------------------------------------------------------------ */
/* triage */
/* ------------------------------------------------------------------ */

/** The row whose priority panel is open, or null. One at a time, deliberately. */
const triaging = ref(null)

const PRIORITY_HINT =
    'Raise or lower the priority. The service target moves with it and the clock does not reset — a ticket is always measured from the moment it was reported.'

/**
 * Why the priority cannot be pressed on this row, or null when it can.
 *
 * TWO REFUSALS, and the second is the row's rather than the reader's. Access
 * comes first because it is true of every row and the route enforces it anyway.
 * The second is a closed ticket: a priority is a promise about when work will be
 * done, and there is no work outstanding on a job that is finished — the service
 * would take the press and record a state change that means nothing. Bringing it
 * back is a reopen, which is board 18's act because it has to say why.
 */
const triageBlockedBy = (row) => {
    if (!props.canUpdate) {
        return props.blockedReason
    }

    if (row.status === 'completed' || row.status === 'cancelled') {
        return 'This ticket is closed, and a priority only sets a deadline for work still outstanding. If the fault came back, reopen it on the ticket — that leaves the original closure standing in the record instead of quietly reviving it.'
    }

    return null
}

const openTriage = (row) => {
    triaging.value = triaging.value === row.id ? null : row.id
}

/**
 * Move one ticket's priority.
 *
 * A plain post rather than a form: there is one field, it has three values, and
 * each is its own button — so there is nothing to type, nothing to validate in
 * the browser and no state worth holding between presses.
 *
 * IT LANDS ON THE TICKET, and that is the route's own redirect rather than an
 * accident of this page. Escalating a job is the moment somebody wants to look
 * at it, and the ticket is where the vendor, the timeline and the new deadline
 * are. The button says so before it is pressed.
 */
const setPriority = (row, priority) => {
    if (triageBlockedBy(row) !== null || priority === row.priority) {
        return
    }

    router.post(`${facilitiesPath.value}/maintenance/${row.number}/priority`, { priority })
}
</script>

<template>
    <Head title="Facilities" />

    <EstateConsole title="Facilities" :estate-name="estate.name" active="facilities">
        <template #actions>
            <!--
              Inert, and the reason depends on which of the two things is
              missing. A manager who holds Facilities create is waiting on a
              form that captures the location, the category and the priority
              together — raising a work order starts an SLA clock, so those are
              chosen deliberately rather than defaulted. A manager who does not
              hold it is waiting on access, and telling them about a form would
              be telling them the wrong thing.
            -->
            <button
                type="button"
                class="btn-primary-sm"
                disabled
                :title="
                    canCreate
                        ? reasons.workOrder
                        : 'Raising a work order commits this estate to a job and starts a service clock against it, so it needs Facilities create access. You are able to read this screen.'
                "
            >
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
                <span>New work order</span>
            </button>
        </template>

        <!--
          Three tabs, one module. The tab for the screen the reader is already
          on is text rather than a control, because there is nowhere for it to
          lead.
        -->
        <div class="subnav">
            <div class="subnav-item active" aria-current="page">Maintenance</div>
            <Link :href="amenitiesHref" class="subnav-item">Amenities</Link>
            <Link v-if="canViewVendors" :href="vendorsHref" class="subnav-item">Vendors</Link>
            <button v-else type="button" class="subnav-item" disabled :title="reasons.vendors">Vendors</button>
        </div>

        <!--
          The flash and the refusal. Neither is on the board — a board is a still
          image and nothing has ever been pressed on it — and neither is drawn on
          a fresh GET, so both are free of the screen's geometry. They are here
          because a ticket that closes in silence, and a priority refused in
          silence, are the same screen to whoever pressed the button. "Ticket
          #1042 completed." arrives here, because resolving one on board 18
          returns the manager to the queue.
        -->
        <p v-if="page.props.flash.success" class="queue-flash">{{ page.props.flash.success }}</p>
        <p v-if="page.props.errors.priority" class="queue-refusal">{{ page.props.errors.priority }}</p>

        <div class="kpi-row">
            <div v-for="kpi in kpis" :key="kpi.key" class="kpi-card">
                <div class="k-top">
                    <div class="kpi-icon">
                        <!--
                          The four glyphs, lifted from this board rather than
                          taken from a component. The payload names no icon —
                          `queueBoard()` carries a key, a figure and a label, and
                          nothing about how any of them is drawn — so the pairing
                          lives here, where the board it came from is.
                        -->
                        <svg v-if="kpi.key === 'open'" viewBox="0 0 24 24" fill="none">
                            <path
                                d="M14.7 6.3a3 3 0 1 0-4.2 4.2l-7 7 2.3 2.3 7-7a3 3 0 0 0 4.2-4.2l-2.1 2.1-2-2z"
                                stroke="currentColor"
                                stroke-width="1.6"
                                stroke-linejoin="round"
                            />
                        </svg>
                        <svg v-else-if="kpi.key === 'in_progress'" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7" />
                            <path d="M12 7v5l3.5 2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                        </svg>
                        <svg v-else-if="kpi.key === 'overdue'" viewBox="0 0 24 24" fill="none">
                            <path d="M12 9v4M12 17h.01" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" />
                            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" />
                        </svg>
                        <svg v-else viewBox="0 0 24 24" fill="none">
                            <path d="M3 3v18h18" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                            <path
                                d="M7 15l4-5 4 3 5-7"
                                stroke="currentColor"
                                stroke-width="1.7"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                    </div>
                </div>

                <div class="k-val">{{ tile(kpi) }}</div>
                <div class="k-lbl">{{ kpi.label }}</div>
            </div>
        </div>

        <!--
          The chips stand in every state below, including the empty ones: hiding
          the control that produced an empty list is how a reader gets stuck on
          it. The tiles stand for the same reason — they count the whole queue
          and do not move when a chip is pressed, so an emptied table under them
          is visibly a view rather than an estate with no tickets.
        -->
        <div class="filter-row">
            <Link
                v-for="chip in filters"
                :key="chip.key"
                :href="chipHref(chip.key)"
                class="f-chip"
                :class="{ active: chip.key === filter }"
            >
                {{ chip.label }}
            </Link>

            <!--
              A READOUT, NOT A FIFTH FILTER, and drawn as the board draws it —
              a <div> with the board's own inline declarations, kept verbatim.
              The chip set behind this row has four keys and none of them is
              "overdue": an overdue ticket is an open one past its deadline, so
              it is already inside "In progress", and a chip that reordered the
              same rows while claiming to narrow them would be a control that
              lies. The figure is the Overdue tile's, from the same count.

              Absent rather than zero. A red chip reading "0 overdue" is an alarm
              for something that has not happened; when the estate is inside
              every service target there is nothing for it to say.
            -->
            <div
                v-if="overdue > 0"
                class="f-chip"
                style="margin-left:auto;background:var(--red-100);color:var(--red-700);"
                title="Open tickets past the service target for their priority, counted from the moment each was reported. The same figure as the Overdue tile — they are one walk of the queue, not two."
            >
                {{ overdue }} overdue
            </div>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="5" :columns="7" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Facilities is not part of your role’s access"
            body="The maintenance queue is what this estate has been asked to fix and who is fixing it, so it opens only to roles that hold Facilities. A committee officer or the estate administrator can grant it from the role access matrix."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The maintenance queue could not be read"
            body="The estate database did not answer. No ticket has changed hands, nothing has been closed and no service clock has been affected — every job still stands exactly as it did before this screen opened."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isEmptyFiltered.value"
            variant="filtered"
            title="No ticket is in this state"
            body="The estate has tickets — the tiles above count them — but none of them is at this stage right now. Nothing has been lost, and the four figures above are unaffected by which chip is pressed."
            action-label="Show the whole queue"
            @action="router.visit(chipHref('all'))"
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="Nothing has been reported"
            body="No fault has been raised in this estate, by a household or by the office. A resident reporting one from their app opens a ticket here with its service clock already running, and the office can raise a work order for a job nobody has called in."
        />

        <table v-else class="data-table">
            <thead>
                <tr>
                    <th>Ticket</th>
                    <th>Location</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Assigned</th>
                    <th>Age</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <template v-for="row in rows" :key="row.id">
                    <tr>
                        <!-- "#1042 — Gate lighting" — composed on the server, so
                             the number and the em dash are written in one place
                             and a bill recorded against the job prints the same
                             string. -->
                        <td>{{ row.ticket }}</td>
                        <td>{{ row.location }}</td>

                        <!--
                          The priority is the row's one act, so it is the row's
                          one control. Pressing it opens the ladder beneath the
                          row rather than over it, because the ticket it belongs
                          to has to stay readable while the choice is made.
                        -->
                        <td>
                            <button
                                type="button"
                                class="priority-dot"
                                :class="dotClass(row.priority)"
                                :disabled="triageBlockedBy(row) !== null"
                                :title="triageBlockedBy(row) ?? PRIORITY_HINT"
                                @click="openTriage(row)"
                            >
                                <i></i>{{ row.priority_label }}
                            </button>
                        </td>

                        <td>
                            <div class="status-badge" :class="row.status" :style="badgeStyle(row.status)">
                                {{ row.status_label }}
                            </div>
                        </td>

                        <!-- "Unassigned" is the board's own word for a ticket
                             nobody has been given, and it is a fact about the
                             job rather than a blank. #1039 is four hours into a
                             one-day target with no vendor on it; left alone
                             until tomorrow it is overdue with nobody named. -->
                        <td>{{ row.assignee }}</td>

                        <!-- Age, or "Closed Sep 2" where the job is finished.
                             How old a completed ticket is tells a manager
                             nothing; when it finished tells them whether the
                             estate is keeping up, which is the tile beside it. -->
                        <td>{{ row.age }}</td>

                        <td><Link :href="ticketHref(row)" class="text-link-sm">Open</Link></td>
                    </tr>

                    <!--
                      Authored, because the board is a still image of a queue
                      nobody has pressed anything on and draws no panel at all.
                      It opens INSIDE the table, under the ticket it belongs to,
                      because a priority is one job's and a panel floating over
                      the table would lose which.
                    -->
                    <tr v-if="triaging === row.id" class="triage-row">
                        <td colspan="7">
                            <div class="triage-panel">
                                <div class="triage-head">
                                    {{ row.ticket }} — changing the priority moves the service target and does
                                    not restart the clock, so this ticket stays measured from the moment it was
                                    reported. Escalating an old one makes it overdue immediately, which is what
                                    escalating is for.
                                </div>

                                <div class="triage-options">
                                    <button
                                        v-for="(label, key) in priorities"
                                        :key="key"
                                        type="button"
                                        class="f-chip"
                                        :class="{ active: key === row.priority }"
                                        :disabled="key === row.priority"
                                        :title="
                                            key === row.priority
                                                ? `${row.ticket} is already ${label.toLowerCase()} priority.`
                                                : `Set ${label.toLowerCase()} priority and open the ticket.`
                                        "
                                        @click="setPriority(row, key)"
                                    >
                                        {{ label }}
                                    </button>

                                    <button type="button" class="text-link-sm" @click="triaging = null">
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only, and each removal names the element that needs it.
 *
 * The board draws its tabs, its topbar action, its chips and its priority cells
 * as divs. Here they are real buttons and real links, and a button element
 * arrives wearing the browser's own font, a border and buttonface grey. The
 * board's own classes supply everything visible; app.css already takes the UA's
 * underline and blue off every anchor on a board page, so the links need nothing.
 *
 * NOTHING BELOW RESETS `border` ON A CLASS THE BOARD GIVES ONE TO. A scoped
 * element selector outranks a single class, so a blanket `button { border: 0 }`
 * would out-specify the board and rub out a real edge — see D-045, where exactly
 * that silently deleted .btn-outline-sm's 1.5px outline and survived measurement
 * because 1.5px on one small control is a few hundred pixels. Each class named
 * here declares no border of its own.
 */
button {
    font: inherit;
}

/*
 * `font: inherit` rather than `font-family: inherit`, because .priority-dot
 * declares no type at all — the board's div takes its 12.5px from the table
 * cell around it, and a button element left alone would take 13.33px Arial from the
 * user agent instead. The classes that DO declare a size out-specify this.
 */
button.priority-dot {
    border: 0;
    background: none;
    padding: 0;
    color: inherit;
    cursor: pointer;
}

/* .btn-primary-sm declares a background and no border; .subnav-item declares
 * neither, and the white belongs to .subnav-item.active — which is this screen,
 * so it is a link and never one of these buttons. .f-chip declares its own
 * navy-100 face and needs only the border taken off. */
button.btn-primary-sm,
button.f-chip {
    border: 0;
}

button.subnav-item {
    border: 0;
    background: none;
}

button.text-link-sm {
    border: 0;
    background: none;
    padding: 0;
    cursor: pointer;
}

button[disabled] {
    cursor: not-allowed;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws no flash, no refusal and no triage
 * panel, because nothing has ever been pressed on it — and all three are things
 * this screen has to be able to say. Kept to the tokens the boards define and to
 * the shapes they already use: the panel is the navy-100 inset the boards put
 * inside a white card, and the type sizes are .data-table's and .res-sub's.
 */
.queue-flash,
.queue-refusal {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 14px;
}

.queue-flash {
    background: var(--green-100);
    color: var(--green-700);
}

.queue-refusal {
    background: var(--red-100);
    color: var(--red-700);
}

.triage-row td {
    background: var(--navy-100);
    padding: 14px 16px;
}

.triage-panel {
    display: flex;
    flex-direction: column;
    gap: 11px;
}

.triage-head {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--navy-800);
    line-height: 1.5;
    max-width: 760px;
}

.triage-options {
    display: flex;
    align-items: center;
    gap: 10px;
}
</style>
