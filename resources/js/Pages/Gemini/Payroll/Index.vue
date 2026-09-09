<script setup>
import { Head, Link } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'

defineProps({
    runs: { type: Array, required: true },
})
</script>

<template>
    <Head title="Payroll & accounting" />

    <GeminiConsole title="Payroll &amp; accounting">
        <p class="payroll-note">
            Gemini Security paying its own guards. An estate's staff payroll is a separate ledger
            in that estate's database and never appears here.
        </p>

        <EmptyState
            v-if="runs.length === 0"
            variant="first-use"
            title="No payroll runs yet"
            body="A run is calculated against the statutory rate version in force for its period, and records that version so it reproduces exactly, to the cent, years later."
        />

        <table v-else class="data-table">
            <thead>
                <tr>
                    <th>Period</th>
                    <th>Employees</th>
                    <th>Gross</th>
                    <th>Net pay</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <template v-for="run in runs" :key="run.id">
                    <tr>
                        <td class="cell-strong">{{ run.period }}</td>
                        <td class="cell-mono">{{ run.employees }}</td>
                        <td class="cell-mono">{{ run.gross }}</td>
                        <td class="cell-mono">{{ run.net }}</td>
                        <td><span class="status-badge" :class="run.status_badge">{{ run.status }}</span></td>
                        <td>
                            <Link :href="`/payroll/${run.id}`" class="text-link-sm">
                                {{ run.can_approve ? 'Review & approve' : 'Review' }}
                            </Link>
                        </td>
                    </tr>
                    <!--
                      A blocked approval explains itself on the row rather than
                      presenting a button that quietly does nothing.
                    -->
                    <tr v-if="run.blocked_reason" class="blocked-row">
                        <td colspan="6">{{ run.blocked_reason }}</td>
                    </tr>
                </template>
            </tbody>
        </table>
    </GeminiConsole>
</template>

<style scoped>
.payroll-note {
    font-size: 12.5px;
    color: var(--slate-600);
    line-height: 1.6;
    max-width: 720px;
    margin-bottom: 16px;
}

.blocked-row td {
    background: var(--amber-100);
    color: var(--amber-700);
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
}
</style>
