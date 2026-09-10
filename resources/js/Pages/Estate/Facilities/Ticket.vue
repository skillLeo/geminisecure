<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * One ticket end to end — board screen community-admin-18.
 *
 * THE TIMELINE IS A SPINE, NOT A LOG. Six stages in fixed lifecycle order,
 * drawn whether or not each has happened: done where an entry exists, active on
 * the furthest one reached while the ticket is open, pending and greyed after
 * that. `Maintenance::timeline()` builds it, and it takes the FIRST entry per
 * stage — a ticket reassigned twice has three assignment entries and one
 * "Assigned to vendor" row, carrying the timestamp the resident was already
 * shown. The later ones are history, and history is not the spine.
 *
 * COMPLETED AND VERIFIED ARE TWO FACTS, WHICH IS WHY THERE ARE SIX STAGES AND
 * NOT FIVE. The estate saying a job is finished and the household agreeing are
 * different statements, and the gap between them is where a reopen comes from.
 * Nothing on this screen collapses them.
 *
 * THE REFUSALS BELONG TO THE SERVICE AND THIS SCREEN MAKES THEM FIRST.
 * `Maintenance::resolve()` will not close a ticket with an empty resolution —
 * "a ticket closed with no resolution tells the resident who reported it
 * nothing" — so the field is in front of the manager while they are still
 * looking at the job, rather than the press being sent to be bounced. Assigning
 * a closed ticket is refused outright, because work restarting on a finished job
 * is a REOPEN and the record has to say the fault came back rather than that it
 * never finished. So the primary action is Mark completed on an open ticket and
 * Reopen on a closed one; they are never both offered.
 *
 * NOT ONE FIGURE HERE IS THE REPORTER'S FINANCIAL POSITION. "Lot 47" in the meta
 * line is a place — the unit that has the fault — and the payload behind it
 * carries no balance, no bucket and no arrears age. The Property Manager holds
 * Facilities in full and holds nothing on Dues & ledger (D-010), and a
 * maintenance screen that named what a household owed would hand them exactly
 * what that rule locks them out of.
 *
 * WHAT THE BOARD DRAWS AND THIS PAGE DOES NOT: `history` — the reassignments,
 * priority changes and notes that advance no stage — is carried by the service
 * and rendered nowhere, exactly as board 18 draws it. It is used here for one
 * thing only: to tell a ticket with nothing recorded against it apart from the
 * spine from a ticket with nothing recorded against it at all.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    ticket: { type: Object, required: true },
    /** Six stages in lifecycle order, each done / active / pending. */
    timeline: { type: Array, required: true },
    /** Null where nobody has been given the job — board 18 draws an assigned one. */
    vendor: { type: Object, default: null },
    history: { type: Array, required: true },
    /** Names only. A vendor's TRN and what it is owed are Accounting's. */
    vendors: { type: Array, required: true },
    priorities: { type: Object, required: true },
    canUpdate: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
    reasons: { type: Object, required: true },
})

/*
 * Boards 17 to 20 share the batch-5 sheet, and the ten Estate Console sheets do
 * not share anything with each other — naming the wrong one renders this screen
 * with no ticket card, no timeline rail and no action stack at all.
 */
useWireframe('community-admin-05-maintenance-amenity-bookings-and-settings')

const page = usePage()

/**
 * All six, and the two empty ones are different tickets rather than two wordings.
 *
 * `rows` counts the stages that have actually HAPPENED, so a ticket whose spine
 * carries nothing is empty even though six rows would still be drawn. That is a
 * real record: the ticket-number migration writes a thin recovered ticket for
 * every number a bill already claims, and one of those has no history at all.
 *
 * `empty-filtered` is the same spine emptied by the VIEW rather than by the
 * ticket — the timeline reads only entries that advance a stage, so a recovered
 * ticket somebody has since re-prioritised has history and an empty spine. The
 * two need different sentences: one says nothing has happened, the other says
 * things have happened that this list does not hold.
 */
const state = useScreenState({
    rows: () => props.timeline.filter((stage) => stage.line !== null).length,
    filtered: () => props.history.length > 0,
})

const retry = () => router.reload()

/*
 * Where this console is rooted, read off the page's own URL. Production gives
 * each estate its own hostname and no prefix; local serves every estate from one
 * host as /estate/{key}/facilities/maintenance/{number}, so a hard-coded root is
 * correct in exactly one of the two — and cutting the URL that served this page
 * cannot address an estate other than the one already open.
 */
const root = computed(() => {
    const cut = page.url.indexOf('/facilities')

    return cut === -1 ? '' : page.url.slice(0, cut)
})

/** The queue, which is the screen the reader pressed "Open" on. */
const queueHref = computed(() => `${root.value}/facilities/maintenance`)

/** This ticket, addressed by the NUMBER its route binds on. */
const ticketPath = computed(() => `${queueHref.value}/${props.ticket.number}`)

/**
 * The board's class name for a priority, which is not the stored value.
 *
 * The sheet defines `.high`, `.med` and `.low`; the enum is high, medium, low.
 */
const dotClass = computed(() => (props.ticket.priority === 'medium' ? 'med' : props.ticket.priority))

/**
 * Board 18's sub-line under each stage heading, and the two places it is not
 * simply what the server composed.
 *
 * SUBMITTED PRINTS THE TIME AND NO NAME. The entry is attributed to the resident
 * — it has to be, the ticket is theirs — but the meta line two lines above
 * already reads "Submitted by Andrea Fletcher", and repeating it here would say
 * the same thing twice in eleven-pixel type.
 *
 * A NOTE OUTRANKS THE TIMESTAMP. Board 18 draws "Technician on site, today
 * 2:00–4:00 PM" against the active stage rather than when the vendor started,
 * because the note is what somebody said about this stage and the timestamp is
 * only when it began. Where a stage carries no note the timestamp and the actor
 * stand, which is rows one to three.
 *
 * A pending stage has no entry, so it has no line — null rather than an empty
 * string, and the board draws no .tt2 element at all for those two rows.
 */
const subLine = (stage) => {
    if (stage.line === null) {
        return null
    }

    return stage.key === 'submitted' ? props.ticket.reported_at : (stage.note ?? stage.line)
}

/* ------------------------------------------------------------------ */
/* the three acts, and why each one may be refused */
/* ------------------------------------------------------------------ */

/** Which authored panel is open: 'resolve', 'assign', 'reopen', 'priority', or none. */
const panel = ref(null)

const resolveForm = useForm({ resolution: '' })

const assignForm = useForm({
    vendor_id: '',
    technician_name: '',
    technician_phone: '',
    /*
     * Blank rather than today. An ETA is a promise made to a household, and
     * defaulting it to now would put a window in front of a resident that nobody
     * agreed with the vendor.
     */
    eta_starts_at: '',
    eta_ends_at: '',
})

const reopenForm = useForm({ reason: '' })

/*
 * The optional fields travel as null rather than as "". A technician nobody
 * named and a window nobody agreed are ABSENT facts, and an empty string stored
 * against either would read back as a technician with no name.
 */
const blankToNull = (value) => (String(value).trim() === '' ? null : value)

assignForm.transform((data) => ({
    ...data,
    technician_name: blankToNull(data.technician_name),
    technician_phone: blankToNull(data.technician_phone),
    eta_starts_at: blankToNull(data.eta_starts_at),
    eta_ends_at: blankToNull(data.eta_ends_at),
}))

/** The viewer's own access, which is true of every control on the screen. */
const accessBlockedBy = computed(() => (props.canUpdate ? null : props.blockedReason))

/**
 * Why the primary action cannot be pressed, or null when it can.
 *
 * There is only ever one primary, and which one it is comes from the ticket
 * rather than from a preference: an open job can be finished and a finished one
 * can only come back. Neither is refused for any reason except access — the
 * service's own refusals are exactly what decided which of the two is drawn.
 */
const primaryBlockedBy = accessBlockedBy

const assignBlockedBy = computed(() => {
    if (accessBlockedBy.value !== null) {
        return accessBlockedBy.value
    }

    /*
     * The service's own refusal, said here first. Sending a vendor to a closed
     * job would leave a record that says the work never finished, when what
     * actually happened is that it came back.
     */
    if (props.ticket.is_closed) {
        return 'This ticket is closed, so no vendor can be given it. Reopen it first — that leaves the original closure standing in the record and says the fault came back, rather than quietly making a finished job unfinished.'
    }

    if (props.vendors.length === 0) {
        return 'The supplier register holds no active vendor, so there is nobody to give this job to. Vendors are added in the Accounting module, which is where the estate approves who it may pay.'
    }

    return null
})

const priorityBlockedBy = computed(() => {
    if (accessBlockedBy.value !== null) {
        return accessBlockedBy.value
    }

    if (props.ticket.is_closed) {
        return 'This ticket is closed, and a priority only sets a deadline for work still outstanding. There is none.'
    }

    return null
})

const openPanel = (which) => {
    panel.value = panel.value === which ? null : which

    resolveForm.clearErrors()
    assignForm.clearErrors()
    reopenForm.clearErrors()
}

const closePanel = () => {
    panel.value = null
}

const submitResolve = () => {
    /*
     * A form submits on Enter from any field and a disabled button does not stop
     * it. The route is gated on `estate.facilities.update` and the service
     * refuses an empty resolution, so this is only about not sending a press
     * that is going to bounce.
     */
    if (primaryBlockedBy.value !== null) {
        return
    }

    resolveForm.post(`${ticketPath.value}/resolve`, { preserveScroll: true })
}

const submitAssign = () => {
    if (assignBlockedBy.value !== null) {
        return
    }

    assignForm.post(`${ticketPath.value}/assign`, {
        preserveScroll: true,
        onSuccess: () => closePanel(),
    })
}

const submitReopen = () => {
    if (primaryBlockedBy.value !== null) {
        return
    }

    reopenForm.post(`${ticketPath.value}/reopen`, {
        preserveScroll: true,
        onSuccess: () => closePanel(),
    })
}

/**
 * Move the priority.
 *
 * A plain post rather than a form: one field, three values, each its own button.
 * The deadline moves with it and the clock does not reset — escalating a
 * three-day-old medium ticket to high makes it overdue immediately, because high
 * means a day from the report and the report was three days ago.
 */
const setPriority = (key) => {
    if (priorityBlockedBy.value !== null || key === props.ticket.priority) {
        return
    }

    router.post(`${ticketPath.value}/priority`, { priority: key }, { preserveScroll: true })
}
</script>

<template>
    <Head :title="ticket.heading" />

    <EstateConsole :title="ticket.heading" :estate-name="estate.name" active="facilities">
        <!--
          The back chevron. This board draws it as the 34px navy circle the other
          estate detail screens use, and its declarations live inline because the
          sheet has no class for it — copied verbatim onto a real link, so the
          control can be clicked, focused and opened in a new tab.

          `flex:0 0 auto` is the one addition: the board's <div> sits in a flex
          topbar with room to spare, and an anchor in the same row would shrink
          below 34px on a narrow viewport the board never had to survive.
        -->
        <template #lead>
            <Link
                :href="queueHref"
                style="width:34px;height:34px;border-radius:50%;background:var(--navy-100);display:flex;align-items:center;justify-content:center;flex:0 0 auto;"
                title="Back to the maintenance queue"
                aria-label="Back to the maintenance queue"
            >
                <svg viewBox="0 0 24 24" fill="none" style="width:16px;height:16px;color:var(--navy-700);">
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
        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="4" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="This ticket is not part of your role’s access"
            body="A maintenance ticket names a household, a fault in their home and the contractor sent to it, so it opens only to roles that hold Facilities. A committee officer or the estate administrator can grant it from the role access matrix."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="This ticket could not be loaded"
            body="The estate database did not answer. Nothing has been closed, nobody has been reassigned and the service clock is untouched — the job stands exactly as it did before this screen opened."
            action-label="Try again"
            @action="retry"
        />

        <template v-else>
            <!--
              The flash and the refusal, neither of which is on the board: a
              board is a still image and nothing has ever been pressed on it.
              Both are absent on a fresh GET, so neither touches the geometry.

              Only the PRIORITY refusal is drawn at page level, because that is
              the one act with no panel of its own — the other three come back
              into the form that sent them, beside the field that caused them,
              and repeating them here would state the same refusal twice.
            -->
            <p v-if="page.props.flash.success" class="ticket-flash">{{ page.props.flash.success }}</p>
            <p v-if="page.props.errors.priority" class="ticket-refusal">{{ page.props.errors.priority }}</p>

            <div class="ticket-head">
                <div class="ticket-summary">
                    <div class="ts-top">
                        <div>
                            <!-- "Gate lighting — Phase 2 visitor parking": the
                                 title and the location, with the queue's middot
                                 form flattened to a space. Composed on the
                                 server, so both screens spell one place the
                                 same way. -->
                            <div class="ts-title">{{ ticket.summary_title }}</div>

                            <!-- "Submitted by Andrea Fletcher · Lot 47 · Sep 2,
                                 6:40 PM · Category: Electrical" — composed out
                                 of only the parts this ticket has, so an office
                                 work order with no reporter closes up rather
                                 than leaving a stray middot in the line. -->
                            <div class="ts-meta">{{ ticket.meta }}</div>
                        </div>

                        <!--
                          The board draws the priority as a readout. It is the
                          one fact on this card that a manager changes, so it is
                          the control that changes it — pressing the thing you
                          want to alter, rather than hunting for a fourth button
                          in a stack the board draws with three.
                        -->
                        <button
                            type="button"
                            class="priority-dot"
                            :class="dotClass"
                            :disabled="priorityBlockedBy !== null"
                            :title="
                                priorityBlockedBy ??
                                'Raise or lower the priority. The service target moves with it and the clock does not reset — this ticket is always measured from the moment it was reported.'
                            "
                            @click="openPanel('priority')"
                        >
                            <i></i>{{ ticket.priority_label }} priority
                        </button>
                    </div>

                    <!-- The resident's own words, in their own words. Absent
                         rather than empty on a ticket nobody described: a pair
                         of quote marks around nothing is not a report. -->
                    <div v-if="ticket.description" class="ts-desc">"{{ ticket.description }}"</div>
                </div>

                <!--
                  Three actions, which is what the board draws, and the first of
                  them is whichever one this ticket can actually take. An open
                  job can be finished; a finished one can only come back, and
                  offering both would offer to close a closed ticket.
                -->
                <div class="action-stack">
                    <button
                        v-if="!ticket.is_closed"
                        type="button"
                        class="stack-btn primary"
                        :disabled="primaryBlockedBy !== null"
                        :title="
                            primaryBlockedBy ??
                            'Close the job and say what was done. The closing date is what the queue prints in place of the age and what the average-resolution figure is measured across.'
                        "
                        @click="openPanel('resolve')"
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
                        <span>Mark completed</span>
                    </button>

                    <button
                        v-else
                        type="button"
                        class="stack-btn primary"
                        :disabled="primaryBlockedBy !== null"
                        :title="
                            primaryBlockedBy ??
                            'The fault came back. The original closure stays in the record — the pair is how anybody ever sees a repair that did not hold.'
                        "
                        @click="openPanel('reopen')"
                    >
                        <svg viewBox="0 0 24 24" fill="none">
                            <path
                                d="M3.5 12a8.5 8.5 0 1 0 2.6-6.1"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                            />
                            <path
                                d="M3 3.5V8h4.5"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                        <span>Reopen ticket</span>
                    </button>

                    <!-- One method for both, because assigning and reassigning
                         are the same act with the same consequence — somebody
                         is being asked to do a job — and the activity log is
                         what distinguishes them. The label follows the ticket. -->
                    <button
                        type="button"
                        class="stack-btn outline"
                        :disabled="assignBlockedBy !== null"
                        :title="
                            assignBlockedBy ??
                            'Give this job to a supplier. It does not move the deadline: a ticket assigned on day six of a one-day target is five days overdue the moment the vendor is named.'
                        "
                        @click="openPanel('assign')"
                    >
                        <svg viewBox="0 0 24 24" fill="none">
                            <path
                                d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"
                                stroke="currentColor"
                                stroke-width="1.7"
                            />
                            <circle cx="12" cy="7" r="4" stroke="currentColor" stroke-width="1.7" />
                        </svg>
                        <span>{{ vendor === null ? 'Assign vendor' : 'Reassign vendor' }}</span>
                    </button>

                    <!-- Inert, and it is the one control here whose backing does
                         not exist anywhere in the application. Messaging the
                         reporter opens a thread that reaches their phone, and a
                         maintenance ticket is not the place to invent one. -->
                    <button type="button" class="stack-btn outline" disabled :title="reasons.message">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path
                                d="M4 11v2a1 1 0 0 0 1 1h2l4 4V6L7 10H5a1 1 0 0 0-1 1z"
                                stroke="currentColor"
                                stroke-width="1.7"
                                stroke-linejoin="round"
                            />
                            <path d="M17 8a5 5 0 0 1 0 8" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                        </svg>
                        <span>Message resident</span>
                    </button>
                </div>
            </div>

            <!--
              Authored, and drawn only for a ticket that has breached. The board
              draws a job inside its target and has no equivalent, and this is
              the one thing about an overdue ticket a reader cannot work out from
              what is drawn — the deadline is arithmetic on two columns and
              appears nowhere on the card.
            -->
            <p v-if="ticket.is_overdue" class="sla-note">
                Overdue — reported {{ ticket.age }} ago and due {{ ticket.sla_due }}. The target runs from the
                report and not from the assignment, so a ticket that sat in the queue is late by however long it
                sat there.
            </p>

            <!--
              The panels. All four are authored — the board is a still image of a
              screen nobody has pressed anything on — and all four are closed on
              a fresh GET, so none of them touches this screen's geometry. One at
              a time, because each is an act against this one job and two open
              forms would ask which of them the Enter key belongs to.
            -->
            <form v-if="panel === 'resolve'" class="act-panel" @submit.prevent="submitResolve">
                <div class="act-head">Close {{ ticket.heading }}</div>

                <div class="act-field">
                    <label for="act-resolution">
                        What was done — the resident who reported this reads it, and so does the next person to
                        hit the same fault
                    </label>
                    <textarea
                        id="act-resolution"
                        v-model="resolveForm.resolution"
                        rows="3"
                        required
                        maxlength="2000"
                        placeholder="Both gate floodlights replaced and the photocell re-set."
                    ></textarea>
                </div>

                <div v-if="resolveForm.errors.resolution" class="act-error">{{ resolveForm.errors.resolution }}</div>

                <div class="act-actions">
                    <button
                        type="submit"
                        class="btn-primary-sm"
                        :disabled="resolveForm.processing"
                        title="Close the job, stamp the day it finished and record who closed it."
                    >
                        <span>{{ resolveForm.processing ? 'Closing…' : 'Mark completed' }}</span>
                    </button>
                    <button type="button" class="text-link-sm" @click="closePanel">Cancel</button>
                </div>
            </form>

            <form v-else-if="panel === 'assign'" class="act-panel" @submit.prevent="submitAssign">
                <div class="act-head">
                    {{ vendor === null ? 'Give this job to a supplier' : `Reassign from ${vendor.name}` }}
                </div>

                <div class="act-fields">
                    <div class="act-field">
                        <label for="act-vendor">Vendor</label>
                        <!-- The placeholder is a real, selectable option with an
                             empty value rather than a disabled one: `required`
                             on the select already refuses the empty string, and
                             a control that is permanently inert owes the reader
                             a reason it has not got — this one is a prompt. -->
                        <select id="act-vendor" v-model="assignForm.vendor_id" required>
                            <option value="">Choose a supplier…</option>
                            <option v-for="option in vendors" :key="option.id" :value="option.id">
                                {{ option.name }}
                            </option>
                        </select>
                    </div>

                    <div class="act-field">
                        <label for="act-technician">Technician</label>
                        <input
                            id="act-technician"
                            v-model="assignForm.technician_name"
                            type="text"
                            maxlength="160"
                            placeholder="Who is coming"
                        />
                    </div>

                    <div class="act-field">
                        <label for="act-phone">Phone</label>
                        <input
                            id="act-phone"
                            v-model="assignForm.technician_phone"
                            type="tel"
                            maxlength="40"
                            placeholder="(876) 555 0110"
                        />
                    </div>

                    <div class="act-field">
                        <label for="act-eta-from">ETA from</label>
                        <input id="act-eta-from" v-model="assignForm.eta_starts_at" type="datetime-local" />
                    </div>

                    <div class="act-field">
                        <label for="act-eta-to">ETA to</label>
                        <input id="act-eta-to" v-model="assignForm.eta_ends_at" type="datetime-local" />
                    </div>
                </div>

                <div v-if="assignForm.errors.vendor_id" class="act-error">{{ assignForm.errors.vendor_id }}</div>
                <div v-if="assignForm.errors.eta_ends_at" class="act-error">{{ assignForm.errors.eta_ends_at }}</div>

                <div class="act-actions">
                    <button
                        type="submit"
                        class="btn-primary-sm"
                        :disabled="assignForm.processing"
                        title="Record who has the job. The deadline does not move — it is measured from the report."
                    >
                        <span>{{ assignForm.processing ? 'Assigning…' : 'Assign' }}</span>
                    </button>
                    <button type="button" class="text-link-sm" @click="closePanel">Cancel</button>
                </div>
            </form>

            <form v-else-if="panel === 'reopen'" class="act-panel" @submit.prevent="submitReopen">
                <div class="act-head">Reopen {{ ticket.heading }}</div>

                <div class="act-field">
                    <label for="act-reason">
                        Why it is being reopened — a job that was signed off and then was not is the one thing a
                        maintenance record has to be able to explain
                    </label>
                    <input
                        id="act-reason"
                        v-model="reopenForm.reason"
                        type="text"
                        required
                        maxlength="300"
                        placeholder="The same light failed again four days after the repair."
                    />
                </div>

                <div v-if="reopenForm.errors.reason" class="act-error">{{ reopenForm.errors.reason }}</div>

                <div class="act-actions">
                    <button
                        type="submit"
                        class="btn-primary-sm"
                        :disabled="reopenForm.processing"
                        title="Put the job back in the queue. The original closure stays in the record, and the service clock still runs from the first report."
                    >
                        <span>{{ reopenForm.processing ? 'Reopening…' : 'Reopen' }}</span>
                    </button>
                    <button type="button" class="text-link-sm" @click="closePanel">Cancel</button>
                </div>
            </form>

            <div v-else-if="panel === 'priority'" class="act-panel">
                <div class="act-head">
                    Priority sets the service target: one day at high, three at medium, seven at low. Changing it
                    moves the deadline and does not restart the clock.
                </div>

                <div class="act-actions">
                    <button
                        v-for="(label, key) in priorities"
                        :key="key"
                        type="button"
                        class="f-chip"
                        :class="{ active: key === ticket.priority }"
                        :disabled="key === ticket.priority"
                        :title="
                            key === ticket.priority
                                ? `This ticket is already ${label.toLowerCase()} priority.`
                                : `Set ${label.toLowerCase()} priority, measured from when the fault was reported.`
                        "
                        @click="setPriority(key)"
                    >
                        {{ label }}
                    </button>

                    <button type="button" class="text-link-sm" @click="closePanel">Cancel</button>
                </div>
            </div>

            <!--
              The spine. Six rows always, in lifecycle order and never in
              timestamp order — a stage nobody has reached still occupies its
              place, greyed, because the point of the list is that the resident
              can see what is left.
            -->
            <EmptyState
                v-if="state.isEmptyFiltered.value"
                variant="filtered"
                title="Nothing on this ticket has advanced its lifecycle"
                body="Things have been recorded against this job — a reassignment, a priority change, a note — but none of them moved it from one stage to the next, and this list holds only the six stages that make up the spine. Nothing has been lost."
            />

            <EmptyState
                v-else-if="state.isEmpty.value"
                variant="first-use"
                title="Nothing has been recorded against this ticket"
                body="The job exists and can be worked, but no state change has ever been written down for it — which is what a ticket recovered from a bill that named its number looks like. Assigning it or closing it writes the first entry, and the resident can follow every one after it."
            />

            <div v-else class="tl2">
                <div v-for="stage in timeline" :key="stage.key" class="tl2-row">
                    <div class="tl2-dot" :class="stage.state">
                        <svg v-if="stage.state === 'done'" viewBox="0 0 24 24" fill="none">
                            <polyline
                                points="20 6 9 17 4 12"
                                stroke="currentColor"
                                stroke-width="3"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                        <svg v-else-if="stage.state === 'active'" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="5" fill="currentColor" />
                        </svg>
                    </div>

                    <div class="tl2-txt" :class="{ muted: stage.state === 'pending' }">
                        <div class="tt1">{{ stage.label }}</div>
                        <div v-if="subLine(stage)" class="tt2">{{ subLine(stage) }}</div>
                    </div>
                </div>
            </div>

            <!--
              Who has the job. "Tech: Owen Grant · (876) 555 0110 · ETA today
              2:00–4:00 PM" is composed on the server out of the parts this
              assignment has, so a supplier with no named technician and no
              window still reads as a supplier rather than as a broken line.
            -->
            <div v-if="vendor !== null" class="vendor-panel">
                <div class="vendor-avatar">{{ vendor.initials }}</div>
                <div class="vendor-info">
                    <div class="vn">{{ vendor.name }}</div>
                    <div v-if="vendor.detail" class="vd">{{ vendor.detail }}</div>
                </div>
            </div>

            <!--
              The board draws an assigned ticket and has no panel for this. It is
              the same card rather than an authored one, because "nobody has this
              job" is a fact about the job in the place the reader already looks
              for who has it — an absent panel would read as a screen that had
              not finished loading.
            -->
            <div v-else class="vendor-panel">
                <div class="vendor-avatar">—</div>
                <div class="vendor-info">
                    <div class="vn">Unassigned</div>
                    <div class="vd">
                        Nobody has been given this job. The service clock started when the fault was reported and
                        is running regardless — assigning a vendor records who has it and moves no deadline.
                    </div>
                </div>
            </div>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only, and each removal names the element that needs it.
 *
 * The board draws its action stack and its priority readout as <div>s; here they
 * are real buttons, which arrive wearing the browser's own font, a border and
 * buttonface grey. The board's classes supply everything visible, and app.css
 * already takes the UA's underline and blue off every anchor on a board page.
 *
 * THE BORDER RESET NAMES THE VARIANT THAT HAS NO BORDER. A scoped element
 * selector outranks a single class, so `button.stack-btn { border: 0 }` would
 * beat .stack-btn.outline and rub out the 1.5px navy edge that is the entire
 * difference between the outline variant and the solid one — a real fidelity
 * defect small enough to survive a passing measurement. See D-045.
 */
button {
    font: inherit;
}

/*
 * `font: inherit` rather than `font-family: inherit`, because .priority-dot
 * declares no type at all: the board's <div> takes its size and colour from the
 * card around it, and a button element left alone would take 13.33px Arial and
 * buttontext from the user agent. Every class that DOES declare a size — the
 * span inside .stack-btn, .f-chip, .text-link-sm — out-specifies this rule.
 */
button.priority-dot {
    border: 0;
    background: none;
    padding: 0;
    color: inherit;
    cursor: pointer;
}

/* .stack-btn.primary declares a background and no border; .stack-btn.outline
 * declares both, and its border is the variant. .btn-primary-sm and .f-chip
 * each declare their own face and no border. */
button.stack-btn.primary,
button.btn-primary-sm,
button.f-chip {
    border: 0;
}

button.stack-btn {
    cursor: pointer;
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
 * AUTHORED BELOW THIS LINE. The board draws no flash, no refusal, no SLA note
 * and no panel of any kind, because nothing has ever been pressed on it — and
 * every one of those is something this screen has to be able to say. Kept to the
 * tokens the boards define and to the shapes they already use: the panel is the
 * white card with the navy-100 edge that .tl2 and .vendor-panel already are, and
 * the type sizes are .ts-meta's and .tt2's.
 */
.ticket-flash,
.ticket-refusal {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 14px;
}

.ticket-flash {
    background: var(--green-100);
    color: var(--green-700);
}

.ticket-refusal {
    background: var(--red-100);
    color: var(--red-700);
}

/* Amber rather than red: a breached target is a job running late, not a fault
 * in the record. Red is what the queue's badge already uses for the count. */
.sla-note {
    font-size: 11.5px;
    color: var(--amber-700);
    line-height: 1.6;
    max-width: 760px;
    margin: 0 0 16px;
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
}

.act-fields {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 11px;
}

.act-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.act-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.act-field input,
.act-field select,
.act-field textarea {
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

.act-field input,
.act-field select {
    height: 34px;
}

.act-field textarea {
    padding: 9px 10px;
    line-height: 1.55;
    resize: vertical;
}

.act-field input::placeholder,
.act-field textarea::placeholder {
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
