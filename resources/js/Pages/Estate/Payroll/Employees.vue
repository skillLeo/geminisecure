<script setup>
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * Payroll employees — board screen community-admin-37.
 *
 * THE BANK ACCOUNT AND NIS NUMBERS ARE MASKED BEFORE THEY LEAVE THE SERVER, not
 * here. `Payroll::maskedBank()` and `maskedNis()` build "NCB •••• 3315" and
 * "••• •••5 208" at the boundary, and the payload carries nothing fuller — a
 * page that masked in a template would be sending the whole number to every
 * browser and hiding it with CSS, which is not hiding it at all. Anybody at the
 * desk can open the network tab. `EstatePayrollTest` asserts no field on any row
 * carries a run of digits long enough to be an account.
 *
 * THE RATE IS THE ONLY MONEY ON THIS SCREEN and it is a monthly gross. The four
 * rates sum to the J$440,000 board 15 debits to payroll expense — one number
 * appearing on two screens because both read the same register, not because
 * either was typed twice.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    rows: { type: Array, required: true },
    tabs: { type: Array, required: true },
    canCreate: { type: Boolean, required: true },
    reasons: { type: Object, required: true },
})

/*
 * Board 37 is drawn in the employees-details-and-billing sheet beside boards 38
 * and 40, NOT in the payroll sheet its two siblings use. Checked against the
 * sheet that defines `.res-cell` and `.res-avatar` rather than inferred from the
 * module — board 38 lost a whole measurement to exactly that assumption.
 */
useWireframe('community-admin-10-payroll-employees-details-and-billing')

const page = usePage()

const state = useScreenState({
    rows: () => props.rows.length,
})

const base = computed(() => page.url.split('/payroll')[0])

/** "$185,000/mo", as the board writes it. */
const rate = (minor) => `$${(minor / 100).toLocaleString('en-JM', { maximumFractionDigits: 0 })}/mo`
</script>

<template>
    <Head title="Payroll & HR" />

    <EstateConsole title="Payroll & HR" :estate-name="estate.name" active="payroll">
        <template #actions>
            <button
                type="button"
                class="btn-primary-sm"
                disabled
                :title="canCreate ? 'Not built yet — adding an employee needs their bank details and NIS number, which is personal data this console has no consented intake for yet.' : reasons.create"
            >
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
                <span>Add employee</span>
            </button>
        </template>

        <div class="subnav">
            <template v-for="tab in tabs" :key="tab.key">
                <div v-if="tab.active" class="subnav-item active" aria-current="page">{{ tab.label }}</div>
                <Link
                    v-else
                    :href="tab.key === 'runs' ? `${base}/payroll` : `${base}/payroll/${tab.key}`"
                    class="subnav-item"
                >
                    {{ tab.label }}
                </Link>
            </template>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="7" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Payroll is not part of your role’s access"
            body="This register holds every member of staff's pay rate, bank account and NIS number, so it opens only to a role that holds Payroll."
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="Nobody is on the payroll yet"
            body="The estate employs no staff of its own on this platform. Security guards are Gemini Security Limited's employees, paid centrally, and never appear here."
        />

        <table v-else class="data-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Role</th>
                    <th>Type</th>
                    <th>Bank account</th>
                    <th>NIS number</th>
                    <th>Rate</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in rows" :key="row.id">
                    <td>
                        <div class="res-cell">
                            <div class="res-avatar">{{ row.initials }}</div>
                            <div>
                                <div class="res-name">{{ row.name }}</div>
                                <div class="res-sub">{{ row.since }}</div>
                            </div>
                        </div>
                    </td>
                    <td>{{ row.role }}</td>
                    <td>{{ row.type }}</td>
                    <td>{{ row.bank }}</td>
                    <td>{{ row.nis }}</td>
                    <td class="num-cell">{{ rate(row.rate_minor) }}</td>
                    <td>
                        <div class="status-badge active">{{ row.status === 'active' ? 'Active' : 'Inactive' }}</div>
                    </td>
                </tr>
            </tbody>
        </table>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws its topbar action and its three tabs as
 * <div>s; here one is a button and two are anchors.
 */
button.btn-primary-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.btn-primary-sm[disabled] {
    cursor: not-allowed;
}

a.subnav-item {
    text-decoration: none;
}
</style>
