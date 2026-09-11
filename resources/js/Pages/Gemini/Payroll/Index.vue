<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'

/**
 * Payroll overview — board screen super-admin-28.
 *
 * DOM and class names are the board's: a tab strip, one exception banner, four
 * KPI cards and the run table, in that order and nothing else.
 *
 * The board draws every control as a <div>. Here the tab that leads somewhere
 * is a Link, the three whose screens do not exist yet are buttons carrying
 * `disabled` and a reason, and the column headings sort. The headings sort on
 * click without gaining a chevron, because the board draws none and inventing
 * one would be authoring a piece of design nobody approved — the tooltip and
 * `aria-sort` say what they do instead.
 */
const props = defineProps({
    kpis: { type: Array, required: true },
    /** The one alert the board has room for, or null when nothing is wrong. */
    banner: { type: Object, default: null },
    runs: { type: Array, required: true },
    filters: { type: Object, required: true },
})

/*
 * The tab whose screen is not built.
 *
 * It is `disabled` and says why. A tab that looks live and swallows the click
 * is worse than one that admits it is not ready.
 */
const unbuilt = {
    employees: 'Not built yet — the people paid here are the guards listed under Guard workforce',
}

const columns = [
    { key: 'period', label: 'Period', hint: 'period' },
    { key: 'employees', label: 'Employees', hint: 'headcount' },
    { key: 'gross', label: 'Gross', hint: 'gross' },
    { key: 'net', label: 'Net pay', hint: 'net pay' },
    { key: 'status', label: 'Status', hint: 'status' },
]

/*
 * Sorting is a server round-trip and lives in the URL, so a sorted view can be
 * linked, reloaded and shared. Clicking the column already sorted reverses it.
 */
const sortBy = (column) => {
    const direction = props.filters.sort === column && props.filters.dir === 'asc' ? 'desc' : 'asc'

    router.get(
        '/payroll',
        { q: props.filters.q || undefined, sort: column, dir: direction },
        { preserveState: true, preserveScroll: true, replace: true }
    )
}

const ariaSort = (column) => {
    if (props.filters.sort !== column) {
        return 'none'
    }

    return props.filters.dir === 'asc' ? 'ascending' : 'descending'
}

/*
 * The whole row opens the run, not only the link at the end of it.
 *
 * Guarded twice: a click that landed on the link is left to the link, and a
 * click that ends a text selection is someone reading a figure, not navigating.
 */
const openRun = (run, event) => {
    if (event.target.closest('a, button')) {
        return
    }

    if (window.getSelection()?.toString()) {
        return
    }

    router.visit(`/payroll/${run.id}`)
}

const clearSearch = () => router.get('/payroll', {}, { preserveScroll: true })
</script>

<template>
    <Head title="Payroll & accounting" />

    <GeminiConsole title="Payroll & accounting" search-route="/payroll" :search-value="filters.q">
        <!-- The four tabs in the board's own order. -->
        <div class="subnav">
            <Link href="/payroll" class="subnav-item active">Pay runs</Link>
            <button type="button" class="subnav-item" disabled :title="unbuilt.employees">Employees</button>
            <Link href="/payroll/filings" class="subnav-item">Statutory filings</Link>
            <!-- Built since this tab was drawn inert: the rate table is its own
                 screen, and the other two payroll tabs already link it. -->
            <Link href="/payroll/rates" class="subnav-item">Rate table</Link>
        </div>

        <div v-if="banner" class="exception-banner">
            <BoardIcon name="warning" />
            <div>
                <div class="eb1">{{ banner.title }}</div>
                <div class="eb2">{{ banner.detail }}</div>
            </div>
        </div>

        <div class="kpi-row">
            <div v-for="kpi in kpis" :key="kpi.key" class="kpi-card">
                <div class="k-top">
                    <div class="kpi-icon">
                        <BoardIcon :name="kpi.icon" :stroke="kpi.stroke" />
                    </div>
                </div>
                <div class="k-val">{{ kpi.value }}</div>
                <div class="k-lbl">{{ kpi.label }}</div>
            </div>
        </div>

        <EmptyState
            v-if="runs.length === 0 && filters.q"
            variant="filtered"
            :title="`No pay run matches “${filters.q}”`"
            body="Every other run is still here. Clear the search to see them all."
            action-label="Clear search"
            @action="clearSearch"
        />

        <EmptyState
            v-else-if="runs.length === 0"
            variant="first-use"
            title="No payroll runs yet"
            body="A run is calculated against the statutory rate version in force for its period, and records that version, so it reproduces exactly — to the cent — years later."
        />

        <table v-else class="data-table">
            <thead>
                <tr>
                    <th
                        v-for="column in columns"
                        :key="column.key"
                        :aria-sort="ariaSort(column.key)"
                        :title="`Sort by ${column.hint}`"
                        @click="sortBy(column.key)"
                    >{{ column.label }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="run in runs" :key="run.id" @click="openRun(run, $event)">
                    <td>{{ run.period }}</td>
                    <td>{{ run.employees }}</td>
                    <td class="num-cell">{{ run.gross }}</td>
                    <td class="num-cell">{{ run.net }}</td>
                    <td><div class="status-badge" :class="run.badge_class" :title="run.blocked_reason || undefined">{{ run.badge_label }}</div></td>
                    <td><Link :href="`/payroll/${run.id}`" class="text-link-sm">{{ run.action_label }}</Link></td>
                </tr>
            </tbody>
        </table>
    </GeminiConsole>
</template>

<style scoped>
/*
 * The only authored CSS here, and every rule takes a browser default back off
 * rather than adding a style of its own.
 *
 * The board draws the tabs and the row action as <div>s, which is free for a
 * still image. They are a link and real buttons here, so the UA's link
 * underline and the button's own face, border and font would otherwise show
 * through and change the pixels. .subnav-item and .text-link-sm already state
 * everything else — size, weight, colour, padding, radius — so nothing below
 * restates any of it.
 */
a.subnav-item,
.text-link-sm {
    text-decoration: none;
}

button.subnav-item {
    -webkit-appearance: none;
    appearance: none;
    border: 0;
    background: none;
    font-family: inherit;
}
</style>
