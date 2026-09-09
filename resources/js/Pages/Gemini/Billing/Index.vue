<script setup>
import { Head } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import ModuleIcon from '../../../Components/ModuleIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'

defineProps({
    kpis: { type: Array, required: true },
    invoices: { type: Array, required: true },
})
</script>

<template>
    <Head title="Billing & subscriptions" />

    <GeminiConsole title="Billing &amp; subscriptions">
        <div class="kpi-row">
            <div v-for="kpi in kpis" :key="kpi.key" class="kpi-card" :class="{ warn: kpi.alert }">
                <div class="k-top">
                    <div class="kpi-icon"><ModuleIcon module="billing_subscriptions" /></div>
                </div>
                <div class="k-val">{{ kpi.value }}</div>
                <div class="k-lbl">{{ kpi.label }}</div>
            </div>
        </div>

        <!--
          Stated on the screen, not just in the schema. Anyone reading a
          dunning figure needs to know it does not gate access.
        -->
        <p class="billing-note">
            Dunning and suspension gate billing features only. Access is never withheld over a
            billing dispute &mdash; no invoice status restricts entry, a safety function, or a
            resident.
        </p>

        <EmptyState
            v-if="invoices.length === 0"
            variant="first-use"
            title="No invoices raised yet"
            body="Invoices are raised per estate, per period, priced by unit count against the estate's plan. They appear here once the first billing period closes."
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
                <tr v-for="invoice in invoices" :key="invoice.id">
                    <td class="cell-strong">{{ invoice.estate }}</td>
                    <td>{{ invoice.period }}</td>
                    <td class="cell-mono">{{ invoice.amount }}</td>
                    <td class="cell-mono">{{ invoice.due_on }}</td>
                    <td><span class="status-badge" :class="invoice.status_badge">{{ invoice.status }}</span></td>
                    <td><span class="text-link-sm">View invoice</span></td>
                </tr>
            </tbody>
        </table>
    </GeminiConsole>
</template>

<style scoped>
.billing-note {
    font-size: 12.5px;
    color: var(--slate-600);
    line-height: 1.6;
    max-width: 720px;
    margin-bottom: 16px;
}
</style>
