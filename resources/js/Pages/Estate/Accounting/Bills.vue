<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * Bills & payments — board screen community-admin-27.
 *
 * THE ACCOUNTS PAYABLE SUB-LEDGER, and it is a sub-ledger rather than a list of
 * invoices. Every figure on it is summed from posted journal lines by
 * `Payables`: "Total payable" is the balance of 2000 Accounts Payable, and the
 * four open rows beneath it are the same lines grouped by bill. That is why the
 * tile and the rows tie exactly — J$8,500 + J$12,000 + J$6,890 + J$94,300 is
 * J$121,690 — and why nothing here is added up in this page.
 *
 * TWO REFUSALS THE SERVICE MAKES, AND THIS SCREEN HAS TO MAKE THEM FIRST.
 *
 *   A PAYMENT CANNOT EXCEED THE BILL WITHOUT AN EXPLICIT OVER-PAYMENT REASON.
 *   The excess leaves the vendor in debit on 2000, which is a real thing — a
 *   deposit, a duplicate to be recovered — and a balance nobody explained is one
 *   nobody can recover. So the reason field APPEARS the moment the typed amount
 *   goes over what the bill still owes, beside the number that caused it, rather
 *   than the press being sent to be bounced.
 *
 *   A PAYMENT IS REFUSED WHEN THE VENDOR HAS NO TRN. Withholding compliance
 *   bites when money moves, not when an invoice arrives, and it is the one
 *   refusal here that cannot be fixed on this screen — the supplier has to send
 *   paperwork. So "Pay now" is inert on that row and says so on hover, because
 *   the alternative is a treasurer filling in four fields to be told the amount
 *   was never the problem.
 *
 * APPROVING IS NOT PAYING, AND THEY ARE NOT THE SAME PERMISSION. A recorded bill
 * is a claim; approving it is the moment it becomes money the estate owes, which
 * is why it needs `estate.accounting_posting.approve` where paying needs
 * `.create` (D-013). A draft row therefore offers Approve and not Pay: there is
 * nothing on 2000 to pay down until the liability is posted.
 *
 * NOTHING HERE EDITS A POSTED ENTRY, and nothing on it offers to. A bill that
 * was approved wrongly is corrected by a journal, not by changing the row.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    kpis: { type: Array, required: true },
    rows: { type: Array, required: true },
    canPay: { type: Boolean, required: true },
    canApprove: { type: Boolean, required: true },
    /** Whether the viewer holds Facilities view, which the work order sits behind. */
    canOpenTicket: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
    reasons: { type: Object, required: true },
    /** What recording a bill chooses from: the register, the expense accounts, the open work orders. */
    billVendors: { type: Array, required: true },
    expenseAccounts: { type: Array, required: true },
    openTickets: { type: Array, required: true },
})

/*
 * Which board's stylesheet this page wears. The ten Estate Console boards do
 * NOT share one sheet the way the nine Gemini boards do, so every estate page
 * has to name its own or it renders with no board CSS at all.
 */
useWireframe('community-admin-07-chart-of-accounts-vendors-bills-and-bank-rec')

const page = usePage()

/**
 * All six, and the sixth is reachable only by forcing.
 *
 * `empty` is an estate that has recorded no bill at all — a first-use panel that
 * offers the register, not a table with headings ruled over nothing.
 * `empty-filtered` is the same table emptied by the view rather than by the
 * estate: this screen is deliberately open bills PLUS whatever was settled this
 * month, so a bill paid in July is absent from a payables screen that is not
 * empty of bills. No control on this page applies that filter, so nothing here
 * can produce the state from data — it is forceable with `?_state=empty-filtered`
 * in local, and it says something true when it is.
 */
const state = useScreenState({
    rows: () => props.rows.length,
})

const retry = () => router.reload()

/*
 * Where this estate's accounting lives, in whichever shape the environment
 * serves. Production gives each estate its own hostname and the path starts at
 * /accounting; local serves them all from one host with the estate in the path
 * (/estate/phoenixpark/accounting/bills). Cutting the current URL at /accounting
 * is right in both, and — unlike a tenant key read off a prop — it cannot
 * address an estate other than the one already open.
 */
const accountingPath = computed(() => {
    const cut = page.url.indexOf('/accounting')

    return cut === -1 ? '/accounting' : `${page.url.slice(0, cut)}/accounting`
})

/*
 * THE REMITTANCE ADVICE (12 §1). Asked for rather than produced — rendered by
 * a worker, kept seven years — and a second press inside the window hands back
 * the same document rather than sending a supplier two.
 */
const remittanceForm = useForm({})

const askForRemittance = (row) => {
    if (row.settled_payment_id === null) {
        return
    }

    remittanceForm.post(`${accountingPath.value}/bill-payments/${row.settled_payment_id}/remittance`, {
        preserveScroll: true,
    })
}

/**
 * The work order behind a bill — board 18, one module over from the same root,
 * addressed by the ticket NUMBER the bill stores.
 */
const ticketHref = (row) => accountingPath.value.replace(/\/accounting$/, `/facilities/maintenance/${row.ticket_id}`)

/**
 * The four Accounting tabs, which are one module and not four screens.
 *
 * All four are built, so all four are links. The tab for the screen the reader
 * is already on is text rather than a control, because there is nowhere for it
 * to lead.
 */
const tabs = computed(() => [
    { key: 'chart', label: 'Chart of accounts', href: `${accountingPath.value}/chart-of-accounts` },
    { key: 'vendors', label: 'Vendors', href: `${accountingPath.value}/vendors` },
    { key: 'bills', label: 'Bills & payments', href: null },
    { key: 'reconciliation', label: 'Bank reconciliation', href: `${accountingPath.value}/reconciliation` },
])

/* ------------------------------------------------------------------ */
/* money */
/* ------------------------------------------------------------------ */

/**
 * The board's format, and the one a payment is actually posted in.
 *
 * `board` is what screen 27 draws in BOTH the tiles and the Amount column —
 * "$121,690", "$8,500" — and unlike the chart of accounts on screen 25 the tiles
 * here are NOT abbreviated. That is the board's own choice and it is the right
 * one: a payables tile is checked against the column beneath it, and "$122k"
 * cannot be checked against anything.
 *
 * `toTheCent` is J$ and two decimals, and it appears only inside the payment
 * panel — on the outstanding figure being settled and on the over-payment
 * warning. A payment is posted to the cent, and a figure quoted to the dollar
 * beside a field that accepts cents invites somebody to pay the rounded number.
 *
 * Every amount arrives as MINOR UNITS — integer cents, the only form that
 * survives arithmetic. The divide by 100 happens here, at the last moment, and
 * nothing is added up after it.
 */
const board = (minor) => `$${(minor / 100).toLocaleString('en-JM', { maximumFractionDigits: 0 })}`

const toTheCent = (minor) =>
    new Intl.NumberFormat('en-JM', {
        style: 'currency',
        currency: 'JMD',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(minor / 100)

/* ------------------------------------------------------------------ */
/* paying one */
/* ------------------------------------------------------------------ */

/** The bill whose payment panel is open, or null. One at a time, deliberately. */
const paying = ref(null)

const form = useForm({
    amount: '',
    method: 'bank',
    /*
     * Blank rather than today. A payment is dated the day the money moved, which
     * is on the cheque stub or the bank's own record — and defaulting it to today
     * would silently date a Friday transfer to the Monday somebody typed it in,
     * putting it in the wrong bank statement period.
     */
    paid_on: '',
    reference: '',
    overpayment_reason: '',
})

/** The amount field as a number, whatever tidying has been done to it. */
const parseAmount = (value) => {
    const parsed = Number.parseFloat(String(value ?? '').replace(/[^0-9.-]/g, ''))

    return Number.isFinite(parsed) ? parsed : 0
}

const amountMinor = computed(() => Math.round(parseAmount(form.amount) * 100))

/** The row the open panel belongs to, so the panel can quote its own figures. */
const openRow = computed(() => props.rows.find((row) => row.id === paying.value) ?? null)

/**
 * Whether what has been typed is more than the bill still owes.
 *
 * THIS IS THE SERVICE'S RULE, ASKED HERE FIRST. `Payables::pay()` refuses an
 * amount above the outstanding balance unless a reason came with it; asking the
 * same question against the same figure means the reason field is in front of
 * the treasurer while they are still looking at the number, instead of arriving
 * as a refusal after the press.
 */
const isOverPayment = computed(
    () => openRow.value !== null && amountMinor.value > openRow.value.outstanding_minor
)

const overPaymentMinor = computed(() =>
    openRow.value === null ? 0 : amountMinor.value - openRow.value.outstanding_minor
)

/** The over-payment is stated and unexplained — the one thing that cannot post. */
const needsReason = computed(() => isOverPayment.value && form.overpayment_reason.trim() === '')

/**
 * Why "Pay now" cannot be pressed on this row, or null when it can.
 *
 * THREE DIFFERENT REFUSALS, and the difference matters to whoever is holding the
 * cheque. The viewer's own access comes first because it is true of every row.
 * The TRN is the vendor's paperwork and cannot be fixed here at all. A draft has
 * raised no liability, so there is nothing on 2000 to pay down — approving it is
 * the next act, and the row offers that instead.
 */
const payBlockedBy = (row) => {
    if (!props.canPay) {
        return props.blockedReason
    }

    if (!row.vendor.has_trn) {
        return `${row.vendor.name} has no TRN on file, so this bill cannot be paid. A bill may be recorded without one — refusing that would understate what the estate owes — but withholding compliance bites when money moves. Put the taxpayer registration number on the vendor record first.`
    }

    if (row.status === 'draft') {
        return 'This bill is still a draft and has raised no liability, so there is nothing on 2000 Accounts Payable to pay down. Approve it first, so the estate’s accounts say it owes the money before they say it paid it.'
    }

    return null
}

/**
 * Why "Approve" cannot be pressed, or null when it can.
 *
 * AUTHORED, because the payload carries one `blockedReason` and it is about
 * paying — the sentence names Accounting CREATE access, which is not the
 * permission approval needs. Approving is where a claim becomes a liability, so
 * it needs `estate.accounting_posting.approve`, and telling somebody they lack
 * the wrong permission is worse than telling them nothing.
 */
const approveBlockedBy = computed(() =>
    props.canApprove
        ? null
        : 'Approving a bill posts the liability to 2000 Accounts Payable, so it needs Accounting approve access — a role may prepare a bill without being able to commit the estate to it. You are able to read this screen.'
)

/** Open the panel on one row, with the outstanding balance already in it. */
const openPayment = (row) => {
    paying.value = row.id

    form.clearErrors()
    form.amount = (row.outstanding_minor / 100).toFixed(2)
    form.method = 'bank'
    form.paid_on = ''
    form.reference = ''
    form.overpayment_reason = ''
}

const closePayment = () => {
    paying.value = null
    form.reset()
    form.clearErrors()
}

/*
 * The field reads like money; the server receives arithmetic. `Money::of`
 * refuses a value it cannot represent exactly, so the amount travels as a
 * decimal STRING and is never cast to a float on the way.
 */
form.transform((data) => ({ ...data, amount: parseAmount(data.amount).toFixed(2) }))

const tidyAmount = () => {
    if (form.amount.trim() !== '') {
        form.amount = parseAmount(form.amount).toFixed(2)
    }
}

const submitPayment = (row) => {
    /*
     * A form submits on Enter from any field, and a disabled button does not stop
     * it. The server refuses both of these too — the route is gated on
     * `estate.accounting_posting.create` and the service refuses an unexplained
     * over-payment — so this is only about not sending a payment that will bounce.
     */
    if (payBlockedBy(row) !== null || needsReason.value) {
        return
    }

    form.post(`${accountingPath.value}/bills/${row.id}/pay`, {
        preserveScroll: true,
        onSuccess: () => closePayment(),
    })
}

/* ------------------------------------------------------------------ */
/* recording a bill — the topbar's "Record bill" (12 §2, Wave 1) */
/* ------------------------------------------------------------------ */

/**
 * The expense account and the work order are chosen here, deliberately, as
 * ruled — a plumber and an electricity bill are different costs, and a bill
 * for a job should say which job. A draft: nothing posts until it is approved.
 */
const recording = ref(false)

const recordForm = useForm({
    vendor_id: props.billVendors[0]?.id ?? '',
    description: '',
    amount: '',
    due_on: new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10),
    account: props.expenseAccounts[0]?.code ?? '',
    ticket_number: '',
})

recordForm.transform((data) => ({
    ...data,
    amount: data.amount.trim() === '' ? '' : parseAmount(data.amount).toFixed(2),
    ticket_number: data.ticket_number === '' ? null : data.ticket_number,
}))

const recordBlockedBy = computed(() => {
    if (!props.canPay) {
        return 'Recording a bill puts a claim on the payables register and needs Accounting create access. You are able to read this screen.'
    }

    if (props.billVendors.length === 0) {
        return 'No supplier is on the register yet. Add the vendor first — a bill has to name who is owed.'
    }

    return null
})

const openRecording = () => {
    if (recordBlockedBy.value !== null) {
        return
    }

    recording.value = !recording.value
    recordForm.clearErrors()
}

const closeRecording = () => {
    recording.value = false
    recordForm.reset()
    recordForm.clearErrors()
}

const submitRecord = () => {
    if (recordBlockedBy.value !== null || recordForm.amount.trim() === '') {
        return
    }

    recordForm.post(`${accountingPath.value}/bills`, {
        preserveScroll: true,
        onSuccess: () => closeRecording(),
    })
}

const approve = (row) => {
    if (approveBlockedBy.value !== null) {
        return
    }

    router.post(`${accountingPath.value}/bills/${row.id}/approve`, {}, { preserveScroll: true })
}
</script>

<template>
    <Head title="Bills &amp; payments" />

    <EstateConsole title="Bills &amp; payments" :estate-name="estate.name" active="accounting">
        <template #actions>
            <button
                type="button"
                class="btn-primary-sm"
                :disabled="recordBlockedBy !== null"
                :title="recordBlockedBy ?? 'Record an invoice that has arrived, as a draft. It says which supplier, which cost and which job; approving it is what posts the liability.'"
                @click="openRecording"
            >
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
                <span>Record bill</span>
            </button>
        </template>

        <div class="subnav">
            <template v-for="tab in tabs" :key="tab.key">
                <Link v-if="tab.href" :href="tab.href" class="subnav-item">{{ tab.label }}</Link>
                <div v-else class="subnav-item active" aria-current="page">{{ tab.label }}</div>
            </template>
        </div>

        <!--
          The flash and the refusals. Neither is on the board — a board is a
          still image and nothing has ever been pressed on it — and neither is
          drawn on a fresh GET, so both are free of the screen's geometry. They
          are here because a payment that posts in silence, and one refused in
          silence, are the same screen to the person who pressed the button.
        -->
        <p v-if="page.props.flash.success" class="bills-flash">{{ page.props.flash.success }}</p>
        <p v-if="page.props.errors.bill" class="bills-refusal">{{ page.props.errors.bill }}</p>

        <!--
          The record-bill panel — authored, closed on a fresh GET, on the same
          shapes the pay panel already uses. A draft is what it makes: the
          liability posts when a Treasurer approves it, not here.
        -->
        <form v-if="recording" class="pay-panel record-panel" @submit.prevent="submitRecord">
            <div class="pay-head">Record an invoice — a draft until it is approved, when Dr the expense, Cr 2000 Accounts Payable posts.</div>

            <div class="pay-fields">
                <div class="pay-field">
                    <label for="rec-vendor">Supplier</label>
                    <select id="rec-vendor" v-model="recordForm.vendor_id" required>
                        <option v-for="vendor in billVendors" :key="vendor.id" :value="vendor.id">
                            {{ vendor.name }}{{ vendor.has_trn ? '' : ' — no TRN yet' }}
                        </option>
                    </select>
                </div>

                <div class="pay-field">
                    <label for="rec-account">Which cost this is</label>
                    <select id="rec-account" v-model="recordForm.account" required>
                        <option v-for="account in expenseAccounts" :key="account.code" :value="account.code">
                            {{ account.label }}
                        </option>
                    </select>
                </div>

                <div class="pay-field">
                    <label for="rec-amount">Amount, J$</label>
                    <input id="rec-amount" v-model="recordForm.amount" type="text" inputmode="decimal" required placeholder="45000.00" />
                </div>

                <div class="pay-field">
                    <label for="rec-due">Due on</label>
                    <input id="rec-due" v-model="recordForm.due_on" type="date" required />
                </div>

                <div class="pay-field pay-field--wide">
                    <label for="rec-description">What it is for</label>
                    <input id="rec-description" v-model="recordForm.description" type="text" required maxlength="200" placeholder="Replacement of two gate floodlights and photocell" />
                </div>

                <div class="pay-field pay-field--wide">
                    <label for="rec-ticket">Work order — leave blank for a bill that is not for a job</label>
                    <select id="rec-ticket" v-model="recordForm.ticket_number">
                        <option value="">Not for a work order</option>
                        <option v-for="ticket in openTickets" :key="ticket.number" :value="ticket.number">{{ ticket.label }}</option>
                    </select>
                </div>
            </div>

            <div v-if="recordForm.errors.amount" class="pay-error">{{ recordForm.errors.amount }}</div>
            <div v-if="recordForm.errors.vendor_id" class="pay-error">{{ recordForm.errors.vendor_id }}</div>
            <div v-if="recordForm.errors.account" class="pay-error">{{ recordForm.errors.account }}</div>
            <div v-if="recordForm.errors.ticket_number" class="pay-error">{{ recordForm.errors.ticket_number }}</div>
            <div v-if="recordForm.errors.due_on" class="pay-error">{{ recordForm.errors.due_on }}</div>

            <div class="pay-actions">
                <button
                    type="submit"
                    class="btn-primary-sm"
                    :disabled="recordForm.processing || recordForm.amount.trim() === ''"
                    :title="recordForm.amount.trim() === '' ? 'Enter the invoice amount.' : 'Record the bill as a draft on the register.'"
                >
                    <span>{{ recordForm.processing ? 'Recording…' : 'Record bill' }}</span>
                </button>
                <button type="button" class="text-link-sm" @click="closeRecording">Cancel</button>
            </div>
        </form>

        <div class="kpi-row">
            <div v-for="kpi in kpis" :key="kpi.key" class="kpi-card">
                <div class="k-top">
                    <div class="kpi-icon">
                        <!--
                          The four glyphs, lifted from this board rather than
                          taken from a component. The payload names no icon —
                          `billsBoard()` carries a key, a figure and a label, and
                          nothing about how any of them is drawn — so the pairing
                          is here, where the board it came from is.
                        -->
                        <svg v-if="kpi.key === 'payable'" viewBox="0 0 24 24" fill="none">
                            <path d="M12 9v4M12 17h.01" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" />
                            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" />
                        </svg>
                        <svg v-else-if="kpi.key === 'overdue'" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7" />
                            <path d="M12 7v5l3.5 2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                        </svg>
                        <svg v-else-if="kpi.key === 'due_week'" viewBox="0 0 24 24" fill="none">
                            <rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.7" />
                            <path d="M3 10h18M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                        </svg>
                        <svg v-else viewBox="0 0 24 24" fill="none">
                            <polyline
                                points="20 6 9 17 4 12"
                                stroke="currentColor"
                                stroke-width="3"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                    </div>
                </div>

                <!--
                  "Due this week" IS A COUNT AND NOT AN AMOUNT. The board draws it
                  bare — "4", no currency — beside three tiles of money, and the
                  payload says the same thing in its own shape: that tile carries
                  `value` where the other three carry `value_minor`. Formatting it
                  as money would tell a committee the estate owes four dollars.
                -->
                <div class="k-val">
                    {{ kpi.value_minor === undefined ? kpi.value : board(kpi.value_minor) }}
                </div>
                <div class="k-lbl">{{ kpi.label }}</div>
            </div>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="5" :columns="6" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Accounting is not part of your role’s access"
            body="What the estate owes its suppliers is the estate’s books, so the module opens only to roles that hold Accounting. A Property Manager sees the vendors they commission and not the money, by platform rule rather than estate preference."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The payables ledger could not be read"
            body="The estate’s accounts did not answer. Nothing has been posted and nothing has been paid — every bill still stands exactly as it did before this screen opened."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isEmptyFiltered.value"
            variant="filtered"
            title="No bill falls inside this view"
            body="This screen is what the estate still owes, plus whatever was settled this month — so a bill paid in an earlier month is absent from it rather than missing. A supplier’s whole history is on its own page in the vendor register."
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="This estate owes nothing"
            body="No bill has been recorded against a supplier, so 2000 Accounts Payable carries no balance and there is nothing to pay. A recorded bill is a draft until it is approved — approving it is what posts the liability."
        />

        <table v-else class="data-table">
            <thead>
                <tr>
                    <th>Vendor</th>
                    <th>Reference</th>
                    <th>Amount</th>
                    <th>Due date</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <template v-for="row in rows" :key="row.id">
                    <tr>
                        <td>
                            <!--
                              The board puts the name DIRECTLY inside .res-cell
                              here, with no .res-sub line beneath it — unlike the
                              vendor register on screen 26, which carries the
                              trade as a second line. Reproduced as drawn.
                            -->
                            <div class="res-cell">
                                <div class="res-avatar">{{ row.vendor.initials }}</div>
                                <div class="res-name">{{ row.vendor.name }}</div>
                            </div>
                        </td>

                        <!--
                          A ticket-linked bill draws the work order as a link,
                          because a committee asking what a job cost is asking
                          about the ticket. The electricity bill has no ticket
                          and draws its description as plain text, exactly as the
                          board does — a link icon on a row that leads nowhere is
                          a control that lies.
                        -->
                        <!--
                          Board 18 is built, so the work order is a real link now,
                          addressed by the ticket NUMBER the bill stores (its
                          foreign key is onto `maintenance_tickets.number`). A
                          viewer without Facilities view keeps the inert twin and
                          the reason, rather than a link that answers 403.
                        -->
                        <td>
                            <Link
                                v-if="row.is_ticket && canOpenTicket"
                                :href="ticketHref(row)"
                                class="ref-link"
                                :title="`Open work order #${row.ticket_id} — what the job was, who did it, and when it closed.`"
                            >
                                <svg viewBox="0 0 24 24" fill="none">
                                    <path
                                        d="M14.7 6.3a3 3 0 1 0-4.2 4.2l-7 7 2.3 2.3 7-7a3 3 0 0 0 4.2-4.2l-2.1 2.1-2-2z"
                                        stroke="currentColor"
                                        stroke-width="1.6"
                                        stroke-linejoin="round"
                                    />
                                </svg>
                                <span>{{ row.reference }}</span>
                            </Link>
                            <button
                                v-else-if="row.is_ticket"
                                type="button"
                                class="ref-link"
                                disabled
                                :title="reasons.ticket"
                            >
                                <svg viewBox="0 0 24 24" fill="none">
                                    <path
                                        d="M14.7 6.3a3 3 0 1 0-4.2 4.2l-7 7 2.3 2.3 7-7a3 3 0 0 0 4.2-4.2l-2.1 2.1-2-2z"
                                        stroke="currentColor"
                                        stroke-width="1.6"
                                        stroke-linejoin="round"
                                    />
                                </svg>
                                <span>{{ row.reference }}</span>
                            </button>
                            <template v-else>{{ row.reference }}</template>
                        </td>

                        <td class="num-cell">{{ board(row.amount_minor) }}</td>
                        <td>{{ row.due_on }}</td>
                        <td><div class="status-badge" :class="row.status">{{ row.status_label }}</div></td>

                        <td>
                            <!--
                              One action per row, and which one is the row's
                              accounting state rather than a preference. A draft
                              has raised nothing, so the only act available is to
                              agree it. A settled bill has a receipt the supplier
                              keeps. Everything else can be paid.
                            -->
                            <button
                                v-if="row.status === 'draft'"
                                type="button"
                                class="text-link-sm"
                                :disabled="approveBlockedBy !== null"
                                :title="approveBlockedBy ?? 'Post the liability: debit the expense account, credit 2000 Accounts Payable.'"
                                @click="approve(row)"
                            >
                                Approve
                            </button>
                            <!--
                              A REMITTANCE ADVICE, and the label is the board's.
                              A receipt is issued by whoever RECEIVED the money
                              and the estate is the payer, so what it can issue
                              is the advice: what was paid, against which
                              invoice, when and how. Server-rendered and queued,
                              kept seven years (12 §1).
                            -->
                            <button
                                v-else-if="row.status === 'paid'"
                                type="button"
                                class="text-link-sm"
                                :disabled="row.settled_payment_id === null || remittanceForm.processing"
                                :title="
                                    row.settled_payment_id === null
                                        ? 'This bill has no recorded payment to advise against.'
                                        : 'Issue the remittance advice the estate sends its supplier — what was paid, against which invoice, when and how. A receipt would come from them, not from here.'
                                "
                                @click="askForRemittance(row)"
                            >
                                View receipt
                            </button>
                            <button
                                v-else
                                type="button"
                                class="text-link-sm"
                                :disabled="payBlockedBy(row) !== null"
                                :title="payBlockedBy(row) ?? `Pay ${row.vendor.name} — debit 2000 Accounts Payable, credit the operating bank account.`"
                                @click="paying === row.id ? closePayment() : openPayment(row)"
                            >
                                Pay now
                            </button>
                        </td>
                    </tr>

                    <!--
                      The payment panel, which is authored: the board draws the
                      happy path of a screen nobody has pressed anything on, so
                      it has no form to copy. It opens INSIDE the table, under the
                      row it belongs to, because a payment is against one bill and
                      a panel floating over the table would lose that.
                    -->
                    <tr v-if="paying === row.id" class="pay-row">
                        <td colspan="6">
                            <form class="pay-panel" @submit.prevent="submitPayment(row)">
                                <div class="pay-head">
                                    Pay {{ row.vendor.name }} — {{ toTheCent(row.outstanding_minor) }} outstanding
                                    on {{ row.reference }}
                                </div>

                                <div class="pay-fields">
                                    <div class="pay-field">
                                        <label for="pay-amount">Amount</label>
                                        <input
                                            id="pay-amount"
                                            v-model="form.amount"
                                            type="text"
                                            inputmode="decimal"
                                            required
                                            @blur="tidyAmount"
                                        />
                                    </div>

                                    <div class="pay-field">
                                        <label for="pay-method">Method</label>
                                        <select id="pay-method" v-model="form.method" required>
                                            <option value="bank">Bank transfer</option>
                                            <option value="cheque">Cheque</option>
                                            <option value="card">Card</option>
                                            <option value="cash">Cash</option>
                                        </select>
                                    </div>

                                    <div class="pay-field">
                                        <label for="pay-paid-on">Paid on</label>
                                        <input id="pay-paid-on" v-model="form.paid_on" type="date" required />
                                    </div>

                                    <div class="pay-field">
                                        <label for="pay-reference">Reference</label>
                                        <input
                                            id="pay-reference"
                                            v-model="form.reference"
                                            type="text"
                                            maxlength="64"
                                            placeholder="Cheque or transfer number"
                                        />
                                    </div>
                                </div>

                                <!--
                                  The over-payment reason, and it appears with the
                                  over-payment rather than after the refusal. The
                                  excess leaves this vendor in DEBIT on 2000 — a
                                  deposit, or a duplicate to be recovered — and
                                  each of those is a fact somebody knows and the
                                  ledger should be told.
                                -->
                                <div v-if="isOverPayment" class="pay-field pay-field--wide">
                                    <label for="pay-overpayment">
                                        Over-payment reason — this is
                                        {{ toTheCent(overPaymentMinor) }} more than the bill still owes
                                    </label>
                                    <input
                                        id="pay-overpayment"
                                        v-model="form.overpayment_reason"
                                        type="text"
                                        required
                                        maxlength="190"
                                        placeholder="A deposit against future work, a duplicate to be recovered…"
                                    />
                                </div>

                                <div v-if="form.errors.amount" class="pay-error">{{ form.errors.amount }}</div>

                                <div class="pay-actions">
                                    <button
                                        type="submit"
                                        class="btn-primary-sm"
                                        :disabled="form.processing || needsReason"
                                        :title="
                                            needsReason
                                                ? 'A payment cannot exceed the bill without an explicit over-payment reason. Say what the excess is, and it posts.'
                                                : 'Record the payment and post the entry.'
                                        "
                                    >
                                        <span>{{ form.processing ? 'Posting…' : 'Record payment' }}</span>
                                    </button>
                                    <button type="button" class="text-link-sm" @click="closePayment">Cancel</button>
                                </div>
                            </form>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only, and the removals name the elements that need them.
 *
 * The board draws every control on this screen as a <div>; here they are links
 * and buttons, which arrive wearing an underline, a border, buttonface grey and
 * the browser's own font. .btn-primary-sm, .subnav-item, .ref-link and
 * .text-link-sm supply everything visible.
 *
 * NOTHING BELOW RESETS `border` ON A CLASS THE BOARD GIVES ONE TO. A scoped
 * element selector outranks a single class, so a blanket `button { border: 0 }`
 * would out-specify the board and strip .btn-outline-sm's 1.5px navy edge — a
 * real fidelity defect that survives measurement, because 1.5px on one small
 * control is well under any threshold. See the note in Gemini/Reports/
 * ReportShell.vue. The three classes reset here declare no border of their own.
 */
a.subnav-item {
    text-decoration: none;
}

button.subnav-item {
    border: 0;
    background: none;
    font-family: inherit;
}

button.btn-primary-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.ref-link,
button.text-link-sm {
    border: 0;
    background: none;
    font: inherit;
    padding: 0;
    text-align: left;
    cursor: pointer;
}

button[disabled] {
    cursor: not-allowed;
}

/*
 * AUTHORED BELOW THIS LINE. The board is a still image of a screen nobody has
 * pressed anything on, so it draws no payment panel, no flash and no refusal —
 * and all three are things this screen has to be able to say. Kept to the tokens
 * the boards define, and to the shapes they already use: the panel is the
 * navy-100 inset the boards put inside a white card, and the type sizes are the
 * ones .data-table and .res-sub already set.
 */
.bills-flash,
.bills-refusal {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 14px;
}

.bills-flash {
    background: var(--green-100);
    color: var(--green-700);
}

.bills-refusal {
    background: var(--red-100);
    color: var(--red-700);
}

.pay-row td {
    background: var(--navy-100);
    padding: 14px 16px;
}

.pay-panel {
    display: flex;
    flex-direction: column;
    gap: 11px;
}

/* The record panel sits above the tiles rather than inside a row, so it carries the row's spacing itself. */
.record-panel {
    margin-bottom: 16px;
}

.pay-head {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--navy-800);
}

.pay-fields {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 11px;
}

.pay-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.pay-field--wide {
    grid-column: 1 / -1;
}

.pay-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
}

.pay-field input,
.pay-field select {
    height: 34px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

.pay-field input::placeholder {
    color: var(--slate-300);
    opacity: 1;
}

.pay-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--red-700);
    line-height: 1.5;
}

.pay-actions {
    display: flex;
    align-items: center;
    gap: 14px;
}
</style>
