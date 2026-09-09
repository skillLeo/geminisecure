<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'

/**
 * Payslip detail — board screen super-admin-29.
 *
 * The board's page body is one table and nothing else, so that is what this
 * renders. Two facts the board has no room for ride on the cells that own them
 * rather than on rows of their own:
 *
 *   why PAYE is zero        on the PAYE cell
 *   why a row is flagged    on the row
 *
 * Both belong on this screen — a bare 0.00 reads as a defect to the person
 * holding the payslip — and a row apiece would cost a second line per payslip
 * that the approved design does not have.
 *
 * The red row background is the board's own inline style, copied verbatim,
 * because the board's stylesheet defines no class for it.
 *
 * There is no approve control here. The board puts "Approve & disburse" in the
 * topbar, and the topbar belongs to the console shell, not to this page. It
 * would be disabled if it were here: D-021 holds the 2026-04 rates as draft
 * and blocks approval, and /payroll carries that reason on its banner.
 */
const props = defineProps({
    run: { type: Object, required: true },
    payslips: { type: Array, required: true },
    filters: { type: Object, required: true },
    /**
     * Whether this role may open a guard's record.
     *
     * The roster is a separate module with a separate permission, and a role
     * can hold payroll without it. Where it does not, the employee cell is the
     * plain cell the board draws rather than a link that lands on a 403.
     */
    canOpenGuards: { type: Boolean, required: true },
})

const columns = [
    { key: 'employee', label: 'Employee', hint: 'employee' },
    { key: 'client', label: 'Client', hint: 'client' },
    { key: 'gross', label: 'Gross', hint: 'gross' },
    { key: 'nis', label: 'NIS', hint: 'NIS' },
    { key: 'nht', label: 'NHT', hint: 'NHT' },
    { key: 'education_tax', label: 'Ed. Tax', hint: 'education tax' },
    { key: 'paye', label: 'PAYE', hint: 'PAYE' },
    { key: 'net', label: 'Net', hint: 'net' },
]

const sortBy = (column) => {
    const direction = props.filters.sort === column && props.filters.dir === 'asc' ? 'desc' : 'asc'

    router.get(
        `/payroll/${props.run.id}`,
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

const clearSearch = () => router.get(`/payroll/${props.run.id}`, {}, { preserveScroll: true })
</script>

<template>
    <Head :title="`${run.period} pay run`" />

    <!--
      No search field. The board draws none in this topbar — it draws a back
      chevron, the title, and "Approve & disburse" — so none is rendered. The
      table's column sort is still live and is how this screen is narrowed.
    -->
    <GeminiConsole :title="`${run.period} pay run`">
        <template #lead>
            <Link href="/payroll" class="topbar-back" title="Back to payroll runs" aria-label="Back to payroll runs">
                <svg viewBox="0 0 24 24" fill="none">
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
            <!--
              D-021: the 2026-04 statutory rates are seeded as DRAFT and
              approval is deliberately blocked pending a client ruling.
              Approving a run against draft rates would post money computed
              from figures nobody has signed off.
            -->
            <button
                type="button"
                class="btn-approve-sm"
                disabled
                title="Blocked: the 2026-04 statutory rates are still a draft and have not been approved (D-021)"
            >
                <svg viewBox="0 0 24 24" fill="none">
                    <polyline
                        points="20 6 9 17 4 12"
                        stroke="currentColor"
                        stroke-width="3"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                </svg>
                <span>Approve &amp; disburse</span>
            </button>
        </template>

        <EmptyState
            v-if="payslips.length === 0 && filters.q"
            variant="filtered"
            :title="`Nobody on this run matches “${filters.q}”`"
            body="Every other payslip is still on the run. Clear the search to see them all."
            action-label="Clear search"
            @action="clearSearch"
        />

        <EmptyState
            v-else-if="payslips.length === 0"
            variant="first-use"
            title="This run has no payslips"
            body="A run is calculated from the guards on the roster for its period. Until it is calculated there is nothing here to review."
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
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="slip in payslips"
                    :key="slip.id"
                    :style="slip.exception ? 'background:var(--red-100);' : undefined"
                    :title="slip.exception_reason || undefined"
                >
                    <td>
                        <component
                            :is="canOpenGuards ? Link : 'div'"
                            class="res-cell"
                            :href="canOpenGuards ? `/guards/${slip.guard_id}` : undefined"
                        >
                            <div class="res-avatar">{{ slip.initials }}</div>
                            <div class="res-name">{{ slip.employee }}</div>
                        </component>
                    </td>
                    <td>{{ slip.estate }}</td>
                    <td class="num-cell">{{ slip.gross }}</td>
                    <td class="num-cell">{{ slip.nis }}</td>
                    <td class="num-cell">{{ slip.nht }}</td>
                    <td class="num-cell">{{ slip.education_tax }}</td>
                    <td class="num-cell" :title="slip.paye_note || undefined">{{ slip.paye }}</td>
                    <td class="num-cell">{{ slip.net }}</td>
                </tr>
            </tbody>
        </table>
    </GeminiConsole>
</template>

<style scoped>
/*
 * The only authored CSS here, and it takes a browser default back off rather
 * than adding a style of its own.
 *
 * The board draws the employee cell as a <div>. It is a link to that guard's
 * record here, so the UA's link colour and underline would otherwise show
 * through and change the pixels. .res-cell and .res-name state everything
 * else — layout, size, weight — so nothing below restates any of it.
 */
a.res-cell {
    color: inherit;
    text-decoration: none;
}

/*
 * The back chevron. The board draws it as an inline-styled <div> because its
 * stylesheet has no class for it; those exact declarations are reproduced
 * here on a real <a> instead, so the control can be clicked, focused and
 * opened in a new tab.
 */
.topbar-back {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: var(--navy-100);
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
}

.topbar-back svg {
    width: 16px;
    height: 16px;
    color: var(--navy-700);
}

/* The board's button is a <div>; as a real <button> it arrives with a border,
 * a system font and buttonface grey. .btn-approve-sm supplies the rest. */
button.btn-approve-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.btn-approve-sm[disabled] {
    cursor: not-allowed;
}
</style>
