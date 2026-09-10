<script setup>
import { computed } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * Statutory filings — board screen community-admin-16.
 *
 * THE BOARD PRINTS NO FIGURES AND EVERY ROW IS A MOVEMENT OF MONEY. An S01
 * clears the four withholdings a pay run took off four people; filing it is
 * Dr 2100 / Cr 1000 for exactly what that run withheld, and `EstatePayrollTest`
 * asserts the tie component by component rather than in aggregate. Four amounts
 * that sum correctly while two of them are individually wrong is a
 * reconciliation that passes and a filing that is false.
 *
 * SO "FILE" IS AN APPROVE-LEVEL ACT, not an update. It remits to the revenue
 * authority and cannot be unremitted, which is the same shape as releasing a pay
 * run — D-013's split, applied to a different creditor. A role without it sees
 * the control inert with the reason, never missing.
 *
 * A RETURN WITH NOTHING TO REMIT POSTS NOTHING. The GCT return and the annual
 * P24 carry no deduction breakdown, so filing them records the date and writes
 * no journal: a zero-value entry is noise in a ledger somebody reads in five
 * years.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    rows: { type: Array, required: true },
    tabs: { type: Array, required: true },
    canFile: { type: Boolean, required: true },
    reasons: { type: Object, required: true },
})

useWireframe('community-admin-04-payroll-runs-exceptions-and-filings')

const page = usePage()

const state = useScreenState({
    rows: () => props.rows.length,
})

const base = computed(() => page.url.split('/payroll')[0])

const badgeClass = (row) =>
    ({
        filed: 'approved',
        due_soon: 'pending',
    })[row.status] ?? 'draft'

const file = (row) => {
    router.post(`${base.value}/payroll/filings/${row.id}/file`, {}, { preserveScroll: true })
}
</script>

<template>
    <Head title="Statutory filings" />

    <EstateConsole title="Statutory filings" :estate-name="estate.name" active="payroll">
        <template #actions>
            <button type="button" class="btn-outline-sm" disabled :title="reasons.calendar">
                <svg viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7" />
                    <path d="M12 7v5l3.5 2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                </svg>
                <span>Compliance calendar</span>
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

        <SkeletonRows v-if="state.isLoading.value" :rows="5" :columns="3" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Payroll is not part of your role’s access"
            body="A filing register says what the estate still owes the revenue authority and what it has already remitted, so it opens only to a role that holds Payroll."
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="No returns are on record"
            body="An estate that has run no payroll owes no statutory deductions. Returns appear here once a run has withheld something to remit."
        />

        <div v-else class="filing-card">
            <div v-for="row in rows" :key="row.id" class="filing-row">
                <div class="filing-icon" :class="{ warn: row.warn }">
                    <svg v-if="row.warn" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7" />
                        <path d="M12 7.5v5M12 16h.01" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" />
                    </svg>
                    <svg v-else-if="row.code === 'P24'" viewBox="0 0 24 24" fill="none">
                        <rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.7" />
                        <path d="M3 10h18M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                    </svg>
                    <svg v-else viewBox="0 0 24 24" fill="none">
                        <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" />
                        <path d="M14 3v5h5" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" />
                    </svg>
                </div>

                <div class="filing-txt">
                    <div class="ft1">{{ row.title }}</div>
                    <div class="ft2">{{ row.subtitle }}</div>
                </div>

                <div class="filing-due">{{ row.due }}</div>

                <div class="status-badge" :class="badgeClass(row)">{{ row.status_label }}</div>

                <!--
                  Authored: the board draws no action on these rows because it
                  draws them as a record. A register a treasurer cannot file
                  from is a register they have to leave to do the thing it is
                  reminding them about, so the outstanding rows carry the act —
                  and a role without `approve` sees why it is inert rather than
                  a control that is simply absent.
                -->
                <button
                    v-if="row.status !== 'filed'"
                    type="button"
                    class="filing-action"
                    :disabled="!canFile"
                    :title="canFile ? `File ${row.code} for ${row.subtitle}` : reasons.file"
                    @click="canFile && file(row)"
                >
                    File
                </button>
            </div>
        </div>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only for the two the board draws as <div>s — its topbar
 * action and its three tabs. `.btn-outline-sm` DECLARES a border and that
 * border IS the variant, so it is left alone: D-045 records a blanket
 * `border: 0` here silently rubbing out a 1.5px outline the board did draw,
 * and surviving several passing measurements afterwards.
 */
button.btn-outline-sm {
    font: inherit;
    cursor: pointer;
}

button.btn-outline-sm[disabled] {
    cursor: not-allowed;
}

a.subnav-item {
    text-decoration: none;
}

/*
 * AUTHORED BELOW THIS LINE. The board's card and rows are its own; the file
 * action is not on it, so it is built from the tokens the board defines rather
 * than from new ones.
 */
.filing-card {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    overflow: hidden;
}

.filing-action {
    height: 30px;
    padding: 0 13px;
    border: 0;
    border-radius: 8px;
    background: var(--navy-600);
    color: var(--white);
    font-family: 'Poppins', sans-serif;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
}

.filing-action[disabled] {
    background: var(--navy-100);
    color: var(--navy-700);
    cursor: not-allowed;
}
</style>
