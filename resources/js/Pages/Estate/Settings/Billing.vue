<script setup>
import { Head, Link } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * Billing & subscription — board screen community-admin-40.
 *
 * EVERY FIGURE IS READ FROM THE CENTRAL SUBSCRIPTION, NOT COPIED INTO THE
 * ESTATE. A second copy of a plan price is a second number that can disagree
 * with the one Gemini actually bills against — and the estate's screen is the
 * one a treasurer would trust, so the drift would be invisible until an invoice
 * arrived for a different figure. `Settings::billingBoard()` reaches the central
 * connection explicitly, the same way permissions do under D-012.
 *
 * NOTHING ON THIS SCREEN IS EDITABLE, and that is a boundary rather than a gap.
 * An estate changing its own plan from its own console is not a settings screen,
 * it is a discount button. Board 40 draws no control that would, and no route
 * exists.
 *
 * THE SECOND PANEL IS THE POINT OF THE SCREEN. Guard staffing is a separate
 * contract with its own invoices, and those live in Accounting as vendor bills —
 * board 40 says so in the estate's own words, and the amounts here are the
 * SOFTWARE subscription only. `billingInvoiceRow()` sums the subscription lines
 * and never the guard add-on line beside them, which is what keeps the two
 * relationships from being read as one bill.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    sections: { type: Array, required: true },
    subscription: { type: Object, default: null },
    invoices: { type: Array, required: true },
    reasons: { type: Object, required: true },
})

/*
 * Board 40 is drawn in the employees-details-and-billing sheet beside boards 37
 * and 38, not in the settings sheet its six siblings use. Checked against the
 * sheet that defines `.hero-card` and `.info-panel` rather than assumed from the
 * module.
 */
useWireframe('community-admin-10-payroll-employees-details-and-billing')

/**
 * An estate with no subscription is a real state, not an error.
 *
 * Ocean View is mid-onboarding on every board that draws it: the plan is agreed
 * at the end of onboarding, so a client can legitimately reach this screen
 * before there is anything to show. `empty` is that, and the copy says which.
 */
const state = useScreenState({
    rows: () => (props.subscription === null ? 0 : 1),
})
</script>

<template>
    <Head title="Billing &amp; subscription" />

    <EstateConsole title="Billing &amp; subscription" :estate-name="estate.name" active="settings">
        <SkeletonRows v-if="state.isLoading.value" :rows="3" :columns="4" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Settings is not part of your role’s access"
            body="What this estate pays for the platform is a commercial fact about the community, so it opens only to a role that holds Settings."
        />

        <div v-else class="settings-layout">
            <div class="settings-nav">
                <template v-for="section in sections" :key="section.key">
                    <div v-if="section.active" class="settings-nav-item active" aria-current="page">
                        {{ section.label }}
                    </div>
                    <Link v-else :href="section.href" class="settings-nav-item">
                        {{ section.label }}
                    </Link>
                </template>
            </div>

            <div>
                <EmptyState
                    v-if="state.isEmpty.value"
                    variant="first-use"
                    title="No plan has been activated yet"
                    body="A subscription is agreed at the end of onboarding, so there is nothing to bill against until Gemini activates this estate's plan. Nothing has been charged."
                />

                <template v-else>
                    <div class="hero-card">
                        <div class="hero-top">
                            <div>
                                <div class="hero-name">{{ subscription.plan_name }}</div>
                                <div class="hero-sub">
                                    {{ subscription.unit_count }} units &middot; {{ subscription.billing_cadence }}
                                </div>
                            </div>
                        </div>
                        <div class="hero-stats">
                            <div class="hero-stat">
                                <div class="hs-v">{{ subscription.price_per_unit }}</div>
                                <div class="hs-l">Per unit / mo</div>
                            </div>
                            <div class="hero-stat">
                                <div class="hs-v">{{ subscription.monthly_total }}</div>
                                <div class="hs-l">Subscription / mo</div>
                            </div>
                            <div class="hero-stat">
                                <div class="hs-v">{{ subscription.next_invoice }}</div>
                                <div class="hs-l">Next invoice</div>
                            </div>
                            <div class="hero-stat">
                                <div class="hs-v">{{ subscription.contract_renewal }}</div>
                                <div class="hs-l">Contract renewal</div>
                            </div>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 16px">
                        <div class="info-panel">
                            <div class="info-panel-head">What’s included on {{ subscription.plan_name.split(' ')[0] }}</div>
                            <div class="cl-txt">{{ subscription.included_blurb }}</div>
                        </div>
                        <div class="info-panel">
                            <div class="info-panel-head">Security services — separate contract</div>
                            <div class="cl-txt">{{ subscription.security_services_note }}</div>
                        </div>
                    </div>

                    <table class="data-table" style="margin-top: 16px">
                        <thead>
                            <tr>
                                <th>Invoice</th>
                                <th>Period</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="invoice in invoices" :key="invoice.number">
                                <td>{{ invoice.number }}</td>
                                <td>{{ invoice.period }}</td>
                                <td class="num-cell">{{ invoice.amount }}</td>
                                <td>
                                    <div class="status-badge active">{{ invoice.status_label }}</div>
                                </td>
                                <td>
                                    <!--
                                      Inert, with the reason. An invoice PDF is
                                      Gemini's document rather than the estate's
                                      to render, and the Gemini console already
                                      draws it on board super-admin-33 — so this
                                      says so instead of drawing a link to a page
                                      that does not exist on this side.
                                    -->
                                    <button type="button" class="text-link-sm" disabled :title="reasons.view_invoice">
                                        View
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </template>
            </div>
        </div>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws its seven nav items and its three row
 * links as <div>s; here they are anchors and buttons, which arrive with a
 * border, buttonface grey, the browser's own font and an underline.
 */
button.settings-nav-item {
    border: 0;
    background: none;
    font: inherit;
    width: 100%;
    text-align: left;
    cursor: not-allowed;
}

a.settings-nav-item {
    text-decoration: none;
    display: block;
}

button.text-link-sm {
    border: 0;
    background: none;
    padding: 0;
    font: inherit;
    cursor: not-allowed;
}
</style>
