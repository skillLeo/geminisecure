<script setup>
import { Head, Link } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * One of this estate's invoices, itemised — board 40's "View" (12 §2, Wave 4).
 *
 * NO BOARD DRAWS THIS SCREEN, and it is not a fidelity target. It is built in
 * board 40's own sheet so the table and the invoice behind a row read as one.
 *
 * THE INVOICE AS ISSUED, EVERY LINE OF IT. Board 40's table shows the
 * subscription portion; the invoice Gemini emails carries the guard add-on as
 * well. So every line is here, the subscription lines are marked, and the
 * sentence under the table says which figure is which — the estate is never
 * shown a second version of a document it already holds.
 *
 * CREDIT NOTES ARE SHOWN, NOT NETTED AWAY. What was invoiced stays what was
 * invoiced; the credits and what remains payable sit beside it.
 *
 * Nothing here writes. The PDF is a download, and like every export it is on
 * the estate's audit record.
 */
defineProps({
    estate: { type: Object, required: true },
    invoice: { type: Object, required: true },
    pdfHref: { type: String, required: true },
})

useWireframe('community-admin-10-payroll-employees-details-and-billing')
</script>

<template>
    <Head :title="`Invoice ${invoice.number}`" />

    <EstateConsole :title="`Invoice ${invoice.number}`" :estate-name="estate.name" active="settings">
        <template #lead>
            <Link
                href="/settings/billing"
                style="width:34px;height:34px;border-radius:50%;background:var(--navy-100);display:flex;align-items:center;justify-content:center;flex:0 0 auto;"
                title="Back to Billing & subscription"
                aria-label="Back to Billing & subscription"
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

        <template #actions>
            <a
                :href="pdfHref"
                class="btn-outline-sm"
                title="Download this invoice as the PDF Gemini sends. The estate records that it was downloaded, and by whom."
            >
                <span>Download PDF</span>
            </a>
        </template>

        <div class="hero-card">
            <div class="hero-top">
                <div>
                    <div class="hero-name">{{ invoice.period }}</div>
                    <div class="hero-sub">Reference {{ invoice.reference }} &middot; {{ invoice.settlement }}</div>
                </div>
                <div class="status-badge active">{{ invoice.status_label }}</div>
            </div>
            <div class="hero-stats">
                <div class="hero-stat">
                    <div class="hs-v">{{ invoice.total }}</div>
                    <div class="hs-l">Invoiced</div>
                </div>
                <div class="hero-stat">
                    <div class="hs-v">{{ invoice.subscription_total }}</div>
                    <div class="hs-l">Subscription portion</div>
                </div>
                <div class="hero-stat">
                    <div class="hs-v">{{ invoice.credited }}</div>
                    <div class="hs-l">Credited</div>
                </div>
                <div class="hero-stat">
                    <div class="hs-v">{{ invoice.payable }}</div>
                    <div class="hs-l">Payable</div>
                </div>
            </div>
        </div>

        <table class="data-table" style="margin-top: 16px">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Detail</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(line, index) in invoice.lines" :key="index">
                    <td>
                        {{ line.description }}
                        <span v-if="line.is_subscription" class="bi-tag">Subscription</span>
                    </td>
                    <td>{{ line.detail }}</td>
                    <td class="num-cell">{{ line.amount }}</td>
                </tr>
            </tbody>
        </table>

        <p class="bi-note">
            Billing &amp; subscription shows this invoice's subscription lines only. Any other line on it — the
            guard staffing add-on — is priced under Gemini Security's separate labour services agreement, and is
            here because it is on the invoice you were sent.
        </p>

        <p v-if="invoice.reconciliation" class="bi-note bi-warn">{{ invoice.reconciliation }}</p>

        <template v-if="invoice.has_credits">
            <div class="info-panel-head" style="margin-top: 18px">Credit notes against this invoice</div>

            <table class="data-table" style="margin-top: 8px">
                <thead>
                    <tr>
                        <th>Note</th>
                        <th>Reason</th>
                        <th>Issued</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="note in invoice.notes" :key="note.reference">
                        <td>{{ note.reference }}</td>
                        <td>{{ note.reason }}</td>
                        <td>{{ note.issued_on }}</td>
                        <td class="num-cell">{{ note.amount }}</td>
                    </tr>
                </tbody>
            </table>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * AUTHORED. No board draws this screen. The hero, table and panel heading are
 * board 40's own classes; only the subscription tag and the notes need rules,
 * kept to the tokens the boards define.
 */
a.btn-outline-sm {
    text-decoration: none;
}

.bi-tag {
    display: inline-block;
    margin-left: 8px;
    font-size: 10px;
    font-weight: 700;
    border-radius: 20px;
    padding: 2px 8px;
    background: var(--navy-100);
    color: var(--navy-700);
}

.bi-note {
    font-size: 11px;
    color: var(--slate-600);
    line-height: 1.6;
    margin: 12px 0 0;
    max-width: 840px;
}

.bi-warn {
    color: var(--red-700);
}
</style>
