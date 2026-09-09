<script setup>
import { Head, Link } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'

/**
 * Billing Overview — board screen super-admin-32.
 *
 * DOM and class names are the board's: the subnav, the four KPI cards, and one
 * data table whose rows are each estate's billing position. Where the board
 * draws a control as a <div>, this renders a real <button> or <Link>.
 *
 * Two of the three tabs are deliberately inert. Plans is board screen 34 and
 * Payment methods is board screen 35; neither is built, and payment methods in
 * particular has no central record at all because card capture sits behind an
 * adapter that does not exist (D-023). Both say so on hover rather than
 * swallowing a click.
 *
 * A row's action is inert for the same reason: the invoice detail screen
 * (board 33) is not built, so "View invoice" is a disabled button rather than
 * an <a> that goes nowhere.
 *
 * Note for anyone extending this screen: an invoice status shown here gates
 * BILLING only. Dunning and suspension never restrict entry, a safety function
 * or a resident — access is never withheld over a billing dispute.
 */
defineProps({
    kpis: { type: Array, required: true },
    rows: { type: Array, required: true },
    search: { type: String, default: '' },
})
</script>

<template>
    <Head title="Billing &amp; subscriptions" />

    <GeminiConsole
        title="Billing &amp; subscriptions"
        search-route="/billing"
        :search-value="search"
    >
        <div class="subnav">
            <Link href="/billing" class="subnav-item active" aria-current="page">Invoices</Link>
            <button
                type="button"
                class="subnav-item"
                disabled
                title="Subscription plans is board screen 34 and is not built yet"
            >
                Plans
            </button>
            <button
                type="button"
                class="subnav-item"
                disabled
                title="Payment methods needs the payment adapter, which does not exist yet (D-023)"
            >
                Payment methods
            </button>
        </div>

        <div class="kpi-row">
            <div v-for="kpi in kpis" :key="kpi.key" class="kpi-card">
                <div class="k-top">
                    <!-- .alert is the board's own red treatment for this icon.
                         It appears only when there really is money overdue. -->
                    <div class="kpi-icon" :class="{ alert: kpi.alert }">
                        <BoardIcon :name="kpi.icon" :stroke="1.7" />
                    </div>
                </div>
                <div class="k-val">{{ kpi.value }}</div>
                <div class="k-lbl">{{ kpi.label }}</div>
            </div>
        </div>

        <EmptyState
            v-if="rows.length === 0 && search === ''"
            variant="first-use"
            title="Nothing to bill yet"
            body="An invoice is raised per estate, per period, priced by unit count against the estate's plan. The first one appears here as soon as an estate is on a subscription."
        />

        <EmptyState
            v-else-if="rows.length === 0"
            variant="filtered"
            :title="`No invoices match “${search}”`"
            body="Every other invoice is still here. Clear the search to go back to the current billing position."
        />

        <table v-else class="data-table">
            <thead>
                <tr>
                    <th>Client</th>
                    <th>Period</th>
                    <th>Amount</th>
                    <th>Due date</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in rows" :key="row.key">
                    <td>
                        <div class="client-cell">
                            <div class="client-avatar">{{ row.initials }}</div>
                            <div class="client-name">{{ row.estate }}</div>
                        </div>
                    </td>
                    <td>{{ row.period }}</td>
                    <td class="num-cell">{{ row.amount }}</td>
                    <td>{{ row.due_on }}</td>
                    <td>
                        <div class="status-badge" :class="row.status">{{ row.status_label }}</div>
                    </td>
                    <td>
                        <button type="button" class="text-link-sm" disabled :title="row.action_reason">
                            {{ row.action }}
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>
    </GeminiConsole>
</template>

<style scoped>
/*
 * The only CSS in this file, and the only kind allowed: a reset.
 *
 * The board draws the tabs and the row action as <div>s. They are real <button>
 * and <a> elements here, which means the browser's own control defaults —
 * border, background colour, font family, line height, text alignment, link
 * underline, inline-block flow — show through and move the pixels. Every rule
 * below takes one of those defaults back off so that the board's own
 * .subnav-item and .text-link-sm rules are what is seen. Nothing here adds
 * styling of its own.
 *
 * Element selectors, not the class on its own, on purpose: `.subnav-item.active`
 * sets a white background, and a bare `.subnav-item { background: transparent }`
 * would out-specify it once the scoped attribute is added and repaint the
 * selected tab.
 *
 * Padding is absent from this list because the board's own stylesheet already
 * zeroes it on everything, and .subnav-item then puts its own back.
 */
button.subnav-item {
    border: 0;
    background: transparent;
    font-family: inherit;
    line-height: inherit;
    text-align: inherit;
}

a.subnav-item {
    text-decoration: none;
}

button.text-link-sm {
    /* display:block restores the board's <div> flow. A button is inline-block,
       which sits on a baseline and would add descender space to the row. */
    display: block;
    border: 0;
    background: transparent;
    font-family: inherit;
    line-height: inherit;
    text-align: inherit;
}

/*
 * Disabled, and honest about it — but not dimmed. The board draws these at full
 * colour and the fidelity of this screen is measured against it, so the state is
 * carried by the disabled attribute, the title, and the cursor rather than by
 * repainting pixels the design already decided.
 */
button.subnav-item[disabled],
button.text-link-sm[disabled] {
    cursor: not-allowed;
}
</style>
