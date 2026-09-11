<script setup>
import { computed } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * The full activity log — the dashboard panel's "See all" (12 §2, Wave 2).
 *
 * NO BOARD DRAWS THIS SCREEN, and it is not a fidelity target. The dashboard
 * draws a five-row panel with a "See all" beside it; this is what that link
 * leads to, built in the dashboard's own board CSS so the two read as one
 * console rather than as a page that came from somewhere else.
 *
 * EVERY ROW IS READ FROM THE TABLE THAT OWNS IT. A payment, an incident, a
 * verification, a closed ticket, a booking and a dunning notice — merged by
 * their own timestamps and never copied into a log table of their own. A
 * category with nothing to show is simply absent; there is no invented row.
 *
 * PAGED, NOT INFINITE. The link on each end is a real GET with a page number,
 * so a reader can bookmark where they were and the back button does what it
 * says. The server reads a little deeper than one page of each table before
 * merging, which is what keeps a log fast on the estate running longest.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    rows: { type: Array, required: true },
    page: { type: Number, required: true },
    hasMore: { type: Boolean, required: true },
    dashboardHref: { type: String, required: true },
    path: { type: String, required: true },
})

useWireframe('community-admin-01-login-dashboard-structure-and-residents')

/*
 * Five of the six. `empty-filtered` cannot arise from the data — this log has
 * no filter that could have excluded anything — and page 2 of an estate with
 * one page is not an empty filter either, it is a page past the end, which the
 * pager below simply does not offer.
 */
const state = useScreenState({
    rows: () => props.rows.length,
})

const retry = () => router.reload()

const pageHref = (n) => `${props.path}?page=${n}`

const range = computed(() => {
    const first = (props.page - 1) * 25 + 1

    return props.rows.length === 0 ? '' : `${first}–${first + props.rows.length - 1}`
})
</script>

<template>
    <Head title="Activity" />

    <EstateConsole title="Activity" :estate-name="estate.name" active="dashboard">
        <template #lead>
            <Link
                :href="dashboardHref"
                style="width:34px;height:34px;border-radius:50%;background:var(--navy-100);display:flex;align-items:center;justify-content:center;flex:0 0 auto;"
                title="Back to the dashboard"
                aria-label="Back to the dashboard"
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

        <SkeletonRows v-if="state.isLoading.value" :rows="10" :columns="3" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="This estate's activity is not part of your access"
            body="The log merges what the modules you hold already show. Yours reach none of them — a committee officer or the estate administrator can grant a module from the role access matrix."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The activity log could not be read"
            body="Nothing on this screen writes anything, so nothing has changed and re-running the read is safe."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isEmpty.value || state.isEmptyFiltered.value"
            variant="first-use"
            title="Nothing has happened in this estate yet"
            body="This log holds one row for every payment taken, incident reported, resident verified, ticket closed, amenity booked and reminder sent. None of those has happened, so there is nothing to read — and no row here is ever invented to fill the space."
        />

        <template v-else>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>What happened</th>
                        <th>Detail</th>
                        <th>When</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, index) in rows" :key="index">
                        <td>
                            <span :class="{ 'act-amber': row.amber }">{{ row.title }}</span>
                        </td>
                        <td>{{ row.detail }}</td>
                        <td :title="row.at">{{ row.when }}</td>
                    </tr>
                </tbody>
            </table>

            <div class="act-pager">
                <Link v-if="page > 1" :href="pageHref(page - 1)" class="act-page" title="The page before this one">
                    Newer
                </Link>
                <span v-else class="act-page act-page--off">Newer</span>

                <span class="act-range">{{ range }}</span>

                <Link v-if="hasMore" :href="pageHref(page + 1)" class="act-page" title="Further back">Older</Link>
                <span v-else class="act-page act-page--off">Older</span>
            </div>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * AUTHORED. No board draws this screen — see the head of the script. The table
 * is the boards' own `.data-table`; only the pager and the amber title need
 * rules, and both are kept to tokens the boards define.
 */
.act-amber {
    color: var(--amber-700);
    font-weight: 600;
}

.act-pager {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-top: 16px;
}

.act-page {
    font-size: 12px;
    font-weight: 700;
    color: var(--amber-600);
    text-decoration: none;
}

.act-page--off {
    color: var(--slate-400);
}

.act-range {
    font-size: 11px;
    color: var(--slate-500);
}
</style>
