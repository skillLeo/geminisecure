<script setup>
import { computed } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'

/**
 * Guard directory — board screen super-admin-18.
 *
 * DOM and class names are the board's. The board draws every control on this
 * screen as a <div>: the sub-navigation, the four KPI cards, the client chips,
 * the column headings and the row action. Each is a real control here, and
 * because a filter, a sort and a row that opens a profile all change the URL,
 * each is a link rather than a button — the whole state of this table is in
 * the query string, and nothing is filtered in the browser.
 *
 * The four screens behind Roster, Standing orders, Gate activity and Incidents
 * are not built. Those items are disabled and say so on hover rather than
 * looking live and swallowing the click.
 */
const props = defineProps({
    guards: { type: Array, required: true },
    pagination: { type: Object, required: true },
    summary: { type: Object, required: true },
    clients: { type: Array, required: true },
    filters: { type: Object, required: true },
    scoped: { type: Boolean, default: false },
    /** Whether the viewer may open board 22, the add-guard form. */
    canCreate: { type: Boolean, default: false },
})

/**
 * Every link on this screen is this URL with one thing changed.
 *
 * Built from the server's own normalised filters rather than from
 * window.location, so a value the server rejected — a made-up sort key, a
 * status that does not exist — cannot survive by riding along in the next
 * link the page draws.
 */
const href = (changes) => {
    const merged = { ...props.filters, page: props.pagination.current, ...changes }

    // A direction with nothing to sort, and page 1, are noise rather than
    // state: they say nothing the bare URL does not already say.
    if (!merged.sort) {
        merged.dir = null
    }

    if (Number(merged.page) <= 1) {
        merged.page = null
    }

    const query = new URLSearchParams()

    for (const [key, value] of Object.entries(merged)) {
        if (value !== null && value !== undefined && value !== '') {
            query.set(key, String(value))
        }
    }

    const string = query.toString()

    return string ? `/guards?${string}` : '/guards'
}

const subnav = [
    { label: 'Directory', href: '/guards', active: true },
    { label: 'Roster', reason: 'Available when the shift roster ships' },
    { label: 'Standing orders', reason: 'Available when standing orders ship' },
    { label: 'Gate activity', reason: 'Available when the Guard App ships' },
    { label: 'Compliance', href: '/guards/compliance' },
    { label: 'Incidents', reason: 'Available when incident reporting ships' },
]

const filtered = computed(
    () => Boolean(props.filters.q || props.filters.client || props.filters.status || props.filters.employment || props.filters.licence),
)

/**
 * The KPI cards are the status filter.
 *
 * The board draws no chip for status and no selected state for a card, so this
 * is a drill-down rather than a toggle: a card narrows the list to what it
 * counts, and Total guards puts it back. The counts themselves are never
 * narrowed — a set that shrank as you filtered would leave nothing to return
 * to — so the card that is currently applied is marked with aria-current and
 * says so on hover, which is as much state as the board's classes can carry.
 */
const kpis = computed(() => [
    {
        key: 'total',
        icon: 'guards',
        stroke: 1.7,
        value: props.summary.total,
        label: 'Total guards',
        href: href({ status: null, licence: null, page: null }),
        current: !props.filters.status && !props.filters.licence,
        title: 'Show every guard on the roster',
    },
    {
        key: 'active',
        icon: 'check',
        stroke: 3,
        value: props.summary.active,
        label: 'Active on post',
        href: href({ status: 'active', licence: null, page: null }),
        current: props.filters.status === 'active',
        title: 'Show only guards active on post',
    },
    {
        key: 'leave',
        icon: 'clock',
        stroke: 1.7,
        value: props.summary.on_leave,
        label: 'On leave',
        href: href({ status: 'on_leave', licence: null, page: null }),
        current: props.filters.status === 'on_leave',
        title: 'Show only guards on leave',
    },
    {
        key: 'expired',
        icon: 'alert',
        stroke: 1.7,
        warn: true,
        value: props.summary.licence_expired,
        label: 'Licence expired',
        href: href({ licence: 'expired', status: null, page: null }),
        current: props.filters.licence === 'expired',
        title: 'Show only guards whose PSRA licence has lapsed',
    },
])

const columns = [
    { key: 'name', label: 'Guard' },
    { key: 'psra', label: 'PSRA number' },
    { key: 'client', label: 'Client' },
    { key: 'post', label: 'Post' },
    { key: 'status', label: 'Status' },
]

/** First click sorts ascending; clicking the column you are on reverses it. */
const sortHref = (key) =>
    href({
        sort: key,
        dir: props.filters.sort === key && props.filters.dir === 'asc' ? 'desc' : 'asc',
        page: null,
    })

const ariaSort = (key) => {
    if (props.filters.sort !== key) {
        return 'none'
    }

    return props.filters.dir === 'desc' ? 'descending' : 'ascending'
}

const sortTitle = (column) =>
    props.filters.sort === column.key && props.filters.dir === 'asc'
        ? `Sort by ${column.label.toLowerCase()}, Z to A`
        : `Sort by ${column.label.toLowerCase()}, A to Z`

/** Clicking a row's employment type filters to it; clicking it again clears it. */
const employmentHref = (guard) =>
    href({
        employment: props.filters.employment === guard.employment_key ? null : guard.employment_key,
        page: null,
    })

/**
 * The whole row opens the profile, not only the View link.
 *
 * The links inside the row have their own destinations, so a click that landed
 * on one is left alone rather than being hijacked by the row underneath it.
 */
const open = (event, guard) => {
    if (event.target.closest('a')) {
        return
    }

    router.get(`/guards/${guard.id}`)
}

const clearFilters = () => router.get('/guards')
</script>

<template>
    <Head title="Guard workforce" />

    <GeminiConsole
        title="Guard workforce"
        search-route="/guards"
        :search-value="filters.q ?? ''"
        search-placeholder="Search guards…"
    >
        <!--
          The board's own topbar button, and a real link since board 22 was
          built: `guards/new` is the form, behind Guard workforce create on both
          halves. A role that may only read the roster gets the inert twin and
          the reason, rather than a link that answers 403.
        -->
        <template #actions>
            <Link v-if="canCreate" href="/guards/new" class="btn-primary-sm">
                <BoardIcon name="plus" :stroke="2" />
                <span>Add guard</span>
            </Link>
            <button
                v-else
                type="button"
                class="btn-primary-sm"
                disabled
                title="Adding a guard creates an employee, so it needs Guard workforce create access. You are able to read this roster."
            >
                <BoardIcon name="plus" :stroke="2" />
                <span>Add guard</span>
            </button>
        </template>

        <div class="subnav">
            <template v-for="item in subnav" :key="item.label">
                <Link v-if="item.href" :href="item.href" class="subnav-item" :class="{ active: item.active }">
                    {{ item.label }}
                </Link>
                <button v-else type="button" class="subnav-item" disabled :title="item.reason">
                    {{ item.label }}
                </button>
            </template>
        </div>

        <div class="kpi-row">
            <Link
                v-for="kpi in kpis"
                :key="kpi.key"
                :href="kpi.href"
                :title="kpi.title"
                :aria-current="kpi.current ? 'true' : undefined"
                class="kpi-card"
                :class="{ warn: kpi.warn }"
            >
                <div class="k-top">
                    <div class="kpi-icon">
                        <BoardIcon :name="kpi.icon" :stroke="kpi.stroke" />
                    </div>
                </div>
                <div class="k-val">{{ kpi.value }}</div>
                <div class="k-lbl">{{ kpi.label }}</div>
            </Link>
        </div>

        <div class="filter-row">
            <Link
                :href="href({ client: null, page: null })"
                class="f-chip"
                :class="{ active: !filters.client }"
                :title="scoped ? 'Your role sees guards at your assigned sites only' : 'Show guards at every client'"
            >
                All clients
            </Link>
            <Link
                v-for="client in clients"
                :key="client.id"
                :href="href({ client: client.id, page: null })"
                class="f-chip"
                :class="{ active: filters.client === client.id }"
            >
                {{ client.name }}
            </Link>
        </div>

        <EmptyState
            v-if="guards.length === 0 && !filtered"
            variant="first-use"
            title="No guards on the roster yet"
            body="Guards are Gemini Security employees, added centrally and then posted to a client. Once added they appear here with their PSRA licence and current post."
        />

        <EmptyState
            v-else-if="guards.length === 0"
            variant="filtered"
            title="No guards match these filters"
            body="Every other guard is still on the roster. Clearing the search, the client and the status filter brings them back."
            action-label="Clear filters"
            @action="clearFilters"
        />

        <table v-else class="data-table">
            <thead>
                <tr>
                    <th v-for="column in columns" :key="column.key" :aria-sort="ariaSort(column.key)">
                        <Link :href="sortHref(column.key)" :title="sortTitle(column)">{{ column.label }}</Link>
                    </th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="guard in guards" :key="guard.id" @click="open($event, guard)">
                    <td>
                        <div class="res-cell">
                            <div class="res-avatar">{{ guard.initials }}</div>
                            <div>
                                <div class="res-name">{{ guard.name }}</div>
                                <Link
                                    :href="employmentHref(guard)"
                                    class="res-sub"
                                    :title="`Show only ${guard.employment_type.toLowerCase()} guards`"
                                >
                                    {{ guard.employment_type }}
                                </Link>
                            </div>
                        </div>
                    </td>
                    <td>{{ guard.psra_number }}</td>
                    <td>{{ guard.estate }}</td>
                    <td>{{ guard.post }}</td>
                    <td>
                        <div class="status-badge" :class="guard.status_badge">{{ guard.status_label }}</div>
                    </td>
                    <td>
                        <Link :href="`/guards/${guard.id}`" class="text-link-sm">{{ guard.action }}</Link>
                    </td>
                </tr>
            </tbody>
        </table>

        <!--
          Paging appears only when there is a second page.

          The board draws none, because the roster it draws fits on one. Drawing
          a dead pager under a six-row table would be inventing a control the
          design does not have; drawing none under a six-hundred-row one would
          be losing five hundred and seventy-five guards.
        -->
        <div v-if="pagination.last > 1" class="filter-row">
            <Link v-if="pagination.current > 1" :href="href({ page: pagination.current - 1 })" class="btn-outline-sm">
                <span>Previous</span>
            </Link>
            <span class="text-link-sm">
                Showing {{ pagination.from }}–{{ pagination.to }} of {{ pagination.total }}
            </span>
            <Link
                v-if="pagination.current < pagination.last"
                :href="href({ page: pagination.current + 1 })"
                class="btn-outline-sm"
            >
                <span>Next</span>
            </Link>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/*
 * The only authored CSS on this screen, and it authors nothing.
 *
 * The board draws its controls as <div>s. Real <a>s and <button>s arrive with
 * styling of the browser's own — a link colour and an underline, an inline box
 * where the board had a block, a button's border, face and font — and these
 * rules take exactly those back off again. Every visible property still comes
 * from the board's own .subnav-item, .kpi-card, .f-chip and .res-sub rules.
 *
 * Specificity is why these are written the way they are. A scoped element
 * selector carries an attribute of its own and so outranks a single class:
 * `a { color: inherit }` would quietly beat .f-chip and .text-link-sm, and
 * `button { background: none }` would beat .btn-primary-sm's navy. So colour
 * and background are removed only where the board declares neither and the
 * browser's own would otherwise show — everywhere else the board's rule
 * already wins against the browser and is left alone.
 */
a {
    text-decoration: none;
}

thead a {
    color: inherit;
    display: block;
}

.res-sub {
    display: block;
}

button {
    font-family: inherit;
}

button.subnav-item {
    border: 0;
    background: none;
}

button.btn-primary-sm {
    border: 0;
}
</style>
