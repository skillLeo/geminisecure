<script setup>
import { Head } from '@inertiajs/vue3'
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
    /** Whether anything is priced but retired — see the note below. */
    hasRetired: { type: Boolean, required: true },
    saveDisabledReason: { type: String, required: true },
})

/*
 * "Filtered" on this screen is `is_active`, which is a real column and a real
 * filter: a retired plan keeps its rows and its history and simply stops being
 * quoted. Empty-because-everything-is-retired is a different screen from
 * empty-because-nothing-was-ever-priced — one says the rate card was emptied,
 * the other that it was never filled — so they are told apart here rather than
 * sharing one blank panel.
 */
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
              The board draws this as a <div>. It is a real <button> because it
              is drawn as a control, and it is disabled because the write behind
              it does not exist and must not be faked: a click that silently did
              nothing to a screen full of prices is the worst version of this.
            -->
            <button type="button" class="btn-primary-sm" disabled :title="saveDisabledReason">
                <span>Save changes</span>
            </button>
        </template>

        <SettingsTabs :tabs="tabs" />

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
</style>
