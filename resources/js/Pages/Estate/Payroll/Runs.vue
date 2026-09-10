<script setup>
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * Pay runs — board screen community-admin-13.
 *
 * THE GROSS AND NET COLUMNS ARE THE RUN'S OWN LINES ADDED UP, computed
 * server-side and passed here formatted. Nothing on this page does arithmetic;
 * `EstatePayrollTest` proves the same figures equal the sum of specific posted
 * journal lines for every month that has been paid.
 *
 * AN UNCALCULATED RUN PRINTS AN EM DASH, NOT A ZERO. September has no gross and
 * no net because nobody has worked them out yet. Zero is a figure — it says the
 * estate owes its staff nothing this month — and this run says nothing at all
 * yet. The controller sends null and this page draws the dash.
 *
 * THE STATUS BADGE IS DERIVED. "2 exceptions" counts rows and "Pending
 * approval" is what `calculated` means to whoever is reading the list; the
 * stored value stays the lifecycle state. That is the same rule board 17's
 * "Overdue" follows — a badge nobody can transition into is not a state.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    kpis: { type: Array, required: true },
    rows: { type: Array, required: true },
    tabs: { type: Array, required: true },
    canCreate: { type: Boolean, required: true },
    reasons: { type: Object, required: true },
})

/*
 * Boards 13, 14 and 15 share the payroll sheet; board 37 and board 40 are drawn
 * in a different one. Confirmed against the sheet that actually defines
 * `.kpi-row` and `.data-table` for these screens rather than assumed from the
 * module — that mistake cost board 38 a whole measurement.
 */
useWireframe('community-admin-04-payroll-runs-exceptions-and-filings')

const page = usePage()

const state = useScreenState({
    rows: () => props.rows.length,
})

/** Everything up to `/payroll`, whichever shape this environment serves. */
const base = computed(() => page.url.split('/payroll')[0])

/**
 * The board's two money formats, and they are two on purpose. A KPI tile
 * abbreviates because it is read at a glance; the table does not, because a
 * treasurer comparing five months needs the figures themselves.
 */
const abbreviated = (minor) => {
    const value = minor / 100

    if (Math.abs(value) >= 1_000_000) {
        return `$${(value / 1_000_000).toFixed(2)}M`
    }

    if (Math.abs(value) >= 1_000) {
        return `$${Math.round(value / 1_000).toLocaleString('en-JM')}k`
    }

    return `$${value.toLocaleString('en-JM')}`
}

const exact = (minor) =>
    minor === null || minor === undefined
        ? '—'
        : `$${(minor / 100).toLocaleString('en-JM', { maximumFractionDigits: 0 })}`

const tile = (kpi) => (kpi.value_minor === undefined ? kpi.value : abbreviated(kpi.value_minor))

/** Which badge class the board gives each state. */
const badgeClass = (row) =>
    ({
        exceptions: 'exceptions',
        calculated: 'pending',
        paid: 'paid',
    })[row.status] ?? 'draft'

/**
 * Where a row's link goes. A run with exceptions opens the exceptions screen;
 * everything else opens the run itself. The label the controller sends says
 * which — "Resolve exceptions", "Review & approve", "View" — so the destination
 * and the words a reader clicks can never disagree.
 */
const rowHref = (row) =>
    row.status === 'exceptions'
        ? `${base.value}/payroll/runs/${row.slug}/exceptions`
        : `${base.value}/payroll/runs/${row.slug}`
</script>

<template>
    <Head title="Payroll & HR" />

    <EstateConsole title="Payroll & HR" :estate-name="estate.name" active="payroll">
        <template #actions>
            <!--
              Starting a run is not built: a run is created for a period, and
              which periods exist is a payroll calendar nobody has specified.
              Drawn with the real reason rather than silently missing.
            -->
            <button
                type="button"
                class="btn-primary-sm"
                disabled
                :title="canCreate ? 'Not built yet — starting a run needs a payroll calendar saying which periods exist and when each one closes.' : reasons.create"
            >
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
                <span>Start new run</span>
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

        <div class="kpi-row">
            <div v-for="kpi in kpis" :key="kpi.key" class="kpi-card">
                <div class="k-top">
                    <div class="kpi-icon">
                        <svg v-if="kpi.key === 'employees'" viewBox="0 0 24 24" fill="none">
                            <circle cx="9" cy="8" r="3.4" stroke="currentColor" stroke-width="1.7" />
                            <path d="M3 20c0-3.3 2.7-5.4 6-5.4s6 2.1 6 5.4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                            <path d="M17 11a3 3 0 1 0 0-6M18 20c0-2.6-1-4.3-2.6-5.1" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                        </svg>
                        <svg v-else-if="kpi.key === 'next_pay_date'" viewBox="0 0 24 24" fill="none">
                            <rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.7" />
                            <path d="M3 10h18M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                        </svg>
                        <svg v-else-if="kpi.key === 'last_net'" viewBox="0 0 24 24" fill="none">
                            <rect x="2" y="6" width="20" height="13" rx="2" stroke="currentColor" stroke-width="1.7" />
                            <path d="M2 10h20" stroke="currentColor" stroke-width="1.7" />
                        </svg>
                        <svg v-else viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7" />
                            <path d="M8 12.5l2.5 2.5L16 9.5" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </div>
                </div>
                <div class="k-val">{{ tile(kpi) }}</div>
                <div class="k-lbl">{{ kpi.label }}</div>
            </div>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="5" :columns="6" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Payroll is not part of your role’s access"
            body="A pay run holds what every member of the estate's staff earns, their bank details and their NIS numbers, so it opens only to a role that holds Payroll. Being on a payroll is not access to one."
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="No pay run yet"
            body="The estate has not run payroll on this platform. A run is raised for a period, its exceptions are cleared, and the payslips are worked out before anything is approved or paid."
        />

        <table v-else class="data-table">
            <thead>
                <tr>
                    <th>Pay period</th>
                    <th>Status</th>
                    <th>Employees</th>
                    <th>Gross</th>
                    <th>Net</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in rows" :key="row.slug">
                    <td>{{ row.period }}</td>
                    <td>
                        <div class="status-badge" :class="badgeClass(row)">{{ row.status_label }}</div>
                    </td>
                    <td>{{ row.employees }}</td>
                    <td class="num-cell">{{ exact(row.gross_minor) }}</td>
                    <td class="num-cell">{{ exact(row.net_minor) }}</td>
                    <td>
                        <Link :href="rowHref(row)" class="text-link-sm">{{ row.action }}</Link>
                    </td>
                </tr>
            </tbody>
        </table>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws its topbar action, its three tabs and
 * its row links as <div>s; here the first is a real button and the rest are
 * anchors, which arrive with a border, a face, the browser's own font and an
 * underline. .btn-primary-sm, .subnav-item and .text-link-sm supply everything
 * visible — and nothing here reaches past those defaults, which is the mistake
 * D-045 records.
 */
button.btn-primary-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.btn-primary-sm[disabled] {
    cursor: not-allowed;
}

a.subnav-item,
a.text-link-sm {
    text-decoration: none;
}
</style>
