<script setup>
import { ref } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * One report, run — board screen community-admin-30.
 *
 * THE PERIOD IS ON THE PAGE, ALWAYS. "A report with an unstated period is the
 * defect that surfaces at an audit" is the P&L card's own reason on the
 * catalogue, and it is the rule for all of them: the window is drawn under the
 * title, it is what the reader chose, and it goes into the audit scope of the
 * export. A figure without its window is a figure somebody will quote.
 *
 * THE NOTE IS PART OF THE REPORT AND NOT A FOOTNOTE. Each one says something a
 * reader would otherwise get wrong — that a P&L is by posting date and not due
 * date, that an ageing is a position today and the chosen dates do not narrow
 * it, that turnout is a count and nothing finer. Those are the sentences that
 * stop a correct report being read incorrectly.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    key: { type: String, required: true },
    title: { type: String, required: true },
    period: { type: String, required: true },
    columns: { type: Array, required: true },
    rows: { type: Array, required: true },
    summary: { type: Array, required: true },
    note: { type: String, default: null },
    from: { type: String, required: true },
    to: { type: String, required: true },
    catalogueHref: { type: String, required: true },
    path: { type: String, required: true },
    exportHref: { type: String, required: true },
})

useWireframe('community-admin-08-reports-unit-claims-and-notices')

const state = useScreenState({
    rows: () => props.rows.length,
})

const retry = () => router.reload()

const from = ref(props.from)
const to = ref(props.to)

const rerun = () => {
    router.get(props.path, { from: from.value, to: to.value }, { preserveScroll: true })
}
</script>

<template>
    <Head :title="title" />

    <EstateConsole :title="title" :estate-name="estate.name" active="reports">
        <template #lead>
            <Link
                :href="catalogueHref"
                style="width:34px;height:34px;border-radius:50%;background:var(--navy-100);display:flex;align-items:center;justify-content:center;flex:0 0 auto;"
                title="Back to the report catalogue"
                aria-label="Back to the report catalogue"
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
                :href="exportHref"
                class="btn-outline-sm"
                title="Download this report as a CSV, over exactly this period. The estate records that it left, who took it and how many rows it held."
            >
                <span>Export</span>
            </a>
        </template>

        <!--
          The period, first and unmissable. It is what the reader chose and what
          every figure below is of.
        -->
        <div class="rp-period">
            <div class="rp-window">{{ period }}</div>

            <form class="rp-dates" @submit.prevent="rerun">
                <label for="rp-from">From</label>
                <input id="rp-from" v-model="from" type="date" />
                <label for="rp-to">To</label>
                <input id="rp-to" v-model="to" type="date" />
                <button type="submit" class="btn-outline-sm" title="Run this report again over the period chosen.">
                    <span>Run</span>
                </button>
            </form>
        </div>

        <div v-if="summary.length" class="rp-summary">
            <div v-for="item in summary" :key="item.label" class="rp-card">
                <div class="rp-label">{{ item.label }}</div>
                <div class="rp-value">{{ item.value }}</div>
            </div>
        </div>

        <p v-if="note" class="rp-note">{{ note }}</p>

        <SkeletonRows v-if="state.isLoading.value" :rows="8" :columns="4" />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The report could not be run"
            body="Nothing on this screen writes anything, so nothing has changed and running it again is safe."
            action-label="Try again"
            @action="retry"
        />

        <!--
          A report with no rows over a chosen period is a real answer, and a
          different one from a report that failed. The period is in the sentence
          because it is the reason.
        -->
        <EmptyState
            v-else-if="state.isEmpty.value || state.isEmptyFiltered.value"
            variant="filtered"
            title="Nothing falls in this period"
            :body="`This report found no rows between ${from} and ${to}. That is an answer rather than a fault — widen the period, or read it as the estate having nothing to report for those dates.`"
        />

        <table v-else class="data-table">
            <thead>
                <tr>
                    <th v-for="column in columns" :key="column">{{ column }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(row, index) in rows" :key="index">
                    <td v-for="(cell, i) in row" :key="i">{{ cell }}</td>
                </tr>
            </tbody>
        </table>
    </EstateConsole>
</template>

<style scoped>
/*
 * AUTHORED. Board 30 is the catalogue's result screen and this sheet gives it a
 * table and nothing else, so the period strip, the summary cards and the note
 * are authored — kept to the tokens the boards define.
 */
a.btn-outline-sm {
    text-decoration: none;
}

button.btn-outline-sm {
    font: inherit;
    cursor: pointer;
}

.rp-period {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    background: var(--navy-100);
    border-radius: 12px;
    padding: 11px 15px;
    margin-bottom: 14px;
}

.rp-window {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-900);
}

.rp-dates {
    display: flex;
    align-items: center;
    gap: 8px;
}

.rp-dates label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
}

.rp-dates input {
    height: 30px;
    border: 1px solid var(--navy-200);
    border-radius: 8px;
    background: var(--white);
    padding: 0 9px;
    font: inherit;
    font-size: 11.5px;
    color: var(--navy-900);
}

.rp-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 11px;
    margin-bottom: 14px;
}

.rp-card {
    flex: 1 1 150px;
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 12px;
    padding: 11px 14px;
}

.rp-label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.rp-value {
    font-size: 15px;
    font-weight: 700;
    color: var(--navy-900);
    line-height: 1.4;
}

.rp-note {
    font-size: 11px;
    color: var(--slate-600);
    line-height: 1.6;
    margin: 0 0 14px;
    max-width: 840px;
}
</style>
