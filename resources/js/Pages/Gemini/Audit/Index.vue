<script setup>
import { computed } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'

/**
 * Access & audit log — board screen super-admin-41.
 *
 * The board's `.content` is one table and nothing else, so in its own state —
 * no search, no filter, one page — that is exactly what this renders. The bar
 * above the table appears only once there is something in it to say: which
 * filters are narrowing the log, and which page of it you are on.
 *
 * Everything on this screen reads. There is no edit control and no delete
 * control, and deliberately not a disabled one either: a greyed-out delete
 * says deletion is a thing that could be switched on, and audit_log is
 * append-only at the grant, at a database trigger and in the model.
 *
 * Every control is a link, because every one of them is a different view of
 * the log at a different URL — which is how a finding gets sent to someone
 * else. Sorting, filtering and paging are all done by the server.
 *
 * The one element here the board does not draw is the top-bar search field.
 * An audit log that cannot be searched is a scroll, and this screen is asked
 * for a live server-side search; the field is live rather than decorative and
 * carries its own placeholder, so it says what it actually searches.
 */
const props = defineProps({
    rows: { type: Array, required: true },
    /** [{ key, label, first }] — the columns the query can actually sort by. */
    columns: { type: Array, required: true },
    filters: { type: Object, required: true },
    sort: { type: Object, required: true },
    pagination: { type: Object, required: true },
    basePath: { type: String, required: true },
    searchRoute: { type: String, required: true },
})

const MONTHS = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
]

/**
 * Formatted from the parts, not through Date. `new Date('2026-09-09')` is
 * midnight UTC, and west of Greenwich that formats as the 8th — a date filter
 * that names the wrong day is worse than no label at all.
 */
const prettyDate = (iso) => {
    const [year, month, day] = iso.split('-').map(Number)

    return `${day} ${MONTHS[month - 1]} ${year}`
}

/* --- URLs -------------------------------------------------------------- */

/**
 * The state this page is in, as query parameters. Sort and direction are
 * always carried, rather than dropped when they match the default, so the
 * default lives in one place — the server — instead of being repeated here.
 */
const current = computed(() => ({
    q: props.filters.q,
    actor: props.filters.actor,
    category: props.filters.category,
    from: props.filters.from,
    to: props.filters.to,
    sort: props.sort.key,
    dir: props.sort.direction,
}))

/**
 * The current view with some parameters changed. `page` is never carried, so
 * changing a filter or a sort returns to page one — page four of a different
 * query is a different set of rows and usually an empty one.
 */
const urlFor = (overrides = {}) => {
    const params = new URLSearchParams()

    for (const [key, value] of Object.entries({ ...current.value, ...overrides })) {
        if (value !== null && value !== undefined && value !== '') {
            params.set(key, String(value))
        }
    }

    const search = params.toString()

    return search === '' ? props.basePath : `${props.basePath}?${search}`
}

/*
 * The export carries the same view, minus the page number: a file is the whole
 * filtered set rather than the twenty-five rows on screen, so paging past row
 * one does not quietly change what a download holds.
 */
const exportHref = computed(() => {
    const params = new URLSearchParams()

    for (const [key, value] of Object.entries(current.value)) {
        if (key !== 'page' && value !== null && value !== undefined && value !== '') {
            params.set(key, String(value))
        }
    }

    const search = params.toString()

    return `${props.basePath}/export${search === '' ? '' : `?${search}`}`
})

/* --- sorting ----------------------------------------------------------- */

const nextDirection = (column) =>
    column.key === props.sort.key
        ? props.sort.direction === 'asc'
            ? 'desc'
            : 'asc'
        : column.first

const sortUrl = (column) => urlFor({ sort: column.key, dir: nextDirection(column) })

const sortTitle = (column) =>
    `Sort by ${column.label.toLowerCase()} (${nextDirection(column) === 'asc' ? 'ascending' : 'descending'})`

const ariaSort = (column) =>
    column.key === props.sort.key
        ? props.sort.direction === 'asc'
            ? 'ascending'
            : 'descending'
        : 'none'

/** Shown on the sorted column only, so the header keeps the board's width. */
const sortMark = (column) =>
    column.key === props.sort.key ? (props.sort.direction === 'asc' ? ' ↑' : ' ↓') : ''

/* --- filtering ---------------------------------------------------------- */

/*
 * Each filter is set by clicking the value it filters on, and cleared by
 * clicking it again. The board draws no filter bar and this screen does not
 * invent one, so the values in the table are the control: click an admin to
 * see only their entries, click the same admin again to stop.
 */
const actorUrl = (row) => urlFor({ actor: props.filters.actor === row.actor ? null : row.actor })

const categoryUrl = (row) =>
    urlFor({ category: props.filters.category === row.category ? null : row.category })

const onThisDay = (row) => props.filters.from === row.date && props.filters.to === row.date

const dateUrl = (row) =>
    onThisDay(row) ? urlFor({ from: null, to: null }) : urlFor({ from: row.date, to: row.date })

const actorTitle = (row) =>
    props.filters.actor === row.actor
        ? `Stop showing only ${row.actor}'s entries`
        : `Show only entries by ${row.actor}`

const categoryTitle = (row) =>
    props.filters.category === row.category
        ? `Stop showing only ${row.category} entries`
        : `Show only ${row.category} entries`

const dateTitle = (row) => (onThisDay(row) ? 'Show every date again' : `Show only ${row.date_label}`)

const dateFilterLabel = computed(() => {
    const { from, to } = props.filters

    if (from && to) {
        return from === to ? `On ${prettyDate(from)}` : `${prettyDate(from)} to ${prettyDate(to)}`
    }

    return from ? `From ${prettyDate(from)}` : `Until ${prettyDate(to)}`
})

/** Every filter now narrowing the log, each one a link that removes it. */
const activeFilters = computed(() => {
    const chips = []

    if (props.filters.q) {
        chips.push({ key: 'q', label: `Search: ${props.filters.q}`, href: urlFor({ q: null }) })
    }

    if (props.filters.actor) {
        chips.push({ key: 'actor', label: `Admin: ${props.filters.actor}`, href: urlFor({ actor: null }) })
    }

    if (props.filters.category) {
        chips.push({
            key: 'category',
            label: `Action: ${props.filters.category}`,
            href: urlFor({ category: null }),
        })
    }

    if (props.filters.from || props.filters.to) {
        chips.push({
            key: 'date',
            label: dateFilterLabel.value,
            href: urlFor({ from: null, to: null }),
        })
    }

    return chips
})

const clearAllUrl = computed(() =>
    urlFor({ q: null, actor: null, category: null, from: null, to: null })
)

/* --- what the bar above the table has to say ---------------------------- */

/*
 * The pager is hidden past the end of the log — a stale ?page=99 would
 * otherwise print "Page 99 of 4" and offer a Previous that lands on another
 * empty page. The empty state below says what happened and offers the way
 * back, which is the only useful control at that point.
 */
const showPager = computed(
    () => props.pagination.pages > 1 && props.pagination.page <= props.pagination.pages
)

const showBar = computed(() => activeFilters.value.length > 0 || showPager.value)

/*
 * Three ways for the table to be empty, and they need different words. A log
 * with nothing in it yet is not the same as a filter that matches nothing, and
 * neither is the same as a page number past the end of the log — which is what
 * a stale bookmark gives you. Telling a reader "nothing has been recorded" when
 * eighty entries exist is the worst answer an audit log can give.
 */
const isFirstUse = computed(() => props.pagination.total === 0 && activeFilters.value.length === 0)
const isEmpty = computed(() => props.rows.length === 0 && !isFirstUse.value)
const isFiltered = computed(() => activeFilters.value.length > 0)

const emptyTitle = computed(() =>
    isFiltered.value ? 'No entries match these filters' : 'That page is past the end of the log'
)

const emptyBody = computed(() =>
    isFiltered.value
        ? 'Every other entry is still recorded and still here. Clearing the filters shows the whole log again.'
        : `The log holds ${props.pagination.total} entries across ${props.pagination.pages} pages, and this is not one of them.`
)

const emptyAction = computed(() => (isFiltered.value ? 'Clear filters' : 'Back to the first page'))
</script>

<template>
    <Head title="Access &amp; audit log" />

    <GeminiConsole
        title="Access &amp; audit log"
        board="super-admin-08-access-and-audit-log"
        :search-route="searchRoute"
        :search-value="filters.q ?? ''"
        search-placeholder="Search the log by admin, action or client&hellip;"
    >
        <template #actions>
            <!--
              The board's own topbar control, and the only one it draws. It
              carries whatever this screen is filtered to, so the file matches
              what is on the page — and taking a copy of who did what is itself
              a thing somebody did, so this export writes an audit entry of its
              own, naming the estates whose rows are in the file.
            -->
            <a
                :href="exportHref"
                class="btn-outline-sm"
                title="Download the log as it is filtered here. This export is itself recorded, with the estates it covered and how many rows it held."
            >
                <BoardIcon name="export" :stroke="1.8" />
                <span>Export</span>
            </a>
        </template>

        <div v-if="showBar" class="filter-row">
            <Link
                v-for="chip in activeFilters"
                :key="chip.key"
                class="f-chip active"
                :href="chip.href"
                :title="`Remove this filter: ${chip.label}`"
            >
                {{ chip.label }} &times;
            </Link>

            <Link
                v-if="activeFilters.length > 1"
                class="f-chip"
                :href="clearAllUrl"
                title="Show the whole log again"
            >
                Clear all filters
            </Link>

            <span v-if="showPager" class="f-chip">
                Page {{ pagination.page }} of {{ pagination.pages }} &middot;
                {{ pagination.total }} entries
            </span>

            <Link
                v-if="showPager && pagination.previous_url"
                class="f-chip"
                :href="pagination.previous_url"
                title="The previous page of entries"
            >
                Previous
            </Link>

            <Link
                v-if="showPager && pagination.next_url"
                class="f-chip"
                :href="pagination.next_url"
                title="The next page of entries"
            >
                Next
            </Link>
        </div>

        <EmptyState
            v-if="isFirstUse"
            variant="first-use"
            title="Nothing has been recorded yet"
            body="The log fills as administrative actions are taken: an estate provisioned, a role reassigned, a licence flagged. An empty log means no such action has happened — not that entries were removed, which nobody can do."
        />

        <EmptyState
            v-else-if="isEmpty"
            variant="filtered"
            :title="emptyTitle"
            :body="emptyBody"
            :action-label="emptyAction"
            @action="router.visit(clearAllUrl)"
        />

        <table v-else class="data-table">
            <thead>
                <tr>
                    <th v-for="column in columns" :key="column.key" :aria-sort="ariaSort(column)">
                        <Link :href="sortUrl(column)" :title="sortTitle(column)">
                            {{ column.label }}{{ sortMark(column) }}
                        </Link>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in rows" :key="row.id">
                    <td class="mono">
                        <Link :href="dateUrl(row)" :title="dateTitle(row)">{{ row.timestamp }}</Link>
                    </td>
                    <td>
                        <div class="res-cell">
                            <div class="res-avatar">{{ row.initials }}</div>
                            <Link :href="actorUrl(row)" :title="actorTitle(row)">{{ row.actor }}</Link>
                        </div>
                    </td>
                    <td>
                        <Link
                            class="action-tag"
                            :class="row.tag"
                            :href="categoryUrl(row)"
                            :title="categoryTitle(row)"
                        >
                            {{ row.category }}
                        </Link>
                    </td>
                    <td>{{ row.estate }}</td>
                    <td>{{ row.details }}</td>
                </tr>
            </tbody>
        </table>
    </GeminiConsole>
</template>

<style scoped>
/*
 * The only authored CSS on this screen, and every rule below takes a browser
 * default off rather than putting a style on.
 *
 * The board draws its column headers, timestamps, admin names and action tags
 * as plain text and <div>s. Each is a real link here, because a sort header
 * that does not sort and a value you cannot narrow the log by are the defects
 * this rebuild exists to remove. An <a> arrives underlined, in the browser's
 * link colour, and inline where the board's box is a block — three differences
 * that would move the pixels away from the board. Nothing here sets a colour,
 * size, weight, radius or spacing of its own: every one of those still comes
 * from the board's own stylesheet.
 */
.data-table a {
    color: inherit;
    text-decoration: none;
}

.filter-row a {
    text-decoration: none;
}

/* The board's boxes in these three places are block-level: the text of a th,
 * the text of a .mono cell, and the .action-tag <div>. An inline <a> would
 * size and sit differently inside the same cell. */
.data-table thead th a,
.data-table td.mono a,
.data-table a.action-tag {
    display: block;
}

/* Export is a <div> on the board and a real <button> here, so the browser's
 * font, colour and native button appearance come off. Its border, background,
 * height, padding, radius and layout are all the board's own
 * .btn-outline-sm, untouched. */
.btn-outline-sm {
    appearance: none;
    font: inherit;
    color: inherit;
    cursor: pointer;
}

/* Inert, and it says so on hover. No opacity change: dimming it would be a
 * pixel the board does not draw. */
.btn-outline-sm[disabled] {
    cursor: not-allowed;
}
</style>
