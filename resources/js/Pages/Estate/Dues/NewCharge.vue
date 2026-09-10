<script setup>
import { computed } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * New charge — board screen community-admin-35.
 *
 * POSTING A CHARGE IS AN ACCOUNTING ACT, not a form submission. It credits the
 * income account named in the GL field — the board draws "4000 — Maintenance
 * Fee Income" — and debits the unit's receivable control account for the same
 * figure. That is why the account is a field a treasurer chooses rather than a
 * constant this screen assumes, and why the amount travels as a decimal string:
 * the server hands it to `Money::of`, which refuses anything it cannot hold
 * exactly, and a float would have already lost the cent by then.
 *
 * THE LEDGER PREVIEW IS DERIVED HERE AND STORED NOWHERE. Current balance is the
 * server's figure, summed off posted journal lines; the new balance is that plus
 * whatever is in the amount field this instant. A second stored "new balance"
 * would be a number that could disagree with the journal, and a number that can
 * disagree with the journal eventually does.
 *
 * ONE SCOPE IS BUILT. The board's segmented control offers a single unit, a
 * whole phase and the whole estate; only the first exists. A phase charge raises
 * hundreds of entries against hundreds of households in one press, and it needs
 * the preview and the confirmation step that a single charge does not — so the
 * other two are drawn and visibly inert, saying so, rather than absent from a
 * control the design has three parts to.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    unit: { type: Object, default: null },
    currentBalanceMinor: { type: Number, required: true },
    accounts: { type: Array, required: true },
    types: { type: Array, required: true },
    canPost: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
    bulkReason: { type: String, required: true },
})

/*
 * The shell carries the estate's chrome but not the board's stylesheet: each
 * board's CSS is lifted verbatim and scoped to its own body class, and board 35
 * lives in the ninth Community Admin sheet alongside Add Resident and Meetings.
 */
useWireframe('community-admin-09-data-privacy-add-resident-and-meetings')

const page = usePage()

/*
 * Where this estate lives, in whichever shape the environment serves.
 *
 * Production gives every estate its own hostname and the path starts at
 * /finance; local serves them all from one host with the estate in the path
 * (/estate/phoenixpark/finance/charges/new). Cutting the current URL at
 * /finance yields the right prefix in both, and — unlike a tenant id read off a
 * prop — it cannot address an estate other than the one already open.
 */
const estatePath = computed(() => {
    const cut = page.url.indexOf('/finance')

    return cut === -1 ? '' : page.url.slice(0, cut)
})

const chargesPath = computed(() => `${estatePath.value}/finance/charges`)
const arrearsPath = computed(() => `${estatePath.value}/finance/arrears`)

const form = useForm({
    unit: props.unit?.reference ?? '',
    type: props.types[0]?.value ?? '',
    /*
     * The due date is deliberately blank. Defaulting it to today would make
     * every charge fall due the moment it is posted and start ageing that
     * afternoon, which is a collections decision taken by a default nobody
     * chose.
     */
    due_on: '',
    amount: '',
    description: '',
    account: props.accounts[0]?.code ?? '',
})

/**
 * Five of the six. `denied` is not reachable by data — the route already
 * requires Dues & ledger read access, so someone without it never renders this
 * screen — but it is forceable with ?_state=denied and must say something true
 * when it is. `canPost` is a separate question: it refuses the POST, not the
 * page, and lands on the button rather than over the whole screen.
 */
const state = useScreenState({
    rows: () => (props.unit === null ? 0 : 1),
    filtered: () => form.unit.trim() !== '',
})

/* ------------------------------------------------------------------ */
/* money */
/* ------------------------------------------------------------------ */

/*
 * Two formatters, because the board draws two and they are not interchangeable.
 *
 * The AMOUNT FIELD carries cents — "$15,000.00" — because it is the figure being
 * posted and a charge is posted to the cent. The PREVIEW carries whole dollars —
 * "$12,400", "$15,000", "$27,400" — because it is a running balance being read
 * at a glance. Folding them into one formatter would change the board on one
 * side or the other. It is the same figure twice: it may differ in form and it
 * may never differ in value, which is why both come off `form.amount`.
 */
const wholeDollars = new Intl.NumberFormat('en-JM', {
    style: 'currency',
    currency: 'JMD',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
})

const withCents = new Intl.NumberFormat('en-JM', {
    style: 'currency',
    currency: 'JMD',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
})

/**
 * Read the amount field as a number.
 *
 * What is in the field is typed, and once it has been tidied on blur it also
 * carries a currency symbol and thousands separators — "$15,000.00". Everything
 * but digits, one point and a leading minus is stripped, and anything that still
 * will not parse counts as zero: "$NaN" in a ledger preview is a defect in the
 * one figure on this screen a treasurer is actually checking.
 */
const parseAmount = (value) => {
    const parsed = Number.parseFloat(String(value ?? '').replace(/[^0-9.-]/g, ''))

    return Number.isFinite(parsed) ? parsed : 0
}

const amountMinor = computed(() => Math.round(parseAmount(form.amount) * 100))

const preview = (minor) => wholeDollars.format(minor / 100)

/*
 * The field reads like the board; the server receives arithmetic.
 *
 * "$15,000.00" is what board 35 prints in the amount field and what this one
 * shows once the value has been tidied, and it is not a number any validator
 * will accept. The transform is where the two part company, so the display can
 * stay faithful without the POST ever carrying a currency symbol.
 */
form.transform((data) => ({ ...data, amount: parseAmount(data.amount).toFixed(2) }))

const tidyAmount = () => {
    if (form.amount.trim() !== '') {
        form.amount = withCents.format(parseAmount(form.amount))
    }
}

/* ------------------------------------------------------------------ */
/* the unit, and the balance that belongs to it */
/* ------------------------------------------------------------------ */

/**
 * Ask the server for a unit, and for the balance that goes with it.
 *
 * The current balance is read off the journal for one unit. Typing a different
 * reference over the top of the field without asking again would leave the
 * preview adding this charge to somebody else's balance — a wrong figure drawn
 * with total confidence, which is the failure this preview exists to prevent.
 * So the same GET that served this screen is asked again for those two props
 * alone, with the form's state preserved so nothing already typed is lost.
 */
const loadUnit = (reference) => {
    router.get(
        `${estatePath.value}/finance/charges/new`,
        reference === '' ? {} : { unit: reference },
        {
            only: ['unit', 'currentBalanceMinor'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            // A match comes back in the estate's own spelling — "lot 47" becomes
            // "Lot 47" — and a miss leaves what was typed in the field so the
            // empty state can quote it back.
            onSuccess: () => {
                form.unit = props.unit?.reference ?? reference
            },
        }
    )
}

/*
 * On change rather than on input: a lookup per keystroke would ask the server
 * for "L", "Lo", "Lot", and none of those are units. An emptied field is a field
 * being retyped, not a request for a different unit.
 */
const lookUpUnit = () => {
    const reference = form.unit.trim()

    if (reference !== '') {
        loadUnit(reference)
    }
}

/** The empty-filtered way back: drop the reference that matched nothing. */
const resetUnit = () => loadUnit('')

/**
 * The unit as the board prints it: "Phase 2 · Lot 47 — Andrea Fletcher".
 *
 * The board's Unit field is a picker showing a unit somebody already chose, so
 * it can print all three parts. A real field holds the value that posts, and
 * what posts is the reference alone — the estate register's own name for the
 * unit. The composite stays on the control as its title and the resident it
 * names is read back in the preview note, so the identity the board prints is
 * still on the screen — just not inside a field whose value has to be postable.
 */
const unitLabel = computed(() =>
    props.unit === null
        ? ''
        : [props.unit.phase, props.unit.reference].filter(Boolean).join(' · ') + ' — ' + props.unit.resident
)

/**
 * Who is notified, or null when nobody is.
 *
 * The board writes "Andrea will see this on her Dues statement", with a pronoun
 * for the one resident it was drawn from. The application knows a name and does
 * not know a pronoun, so the name carries the sentence — and a vacant unit gets
 * a different sentence entirely, because "No resident on record will see this"
 * is what the board's wording turns into when the household is empty.
 */
const notifies = computed(() => {
    const resident = props.unit?.resident ?? ''

    return resident === '' || resident === 'No resident on record' ? null : resident.split(' ')[0]
})

const submit = () => {
    /*
     * A form submits on Enter from any field, and a disabled button does not
     * stop it. The server refuses this too — `estate.dues_ledger.create` guards
     * the route — so this is only about not sending a charge that will bounce.
     */
    if (!props.canPost) {
        return
    }

    form.post(chargesPath.value, { preserveScroll: true })
}

const retry = () => router.reload()
</script>

<template>
    <Head title="New charge" />

    <EstateConsole title="New charge" :estate-name="estate.name" active="dues_ledger">
        <template #lead>
            <Link
                :href="arrearsPath"
                class="topbar-back"
                title="Back to Dues &amp; ledger"
                aria-label="Back to Dues and ledger"
            >
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

        <div v-if="state.isLoading.value" class="form-layout">
            <div class="form-panel">
                <SkeletonRows :rows="6" :columns="2" />
            </div>
            <div class="preview-panel">
                <div class="preview-head">Ledger preview</div>
                <SkeletonRows :rows="3" :columns="2" />
            </div>
        </div>

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Dues &amp; ledger is not part of your role's access"
            body="A charge names a household and what it owes, so the module opens only to roles that hold it. The Property Manager holds neither reading nor posting here, by platform invariant rather than estate preference."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="This unit's balance could not be read"
            body="The estate's ledger did not answer. Nothing has been posted and no charge has been raised — the unit owes exactly what it owed before this screen opened."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isEmptyFiltered.value"
            variant="filtered"
            :title="`No unit is registered as “${form.unit.trim()}”`"
            body="A unit is found by the reference the estate register prints — “Lot 47”, not a household name or a resident's. Nothing typed into the rest of the form has been lost."
            action-label="Clear the unit"
            @action="resetUnit"
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="A charge has to be posted to a unit"
            body="A charge is a debit against one unit's receivable, so there is no charge to raise until there is a unit to raise it against. This estate has no units on its register yet."
            action-label="Open Dues &amp; ledger"
            @action="router.get(arrearsPath)"
        />

        <div v-else class="form-layout">
            <form class="form-panel" @submit.prevent="submit">
                <!--
                  One scope is built, and the control still has three parts
                  because the design does. "Single unit" is not a switch — with
                  one scope there is nothing to switch to — so it carries the
                  selected state and no handler, and the two that would be
                  switches say in their own words why they are not.
                -->
                <div class="seg" role="radiogroup" aria-label="What this charge is posted to">
                    <button type="button" class="seg-item active" role="radio" aria-checked="true">
                        Single unit
                    </button>
                    <button
                        type="button"
                        class="seg-item"
                        role="radio"
                        aria-checked="false"
                        disabled
                        :title="bulkReason"
                    >
                        Whole phase
                    </button>
                    <button
                        type="button"
                        class="seg-item"
                        role="radio"
                        aria-checked="false"
                        disabled
                        :title="bulkReason"
                    >
                        Whole estate
                    </button>
                </div>

                <!--
                  The board draws this field .focused — the screenshot was taken
                  with the cursor in it — and a unit arrives already resolved, so
                  the amber ring follows the field having a value, exactly as the
                  sibling form pages bind it.
                -->
                <div class="m-field">
                    <label for="unit">Unit</label>
                    <div class="m-input" :class="{ focused: form.unit !== '' }">
                        <span>
                            <input
                                id="unit"
                                v-model="form.unit"
                                type="text"
                                required
                                placeholder="Lot 47"
                                :title="unitLabel"
                                @change="lookUpUnit"
                            />
                        </span>
                    </div>
                    <div v-if="form.errors.unit" class="field-error">{{ form.errors.unit }}</div>
                </div>

                <div class="m-two-col">
                    <div class="m-field">
                        <label for="type">Charge type</label>
                        <div class="m-input">
                            <span>
                                <select id="type" v-model="form.type" required>
                                    <option v-for="option in types" :key="option.value" :value="option.value">
                                        {{ option.label }}
                                    </option>
                                </select>
                            </span>
                        </div>
                        <div v-if="form.errors.type" class="field-error">{{ form.errors.type }}</div>
                    </div>

                    <div class="m-field">
                        <label for="amount">Amount</label>
                        <div class="m-input">
                            <span>
                                <input
                                    id="amount"
                                    v-model="form.amount"
                                    type="text"
                                    inputmode="decimal"
                                    required
                                    placeholder="$0.00"
                                    @blur="tidyAmount"
                                />
                            </span>
                        </div>
                        <div v-if="form.errors.amount" class="field-error">{{ form.errors.amount }}</div>
                    </div>
                </div>

                <div class="m-field">
                    <label for="description">Description</label>
                    <div class="m-input">
                        <span>
                            <!--
                              200 characters is the server's limit, stated here
                              so the field stops rather than the submission.
                            -->
                            <input
                                id="description"
                                v-model="form.description"
                                type="text"
                                required
                                maxlength="200"
                                placeholder="What this charge is for"
                            />
                        </span>
                    </div>
                    <div v-if="form.errors.description" class="field-error">{{ form.errors.description }}</div>
                </div>

                <div class="m-two-col">
                    <div class="m-field">
                        <label for="due_on">Due date</label>
                        <div class="m-input">
                            <BoardIcon name="calendar" :stroke="1.6" />
                            <span>
                                <input id="due_on" v-model="form.due_on" type="date" required />
                            </span>
                        </div>
                        <div v-if="form.errors.due_on" class="field-error">{{ form.errors.due_on }}</div>
                    </div>

                    <div class="m-field">
                        <label for="account">GL account</label>
                        <div class="m-input">
                            <span>
                                <!--
                                  Income accounts only, and the code travels
                                  rather than the label: the charge credits this
                                  account, and an account is identified by its
                                  code in every ledger it appears in.
                                -->
                                <select id="account" v-model="form.account" required>
                                    <option v-for="option in accounts" :key="option.code" :value="option.code">
                                        {{ option.label }}
                                    </option>
                                </select>
                            </span>
                        </div>
                        <div v-if="form.errors.account" class="field-error">{{ form.errors.account }}</div>
                    </div>
                </div>

                <button
                    type="submit"
                    class="stack-btn primary"
                    style="width: 100%"
                    :disabled="!canPost || form.processing"
                    :title="canPost ? null : blockedReason"
                >
                    <BoardIcon name="plus" :stroke="2" />
                    <span>{{ form.processing ? 'Posting…' : 'Post charge' }}</span>
                </button>
            </form>

            <div class="preview-panel">
                <div class="preview-head">Ledger preview</div>

                <div style="background: var(--navy-100); border-radius: 12px; padding: 13px">
                    <div
                        style="
                            display: flex;
                            justify-content: space-between;
                            font-size: 12px;
                            color: var(--navy-900);
                            padding: 5px 0;
                        "
                    >
                        <span>Current balance</span>
                        <b>{{ preview(currentBalanceMinor) }}</b>
                    </div>
                    <div
                        style="
                            display: flex;
                            justify-content: space-between;
                            font-size: 12px;
                            color: var(--navy-900);
                            padding: 5px 0;
                        "
                    >
                        <span>+ New charge</span>
                        <b>{{ preview(amountMinor) }}</b>
                    </div>
                    <div
                        style="
                            display: flex;
                            justify-content: space-between;
                            font-size: 13px;
                            font-weight: 700;
                            color: var(--navy-900);
                            padding: 9px 0 0;
                            border-top: 1px solid var(--navy-200);
                            margin-top: 6px;
                        "
                    >
                        <span>New balance</span>
                        <b>{{ preview(currentBalanceMinor + amountMinor) }}</b>
                    </div>
                </div>

                <!--
                  The board's sentence, with its ageing clause made true.
                  It is conditional because the claim it makes is: this estate
                  ages a unit's WHOLE balance by its oldest open charge, so a
                  charge on a unit that already owes something joins that bucket
                  and cannot start a new one — but on a unit that owes nothing
                  there is no bucket to join, and the same sentence would be
                  telling a treasurer their clean unit is 30 days late.
                -->
                <div class="preview-note">
                    <template v-if="notifies">
                        {{ notifies }} will see this on the Dues statement and get a push notification.
                    </template>
                    <template v-else>
                        No resident is registered to {{ unit.reference }}, so nobody is notified — the charge
                        stands against the unit until one is.
                    </template>

                    <template v-if="currentBalanceMinor > 0">
                        It joins {{ unit.reference }}'s existing ageing bucket rather than starting a new one,
                        because a unit's whole balance is aged by its oldest open charge.
                    </template>
                    <template v-else>
                        {{ unit.reference }} owes nothing today, so this charge opens a current-bucket balance.
                    </template>
                </div>
            </div>
        </div>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only.
 *
 * The board draws every field as a <div class="m-input"><span>value</span></div>
 * and both the segments and the submit as <div>s. Here they are real controls,
 * and each arrives wearing the browser's own border, background, font and
 * spin buttons. Nothing below introduces a colour, size or weight: the control
 * sits INSIDE the board's own <span>, so the board's `.m-input span` rule
 * supplies its type and `font: inherit` picks it up.
 */
.m-input span {
    /* The board's span wraps a string and needs no width; this one wraps a
     * control that has to fill the pill. */
    flex: 1;
    min-width: 0;
}

.m-input input,
.m-input select {
    width: 100%;
    border: 0;
    background: transparent;
    font: inherit;
    color: inherit;
    padding: 0;
    appearance: none;
    -webkit-appearance: none;
}

/* The board defines its own placeholder colour, on .m-input .placeholder. */
.m-input input::placeholder {
    color: var(--slate-500);
    opacity: 1;
}

/*
 * A date input draws its own calendar button, and the board already draws a
 * calendar in this field. Two would be the browser's decoration on top of the
 * design; the date stays fully typeable and the picker still opens from the
 * keyboard.
 */
.m-input input[type='date']::-webkit-calendar-picker-indicator {
    display: none;
}

/*
 * The focus ring is deliberately NOT removed here, unlike on the sibling form
 * pages. The board marks its active field with .m-input.focused, which is bound
 * to the unit having a value rather than to focus — so with the UA outline gone
 * as well, a keyboard user would have nothing at all telling them which of six
 * fields they are typing into.
 */

button.seg-item {
    border: 0;
    background: transparent;
    font: inherit;
    color: inherit;
    cursor: pointer;
}

button.seg-item[disabled] {
    cursor: not-allowed;
    /* The board draws one weight of segment. Why the other two cannot be
     * pressed is on the title, not in a shade the design does not have. */
    opacity: 1;
}

button.stack-btn {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.stack-btn[disabled] {
    cursor: not-allowed;
}

/* Authored: the board draws one filled-in happy path and has no error state to
 * copy. Kept to the tokens the boards do define, and placed under its own field
 * rather than in a summary, so a refusal is read beside the value that caused
 * it. */
.field-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--red-700);
    margin-top: 5px;
    line-height: 1.45;
}

/*
 * The back chevron. The board draws it as an inline-styled <div> because its
 * stylesheet has no class for it; those exact declarations are reproduced here
 * on a real <a> so the control can be clicked, focused and opened in a new tab.
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
</style>
