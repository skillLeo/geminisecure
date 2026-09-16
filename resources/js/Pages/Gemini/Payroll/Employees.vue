<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'

/**
 * Payroll — Employees tab (12 §2, Wave 4).
 *
 * NO BOARD DRAWS THIS TAB'S BODY, and it is not a fidelity target. Boards 28, 30
 * and 31 draw the tab; this is what it opens, built from the payroll sheet's own
 * table so the four tabs read as one module.
 *
 * THE PEOPLE PAID HERE ARE THE GUARDS. This is payroll's view of the guard
 * record — rate, site, standing, and what approved runs have paid them — and
 * not a second register: a guard is hired, licensed and posted under Guard
 * workforce, and nothing here writes.
 *
 * APPROVED RUNS ONLY. The year-to-date and last-pay figures come from approved
 * runs, the same rule the statutory register keeps, so neither can show a
 * number a remittance will never match.
 */
defineProps({
    year: { type: Number, required: true },
    employees: { type: Array, required: true },
    filters: { type: Object, required: true },
})

const clearSearch = () => router.get('/payroll/employees', {}, { preserveScroll: true })
</script>

<template>
    <Head title="Payroll — employees" />

    <GeminiConsole title="Payroll & accounting" search-route="/payroll/employees" :search-value="filters.q">
        <div class="subnav">
            <Link href="/payroll" class="subnav-item">Pay runs</Link>
            <Link href="/payroll/employees" class="subnav-item active">Employees</Link>
            <Link href="/payroll/filings" class="subnav-item">Statutory filings</Link>
            <Link href="/payroll/rates" class="subnav-item">Rate table</Link>
        </div>

        <EmptyState
            v-if="employees.length === 0 && filters.q"
            variant="filtered"
            :title="`No guard matches “${filters.q}”`"
            body="Search by name or employee number. Every other guard is still here."
            action-label="Clear search"
            @action="clearSearch"
        />

        <EmptyState
            v-else-if="employees.length === 0"
            variant="first-use"
            title="No guards on the payroll yet"
            body="A guard is added under Guard workforce. Once they are paid by an approved run, what they have been paid appears here."
        />

        <table v-else class="data-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Type</th>
                    <th>Standard rate</th>
                    <th>Site</th>
                    <th>Status</th>
                    <th>Gross {{ year }} to date</th>
                    <th>Last net pay</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="employee in employees" :key="employee.id">
                    <td>
                        <div class="pe-name">{{ employee.name }}</div>
                        <div class="pe-sub">{{ employee.number }}</div>
                    </td>
                    <td>{{ employee.type }}</td>
                    <td class="num-cell">{{ employee.rate }}</td>
                    <td>{{ employee.site }}</td>
                    <td>{{ employee.status }}</td>
                    <td class="num-cell">{{ employee.ytd_gross }}</td>
                    <td class="num-cell">
                        {{ employee.last_net }}
                        <div class="pe-sub">{{ employee.last_period }}</div>
                    </td>
                </tr>
            </tbody>
        </table>

        <p class="pe-note">
            Figures come from approved pay runs only. A guard's record — hiring, licence, posting — is kept under Guard
            workforce.
        </p>
    </GeminiConsole>
</template>

<style scoped>
/*
 * AUTHORED. No board draws this tab's body. The tabs and table are the payroll
 * sheet's own classes; only the name stack and the note need rules, kept to the
 * tokens the Gemini boards define.
 */
a.subnav-item {
    text-decoration: none;
}

.pe-name {
    font-weight: 600;
    color: var(--navy-900);
}

.pe-sub {
    font-size: 11px;
    color: var(--slate-500);
}

.pe-note {
    font-size: 11px;
    color: var(--slate-600);
    line-height: 1.6;
    margin: 12px 0 0;
}
</style>
