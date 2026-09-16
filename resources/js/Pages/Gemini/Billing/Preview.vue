<script setup>
import { ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'

/**
 * Invoice preview — a "Not yet invoiced" row's "Preview" (12 §2, item 44).
 *
 * NO BOARD DRAWS THIS SCREEN. It borrows the invoice screen's line rows so the
 * two read alike, and it says in words, first, that it is NOT an invoice: no
 * number, no due amount, nothing owed. A preview of a bill that looked like a
 * document would be a figure somebody forwards as one.
 *
 * ONE READING OF WHAT A CLIENT PAYS. The lines are the tier, the per-guard
 * add-on and the client's line items in force today — exactly what board 44
 * reads — so the preview and the line-items screen cannot disagree.
 */
const props = defineProps({
    projection: { type: Object, required: true },
    bill: { type: Object, required: true },
    /** The invoice run (13 B1) — where it posts, and whether it may. */
    raiseHref: { type: String, required: true },
    canRaise: { type: Boolean, required: true },
    raiseBlockedReason: { type: String, default: null },
})

const page = usePage()

/*
 * CONFIRMED BEFORE IT POSTS. Raising an invoice numbers it, posts it to the
 * client's receivable and can never be edited — a mistake is a credit note. So
 * the first press states what is about to happen, and the second does it.
 */
const confirming = ref(false)
const raising = ref(false)

const raiseTitle = () => {
    if (!props.canRaise) {
        return 'Raising an invoice changes what a client owes, so it needs Billing update access. You are able to read this preview.'
    }

    return props.raiseBlockedReason ?? `Raise ${props.projection.period} for ${props.projection.estate}. You confirm before it posts.`
}

const raise = () => {
    raising.value = true
    router.post(props.raiseHref, {}, { onFinish: () => { raising.value = false } })
}
</script>

<template>
    <Head :title="`Preview — ${projection.estate}`" />

    <GeminiConsole title="Invoice preview">
        <template #lead>
            <Link href="/billing" class="pv-back" title="Back to billing" aria-label="Back to billing">
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
            <button
                type="button"
                class="btn-primary-sm"
                :disabled="!canRaise || raiseBlockedReason !== null"
                :title="raiseTitle()"
                @click="confirming = !confirming"
            >
                <span>Raise this invoice</span>
            </button>
        </template>

        <div v-if="confirming && canRaise && raiseBlockedReason === null" class="pv-confirm">
            <div>
                Raise {{ projection.estate }}'s invoice for {{ projection.period }} at {{ bill.total.replace('/mo', '') }}?
                It is numbered, posted to the client's account and never edited — a mistake is corrected by a credit
                note. It is not emailed until you send it.
            </div>
            <div class="pv-confirm-actions">
                <button type="button" class="text-link-sm" @click="confirming = false">Cancel</button>
                <button type="button" class="btn-primary-sm" :disabled="raising" @click="raise">
                    <span>{{ raising ? 'Raising…' : 'Raise and post' }}</span>
                </button>
            </div>
        </div>

        <div v-if="page.props.errors?.invoice" class="pv-error">{{ page.props.errors.invoice }}</div>

        <div class="warn-banner">
            <BoardIcon name="warning" :stroke="1.7" />
            <div>
                <div class="wb1">A projection, not an invoice</div>
                <div class="wb2">
                    {{ projection.estate }} has not been invoiced for {{ projection.period }}. This is what that invoice
                    would carry at the rates and line items in force today. It has no number and nothing is owed on it.
                </div>
            </div>
        </div>

        <div class="pv-card">
            <div class="pv-top">
                <div>
                    <div class="pv-name">{{ projection.estate }}</div>
                    <div class="pv-sub">{{ projection.period }} · expected due {{ projection.due_on }}</div>
                </div>
                <div class="pv-pill">Not yet invoiced</div>
            </div>

            <div class="pv-section">{{ bill.baseHead }}</div>
            <div v-for="(row, i) in bill.base" :key="'b' + i" class="line-item-row">
                <div class="li-desc">
                    <div class="li1">{{ row.name }}</div>
                    <div class="li2">{{ row.detail }}</div>
                </div>
                <div class="li-amt">{{ row.price }}</div>
            </div>

            <template v-if="bill.additions.length">
                <div class="pv-section">Additions for this client</div>
                <div v-for="(row, i) in bill.additions" :key="'a' + i" class="line-item-row">
                    <div class="li-desc">
                        <div class="li1">{{ row.name }}</div>
                        <div class="li2">{{ row.detail }}</div>
                    </div>
                    <div class="li-amt">{{ row.price }}</div>
                </div>
            </template>

            <template v-if="bill.removals.length">
                <div class="pv-section">Removals for this client</div>
                <div v-for="(row, i) in bill.removals" :key="'r' + i" class="line-item-row">
                    <div class="li-desc">
                        <div class="li1">{{ row.name }}</div>
                        <div class="li2">{{ row.detail }}</div>
                    </div>
                    <div class="li-amt">−{{ row.price }}</div>
                </div>
            </template>

            <div class="line-total">
                <span>Projected monthly total</span>
                <span>{{ bill.total }}</span>
            </div>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/*
 * AUTHORED around the invoice sheet's own line rows and warning banner. Kept to
 * the tokens the Gemini boards define.
 */
button.btn-primary-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.btn-primary-sm[disabled] {
    cursor: not-allowed;
    opacity: 0.55;
}

button.text-link-sm {
    background: none;
    border: 0;
    font-family: inherit;
    line-height: inherit;
    cursor: pointer;
}

.pv-confirm {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    max-width: 820px;
    margin-bottom: 12px;
    padding: 13px 16px;
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 14px;
    font-size: 12px;
    line-height: 1.55;
    color: var(--navy-800);
}

.pv-confirm-actions {
    display: flex;
    gap: 10px;
    flex: 0 0 auto;
    align-items: center;
}

.pv-error {
    max-width: 820px;
    margin-bottom: 12px;
    font-size: 12px;
    color: var(--red-700);
}

.pv-back {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: var(--navy-100);
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
}

.pv-back svg {
    width: 16px;
    height: 16px;
    color: var(--navy-700);
}

.pv-card {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 18px 20px;
    margin-top: 14px;
    max-width: 820px;
}

.pv-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
}

.pv-name {
    font-size: 15px;
    font-weight: 700;
    color: var(--navy-900);
}

.pv-sub {
    font-size: 11.5px;
    color: var(--slate-500);
}

.pv-pill {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--amber-700);
    background: var(--amber-100);
    padding: 5px 11px;
    border-radius: 20px;
}

.pv-section {
    font-size: 11px;
    font-weight: 700;
    color: var(--slate-600);
    margin: 14px 0 4px;
}
</style>
