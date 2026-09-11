<script setup>
import { computed } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * The receipt register — board 5's Receipts tab, built to the ruling.
 *
 * NOT DRAWN ON ANY BOARD: board 5 names the tab and draws the arrears list
 * under it. The register borrows board 5's own shapes — the KPI row, the chip
 * row's sheet, the data table — so it reads as the fourth tab of one module
 * rather than as a screen of its own.
 *
 * THE NUMBER IS THE POINT. `{PREFIX}-R-{00001}`, sequential per estate, never
 * reused, allocated when a payment posts and never before. The sequence keeps
 * a high-water mark, and every number up to it that no receipt carries is
 * drawn here as a GAP, in its place in the order — so a missing receipt is a
 * row somebody has to look at rather than a number nobody notices is absent.
 *
 * NOT ONE FIGURE HERE IS A HOUSEHOLD'S BALANCE. A receipt is an amount the
 * estate took, which is a fact about the receipt; what a household still owes
 * is the arrears list's and the unit ledger's.
 *
 * TWO DATES PER RECEIPT, AND THE GAP BETWEEN THEM IS SHOWN. Received is the
 * day the money arrived and entered is the day it was keyed; cash taken on
 * Friday and keyed on Monday is late paperwork, not a late payment, and a
 * treasurer reconciling a week's takings needs to see which it was.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    prefix: { type: String, required: true },
    /** Where this estate's sequence starts — 1, or where an earlier numbering left off. */
    first_no: { type: Number, required: true },
    last_no: { type: Number, required: true },
    kpis: { type: Array, required: true },
    rows: { type: Array, required: true },
    /** Numbers allocated that no receipt carries. */
    gaps: { type: Array, required: true },
    canRecord: { type: Boolean, required: true },
    reasons: { type: Object, required: true },
})

useWireframe('community-admin-02-arrears-ledger-payment-plan-and-dunning')

const page = usePage()

/*
 * Five of the six. There is no filter on the register, so empty-filtered is
 * reachable only when forced, and it says so.
 */
const state = useScreenState({
    rows: () => props.rows.length + props.gaps.length,
})

const root = computed(() => {
    const cut = page.url.indexOf('/finance')

    return cut === -1 ? '' : page.url.slice(0, cut)
})

const financePath = (suffix) => `${root.value}/finance${suffix}`

/**
 * The module's own tabs, as board 5 draws them. Arrears is a link, this is the
 * screen the reader is on, and the two still unbuilt say what they wait on.
 */
const subnav = computed(() => [
    { label: 'Arrears command centre', href: financePath('/arrears') },
    {
        label: 'Charge schedule',
        reason:
            'Not built yet — the charge schedule raises the recurring maintenance fee against every unit on a date, so it needs a run that can be previewed and reversed before it needs a list.',
    },
    { label: 'Payment plans', reason: props.reasons.plan },
    { label: 'Receipts', active: true },
])

/**
 * Receipts and gaps in one order — the sequence's — newest first.
 *
 * A gap is placed by its number, between the receipts on either side of it,
 * because that is where somebody looking for it will look. The receipts are
 * sorted by their own number; a legacy receipt that is not in the format has
 * no number and sorts last, by date.
 */
const numberOf = (receiptNo) => {
    const match = /-R-(\d+)$/.exec(receiptNo)

    return match ? Number(match[1]) : null
}

const sequence = computed(() => {
    const items = [
        ...props.rows.map((row) => ({ kind: 'receipt', number: numberOf(row.receipt_no), row })),
        ...props.gaps.map((gap) => ({ kind: 'gap', number: gap.number, gap })),
    ]

    return items.sort((a, b) => (b.number ?? -1) - (a.number ?? -1))
})

/** The board's own status pills: recorded is green, reversed is the neutral navy. */
const statusStyle = (status) =>
    status === 'recorded'
        ? 'background:var(--green-100);color:var(--green-700);'
        : 'background:var(--navy-100);color:var(--slate-600);'
</script>

<template>
    <Head title="Receipts" />

    <EstateConsole title="Receipts" :estate-name="estate.name" active="dues_ledger">
        <template #actions>
            <!--
              A receipt export leaves the estate as a file naming who paid what,
              and needs the retention rule before it needs a button — the same
              refusal the arrears export gives, for the same reason.
            -->
            <button type="button" class="btn-outline-sm" disabled :title="reasons.export">
                <BoardIcon name="export" :stroke="1.8" />
                <span>Export</span>
            </button>
        </template>

        <div class="subnav">
            <template v-for="item in subnav" :key="item.label">
                <div v-if="item.active" class="subnav-item active" aria-current="page">{{ item.label }}</div>
                <Link v-else-if="item.href" :href="item.href" class="subnav-item">{{ item.label }}</Link>
                <button v-else type="button" class="subnav-item" disabled :title="item.reason">
                    {{ item.label }}
                </button>
            </template>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="6" :columns="8" />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The receipt register could not be read"
            body="No receipt has been issued or altered by this read. The register is built from the payments table and the estate's sequence, both intact; re-running the read is safe."
            action-label="Try again"
            @action="router.reload()"
        />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="The receipt register is not yours to see"
            body="Receipts name who paid what and when, which is Dues & ledger's, and your role does not hold it. A committee officer or the estate administrator can grant it from the role access matrix."
        />

        <template v-else>
            <!--
              Three cards on board 5's KPI sheet. `total` is the navy card, and
              the other two take the neutral ground rather than an ageing colour
              — a count of receipts is not a bucket of arrears.
            -->
            <div class="age-kpi-row rcpt-kpis">
                <div v-for="card in kpis" :key="card.key" class="age-kpi" :class="card.key === 'issued' ? 'total' : 'plain'">
                    <div class="av">{{ card.value }}</div>
                    <div class="al">{{ card.label }}</div>
                </div>
            </div>

            <p class="rcpt-note">
                Numbered <strong>{{ prefix }}-R-{{ String(first_no).padStart(5, '0') }}</strong> upward, one sequence
                for this estate, allocated when a payment posts and never reused. The next receipt will be
                <strong>{{ prefix }}-R-{{ String(last_no + 1).padStart(5, '0') }}</strong>. A number that no receipt
                carries is drawn below as a gap, in its place.
                <template v-if="!canRecord">
                    Payments are recorded from a unit's ledger by a role holding Payments create access.
                </template>
                <template v-else>Payments are recorded from a unit's ledger — open one from the arrears list.</template>
            </p>

            <EmptyState
                v-if="state.isEmptyFiltered.value"
                variant="filtered"
                title="Nothing matches"
                body="The register has no filter, so this state is only ever forced for review. Every receipt the estate has issued is in it."
            />

            <EmptyState
                v-else-if="state.isEmpty.value"
                variant="first-use"
                title="No receipt has been issued yet"
                body="Nothing has been paid to this estate, so no number has been allocated. The first payment recorded on any unit's ledger takes the first number of the sequence, and every one after it the next."
            />

            <table v-else class="data-table">
                <thead>
                    <tr>
                        <th>Receipt</th>
                        <th>Received</th>
                        <th>Entered</th>
                        <th>Unit</th>
                        <th>Method</th>
                        <th>Reference</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Entry</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="item in sequence" :key="item.kind === 'gap' ? `gap-${item.number}` : `r-${item.row.id}`">
                        <!--
                          A gap, in its place. Red because it is the one row on
                          this register that is a finding rather than a fact.
                        -->
                        <tr v-if="item.kind === 'gap'" class="rcpt-gap" :title="item.gap.note">
                            <td>{{ item.gap.receipt_no }}</td>
                            <td colspan="8">Gap — {{ item.gap.note }}</td>
                        </tr>

                        <tr v-else>
                            <td class="rcpt-no">{{ item.row.receipt_no }}</td>
                            <td>{{ item.row.received_on }}</td>
                            <td>
                                {{ item.row.entered_on }}
                                <span
                                    v-if="item.row.entered_late"
                                    class="rcpt-late"
                                    title="Keyed on a later day than the money arrived — late paperwork, not a late payment."
                                >
                                    late entry
                                </span>
                            </td>
                            <td>
                                <Link :href="financePath(`/units/${item.row.unit_id}`)" class="text-link-sm">
                                    {{ item.row.unit }}
                                </Link>
                            </td>
                            <td>{{ item.row.method_label }}</td>
                            <td>{{ item.row.reference ?? '' }}</td>
                            <td class="bal-amt">{{ item.row.amount }}</td>
                            <td>
                                <div class="age-badge" :style="statusStyle(item.row.status)">{{ item.row.status }}</div>
                            </td>
                            <td>
                                <Link
                                    v-if="item.row.journal_id"
                                    :href="`${root}/records/journals/${item.row.journal_id}`"
                                    class="text-link-sm"
                                    title="The posted entry behind this receipt — Dr Bank, Cr Dues Receivable."
                                >
                                    {{ item.row.journal_ref }}
                                </Link>
                                <template v-else>{{ item.row.journal_ref ?? '' }}</template>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal, as board 5's own page makes it: the sheet draws tabs and row
 * actions as <div>s, and a real button or link arrives with the browser's own
 * chrome. .btn-outline-sm keeps its border — it is the sheet's.
 */
a {
    text-decoration: none;
}

button {
    font-family: inherit;
}

button.subnav-item {
    border: 0;
    background: none;
}

button[disabled] {
    cursor: not-allowed;
}

/*
 * AUTHORED BELOW THIS LINE. Board 5 draws five ageing cards; this register has
 * three counts, so the grid narrows and the two non-navy cards take a neutral
 * ground the sheet has no class for.
 */
.rcpt-kpis {
    grid-template-columns: repeat(3, 1fr);
}

.age-kpi.plain {
    background: var(--white);
    border: 1px solid var(--navy-100);
}

.age-kpi.plain .av {
    color: var(--navy-900);
}

.age-kpi.plain .al {
    color: var(--slate-500);
}

.rcpt-note {
    font-size: 11.5px;
    color: var(--slate-600);
    line-height: 1.6;
    margin: 0 0 16px;
    max-width: 820px;
}

.rcpt-no {
    font-variant-numeric: tabular-nums;
    font-weight: 700;
    color: var(--navy-800);
}

.rcpt-late {
    display: inline-block;
    margin-left: 6px;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 20px;
    background: var(--amber-100);
    color: var(--amber-700);
}

.rcpt-gap td {
    background: var(--red-100);
    color: var(--red-700);
    font-weight: 600;
}
</style>
