<script setup>
import { computed } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useWireframe } from '../../../composables/useWireframe'
import { useScreenState } from '../../../composables/useScreenState'

/**
 * Arrears command centre — board screen community-admin-05.
 *
 * DOM and class names are the board's. Every figure arrives already summed from
 * posted journal lines, so nothing on this page adds anything up: the total
 * outstanding card is the sum of the four ageing buckets beside it, and it is
 * derived once, in the ledger, where a test can re-sum it in raw SQL. A page
 * that recomputed it in JavaScript would agree with the accounts until the day
 * it did not.
 *
 * THE CHIPS FILTER IN THE QUERY, NOT IN THE BROWSER. Filtering the rows already
 * sent would leave the ageing cards reporting the whole estate above a list
 * reporting one phase, and a screen that computes its total and its list in two
 * places eventually reports two different numbers. The chips are links, so a
 * filtered view is a URL a treasurer can bookmark, share and reload.
 *
 * `reasons.restrict` is deliberately unused. Restriction stops a household's
 * guest passes at the gate, and the reason the server sends says exactly why it
 * is not a list action — it is never applied without the household in front of
 * you — so it belongs on the unit ledger, board 6. The board draws no restrict
 * control here either.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    /** Five cards in board order: total first, then current, 30, 60, 90+. */
    ageing: { type: Array, required: true },
    phases: { type: Array, required: true },
    filters: { type: Object, required: true },
    rows: { type: Array, required: true },
    canCharge: { type: Boolean, required: true },
    reasons: { type: Object, required: true },
})

/*
 * The estate boards are ten stylesheets that disagree with each other, so each
 * one is scoped to its own body class and a screen names the board it
 * reproduces rather than inheriting a house sheet.
 */
useWireframe('community-admin-02-arrears-ledger-payment-plan-and-dunning')

const page = usePage()

/*
 * Where this console is rooted, read off the page's own URL.
 *
 * Production gives each estate its own hostname and no prefix; local serves
 * every estate from one host with the estate key in the path, as
 * /estate/{key}/finance/arrears. The URL that served this page already carries
 * whichever shape this environment uses, so every link below is built by
 * cutting it at /finance — a hard-coded root would be correct in exactly one of
 * the two.
 */
const root = computed(() => page.url.slice(0, page.url.indexOf('/finance')))

const financePath = (suffix) => `${root.value}/finance${suffix}`

/*
 * Phase and ageing are independent filters, so each chip carries the other's
 * current value rather than clearing it: "Phase 2" from a 90+ view means 90+ in
 * Phase 2, which is the question somebody chasing a phase actually has.
 */
const chipHref = (phase, overdue) => {
    const query = new URLSearchParams()

    if (phase !== '') {
        query.set('phase', phase)
    }

    if (overdue) {
        query.set('overdue', '1')
    }

    const suffix = query.toString()

    return financePath(`/arrears${suffix === '' ? '' : `?${suffix}`}`)
}

/*
 * The board's own inline style on the last chip, kept verbatim — it is what
 * right-aligns the chip and makes it red. Inline declarations outrank
 * `.f-chip.active`, so a chip wearing them could never show that its filter is
 * engaged, and the board only ever draws it off. When it is on, the two colour
 * declarations stand down and the board's active pill shows through;
 * margin-left stays either way, because it is layout rather than state.
 */
const overdueChipStyle = computed(() =>
    props.filters.overdue
        ? { marginLeft: 'auto' }
        : { marginLeft: 'auto', background: 'var(--red-100)', color: 'var(--red-700)' }
)

/*
 * Money is formatted here, from integer cents, because the server's job is to
 * keep the figure exact and the page's is to draw it the way the board draws
 * it — and the board draws the same kind of number two different ways.
 *
 * The estate's ledger is denominated in JMD and the board prints a bare $,
 * which is how Jamaica writes its own currency domestically. Reproduced rather
 * than corrected to "J$".
 */
const dollars = (minor) => Math.round(minor / 100)

/**
 * The table's money: whole dollars, comma-grouped, no decimals.
 *
 * A unit balance is read as the sum a household is about to be asked for, so it
 * is never abbreviated — "$12k" is not an answer to "what do I owe". Cents
 * round for display; the exact figure and the journal lines behind it are on
 * that unit's ledger, board 6.
 */
const amount = (minor) => `$${dollars(minor).toLocaleString('en-US')}`

/**
 * The ageing cards' money: abbreviated, as the board draws it — $1.84M, $612k.
 *
 * Not an inconsistency with the table: these five cards carry the estate's
 * whole receivable at 21px across a five-column grid, and they are read as
 * magnitudes rather than as amounts anybody will be invoiced. Two significant
 * decimals on millions and none on thousands is the board's rule, not a
 * rounding convenience.
 */
const abbreviated = (minor) => {
    const value = dollars(minor)

    if (Math.abs(value) >= 1_000_000) {
        return `$${(value / 1_000_000).toFixed(2)}M`
    }

    if (Math.abs(value) >= 1_000) {
        return `$${Math.round(value / 1_000).toLocaleString('en-US')}k`
    }

    return `$${value.toLocaleString('en-US')}`
}

/*
 * Sources are functions, not values: useScreenState runs once during setup, and
 * a value read there would freeze on the first render and stop agreeing with
 * the props behind it.
 */
const state = useScreenState({
    rows: () => props.rows.length,
    filtered: () => props.filters.phase !== '' || props.filters.overdue,
})

/*
 * Each unbuilt tab says what it is waiting on. One shared "coming soon" would
 * be the same sentence three times and would not tell a treasurer which of the
 * three is nearest, which is the only thing they can act on.
 */
const subnav = [
    { label: 'Arrears command centre', active: true },
    {
        label: 'Charge schedule',
        reason:
            'Not built yet — the charge schedule raises the recurring maintenance fee against every unit on a date, so it needs a run that can be previewed and reversed before it needs a list.',
    },
    { label: 'Payment plans', reason: props.reasons.plan },

    // Built, to the ruling that settled the numbering: one sequence per
    // estate, never reused, gaps drawn in their place.
    { label: 'Receipts', href: '/receipts' },
]

/*
 * Reading the arrears and adding to what a household owes are separate
 * permissions, by platform invariant rather than estate preference: a committee
 * member may read this list all day and may not post to it.
 */
const NO_CHARGE_ACCESS =
    'Posting a charge adds to what a household owes and needs Dues & ledger create access. Reading the arrears does not carry it.'

/** The board's own em dash, for a fact that is absent rather than zero. */
const NEVER = '—'
</script>

<template>
    <Head title="Dues & ledger" />

    <EstateConsole
        title="Dues & ledger"
        :estate-name="estate.name"
        active="dues_ledger"
        search-placeholder="Search unit or resident…"
    >
        <template #actions>
            <!--
              An arrears export leaves the estate as a file naming who owes
              what. It is inert and says why, rather than producing a document
              with no retention rule behind it.
            -->
            <button type="button" class="btn-outline-sm" disabled :title="reasons.export">
                <BoardIcon name="export" :stroke="1.8" />
                <span>Export</span>
            </button>

            <Link v-if="canCharge" :href="financePath('/charges/new')" class="btn-primary-sm">
                <BoardIcon name="plus" :stroke="2" />
                <span>New charge</span>
            </Link>
            <button v-else type="button" class="btn-primary-sm" disabled :title="NO_CHARGE_ACCESS">
                <BoardIcon name="plus" :stroke="2" />
                <span>New charge</span>
            </button>
        </template>

        <div class="subnav">
            <template v-for="item in subnav" :key="item.label">
                <Link v-if="item.active" :href="financePath('/arrears')" class="subnav-item active">
                    {{ item.label }}
                </Link>
                <Link v-else-if="item.href" :href="financePath(item.href)" class="subnav-item">
                    {{ item.label }}
                </Link>
                <button v-else type="button" class="subnav-item" disabled :title="item.reason">
                    {{ item.label }}
                </button>
            </template>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="5" :columns="7" />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The arrears could not be read"
            body="No balance has changed and nothing has been written off. The ledger is intact; this is a read that failed, and re-running it is safe."
            action-label="Try again"
            @action="router.reload()"
        />

        <!--
          The cards are below this branch on purpose. Somebody refused Dues &
          ledger must not be told the estate's receivable by a summary left
          standing over the refusal.
        -->
        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="The arrears are not yours to see"
            body="Dues & ledger covers what every household owes, and your role does not hold it. A committee officer or the estate administrator can grant it from the role access matrix."
        />

        <template v-else>
            <!--
              The cards and the chips stand in every remaining state. The cards
              are the whole estate's receivable and do not move when a chip is
              clicked, so hiding them over an empty filtered list would suggest
              the arrears had gone away; hiding the chips would take away the
              control that produced the empty screen.

              The modifier class IS the bucket key — total, current, d30, d60,
              d90 — and the order is the server's, which is the board's.
            -->
            <div class="age-kpi-row">
                <div v-for="card in ageing" :key="card.key" class="age-kpi" :class="card.key">
                    <div class="av">{{ abbreviated(card.value_minor) }}</div>
                    <div class="al">{{ card.label }}</div>
                </div>
            </div>

            <div class="filter-row">
                <Link
                    :href="chipHref('', filters.overdue)"
                    class="f-chip"
                    :class="{ active: filters.phase === '' }"
                >
                    All phases
                </Link>
                <Link
                    v-for="phase in phases"
                    :key="phase"
                    :href="chipHref(phase, filters.overdue)"
                    class="f-chip"
                    :class="{ active: filters.phase === phase }"
                >
                    {{ phase }}
                </Link>

                <Link
                    :href="chipHref(filters.phase, !filters.overdue)"
                    class="f-chip"
                    :class="{ active: filters.overdue }"
                    :style="overdueChipStyle"
                >
                    90+ days only
                </Link>
            </div>

            <EmptyState
                v-if="state.isEmptyFiltered.value"
                variant="filtered"
                title="No unit matches this filter"
                body="Units are in arrears elsewhere in the estate — the ageing above counts them — but none of them is in this phase, or none is ninety days past due. Nothing has been lost."
                action-label="Show every unit"
                @action="router.visit(chipHref('', false))"
            />

            <EmptyState
                v-else-if="state.isEmpty.value"
                variant="first-use"
                title="Nobody is in arrears"
                body="Every unit's ledger is settled — not one household carries a balance. This list holds only units that owe the estate money, so it empties as they pay and fills again on the next charge run."
            />

            <table v-else class="data-table">
                <thead>
                    <tr>
                        <th>Household</th>
                        <th>Unit</th>
                        <th>Balance</th>
                        <th>Ageing</th>
                        <th>Last payment</th>
                        <th>Last reminder</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id">
                        <td>
                            <div class="res-cell">
                                <div class="res-avatar">{{ row.initials }}</div>
                                <div>
                                    <div class="res-name">{{ row.resident }}</div>
                                    <div class="res-sub">{{ row.household }}</div>
                                </div>
                            </div>
                        </td>
                        <td>{{ row.unit }}</td>
                        <td class="bal-amt">{{ amount(row.balance_minor) }}</td>
                        <td>
                            <div class="age-badge" :class="row.bucket">{{ row.bucket_label }}</div>
                        </td>

                        <!-- A unit that has never paid is a different fact from
                             one that paid long ago, and the board draws the
                             first as an em dash rather than an empty cell. -->
                        <td>{{ row.last_payment ?? NEVER }}</td>

                        <!-- The board draws "Today, 9:00 AM" here, which is a
                             nightly dunning run at 09:00. Dunning is board 8 and
                             is not built, so no notice has been sent to anybody
                             and every row is honestly the board's own never. -->
                        <td :title="reasons.dunning">{{ NEVER }}</td>

                        <td>
                            <div class="row-actions">
                                <Link :href="financePath(`/units/${row.id}`)" class="text-link-sm">View ledger</Link>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws its sub-navigation, its topbar actions,
 * its filter chips and its row links as <div>s; here they are real links and
 * real buttons, and a <button> arrives wearing the system UI font and a border
 * and face of the browser's own. app.css already takes the UA's underline and
 * blue off every anchor on a board page, so nothing is needed for the links.
 *
 * Not one declaration below introduces a colour, a size or a spacing: each of
 * them subtracts something the board never had.
 */
button {
    font: inherit;
}

/*
 * .btn-outline-sm declares its own border and its own white; .btn-primary-sm
 * declares a background and no border at all, so only that one needs the UA
 * border taken off.
 */
button.btn-primary-sm {
    border: 0;
}

/*
 * .subnav-item is the one control whose board rule declares no background of
 * its own. The white belongs to `.subnav-item.active`, and the active tab is
 * this screen, so it is a link and never one of these buttons — which is why
 * the face can come off flatly here without tying on specificity with it.
 */
button.subnav-item {
    border: 0;
    background: none;
}

/* Every button this screen draws is inert, and says so under the cursor. */
button[disabled] {
    cursor: not-allowed;
}
</style>
