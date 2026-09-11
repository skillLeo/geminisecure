<script setup>
import { computed, ref } from 'vue'
import { Head, useForm, usePage } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import SettingsTabs from './SettingsTabs.vue'
import { useScreenState } from '../../../composables/useScreenState'

/**
 * Platform settings — board screen super-admin-42, the module's landing screen.
 *
 * Two panels: what the platform charges, and who holds an account on it.
 *
 * The rate card is two sources rendered as one list, and that is the board's
 * doing rather than a shortcut. Three rows are tier prices and come from
 * `plans`; the fourth is a per-guard charge that applies on top of ANY tier and
 * therefore cannot live on a plan. The board draws them as one card because
 * they answer one question — what does this platform charge — and the second
 * line on each row says which kind it is.
 *
 * NOTHING HERE IS EDITABLE, including the rate boxes. The board draws them as
 * <div>s holding a span, not as inputs, and that is the right shape: changing a
 * tier price re-prices every client on that tier from a date, which is a
 * privileged audited write and not something a display screen grows by
 * accident. "Save changes" renders disabled and says so.
 *
 * The card's wrapper carries the board's own inline grid style, copied
 * verbatim, because the board's stylesheet defines no class for it.
 */
const props = defineProps({
    tabs: { type: Array, required: true },
    rates: { type: Array, required: true },
    admins: { type: Array, required: true },
    /** The tiers a price change can be made against, with what they cost now. */
    plans: { type: Array, required: true },
    /** Changes recorded and not yet in force. */
    pending: { type: Array, required: true },
    /** Whether anything is priced but retired — see the note below. */
    hasRetired: { type: Boolean, required: true },
    canWrite: { type: Boolean, required: true },
    saveDisabledReason: { type: String, required: true },
})

/* ------------------------------------------------------------------ */
/* changing a tier price (12 §2, Wave 4) */
/* ------------------------------------------------------------------ */

/*
 * A DATE AND A REASON, AND NEITHER IS OPTIONAL. This re-prices every client on
 * the tier: the date is what stops it being retroactive — an invoice already
 * raised was raised at the price in force — and the reason is what an auditor
 * reads a year later instead of asking somebody to remember.
 */
const editing = ref(false)

const priceForm = useForm({
    plan_id: props.plans[0]?.id ?? null,
    amount: props.plans[0]?.amount ?? '',
    effective_from: new Date().toISOString().slice(0, 10),
    reason: '',
})

const chosenPlan = computed(() => props.plans.find((plan) => plan.id === priceForm.plan_id) ?? null)

/** What the press will do, said before it happens. */
const priceReadBack = computed(() => {
    if (chosenPlan.value === null) {
        return ''
    }

    const today = new Date().toISOString().slice(0, 10)
    const when = priceForm.effective_from <= today ? 'from today' : `from ${priceForm.effective_from}`

    return `${chosenPlan.value.name} moves from ${chosenPlan.value.price} to $${priceForm.amount} per unit ${when}. Every client on that tier is re-priced; nothing already invoiced changes.`
})

const openEdit = () => {
    if (!props.canWrite) {
        return
    }

    priceForm.clearErrors()
    editing.value = !editing.value
}

const submitPrice = () => {
    if (priceForm.reason.trim() === '') {
        return
    }

    priceForm.post('/settings/prices', {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = false
            priceForm.reason = ''
        },
    })
}

/*
 * "Filtered" on this screen is `is_active`, which is a real column and a real
 * filter: a retired plan keeps its rows and its history and simply stops being
 * quoted. Empty-because-everything-is-retired is a different screen from
 * empty-because-nothing-was-ever-priced — one says the rate card was emptied,
 * the other that it was never filled — so they are told apart here rather than
 * sharing one blank panel.
 */
const page = usePage()

const state = useScreenState({
    rows: () => props.rates.length,
    filtered: () => props.hasRetired,
})
</script>

<template>
    <Head title="Platform settings" />

    <GeminiConsole title="Platform settings">
        <template #actions>
            <!--
              The board draws this as a <div>. It opens the price panel rather
              than saving a form the board does not have: a tier price is
              changed one at a time, from a date, with a reason — see the panel.
            -->
            <button
                type="button"
                class="btn-primary-sm"
                :disabled="!canWrite || plans.length === 0"
                :title="canWrite ? 'Change a tier price, from a date, with the reason. It re-prices every client on that tier and never anything already invoiced.' : saveDisabledReason"
                @click="openEdit"
            >
                <span>Save changes</span>
            </button>
        </template>

        <SettingsTabs :tabs="tabs" />

        <p v-if="page.props.flash?.success" class="ps-flash">{{ page.props.flash.success }}</p>

        <!--
          AUTHORED. The board draws a rate card nobody is changing, so it has no
          panel and no pending list.
        -->
        <form v-if="editing" class="ps-panel" @submit.prevent="submitPrice">
            <div class="ps-head">
                A tier price re-prices every client on it. The date is what stops it being retroactive — an invoice
                already raised was raised at the price in force — and the reason is what an auditor reads instead of
                asking somebody to remember.
            </div>

            <div class="ps-fields">
                <div class="ps-field">
                    <label for="ps-plan">Tier</label>
                    <select id="ps-plan" v-model="priceForm.plan_id" required>
                        <option v-for="plan in plans" :key="plan.id" :value="plan.id">
                            {{ plan.name }} — {{ plan.price }}/unit
                        </option>
                    </select>
                </div>
                <div class="ps-field">
                    <label for="ps-amount">New price per unit, J$</label>
                    <input id="ps-amount" v-model="priceForm.amount" type="text" inputmode="decimal" required />
                </div>
                <div class="ps-field">
                    <label for="ps-from">In force from</label>
                    <input id="ps-from" v-model="priceForm.effective_from" type="date" required />
                </div>
                <div class="ps-field ps-field--wide">
                    <label for="ps-reason">Why</label>
                    <input id="ps-reason" v-model="priceForm.reason" type="text" required maxlength="255" />
                </div>
            </div>

            <p class="ps-read">{{ priceReadBack }}</p>

            <div v-if="priceForm.errors.amount" class="ps-error">{{ priceForm.errors.amount }}</div>
            <div v-if="priceForm.errors.reason" class="ps-error">{{ priceForm.errors.reason }}</div>

            <div class="ps-actions">
                <button
                    type="submit"
                    class="btn-primary-sm"
                    :disabled="priceForm.processing || priceForm.reason.trim() === ''"
                    :title="priceForm.reason.trim() === '' ? 'Say why. An unexplained price change is the entry an auditor stops at.' : 'Record the change.'"
                >
                    <span>{{ priceForm.processing ? 'Saving…' : 'Change the price' }}</span>
                </button>
                <button type="button" class="text-link-sm" @click="editing = false">Cancel</button>
            </div>
        </form>

        <div v-if="pending.length" class="ps-pending">
            <div class="ps-head">Recorded and not yet in force</div>
            <div v-for="change in pending" :key="change.id" class="ps-pending-row">
                <span>
                    <b>{{ change.plan }}</b> {{ change.from }} → {{ change.to }} from {{ change.effective_from }}
                </span>
                <span class="ps-pending-why">{{ change.reason }} · {{ change.by }}</span>
            </div>
        </div>

        <EmptyState
            v-if="state.isDenied.value"
            variant="denied"
            title="Platform settings is not part of your role's access"
            body="This module holds what every client is charged and who can reach what across the platform, so it opens only to roles that hold it outright. Yours does not."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The rate card could not be loaded"
            body="The platform database did not answer. No price has been changed and nothing is lost — the rates are still on file to be read."
        />

        <div v-else style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px">
            <div class="panel">
                <div class="panel-head">
                    <h3>Subscription tier pricing</h3>
                </div>

                <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="2" />

                <EmptyState
                    v-else-if="state.isEmptyFiltered.value"
                    variant="filtered"
                    title="Every plan and rate is retired"
                    body="Nothing is being quoted. Retired plans keep their subscriptions, their invoices and their history — they are simply no longer offered, and marking one active again brings it back to this card."
                />

                <EmptyState
                    v-else-if="state.isEmpty.value"
                    variant="first-use"
                    title="Nothing is priced yet"
                    body="A tier appears here once it has a per-unit price, and a charge that applies on top of any tier appears beneath them. Until then this platform quotes nothing."
                />

                <template v-else>
                    <div v-for="rate in rates" :key="rate.name" class="rate-row">
                        <div>
                            <div class="rn">{{ rate.name }}</div>
                            <div class="rd">{{ rate.detail }}</div>
                        </div>
                        <div class="rate-input">
                            <span>{{ rate.amount }}</span>
                        </div>
                    </div>
                </template>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <h3>Platform administrators</h3>
                </div>

                <SkeletonRows v-if="state.isLoading.value" :rows="2" :columns="2" />

                <EmptyState
                    v-else-if="admins.length === 0"
                    variant="first-use"
                    title="Nobody holds a platform account"
                    body="Anyone carrying a Gemini Console role appears here, with what that role can reach. Accounts are issued by invitation and never self-created."
                />

                <template v-else>
                    <div v-for="admin in admins" :key="admin.name" class="admin-row">
                        <div class="res-avatar">{{ admin.initials }}</div>
                        <div>
                            <div class="an">{{ admin.name }}</div>
                            <div class="ar">{{ admin.detail }}</div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/*
 * The only authored CSS on this screen, and every line removes a browser
 * default rather than adding a style.
 *
 * The board draws "Save changes" as a <div>. It is a real <button> here, and a
 * button arrives with a border, a background and Arial. The board's own
 * .btn-primary-sm rule already sets the height, padding, radius, background and
 * shadow, so only what the UA adds is taken off.
 */
button.btn-primary-sm {
    appearance: none;
    border: 0;
    font-family: inherit;
}

/* Inert, and it says why on hover. No opacity change: the board draws this
 * button at one weight, and dimming it would be a pixel the design does not
 * have. */
button.btn-primary-sm[disabled] {
    cursor: not-allowed;
}

button.btn-primary-sm {
    cursor: pointer;
}

button.text-link-sm {
    border: 0;
    background: none;
    padding: 0;
    font: inherit;
    cursor: pointer;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws a rate card nobody is changing, so
 * it has no panel, no flash and no pending list. Kept to the tokens the Gemini
 * boards define.
 */
.ps-flash {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 14px;
    background: var(--green-100);
    color: var(--green-700);
}

.ps-panel,
.ps-pending {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 17px;
    margin-bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 11px;
}

.ps-head {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--navy-800);
    line-height: 1.55;
    max-width: 820px;
}

.ps-fields {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 11px;
}

.ps-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.ps-field--wide {
    grid-column: span 3;
}

.ps-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.ps-field input,
.ps-field select {
    height: 34px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

.ps-read {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--navy-900);
    line-height: 1.55;
    margin: 0;
    background: var(--navy-100);
    border-radius: 10px;
    padding: 10px 14px;
}

.ps-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--red-700);
    line-height: 1.5;
}

.ps-actions {
    display: flex;
    align-items: center;
    gap: 14px;
}

.ps-pending-row {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 14px;
    font-size: 11.5px;
    color: var(--navy-900);
    line-height: 1.6;
    border-top: 1px solid var(--navy-100);
    padding-top: 7px;
}

.ps-pending-why {
    font-size: 10.5px;
    color: var(--slate-500);
}
</style>
