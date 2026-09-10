<script setup>
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EstateIcon from '../../../Components/EstateIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * Chart of accounts — board screen community-admin-25.
 *
 * The structure every journal in this estate posts against, grouped by type
 * and ordered by code, which is what an accountant navigates by.
 *
 * NO BALANCE ON THIS SCREEN IS STORED. Each is the sum of the account's posted
 * lines, computed on read. A stored balance is a second copy of the ledger free
 * to drift from it — and the copy is the one a committee reads, so the drift is
 * invisible until an auditor arrives.
 *
 * INCOME AND EXPENSE ARE FOR THE PERIOD; EVERYTHING ELSE IS CUMULATIVE, and
 * that distinction is what the two kinds of account mean rather than a
 * presentation choice. A bank balance is everything that ever happened to it;
 * "Maintenance Fee Income" with no period stated is a number nobody can act on.
 * The board draws J$2,790,000 against maintenance income, which is exactly 450
 * units at J$6,200 — one month's assessment (D-042) — so the period is the
 * current month and the screen SAYS SO. The board leaves it implied; a figure
 * whose period a reader has to guess is the defect that surfaces at an audit.
 *
 * THE EQUITY GROUP IS NOT ON THE BOARD, and it has to be here. Total the twelve
 * accounts the board draws and they are out by J$8,556,719 — which is not an
 * error in the figures, it IS the members' accumulated fund. A chart of
 * accounts that hides an account from the accountant, and a trial balance that
 * can never agree, are both worse than a row the board did not draw. See D-041.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    groups: { type: Array, required: true },
    period: { type: String, required: true },
    kpis: { type: Array, required: true },
    tabs: { type: Array, required: true },
    reasons: { type: Object, required: true },
})

const page = usePage()

/*
 * Which board's stylesheet this page wears. The ten Estate Console boards do
 * NOT share one sheet the way the nine Gemini boards do, so every estate page
 * has to name its own or it renders with no board CSS at all.
 */
useWireframe('community-admin-07-chart-of-accounts-vendors-bills-and-bank-rec')

const state = useScreenState({
    rows: () => props.groups.reduce((total, group) => total + group.rows.length, 0),
})

/**
 * The board's two money formats, and they are two on purpose.
 *
 * The KPI tiles abbreviate — "$4.30M", "$412k" — because a tile is read at a
 * glance and eight digits in it are noise. The table does not, because an
 * accountant reconciling a column needs the figure itself. Both are drawn that
 * way on the board.
 */
const abbreviated = (minor) => {
    const value = minor / 100

    if (Math.abs(value) >= 1_000_000) {
        return `$${(value / 1_000_000).toFixed(2)}M`
    }

    if (Math.abs(value) >= 1_000) {
        return `$${Math.round(value / 1_000)}k`
    }

    return `$${value.toLocaleString('en-JM')}`
}

const exact = (minor) => `$${(minor / 100).toLocaleString('en-JM', { maximumFractionDigits: 0 })}`

/**
 * The estate path this console is served under.
 *
 * Local puts the estate in the path and production gives each its own
 * hostname, so the prefix is taken from the URL we are already on rather than
 * rebuilt. Everything up to `/accounting` is the prefix, whichever shape it is.
 */
const base = computed(() => page.url.split('/accounting')[0])
</script>

<template>
    <Head title="Accounting" />

    <EstateConsole title="Accounting" :estate-name="estate.name" active="accounting">
        <template #actions>
            <button type="button" class="btn-primary-sm" disabled :title="reasons.add">
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
                <span>Add account</span>
            </button>
        </template>

        <div class="subnav">
            <template v-for="tab in tabs" :key="tab.key">
                <div v-if="tab.key === 'chart'" class="subnav-item active">{{ tab.label }}</div>
                <Link v-else :href="`${base}/accounting/${tab.key}`" class="subnav-item">
                    {{ tab.label }}
                </Link>
            </template>
        </div>

        <div class="kpi-row">
            <div v-for="kpi in kpis" :key="kpi.key" class="kpi-card">
                <div class="k-top">
                    <div class="kpi-icon"><EstateIcon :name="kpi.icon" /></div>
                </div>
                <div class="k-val">{{ abbreviated(kpi.value_minor) }}</div>
                <div class="k-lbl">{{ kpi.label }}</div>
            </div>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="12" :columns="4" />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="No chart of accounts yet"
            body="Every journal entry posts against an account. Until the chart exists, nothing in this estate can be booked."
        />

        <table v-else class="data-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Account name</th>
                    <th>Type</th>
                    <th>Balance</th>
                </tr>
            </thead>
            <tbody>
                <template v-for="group in groups" :key="group.label">
                    <tr class="group-head">
                        <td colspan="4">{{ group.label }}</td>
                    </tr>
                    <tr v-for="row in group.rows" :key="row.id">
                        <td>{{ row.code }}</td>
                        <td>{{ row.name }}</td>
                        <td>{{ row.type }}</td>
                        <td class="num-cell">{{ exact(row.balance_minor) }}</td>
                    </tr>
                </template>
            </tbody>
        </table>

        <!--
          Authored, and it earns its place. Two of the five groups above are
          period figures and three are cumulative; a reader who assumes one rule
          for the whole column will misread the income by a factor of however
          many months the estate has been billing.
        -->
        <p v-if="!state.isEmpty.value" class="chart-note">
            Income and expense balances are for {{ period }}. Assets, liabilities and equity are cumulative.
        </p>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws the topbar action as a <div> and the
 * three sibling tabs as <div>s; here the first is a real button and the others
 * are anchors, which arrive with a border, a face, the browser's own font and
 * an underline. .btn-primary-sm and .subnav-item supply everything visible.
 */
button.btn-primary-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

a.subnav-item {
    text-decoration: none;
}

button.btn-primary-sm[disabled] {
    cursor: not-allowed;
}

/*
 * The period note. Authored, because the board carries no equivalent — and it
 * is the one thing on this screen a reader cannot work out from what is drawn.
 * Kept to the tokens the boards define.
 */
.chart-note {
    font-size: 11px;
    color: var(--slate-500);
    line-height: 1.6;
    margin: 12px 2px 0;
}
</style>
