<script setup>
import { computed } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { duesTabs } from './tabs'

/**
 * Payment plans register — board 5's "Payment plans" tab (12 §2, item 18).
 *
 * NOT DRAWN ON ANY BOARD: board 5 names the tab. The register borrows board 5's
 * own shapes — the subnav, the data table, the ageing badges — so it reads as a
 * tab of one module.
 *
 * A REGISTER, NOT A SECOND PLAN SCREEN. A plan is drawn up, agreed and activated
 * on its household's own screen, board 7, and every row here opens it. Nothing
 * on this page writes.
 *
 * PROGRESS IS COUNTED FROM THE INSTALMENTS. "3 of 6 met · 1 missed" is read off
 * the schedule each time, so a missed month cannot hide behind a status nobody
 * updated.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    rows: { type: Array, required: true },
    counts: { type: Object, required: true },
    filter: { type: String, required: true },
})

useWireframe('community-admin-02-arrears-ledger-payment-plan-and-dunning')

const page = usePage()

const state = useScreenState({
    rows: () => props.rows.length,
    filtered: () => props.filter !== '',
})

const root = computed(() => {
    const cut = page.url.indexOf('/finance')

    return cut === -1 ? '' : page.url.slice(0, cut)
})

const financePath = (suffix) => `${root.value}/finance${suffix}`

const subnav = computed(() => duesTabs(financePath, 'plans'))

const chips = computed(() => [
    { key: '', label: `All (${Object.values(props.counts).reduce((a, b) => a + b, 0)})` },
    { key: 'active', label: `Active (${props.counts.active ?? 0})` },
    { key: 'draft', label: `Draft (${props.counts.draft ?? 0})` },
    { key: 'defaulted', label: `Defaulted (${props.counts.defaulted ?? 0})` },
    { key: 'completed', label: `Completed (${props.counts.completed ?? 0})` },
    { key: 'cancelled', label: `Cancelled (${props.counts.cancelled ?? 0})` },
])

const chipHref = (key) => (key === '' ? financePath('/payment-plans') : financePath(`/payment-plans?status=${key}`))

/* Board 5's own ageing badge colours, read as standing rather than as age. */
const badgeStyle = (status) =>
    ({
        active: 'background:var(--success-100);color:var(--success-700);',
        draft: 'background:var(--amber-100);color:var(--amber-700);',
        defaulted: 'background:var(--red-100);color:var(--red-700);',
    })[status] ?? 'background:var(--navy-100);color:var(--slate-600);'
</script>

<template>
    <Head title="Payment plans" />

    <EstateConsole title="Payment plans" :estate-name="estate.name" active="dues_ledger">
        <div class="subnav">
            <template v-for="item in subnav" :key="item.label">
                <div v-if="item.active" class="subnav-item active" aria-current="page">{{ item.label }}</div>
                <Link v-else :href="item.href" class="subnav-item">{{ item.label }}</Link>
            </template>
        </div>

        <div class="pp-chips">
            <Link
                v-for="chip in chips"
                :key="chip.key"
                :href="chipHref(chip.key)"
                class="pp-chip"
                :class="{ on: filter === chip.key }"
            >
                {{ chip.label }}
            </Link>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="6" :columns="8" />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The plan register could not be read"
            body="Nothing on this page writes, so no plan has changed. Reading it again is safe."
            action-label="Try again"
            @action="router.reload()"
        />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Payment plans are not yours to see"
            body="A plan says what a household owes and how it is paying it off, which is Dues & ledger's, and your role does not hold it."
        />

        <EmptyState
            v-else-if="state.isEmptyFiltered.value"
            variant="filtered"
            title="No plan in this state"
            body="Every other plan is still on the register. Show them all to see the rest."
            action-label="Show every plan"
            @action="router.get(chipHref(''))"
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="No payment plan has been drawn up"
            body="A plan is drawn up from a household's ledger, under “Place on payment plan”, and appears here from its first draft."
        />

        <table v-else class="data-table">
            <thead>
                <tr>
                    <th>Plan</th>
                    <th>Unit</th>
                    <th>Household</th>
                    <th>Total</th>
                    <th>Progress</th>
                    <th>Next instalment</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in rows" :key="row.id">
                    <td class="pp-ref">{{ row.reference }}</td>
                    <td>
                        <Link :href="financePath(`/units/${row.unit_id}`)" class="text-link-sm">{{ row.unit }}</Link>
                    </td>
                    <td>{{ row.household }}</td>
                    <td class="bal-amt">{{ row.total }}</td>
                    <td :class="{ 'pp-missed': row.missed > 0 }">{{ row.progress }}</td>
                    <td>{{ row.next }}</td>
                    <td>
                        <div class="age-badge" :style="badgeStyle(row.status)">{{ row.status_label }}</div>
                    </td>
                    <td>
                        <Link
                            :href="financePath(`/units/${row.unit_id}/payment-plan`)"
                            class="text-link-sm"
                            title="Open this household's plan — where it is agreed, activated and followed."
                        >
                            Open
                        </Link>
                    </td>
                </tr>
            </tbody>
        </table>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal for the sheet's tabs and links, then AUTHORED: the status
 * chips and the missed-instalment emphasis, kept to the tokens the boards define.
 */
a {
    text-decoration: none;
}

.pp-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 14px;
}

.pp-chip {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--navy-700);
    background: var(--white);
    border: 1px solid var(--navy-200);
    border-radius: 20px;
    padding: 5px 12px;
}

.pp-chip.on {
    background: var(--navy-600);
    border-color: var(--navy-600);
    color: var(--white);
}

.pp-ref {
    font-weight: 700;
    color: var(--navy-800);
    font-variant-numeric: tabular-nums;
}

.pp-missed {
    color: var(--red-700);
    font-weight: 600;
}
</style>
