<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
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
    /** The next period on the payroll calendar, and why it cannot be started if it cannot. */
    calendar: { type: Object, required: true },
    canCreate: { type: Boolean, required: true },
    reasons: { type: Object, required: true },
})

/*
 * STARTING A RUN IS BUILT (12 §2, Wave 1). The calendar is calendar months,
 * one open at a time, read from the runs themselves; the panel reads the next
 * period back before the press. The permission comes first, then the
 * calendar's own reason — a month still open, or no rate card in force.
 */
const starting = ref(false)
const submitting = ref(false)

const startBlockedBy = computed(() => {
    if (!props.canCreate) {
        return props.reasons.create
    }

    return props.calendar.blocked_by
})

const startRun = () => {
    if (startBlockedBy.value !== null) {
        return
    }

    submitting.value = true

    router.post(`${base.value}/payroll/runs`, {}, {
        preserveScroll: true,
        onFinish: () => {
            submitting.value = false
            starting.value = false
        },
    })
}

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
              Built (12 §2, Wave 1): the calendar is calendar months, one open
              at a time, and the next period is read from the runs themselves.
              Inert, with the calendar's own reason, while a month is still
              open or no rate card is in force on the pay date.
            -->
            <button
                type="button"
                class="btn-primary-sm"
                :disabled="startBlockedBy !== null"
                :title="startBlockedBy ?? `Start ${calendar.label} — ${calendar.period_start} to ${calendar.period_end}, paid ${calendar.pay_date}, on the ${calendar.rate_card} rate card. A draft: nothing is calculated or paid yet.`"
                @click="starting = !starting"
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

        <p v-if="page.props.flash?.success" class="runs-flash">{{ page.props.flash.success }}</p>
        <p v-if="page.props.errors?.run" class="runs-refusal">{{ page.props.errors.run }}</p>

        <!--
          The calendar, read back before the press: which month, which days,
          when it is paid and on which card. One button starts it as a draft.
        -->
        <div v-if="starting" class="runs-panel">
            <div class="runs-panel-head">The next period on the calendar</div>
            <dl class="runs-calendar">
                <div><dt>Period</dt><dd>{{ calendar.label }}</dd></div>
                <div><dt>Runs</dt><dd>{{ calendar.period_start }} to {{ calendar.period_end }}</dd></div>
                <div><dt>Closes and is paid</dt><dd>{{ calendar.pay_date }}</dd></div>
                <div><dt>Rate card</dt><dd>{{ calendar.rate_card ?? 'none in force' }}</dd></div>
            </dl>
            <p class="runs-panel-note">
                Starting it makes a draft prepared by you. Timesheets are checked on the exceptions screen, the payslips are
                calculated after that, and nothing is paid until an approver signs the run off.
            </p>
            <div class="runs-panel-actions">
                <button
                    type="button"
                    class="btn-primary-sm"
                    :disabled="startBlockedBy !== null || submitting"
                    :title="startBlockedBy ?? `Start ${calendar.label} as a draft.`"
                    @click="startRun"
                >
                    <span>{{ submitting ? 'Starting…' : `Start ${calendar.label}` }}</span>
                </button>
                <button type="button" class="text-link-sm" @click="starting = false">Cancel</button>
            </div>
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

button.text-link-sm {
    border: 0;
    background: none;
    font: inherit;
    padding: 0;
    cursor: pointer;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws a list nobody is starting a run
 * from, so it has no calendar panel, no flash and no refusal. Kept to the
 * tokens the boards define and the shapes they already use.
 */
.runs-flash,
.runs-refusal {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 14px;
}

.runs-flash {
    background: var(--green-100);
    color: var(--green-700);
}

.runs-refusal {
    background: var(--red-100);
    color: var(--red-700);
}

.runs-panel {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 18px;
    margin-bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 11px;
}

.runs-panel-head {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-800);
}

.runs-calendar {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 11px;
    margin: 0;
}

.runs-calendar dt {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    margin: 0 0 3px;
}

.runs-calendar dd {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--navy-900);
    margin: 0;
}

.runs-panel-note {
    font-size: 11.5px;
    color: var(--slate-600);
    line-height: 1.6;
    margin: 0;
    max-width: 760px;
}

.runs-panel-actions {
    display: flex;
    align-items: center;
    gap: 14px;
}
</style>
