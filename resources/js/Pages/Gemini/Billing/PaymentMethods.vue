<script setup>
import { Head } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BillingTabs from './BillingTabs.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'

/**
 * Payment methods — board screen super-admin-35.
 *
 * How each client settles their platform invoices. D-023 makes this necessary
 * rather than decorative: manual recording is the day-one path, and a payment
 * cannot be matched to a client without knowing which account it came from.
 *
 * DRIVEN FROM THE CLIENT LIST, not the methods table. A client with nothing on
 * file is the row that matters most — "Not yet on file · Needed before
 * go-live" — and listing only the methods that exist would silently drop
 * exactly the client an operator needs to chase.
 *
 * NO CARD DATA REACHES THIS SCREEN because none exists to reach it. The schema
 * holds an institution name and four characters; there is no PAN column, no
 * expiry and no token. When card capture arrives it arrives behind the
 * PaymentGateway interface with a gateway-side token, and the token stays with
 * the gateway.
 *
 * Both row controls are inert. Offering to add a card would be offering a
 * capability the platform does not have.
 */
const props = defineProps({
    rows: { type: Array, required: true },
    writeDisabledReason: { type: String, required: true },
})

const state = useScreenState({
    rows: () => props.rows.length,
})
</script>

<template>
    <Head title="Payment methods" />

    <GeminiConsole title="Billing &amp; subscriptions">
        <BillingTabs active="payment-methods" />

        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="5" />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="No clients yet"
            body="Settlement details are recorded per client. They can be entered once an estate is onboarded."
        />

        <table v-else class="data-table">
            <thead>
                <tr>
                    <th>Client</th>
                    <th>Method</th>
                    <th>Details</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in rows" :key="row.id">
                    <td>
                        <div class="client-cell">
                            <div class="client-avatar">{{ row.initials }}</div>
                            <div class="client-name">{{ row.estate }}</div>
                        </div>
                    </td>
                    <td>
                        <div class="method-cell">
                            <div class="method-icon">
                                <BoardIcon name="billing" :stroke="1.6" />
                            </div>
                            {{ row.method }}
                        </div>
                    </td>
                    <td>{{ row.detail }}</td>
                    <td>
                        <div class="status-badge" :class="row.status">{{ row.status_label }}</div>
                    </td>
                    <td>
                        <button type="button" class="text-link-sm" disabled :title="writeDisabledReason">
                            {{ row.action }}
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>
    </GeminiConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws the row action as a <div>; as a real
 * <button> it arrives inline-block on a baseline with a border, a background
 * and Arial. .text-link-sm supplies the colour and weight.
 */
button.text-link-sm {
    display: block;
    border: 0;
    background: transparent;
    font-family: inherit;
    line-height: inherit;
    text-align: inherit;
}

/* Inert, and it says why on hover. Not dimmed — the board draws these at full
 * colour and the diff is measured against it. */
button.text-link-sm[disabled] {
    cursor: not-allowed;
}
</style>
