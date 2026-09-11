<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * One booking and the deposit behind it — the booking detail.
 *
 * NOT ON ANY APPROVED BOARD, AND ADDED DELIBERATELY (D-086). Board 19 draws the
 * deposit STATE in its diary and no control that changes it, and the service
 * that moves the money had no route at all. The client's ruling: "A service
 * that moves money with no route is a worse gap than the caution it replaced.
 * Put the control on the amenity booking detail." So this screen exists to carry
 * that control, and it borrows board 18's shapes — the summary card, the action
 * stack, the timeline rail — from the same stylesheet rather than inventing a
 * fourth visual language for one screen nobody drew.
 *
 * THREE ACTS, TWO GATES, AND THE HIGHER ONE KEEPS MONEY. Recording the deposit
 * received and refunding it need Facilities update: each is the estate's books
 * catching up with cash that has already moved. Forfeiting keeps a resident's
 * money as the estate's income, so it needs Facilities APPROVE — "the higher
 * verb" — and a reason, and the reason field is put in front of the press rather
 * than the press being sent to be bounced. `canUpdate` and `canApprove` answer
 * the two gates; the deposit's own state answers the rest.
 *
 * THE ENTRY IS NAMED BEFORE IT IS MADE. Each panel says what it will post, and
 * none of the three touches 1200 Dues Receivable: a deposit is the estate
 * holding somebody's money, not somebody owing the estate money.
 *
 * NOT ONE FIGURE HERE IS A HOUSEHOLD'S FINANCIAL POSITION. The Property Manager
 * holds Facilities and nothing on Dues & ledger (D-010). The amounts on this
 * screen are the terms snapshotted onto the booking — its fee and its deposit —
 * which are facts about the booking rather than about the household's account.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    booking: { type: Object, required: true },
    /** The deposit as the booking carries it: amount, state, and its three steps. */
    deposit: { type: Object, required: true },
    canUpdate: { type: Boolean, required: true },
    canApprove: { type: Boolean, required: true },
    updateReason: { type: String, required: true },
    approveReason: { type: String, required: true },
})

/*
 * Boards 17 to 20 share the batch-5 sheet, and so does this screen: it is drawn
 * from board 18's classes, and naming any other sheet would render it with no
 * summary card, no action stack and no rail.
 */
useWireframe('community-admin-05-maintenance-amenity-bookings-and-settings')

const page = usePage()

/*
 * Populated where the booking carries a deposit; empty where it never took one —
 * a Pool Deck booking has nothing to hold, and the rail would be three steps of
 * nothing. There is no filter here, so empty-filtered is reachable only when
 * forced, and it says so.
 */
const state = useScreenState({
    rows: () => (props.deposit.amount === null ? 0 : 1),
})

const retry = () => router.reload()

/*
 * Where this console is rooted, read off the page's own URL — production gives
 * each estate its own hostname and no prefix, local serves every estate from one
 * host under /estate/{key}. Cutting the URL that served this page is correct in
 * both and cannot address an estate other than the one already open.
 */
const root = computed(() => {
    const cut = page.url.indexOf('/facilities')

    return cut === -1 ? '' : page.url.slice(0, cut)
})

/** The diary, which is where the reader pressed "View". */
const diaryHref = computed(() => `${root.value}/facilities/amenities/bookings`)

const bookingPath = computed(() => `${diaryHref.value}/${props.booking.id}`)

/*
 * The diary's own badge rule: the sheet draws Confirmed, Pending and Completed,
 * and Declined and Cancelled take the neutral navy the boards use for a fact
 * that is simply over.
 */
const NEUTRAL_BADGE = 'background:var(--navy-100);color:var(--slate-600);'

const badgeStyle = computed(() =>
    ['confirmed', 'pending', 'completed'].includes(props.booking.status) ? undefined : NEUTRAL_BADGE
)

/* ------------------------------------------------------------------ */
/* the three acts, and why each one may be refused */
/* ------------------------------------------------------------------ */

/** Which panel is open: 'hold', 'refund', 'forfeit', or none. One at a time. */
const panel = ref(null)

const holdForm = useForm({ on: '' })
const refundForm = useForm({ on: '' })
const forfeitForm = useForm({ reason: '' })

/*
 * A blank date travels as null, which the service reads as today. An empty
 * string would be refused by the date rule and tell the reader their date was
 * wrong when they had not given one.
 */
const blankToNull = (value) => (String(value).trim() === '' ? null : value)

holdForm.transform((data) => ({ on: blankToNull(data.on) }))
refundForm.transform((data) => ({ on: blankToNull(data.on) }))

/**
 * Why the deposit cannot be recorded as received, or null when it can.
 *
 * The service's own refusals, said here first. A deposit is taken once, from
 * awaiting, and only for a booking that may still happen.
 */
const holdBlockedBy = computed(() => {
    if (!props.canUpdate) {
        return props.updateReason
    }

    if (props.deposit.state === 'held') {
        return 'The estate is already holding this deposit. Recording it again would put the same money in the bank twice.'
    }

    if (props.deposit.state !== 'awaiting') {
        return `This deposit has been ${props.deposit.state}. A deposit is taken once, and this one has already gone back or been kept.`
    }

    if (props.booking.status === 'declined' || props.booking.status === 'cancelled') {
        return `This booking was ${props.booking.status}, so there is no event for a deposit to secure. Recording one held would leave the estate owing money on a booking that will never happen.`
    }

    return null
})

const refundBlockedBy = computed(() => {
    if (!props.canUpdate) {
        return props.updateReason
    }

    if (props.deposit.state !== 'held') {
        return 'Only a deposit the estate is holding can be refunded. Refunding money it never received would take it out of the operating account.'
    }

    return null
})

/**
 * Why the deposit cannot be kept, or null when it can.
 *
 * THE HIGHER VERB COMES SECOND, after the lower one, because a viewer without
 * update holds no approve either — and the sentence they need is the one about
 * the access they actually lack.
 */
const forfeitBlockedBy = computed(() => {
    if (!props.canUpdate) {
        return props.updateReason
    }

    if (!props.canApprove) {
        return props.approveReason
    }

    if (props.deposit.state !== 'held') {
        return 'Only a deposit the estate is holding can be forfeited. The estate cannot keep money it does not have.'
    }

    return null
})

const openPanel = (which) => {
    panel.value = panel.value === which ? null : which

    holdForm.clearErrors()
    refundForm.clearErrors()
    forfeitForm.clearErrors()
}

const closePanel = () => {
    panel.value = null
}

/*
 * A form submits on Enter from any field and a disabled button does not stop
 * it. Every route is gated and the service refuses every one of these states,
 * so the guards below are only about not sending a press that will bounce.
 */
const submitHold = () => {
    if (holdBlockedBy.value !== null) {
        return
    }

    holdForm.post(`${bookingPath.value}/deposit/hold`, {
        preserveScroll: true,
        onSuccess: () => closePanel(),
    })
}

const submitRefund = () => {
    if (refundBlockedBy.value !== null) {
        return
    }

    refundForm.post(`${bookingPath.value}/deposit/refund`, {
        preserveScroll: true,
        onSuccess: () => closePanel(),
    })
}

const submitForfeit = () => {
    if (forfeitBlockedBy.value !== null || forfeitForm.reason.trim() === '') {
        return
    }

    forfeitForm.post(`${bookingPath.value}/deposit/forfeit`, {
        preserveScroll: true,
        onSuccess: () => {
            closePanel()
            forfeitForm.reset()
        },
    })
}
</script>

<template>
    <Head :title="`Booking ${booking.reference}`" />

    <EstateConsole :title="`Booking ${booking.reference}`" :estate-name="estate.name" active="facilities">
        <!--
          The back chevron, drawn the way board 18 draws its own: the 34px navy
          circle the estate detail screens share, inline because the sheet has no
          class for it.
        -->
        <template #lead>
            <Link
                :href="diaryHref"
                style="width:34px;height:34px;border-radius:50%;background:var(--navy-100);display:flex;align-items:center;justify-content:center;flex:0 0 auto;"
                title="Back to the booking diary"
                aria-label="Back to the booking diary"
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

        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="4" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="This booking is not part of your role’s access"
            body="A booking names a household, the amenity they reserved and the deposit the estate is holding for them, so it opens only to roles that hold Facilities. A committee officer or the estate administrator can grant it from the role access matrix."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="This booking could not be loaded"
            body="The estate database did not answer. No deposit has been taken, refunded or kept — the booking and its money stand exactly as they did before this screen opened, and re-running this read is safe."
            action-label="Try again"
            @action="retry"
        />

        <template v-else>
            <!--
              The flash and the refusal. The service's refusals come back under
              `deposit`; the forfeit's reason comes back into its own panel,
              beside the field that caused it.
            -->
            <p v-if="page.props.flash.success" class="bk-flash">{{ page.props.flash.success }}</p>
            <p v-if="page.props.errors.deposit" class="bk-refusal">{{ page.props.errors.deposit }}</p>

            <div class="ticket-head">
                <div class="ticket-summary">
                    <div class="ts-top">
                        <div>
                            <div class="ts-title">{{ booking.amenity }} — {{ booking.resident }}, {{ booking.unit }}</div>
                            <div class="ts-meta">
                                {{ booking.reference }} · {{ booking.when
                                }}<template v-if="booking.guests"> · {{ booking.guests }} guests</template>
                            </div>
                        </div>

                        <div class="status-badge" :class="booking.status" :style="badgeStyle">
                            {{ booking.status_label }}
                        </div>
                    </div>

                    <!--
                      The terms AS THE BOOKING CARRIES THEM — copied off the
                      amenity when it was made, and never read back, so the rate
                      card can change tomorrow without restating these.
                    -->
                    <dl class="bk-terms">
                        <div>
                            <dt>Booking fee</dt>
                            <dd>{{ booking.fee }}<template v-if="booking.fee_charged"> · charged to the unit</template></dd>
                        </div>
                        <div>
                            <dt>Deposit</dt>
                            <dd>{{ deposit.amount ?? 'None' }}</dd>
                        </div>
                        <div v-if="booking.cancellation">
                            <dt>Cancellation notice</dt>
                            <dd>{{ booking.cancellation }}</dd>
                        </div>
                        <div v-if="booking.decision">
                            <dt>Decision</dt>
                            <dd>{{ booking.decision }}</dd>
                        </div>
                    </dl>

                    <div v-if="booking.notes" class="ts-desc">"{{ booking.notes }}"</div>
                </div>

                <!--
                  The deposit door. The primary is whichever act the deposit can
                  take next — recording it received while it is awaited, refunding
                  it while it is held — and forfeiting is never the primary: keeping
                  a resident's money is the exception, not the next step.
                -->
                <div v-if="deposit.amount !== null" class="action-stack">
                    <button
                        type="button"
                        class="stack-btn"
                        :class="deposit.state === 'awaiting' ? 'primary' : 'outline'"
                        :disabled="holdBlockedBy !== null"
                        :title="
                            holdBlockedBy ??
                            `Record the ${deposit.amount} deposit as received — Dr 1000 Bank, Cr 2200 Resident Deposits Held.`
                        "
                        @click="openPanel('hold')"
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
                        <span>Record deposit received</span>
                    </button>

                    <button
                        type="button"
                        class="stack-btn"
                        :class="deposit.state === 'held' ? 'primary' : 'outline'"
                        :disabled="refundBlockedBy !== null"
                        :title="
                            refundBlockedBy ??
                            `Return the ${deposit.amount} to the household — Dr 2200 Resident Deposits Held, Cr 1000 Bank.`
                        "
                        @click="openPanel('refund')"
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
                        <span>Refund deposit</span>
                    </button>

                    <button
                        type="button"
                        class="stack-btn outline"
                        :disabled="forfeitBlockedBy !== null"
                        :title="
                            forfeitBlockedBy ??
                            `Keep the ${deposit.amount} as the estate's income — Dr 2200 Resident Deposits Held, Cr 4100 Amenity Booking Fees. A reason is required.`
                        "
                        @click="openPanel('forfeit')"
                    >
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="5" y="11" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.7" />
                            <path d="M8 11V7a4 4 0 0 1 8 0v4" stroke="currentColor" stroke-width="1.7" />
                        </svg>
                        <span>Forfeit deposit</span>
                    </button>
                </div>
            </div>

            <!--
              The panels, all closed on a fresh GET. Each names the entry it will
              post before the press, because on a screen that moves money the
              reader should know which way it is about to move.
            -->
            <form v-if="panel === 'hold'" class="act-panel" @submit.prevent="submitHold">
                <div class="act-head">Record the {{ deposit.amount }} deposit on {{ booking.reference }} as received</div>
                <p class="act-note">
                    Posts Dr 1000 Bank, Cr 2200 Resident Deposits Held. The estate is holding the household’s money and
                    owes it back after the event; nothing is charged to the unit.
                </p>

                <div class="act-field act-date">
                    <label for="act-hold-on">Received on — leave blank for today</label>
                    <input id="act-hold-on" v-model="holdForm.on" type="date" />
                </div>

                <div v-if="holdForm.errors.on" class="act-error">{{ holdForm.errors.on }}</div>

                <div class="act-actions">
                    <button
                        type="submit"
                        class="btn-primary-sm"
                        :disabled="holdForm.processing"
                        title="Post the deposit to the bank and to the deposits-held liability."
                    >
                        <span>{{ holdForm.processing ? 'Recording…' : 'Record as held' }}</span>
                    </button>
                    <button type="button" class="text-link-sm" @click="closePanel">Cancel</button>
                </div>
            </form>

            <form v-else-if="panel === 'refund'" class="act-panel" @submit.prevent="submitRefund">
                <div class="act-head">Refund the {{ deposit.amount }} deposit on {{ booking.reference }}</div>
                <p class="act-note">
                    Posts Dr 2200 Resident Deposits Held, Cr 1000 Bank — the reverse of taking it. A deposit returned was
                    never the estate’s to earn, so it never reaches income.
                </p>

                <div class="act-field act-date">
                    <label for="act-refund-on">Refunded on — leave blank for today</label>
                    <input id="act-refund-on" v-model="refundForm.on" type="date" />
                </div>

                <div v-if="refundForm.errors.on" class="act-error">{{ refundForm.errors.on }}</div>

                <div class="act-actions">
                    <button
                        type="submit"
                        class="btn-primary-sm"
                        :disabled="refundForm.processing"
                        title="Post the refund and release the deposit from the liability."
                    >
                        <span>{{ refundForm.processing ? 'Refunding…' : 'Refund deposit' }}</span>
                    </button>
                    <button type="button" class="text-link-sm" @click="closePanel">Cancel</button>
                </div>
            </form>

            <form v-else-if="panel === 'forfeit'" class="act-panel" @submit.prevent="submitForfeit">
                <div class="act-head">Keep the {{ deposit.amount }} deposit on {{ booking.reference }}</div>
                <p class="act-note">
                    Posts Dr 2200 Resident Deposits Held, Cr 4100 Amenity Booking Fees — the only way a deposit ever
                    becomes the estate’s income. No cash moves; it has been in the bank since it was taken.
                </p>

                <div class="act-field">
                    <label for="act-forfeit-reason">
                        Why the estate is keeping it — the household will ask, and this is the answer the committee gives
                    </label>
                    <textarea
                        id="act-forfeit-reason"
                        v-model="forfeitForm.reason"
                        rows="3"
                        required
                        maxlength="300"
                        placeholder="Gazebo returned with two chairs broken and the lighting rig down."
                    ></textarea>
                </div>

                <div v-if="forfeitForm.errors.reason" class="act-error">{{ forfeitForm.errors.reason }}</div>

                <div class="act-actions">
                    <button
                        type="submit"
                        class="btn-primary-sm"
                        :disabled="forfeitForm.processing || forfeitForm.reason.trim() === ''"
                        :title="
                            forfeitForm.reason.trim() === ''
                                ? 'Say why the deposit is being kept. “Forfeited” with nothing after it is not an answer a committee can give.'
                                : 'Keep the deposit as income and record the reason on the booking and in the entry.'
                        "
                    >
                        <span>{{ forfeitForm.processing ? 'Forfeiting…' : 'Forfeit deposit' }}</span>
                    </button>
                    <button type="button" class="text-link-sm" @click="closePanel">Cancel</button>
                </div>
            </form>

            <EmptyState
                v-if="state.isEmptyFiltered.value"
                variant="filtered"
                title="Nothing on this booking matches a filter"
                body="This screen has no filter, so this state is only ever forced for review. The booking and its deposit are unchanged."
            />

            <EmptyState
                v-else-if="state.isEmpty.value"
                variant="first-use"
                title="No deposit is taken on this booking"
                body="The amenity carried no deposit when this booking was made, so there is nothing for the estate to hold, refund or keep. A deposit added to the rate card later applies to future bookings, never to this one."
            />

            <!--
              The deposit's story, read off the entries that posted it. Three steps
              always, in order, and a step nobody has reached still occupies its
              place, greyed — so the reader can see what is left.
            -->
            <div v-else class="tl2">
                <div v-for="step in deposit.timeline" :key="step.key" class="tl2-row">
                    <div class="tl2-dot" :class="step.state">
                        <svg v-if="step.state === 'done'" viewBox="0 0 24 24" fill="none">
                            <polyline
                                points="20 6 9 17 4 12"
                                stroke="currentColor"
                                stroke-width="3"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                        <svg v-else-if="step.state === 'active'" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="5" fill="currentColor" />
                        </svg>
                    </div>

                    <div class="tl2-txt" :class="{ muted: step.state === 'pending' }">
                        <div class="tt1">{{ step.label }}</div>
                        <div v-if="step.line" class="tt2">{{ step.line }}</div>
                    </div>
                </div>
            </div>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only, the same removals board 18's page makes and for the same
 * reasons: the sheet draws its action stack as <div>s, and a <button> arrives
 * wearing the browser's font, border and buttonface grey. The border reset names
 * only the variants that have no border of their own — `.stack-btn.outline`'s
 * 1.5px navy edge is the whole difference between the two variants (D-045).
 */
button {
    font: inherit;
}

button.stack-btn.primary,
button.btn-primary-sm {
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
 * AUTHORED BELOW THIS LINE. No board draws this screen, so everything it adds
 * to board 18's shapes is kept to the tokens the boards define and the type
 * sizes .ts-meta and .tt2 already set.
 */
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

.bk-terms {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 10px 18px;
    margin: 14px 0 0;
}

.bk-terms dt {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    margin: 0 0 3px;
}

.bk-terms dd {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--navy-900);
    line-height: 1.5;
    margin: 0;
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

.act-note {
    font-size: 11.5px;
    color: var(--slate-600);
    line-height: 1.6;
    margin: 0;
    max-width: 760px;
}

.act-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.act-date {
    max-width: 240px;
}

.act-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.act-field input,
.act-field textarea {
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    font: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

.act-field input {
    height: 34px;
    padding: 0 10px;
}

.act-field textarea {
    padding: 9px 10px;
    line-height: 1.55;
    resize: vertical;
}

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
    flex-wrap: wrap;
    gap: 14px;
}
</style>
