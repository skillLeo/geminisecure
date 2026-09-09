<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'

/**
 * Client Directory — board screen super-admin-04.
 *
 * DOM and class names are the board's. Where the board draws a control as a
 * <div> — the filter chips, the column headings, the "Open" link — this renders
 * a real <a> that changes the URL, because the query string is where the
 * directory's state actually lives: a filtered, sorted, paged view is something
 * a person can bookmark and send to a colleague.
 *
 * Nothing is filtered, sorted or paged here. All of it happens in SQL, so a
 * viewer scoped to assigned sites cannot page past their scope, and the count
 * in the pager is the real one rather than the size of one page.
 */
defineProps({
    clients: { type: Array, required: true },
    /** Each chip's toggle URL and whether it is currently on. */
    filters: { type: Object, required: true },
    /** Each sortable heading's URL and aria-sort. */
    columns: { type: Object, required: true },
    pages: { type: Array, required: true },
    total: { type: Number, required: true },
    isFiltered: { type: Boolean, required: true },
    search: { type: String, default: '' },
})

/**
 * The whole row opens the client, not only the "Open" cell.
 *
 * The link stays because it is the keyboard and screen-reader route in; this
 * only adds the larger mouse target a table row is expected to be.
 */
const open = (client) => router.visit(client.href)
</script>

<template>
    <Head title="Clients" />

    <GeminiConsole
        title="Clients"
        board="super-admin-01-login-dashboard-and-activity"
        search-route="/clients"
        :search-value="search"
    >
        <div class="filter-row">
            <Link :href="filters.all.href" class="f-chip" :class="{ active: filters.all.active }">All clients</Link>
            <Link :href="filters.active.href" class="f-chip" :class="{ active: filters.active.active }">Active</Link>
            <!-- prettier-ignore -->
            <Link :href="filters.onboarding.href" class="f-chip" :class="{ active: filters.onboarding.active }">Onboarding</Link>
            <!-- prettier-ignore -->
            <Link :href="filters['at-risk'].href" class="f-chip" :class="{ active: filters['at-risk'].active }">At risk</Link>
        </div>

        <EmptyState
            v-if="total === 0 && !isFiltered"
            variant="first-use"
            title="No clients yet"
            body="An estate becomes a client when it is provisioned its own database and put on a plan. It appears here from that moment, with its units, tier and deployed guards."
        />

        <EmptyState
            v-else-if="total === 0"
            variant="filtered"
            title="No clients match those filters"
            body="Every other client is still here. Clearing the search and the status chips brings the full directory back."
            action-label="Clear filters"
            @action="router.visit('/clients')"
        />

        <table v-else class="data-table">
            <thead>
                <tr>
                    <th :aria-sort="columns.name.aria"><Link :href="columns.name.href">Client</Link></th>
                    <th :aria-sort="columns.units.aria"><Link :href="columns.units.href">Units</Link></th>
                    <th :aria-sort="columns.tier.aria"><Link :href="columns.tier.href">Tier</Link></th>
                    <th :aria-sort="columns.guards.aria"><Link :href="columns.guards.href">Guards</Link></th>
                    <th :aria-sort="columns.mrr.aria"><Link :href="columns.mrr.href">MRR</Link></th>
                    <th :aria-sort="columns.status.aria"><Link :href="columns.status.href">Status</Link></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="client in clients" :key="client.id" @click="open(client)">
                    <td>
                        <div class="client-cell">
                            <div class="client-avatar">{{ client.initials }}</div>
                            <div>
                                <div class="client-name">{{ client.name }}</div>
                                <div class="client-sub">{{ client.sub }}</div>
                            </div>
                        </div>
                    </td>
                    <td>{{ client.units }}</td>
                    <td>
                        <div v-if="client.tierKey" class="tier-badge" :class="client.tierKey">{{ client.tierLabel }}</div>
                        <template v-else>—</template>
                    </td>
                    <td>{{ client.guards }}</td>
                    <td class="num-cell">{{ client.mrr }}</td>
                    <td>
                        <div class="status-badge" :class="client.statusBadge">{{ client.statusLabel }}</div>
                    </td>
                    <td>
                        <Link :href="client.href" class="text-link-sm" @click.stop>Open</Link>
                    </td>
                </tr>
            </tbody>
        </table>

        <!--
          Drawn only when there is more than one page. The board has no pager,
          and a pager over a single page is a control that cannot do anything.
        -->
        <nav v-if="pages.length" class="filter-row" aria-label="Directory pages">
            <Link
                v-for="page in pages"
                :key="page.label"
                :href="page.href"
                class="f-chip"
                :class="{ active: page.active }"
                :aria-current="page.active ? 'page' : undefined"
            >
                {{ page.label }}
            </Link>
        </nav>
    </GeminiConsole>
</template>

<style scoped>
/*
 * The only authored CSS on this screen, and every line of it removes a browser
 * default rather than adding a style.
 *
 * The board draws the chips, the column headings and the "Open" cell as <div>s.
 * All three are real anchors here, and an anchor arrives underlined — and, where
 * the board sets no colour of its own, in the browser's link blue. Those two
 * defaults would change the pixels, so they are taken back off. Nothing sets
 * colour on the chips or on .text-link-sm: the board already does, and a rule
 * here would out-specify it.
 */
.filter-row a {
    text-decoration: none;
}

.data-table tbody a {
    text-decoration: none;
}

.data-table thead th a {
    text-decoration: none;
    color: inherit;
}
</style>
