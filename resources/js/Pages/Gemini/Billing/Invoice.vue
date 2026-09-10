<script setup>
import { Head, Link } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'

/**
 * One invoice, taken apart — board screen super-admin-33.
 *
 * THE LINES ARE THE POINT. An invoice carrying only a total is a number nobody
 * can query; a client asking "why is this J$171,000" needs the two lines that
 * make it up, and this screen exists to answer that. Phoenix Park's August bill
 * is 450 units at J$340 plus 4 guards at J$4,500, and the two add to the posted
 * total exactly.
 *
 * A POSTED RECORD. Nothing on this screen edits the invoice and no route
 * behind it could: a correction to a raised invoice is a credit note, which is
 * a new posted record of its own. The two controls the board draws are inert
 * and say why — on a screen about money, a control that looks live and does
 * nothing is worse than anywhere else on the platform.
 *
 * If the lines ever stop adding up to the posted total, the screen SAYS SO
 * rather than showing one and hiding the other. The posted amount is what the
 * client owes and cannot be edited to make the arithmetic work, so a mismatch
 * is a real finding and gets reported as one.
 */
const props = defineProps({
    invoice: { type: Object, required: true },
    writeDisabledReason: { type: String, required: true },
})

const state = useScreenState({
    rows: () => props.invoice.lines.length,
})
</script>

<template>
    <Head :title="`Invoice ${invoice.reference}`" />

    <GeminiConsole :title="`Invoice — ${invoice.period}`">
        <template #lead>
            <Link href="/billing" class="topbar-back" title="Back to billing" aria-label="Back to billing">
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
            <button type="button" class="btn-outline-sm" disabled :title="writeDisabledReason">
                <BoardIcon name="export" :stroke="1.8" />
                <span>Download PDF</span>
            </button>
        </template>

        <div class="invoice-head">
            <div class="invoice-card">
                <div class="inv-top">
                    <div>
                        <!--
                          The client's name links to their record. A reader of
                          an invoice is one question away from wanting the
                          client behind it.
                        -->
                        <Link :href="invoice.clientHref" class="inv-name">{{ invoice.client }}</Link>
                        <div class="inv-sub">{{ invoice.periodLabel }}</div>
                    </div>
                    <!-- The status pill's inline style is the board's own. -->
                    <div
                        v-if="invoice.status === 'paid'"
                        style="
                            font-size: 10.5px;
                            font-weight: 700;
                            color: #16a34a;
                            background: #dcfce7;
                            padding: 5px 11px;
                            border-radius: 20px;
                        "
                    >
                        {{ invoice.statusLabel }}
                    </div>
                    <div
                        v-else
                        style="
                            font-size: 10.5px;
                            font-weight: 700;
                            color: var(--amber-700);
                            background: var(--amber-100);
                            padding: 5px 11px;
                            border-radius: 20px;
                        "
                    >
                        {{ invoice.statusLabel }}
                    </div>
                </div>
                <div class="inv-amount">{{ invoice.amount }}</div>
                <div class="inv-amount-lbl">{{ invoice.settlement }}</div>
            </div>

            <div class="action-stack">
                <button type="button" class="stack-btn outline" disabled :title="writeDisabledReason">
                    <BoardIcon name="broadcast" :stroke="1.7" />
                    <span>Resend to client</span>
                </button>
                <button type="button" class="stack-btn outline" disabled :title="writeDisabledReason">
                    <BoardIcon name="pencil" :stroke="1.6" />
                    <span>Issue credit note</span>
                </button>
            </div>
        </div>

        <div style="background: var(--white); border: 1px solid var(--navy-100); border-radius: 16px; overflow: hidden">
            <SkeletonRows v-if="state.isLoading.value" :rows="3" :columns="2" />

            <!--
              A raised invoice with no lines. Not an error and not empty-because
              -filtered: it is an invoice whose breakdown was never recorded,
              and the posted total still stands.
            -->
            <EmptyState
                v-else-if="state.isEmpty.value"
                variant="first-use"
                title="No line breakdown on this invoice"
                body="The posted total stands and is what the client owes. This invoice was raised without an itemised breakdown, so there is nothing to take apart here."
            />

            <template v-else>
                <div v-for="(line, i) in invoice.lines" :key="i" class="line-item-row">
                    <div class="li-desc">
                        <div class="li1">{{ line.description }}</div>
                        <div class="li2">{{ line.detail }}</div>
                    </div>
                    <div class="li-amt">{{ line.amount }}</div>
                </div>

                <div class="line-total">
                    <span>Total due</span>
                    <span>{{ invoice.amount }}</span>
                </div>
            </template>
        </div>

        <!--
          Shown only when the breakdown disagrees with the posted total. See
          the class docblock: this is a finding, not something to resolve by
          adjusting one of the two numbers.
        -->
        <div v-if="invoice.reconciliation" class="warn-banner">
            <BoardIcon name="warning" :stroke="1.7" />
            <div>
                <div class="wb1">This breakdown does not reconcile</div>
                <div class="wb2">{{ invoice.reconciliation }}</div>
            </div>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/*
 * Default-removal only.
 *
 * The board draws the back chevron, the two stack buttons, the download
 * control and the client name as <div>s. Here they are a link, buttons and a
 * link, so the UA's underline and the button's own border, face and font would
 * show through. .stack-btn, .btn-outline-sm and .inv-name state everything
 * else.
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

a.inv-name {
    text-decoration: none;
    color: inherit;
}

button.stack-btn,
button.btn-outline-sm {
    font: inherit;
    cursor: pointer;
}

/*
 * `border` is deliberately not reset anywhere here. The board gives both
 * .stack-btn.outline and .btn-outline-sm their own borders, and resetting the
 * second out-specified the board and stripped a 1.5px navy outline off the
 * topbar control — a real defect that passed measurement because 1.5px on one
 * small button is well under the threshold.
 */

button.stack-btn[disabled],
button.btn-outline-sm[disabled] {
    cursor: not-allowed;
}
</style>
