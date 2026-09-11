<script setup>
import { ref } from 'vue'
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'

/**
 * One invoice, taken apart — board screen super-admin-33.
 *
 * THE LINES ARE THE POINT. An invoice carrying only a total is a number nobody
 * can query; a client asking "why is this J$171,000" needs the two lines that
 * make it up, and this screen exists to answer that. Phoenix Park's August bill
 * is 450 units at J$340 plus 4 guards at J$4,500, and the two add to the posted
 * total exactly.
 *
 * A POSTED RECORD. Nothing on this screen edits the invoice and no route
 * behind it could: a correction to a raised invoice is a credit note, which is
 * a new posted record of its own. The two controls the board draws are inert
 * and say why — on a screen about money, a control that looks live and does
 * nothing is worse than anywhere else on the platform.
 *
 * If the lines ever stop adding up to the posted total, the screen SAYS SO
 * rather than showing one and hiding the other. The posted amount is what the
 * client owes and cannot be edited to make the arithmetic work, so a mismatch
 * is a real finding and gets reported as one.
 */
const props = defineProps({
    invoice: { type: Object, required: true },
    /** Credit notes against this invoice, and what they come to. */
    notes: { type: Array, required: true },
    credited_minor: { type: Number, required: true },
    /** When it was sent, and to whom. */
    sends: { type: Array, required: true },
    canWrite: { type: Boolean, required: true },
    writeDisabledReason: { type: String, required: true },
})

const page = usePage()

const state = useScreenState({
    rows: () => props.invoice.lines.length,
})

/* ------------------------------------------------------------------ */
/* the two writes (12 §2, Wave 4) */
/* ------------------------------------------------------------------ */

/*
 * A RAISED INVOICE IS NEVER EDITED. A correction is a credit note — a new
 * posted record of its own — and the invoice keeps its total, its lines and its
 * reference. Both figures are shown rather than one netted number, because a
 * client holding an invoice for one amount and a statement showing another has
 * been given two answers.
 */
const crediting = ref(false)

const creditForm = useForm({ amount: '', reason: '' })

const resendForm = useForm({})

const resend = () => {
    if (!props.canWrite) {
        return
    }

    resendForm.post(`/billing/invoices/${props.invoice.id}/resend`, { preserveScroll: true })
}

const submitCredit = () => {
    if (creditForm.reason.trim() === '') {
        return
    }

    creditForm.post(`/billing/invoices/${props.invoice.id}/credit-note`, {
        preserveScroll: true,
        onSuccess: () => {
            crediting.value = false
            creditForm.reset()
        },
    })
}

/** "$1,200.00", from minor units. Never arithmetic on a formatted string. */
const money = (minor) => `$${(minor / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
</script>

<template>
    <Head :title="`Invoice ${invoice.reference}`" />

    <GeminiConsole :title="`Invoice — ${invoice.period}`">
        <template #lead>
            <Link href="/billing" class="topbar-back" title="Back to billing" aria-label="Back to billing">
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

        <!--
          The PDF is a READ of an immutable record, so every viewer who can
          open this screen can take it: an invoice's lines, total and reference
          never change, and a correction is a credit note of its own.
        -->
        <template #actions>
            <a
                :href="`/billing/invoices/${invoice.id}/pdf`"
                class="btn-outline-sm"
                title="Download this invoice as a PDF, with any credit notes against it. The platform records that it left and who took it."
            >
                <BoardIcon name="export" :stroke="1.8" />
                <span>Download PDF</span>
            </a>
        </template>

        <p v-if="page.props.flash?.success" class="inv-flash">{{ page.props.flash.success }}</p>
        <p v-if="page.props.errors.invoice" class="inv-refusal">{{ page.props.errors.invoice }}</p>

        <div class="invoice-head">
            <div class="invoice-card">
                <div class="inv-top">
                    <div>
                        <!--
                          The client's name links to their record. A reader of
                          an invoice is one question away from wanting the
                          client behind it.
                        -->
                        <Link :href="invoice.clientHref" class="inv-name">{{ invoice.client }}</Link>
                        <div class="inv-sub">{{ invoice.periodLabel }}</div>
                    </div>
                    <!--
                      The status pill's inline style is the board's own, with its
                      colours as tokens. The label is --success-700, the signed-off
                      success TEXT colour, rather than the board's --success-600 —
                      3.30:1 on white, below the text threshold (tokens.css).
                    -->
                    <div
                        v-if="invoice.status === 'paid'"
                        style="
                            font-size: 10.5px;
                            font-weight: 700;
                            color: var(--success-700);
                            background: var(--success-100);
                            padding: 5px 11px;
                            border-radius: 20px;
                        "
                    >
                        {{ invoice.statusLabel }}
                    </div>
                    <div
                        v-else
                        style="
                            font-size: 10.5px;
                            font-weight: 700;
                            color: var(--amber-700);
                            background: var(--amber-100);
                            padding: 5px 11px;
                            border-radius: 20px;
                        "
                    >
                        {{ invoice.statusLabel }}
                    </div>
                </div>
                <div class="inv-amount">{{ invoice.amount }}</div>
                <div class="inv-amount-lbl">{{ invoice.settlement }}</div>
            </div>

            <div class="action-stack">
                <button
                    type="button"
                    class="stack-btn outline"
                    :disabled="!canWrite || resendForm.processing"
                    :title="canWrite ? 'Email this invoice to whoever holds the estate\'s account. Who it goes to is derived from the platform, never typed — an invoice sent to a typo is a conversation about money that never happened.' : writeDisabledReason"
                    @click="resend"
                >
                    <BoardIcon name="broadcast" :stroke="1.7" />
                    <span>{{ resendForm.processing ? 'Sending…' : 'Resend to client' }}</span>
                </button>
                <button
                    type="button"
                    class="stack-btn outline"
                    :disabled="!canWrite"
                    :title="canWrite ? 'A raised invoice is never edited. A correction is a credit note — a posted record of its own — and this invoice keeps its total.' : writeDisabledReason"
                    @click="crediting = !crediting"
                >
                    <BoardIcon name="pencil" :stroke="1.6" />
                    <span>Issue credit note</span>
                </button>
            </div>
        </div>

        <!--
          AUTHORED. The board draws an invoice nobody is correcting, so it has
          no panel, no credit-note list and no send log. All three are things
          this screen has to be able to say.
        -->
        <form v-if="crediting" class="inv-panel" @submit.prevent="submitCredit">
            <div class="inv-head">
                A credit note is a posted record of its own. This invoice keeps its total — what it is worth now is
                that total less its credit notes, and both are shown rather than one netted figure.
            </div>

            <div class="inv-fields">
                <div class="inv-field">
                    <label for="cn-amount">Amount to credit, J$</label>
                    <input id="cn-amount" v-model="creditForm.amount" type="text" inputmode="decimal" required />
                </div>
                <div class="inv-field inv-field--wide">
                    <label for="cn-reason">Why — read a year later</label>
                    <input id="cn-reason" v-model="creditForm.reason" type="text" required maxlength="300" />
                </div>
            </div>

            <div v-if="creditForm.errors.amount" class="inv-error">{{ creditForm.errors.amount }}</div>
            <div v-if="creditForm.errors.reason" class="inv-error">{{ creditForm.errors.reason }}</div>

            <div class="inv-actions">
                <button
                    type="submit"
                    class="btn-primary-sm"
                    :disabled="creditForm.processing || creditForm.reason.trim() === ''"
                    :title="creditForm.reason.trim() === '' ? 'Say why. A credit note with no stated reason is one nobody can review a year later.' : 'Issue the credit note.'"
                >
                    <span>{{ creditForm.processing ? 'Issuing…' : 'Issue credit note' }}</span>
                </button>
                <button type="button" class="text-link-sm" @click="crediting = false">Cancel</button>
            </div>
        </form>

        <div v-if="notes.length" class="inv-panel">
            <div class="inv-head">
                Credit notes — {{ invoice.amount }} invoiced, {{ money(credited_minor) }} credited,
                {{ money((invoice.total_minor ?? 0) - credited_minor) }} payable.
            </div>

            <div v-for="note in notes" :key="note.reference" class="inv-row">
                <span><b>{{ note.reference }}</b> — {{ note.reason }}</span>
                <span class="inv-row-meta">
                    −{{ money(note.amount_minor) }} · {{ note.issued_on }} · {{ note.issued_by }}
                </span>
            </div>
        </div>

        <div v-if="sends.length" class="inv-panel">
            <div class="inv-head">Sent to the client</div>
            <div v-for="(send, i) in sends" :key="i" class="inv-row">
                <span>{{ send.recipients }}</span>
                <span class="inv-row-meta">{{ send.at }} · {{ send.by }}</span>
            </div>
        </div>

        <div style="background: var(--white); border: 1px solid var(--navy-100); border-radius: 16px; overflow: hidden">
            <SkeletonRows v-if="state.isLoading.value" :rows="3" :columns="2" />

            <!--
              A raised invoice with no lines. Not an error and not empty-because
              -filtered: it is an invoice whose breakdown was never recorded,
              and the posted total still stands.
            -->
            <EmptyState
                v-else-if="state.isEmpty.value"
                variant="first-use"
                title="No line breakdown on this invoice"
                body="The posted total stands and is what the client owes. This invoice was raised without an itemised breakdown, so there is nothing to take apart here."
            />

            <template v-else>
                <div v-for="(line, i) in invoice.lines" :key="i" class="line-item-row">
                    <div class="li-desc">
                        <div class="li1">{{ line.description }}</div>
                        <div class="li2">{{ line.detail }}</div>
                    </div>
                    <div class="li-amt">{{ line.amount }}</div>
                </div>

                <div class="line-total">
                    <span>Total due</span>
                    <span>{{ invoice.amount }}</span>
                </div>
            </template>
        </div>

        <!--
          Shown only when the breakdown disagrees with the posted total. See
          the class docblock: this is a finding, not something to resolve by
          adjusting one of the two numbers.
        -->
        <div v-if="invoice.reconciliation" class="warn-banner">
            <BoardIcon name="warning" :stroke="1.7" />
            <div>
                <div class="wb1">This breakdown does not reconcile</div>
                <div class="wb2">{{ invoice.reconciliation }}</div>
            </div>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/*
 * Default-removal only.
 *
 * The board draws the back chevron, the two stack buttons, the download
 * control and the client name as <div>s. Here they are a link, buttons and a
 * link, so the UA's underline and the button's own border, face and font would
 * show through. .stack-btn, .btn-outline-sm and .inv-name state everything
 * else.
 */
.topbar-back {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: var(--navy-100);
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
}

.topbar-back svg {
    width: 16px;
    height: 16px;
    color: var(--navy-700);
}

a.inv-name {
    text-decoration: none;
    color: inherit;
}

button.stack-btn,
button.btn-outline-sm {
    font: inherit;
    cursor: pointer;
}

/*
 * `border` is deliberately not reset anywhere here. The board gives both
 * .stack-btn.outline and .btn-outline-sm their own borders, and resetting the
 * second out-specified the board and stripped a 1.5px navy outline off the
 * topbar control — a real defect that passed measurement because 1.5px on one
 * small button is well under the threshold.
 */

button.stack-btn[disabled],
button.btn-outline-sm[disabled] {
    cursor: not-allowed;
}

a.btn-outline-sm {
    text-decoration: none;
}

button.btn-primary-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.btn-primary-sm[disabled] {
    cursor: not-allowed;
}

button.text-link-sm {
    border: 0;
    background: none;
    padding: 0;
    font: inherit;
    cursor: pointer;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws an invoice nobody is correcting, so
 * it has no panel, no credit-note list and no send log.
 */
.inv-flash,
.inv-refusal {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 14px;
}

.inv-flash {
    background: var(--green-100);
    color: var(--green-700);
}

.inv-refusal {
    background: var(--red-100);
    color: var(--red-700);
}

.inv-panel {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 16px;
    margin-bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.inv-head {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--navy-800);
    line-height: 1.55;
    max-width: 780px;
}

.inv-fields {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
}

.inv-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.inv-field--wide {
    grid-column: span 2;
}

.inv-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.inv-field input {
    height: 33px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12px;
    color: var(--navy-900);
}

.inv-error {
    font-size: 11px;
    font-weight: 600;
    color: var(--red-700);
    line-height: 1.5;
}

.inv-actions {
    display: flex;
    align-items: center;
    gap: 14px;
}

.inv-row {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 14px;
    font-size: 11.5px;
    color: var(--navy-900);
    line-height: 1.6;
    border-top: 1px solid var(--navy-100);
    padding-top: 7px;
}

.inv-row-meta {
    font-size: 10.5px;
    color: var(--slate-500);
    white-space: nowrap;
}
</style>
