<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EstateIcon from '../../../Components/EstateIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * Chart of accounts — board screen community-admin-25.
 *
 * The structure every journal in this estate posts against, grouped by type
 * and ordered by code, which is what an accountant navigates by.
 *
 * NO BALANCE ON THIS SCREEN IS STORED. Each is the sum of the account's posted
 * lines, computed on read. A stored balance is a second copy of the ledger free
 * to drift from it — and the copy is the one a committee reads, so the drift is
 * invisible until an auditor arrives.
 *
 * INCOME AND EXPENSE ARE FOR THE PERIOD; EVERYTHING ELSE IS CUMULATIVE, and
 * that distinction is what the two kinds of account mean rather than a
 * presentation choice. A bank balance is everything that ever happened to it;
 * "Maintenance Fee Income" with no period stated is a number nobody can act on.
 * The board draws J$2,790,000 against maintenance income, which is exactly 450
 * units at J$6,200 — one month's assessment (D-042) — so the period is the
 * current month and the screen SAYS SO. The board leaves it implied; a figure
 * whose period a reader has to guess is the defect that surfaces at an audit.
 *
 * THE EQUITY GROUP IS NOT ON THE BOARD, and it has to be here. Total the twelve
 * accounts the board draws and they are out by J$8,556,719 — which is not an
 * error in the figures, it IS the members' accumulated fund. A chart of
 * accounts that hides an account from the accountant, and a trial balance that
 * can never agree, are both worse than a row the board did not draw. See D-041.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    groups: { type: Array, required: true },
    period: { type: String, required: true },
    kpis: { type: Array, required: true },
    tabs: { type: Array, required: true },
    /** Whether the viewer holds Accounting configure — the chart's own verb. */
    canConfigure: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
    /** Every active account, for the parent picker, each with its type. */
    parents: { type: Array, required: true },
})

const page = usePage()

/*
 * ADDING AN ACCOUNT IS BUILT, WITH THE REVIEW STEP (12 §2, Wave 1). An account
 * changes what every future entry may post against and can be archived,
 * never removed — so the panel reads the code, the name and the type back to
 * the person adding it and asks them to confirm before the press. The server
 * refuses the press without the confirmation too.
 */
const adding = ref(false)

const TYPES = [
    { value: 'asset', label: 'Asset' },
    { value: 'liability', label: 'Liability' },
    { value: 'equity', label: 'Equity' },
    { value: 'income', label: 'Income' },
    { value: 'expense', label: 'Expense' },
]

const accountForm = useForm({
    code: '',
    name: '',
    type: 'expense',
    parent_id: '',
    is_bank_account: false,
    reviewed: false,
})

/** Only parents of the same type: an expense filed under an asset is a chart that lies. */
const parentsOfType = computed(() => props.parents.filter((parent) => parent.type === accountForm.type))

const typeLabel = computed(() => TYPES.find((type) => type.value === accountForm.type)?.label ?? accountForm.type)

const review = computed(() => {
    if (accountForm.code.trim() === '' || accountForm.name.trim() === '') {
        return null
    }

    const parent = props.parents.find((p) => p.id === accountForm.parent_id)

    return `${accountForm.code.trim()} · ${accountForm.name.trim()} — ${typeLabel.value.toLowerCase()}${parent ? `, under ${parent.label}` : ''}${accountForm.is_bank_account ? ', money at a bank' : ''}. Every future entry may post to it. It can be archived and never removed.`
})

const openAdding = () => {
    if (!props.canConfigure) {
        return
    }

    adding.value = !adding.value
    accountForm.clearErrors()
}

const closeAdding = () => {
    adding.value = false
    accountForm.reset()
    accountForm.clearErrors()
}

const onTypeChange = () => {
    accountForm.parent_id = ''
    accountForm.reviewed = false

    if (accountForm.type !== 'asset') {
        accountForm.is_bank_account = false
    }
}

const submitAccount = () => {
    if (!props.canConfigure || !accountForm.reviewed || review.value === null) {
        return
    }

    accountForm.post(`${base.value}/accounting/chart-of-accounts`, {
        preserveScroll: true,
        onSuccess: () => closeAdding(),
    })
}

const archive = (row) => {
    if (!props.canConfigure || !row.is_active) {
        return
    }

    router.post(`${base.value}/accounting/chart-of-accounts/${row.id}/archive`, {}, { preserveScroll: true })
}

/*
 * Which board's stylesheet this page wears. The ten Estate Console boards do
 * NOT share one sheet the way the nine Gemini boards do, so every estate page
 * has to name its own or it renders with no board CSS at all.
 */
useWireframe('community-admin-07-chart-of-accounts-vendors-bills-and-bank-rec')

const state = useScreenState({
    rows: () => props.groups.reduce((total, group) => total + group.rows.length, 0),
})

/**
 * The board's two money formats, and they are two on purpose.
 *
 * The KPI tiles abbreviate — "$4.30M", "$412k" — because a tile is read at a
 * glance and eight digits in it are noise. The table does not, because an
 * accountant reconciling a column needs the figure itself. Both are drawn that
 * way on the board.
 */
const abbreviated = (minor) => {
    const value = minor / 100

    if (Math.abs(value) >= 1_000_000) {
        return `$${(value / 1_000_000).toFixed(2)}M`
    }

    if (Math.abs(value) >= 1_000) {
        return `$${Math.round(value / 1_000)}k`
    }

    return `$${value.toLocaleString('en-JM')}`
}

const exact = (minor) => `$${(minor / 100).toLocaleString('en-JM', { maximumFractionDigits: 0 })}`

/**
 * The estate path this console is served under.
 *
 * Local puts the estate in the path and production gives each its own
 * hostname, so the prefix is taken from the URL we are already on rather than
 * rebuilt. Everything up to `/accounting` is the prefix, whichever shape it is.
 */
const base = computed(() => page.url.split('/accounting')[0])
</script>

<template>
    <Head title="Accounting" />

    <EstateConsole title="Accounting" :estate-name="estate.name" active="accounting">
        <template #actions>
            <button
                type="button"
                class="btn-primary-sm"
                :disabled="!canConfigure"
                :title="canConfigure ? 'Add an account to the chart. It is read back to you before it is added, because it can be archived and never removed.' : blockedReason"
                @click="openAdding"
            >
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
                <span>Add account</span>
            </button>
        </template>

        <div class="subnav">
            <template v-for="tab in tabs" :key="tab.key">
                <div v-if="tab.key === 'chart'" class="subnav-item active">{{ tab.label }}</div>
                <Link v-else :href="`${base}/accounting/${tab.key}`" class="subnav-item">
                    {{ tab.label }}
                </Link>
            </template>
        </div>

        <p v-if="page.props.flash?.success" class="coa-flash">{{ page.props.flash.success }}</p>
        <p v-if="page.props.errors?.account" class="coa-refusal">{{ page.props.errors.account }}</p>

        <!--
          The add-account panel, with its review step: the account is read
          back as one sentence and the tick is what enables the press.
        -->
        <form v-if="adding" class="coa-panel" @submit.prevent="submitAccount">
            <div class="coa-head">Add an account to the chart</div>

            <div class="coa-fields">
                <div class="coa-field">
                    <label for="coa-code">Code</label>
                    <input id="coa-code" v-model="accountForm.code" type="text" required maxlength="16" placeholder="5300" @input="accountForm.reviewed = false" />
                </div>

                <div class="coa-field coa-field--wide">
                    <label for="coa-name">Name</label>
                    <input id="coa-name" v-model="accountForm.name" type="text" required maxlength="120" placeholder="Security Services" @input="accountForm.reviewed = false" />
                </div>

                <div class="coa-field">
                    <label for="coa-type">Type</label>
                    <select id="coa-type" v-model="accountForm.type" required @change="onTypeChange">
                        <option v-for="type in TYPES" :key="type.value" :value="type.value">{{ type.label }}</option>
                    </select>
                </div>

                <div class="coa-field coa-field--wide">
                    <label for="coa-parent">Filed under — optional, same type only</label>
                    <select id="coa-parent" v-model="accountForm.parent_id" @change="accountForm.reviewed = false">
                        <option value="">Top level</option>
                        <option v-for="parent in parentsOfType" :key="parent.id" :value="parent.id">{{ parent.label }}</option>
                    </select>
                </div>
            </div>

            <label v-if="accountForm.type === 'asset'" class="coa-check">
                <input v-model="accountForm.is_bank_account" type="checkbox" @change="accountForm.reviewed = false" />
                <span>Money at a bank — counted in cash on hand, and reconcilable against a statement</span>
            </label>

            <!-- THE REVIEW STEP. -->
            <div class="coa-review" :class="{ ready: review !== null }">
                <div class="coa-review-lead">Read it back before it is added:</div>
                <div class="coa-review-line">{{ review ?? 'Enter a code and a name.' }}</div>
                <label class="coa-check">
                    <input v-model="accountForm.reviewed" type="checkbox" :disabled="review === null" />
                    <span>The code is not already in use and the type is right. Add it.</span>
                </label>
            </div>

            <div v-if="accountForm.errors.code" class="coa-error">{{ accountForm.errors.code }}</div>
            <div v-if="accountForm.errors.name" class="coa-error">{{ accountForm.errors.name }}</div>
            <div v-if="accountForm.errors.parent_id" class="coa-error">{{ accountForm.errors.parent_id }}</div>
            <div v-if="accountForm.errors.reviewed" class="coa-error">{{ accountForm.errors.reviewed }}</div>

            <div class="coa-actions">
                <button
                    type="submit"
                    class="btn-primary-sm"
                    :disabled="accountForm.processing || !accountForm.reviewed"
                    :title="accountForm.reviewed ? 'Add the account.' : 'Read the account back and tick the review first — it can be archived and never removed.'"
                >
                    <span>{{ accountForm.processing ? 'Adding…' : 'Add account' }}</span>
                </button>
                <button type="button" class="text-link-sm" @click="closeAdding">Cancel</button>
            </div>
        </form>

        <div class="kpi-row">
            <div v-for="kpi in kpis" :key="kpi.key" class="kpi-card">
                <div class="k-top">
                    <div class="kpi-icon"><EstateIcon :name="kpi.icon" /></div>
                </div>
                <div class="k-val">{{ abbreviated(kpi.value_minor) }}</div>
                <div class="k-lbl">{{ kpi.label }}</div>
            </div>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="12" :columns="4" />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="No chart of accounts yet"
            body="Every journal entry posts against an account. Until the chart exists, nothing in this estate can be booked."
        />

        <table v-else class="data-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Account name</th>
                    <th>Type</th>
                    <th>Balance</th>
                </tr>
            </thead>
            <tbody>
                <template v-for="group in groups" :key="group.label">
                    <tr class="group-head">
                        <td colspan="4">{{ group.label }}</td>
                    </tr>
                    <tr v-for="row in group.rows" :key="row.id" :class="{ 'coa-archived': !row.is_active }">
                        <td>{{ row.code }}</td>
                        <td>
                            {{ row.name }}
                            <!--
                              Archive, for a viewer holding configure, on an
                              account still open. Drawn as a quiet link at the
                              end of the name rather than a fifth column the
                              board does not have. An archived account stays
                              in the chart, greyed, with its history.
                            -->
                            <button
                                v-if="canConfigure && row.is_active"
                                type="button"
                                class="coa-archive"
                                :title="`Archive ${row.code} ${row.name}. Nothing already posted to it moves; nothing new may post to it. It is never removed.`"
                                :aria-label="`Archive ${row.code} ${row.name}`"
                                @click="archive(row)"
                            >
                                archive
                            </button>
                            <span v-else-if="!row.is_active" class="coa-archived-tag" title="Archived: nothing new may post to it, and everything posted to it stands.">archived</span>
                        </td>
                        <td>{{ row.type }}</td>
                        <td class="num-cell">{{ exact(row.balance_minor) }}</td>
                    </tr>
                </template>
            </tbody>
        </table>

        <!--
          Authored, and it earns its place. Two of the five groups above are
          period figures and three are cumulative; a reader who assumes one rule
          for the whole column will misread the income by a factor of however
          many months the estate has been billing.
        -->
        <p v-if="!state.isEmpty.value" class="chart-note">
            Income and expense balances are for {{ period }}. Assets, liabilities and equity are cumulative.
        </p>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws the topbar action as a <div> and the
 * three sibling tabs as <div>s; here the first is a real button and the others
 * are anchors, which arrive with a border, a face, the browser's own font and
 * an underline. .btn-primary-sm and .subnav-item supply everything visible.
 */
button.btn-primary-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

a.subnav-item {
    text-decoration: none;
}

button.btn-primary-sm[disabled] {
    cursor: not-allowed;
}

/*
 * The period note. Authored, because the board carries no equivalent — and it
 * is the one thing on this screen a reader cannot work out from what is drawn.
 * Kept to the tokens the boards define.
 */
.chart-note {
    font-size: 11px;
    color: var(--slate-500);
    line-height: 1.6;
    margin: 12px 2px 0;
}

button.text-link-sm {
    border: 0;
    background: none;
    font: inherit;
    padding: 0;
    cursor: pointer;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws a chart nobody is adding to, so it
 * has no panel, no flash, no review and no archive control. Kept to the tokens
 * the boards define and the shapes they already use.
 */
.coa-flash,
.coa-refusal {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 14px;
}

.coa-flash {
    background: var(--green-100);
    color: var(--green-700);
}

.coa-refusal {
    background: var(--red-100);
    color: var(--red-700);
}

.coa-panel {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 18px;
    margin-bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 11px;
}

.coa-head {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-800);
}

.coa-fields {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 11px;
}

.coa-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.coa-field--wide {
    grid-column: span 2;
}

.coa-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.coa-field input,
.coa-field select {
    height: 34px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

.coa-check {
    display: flex;
    align-items: flex-start;
    gap: 9px;
    font-size: 11.5px;
    color: var(--slate-600);
    line-height: 1.5;
    cursor: pointer;
}

.coa-check input {
    margin: 3px 0 0;
    flex: 0 0 auto;
}

/* The review step: the account read back, and the tick that enables the press. */
.coa-review {
    background: var(--navy-100);
    border-radius: 10px;
    padding: 12px 14px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.coa-review-lead {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.coa-review-line {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--slate-500);
    line-height: 1.5;
}

.coa-review.ready .coa-review-line {
    color: var(--navy-900);
}

.coa-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--red-700);
    line-height: 1.5;
}

.coa-actions {
    display: flex;
    align-items: center;
    gap: 14px;
}

.coa-archive {
    border: 0;
    background: none;
    font: inherit;
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    padding: 0;
    margin-left: 8px;
    cursor: pointer;
}

.coa-archive:hover {
    color: var(--red-700);
}

.coa-archived td {
    color: var(--slate-500);
}

.coa-archived-tag {
    display: inline-block;
    margin-left: 8px;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 20px;
    background: var(--navy-100);
    color: var(--slate-600);
}
</style>
