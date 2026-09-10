<script setup>
import { computed } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import SettingsTabs from './SettingsTabs.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'

/**
 * Client line items — board screen super-admin-44.
 *
 * ONE CLIENT'S EXCEPTIONS, not the template. Screen 43 changes what a whole
 * tier includes; this records what a single client has above or below theirs.
 * The two are deliberately separate screens because the blast radius is
 * different: a template row reprices everyone on that tier, a line item
 * reprices one estate.
 *
 * Three sections, in the board's order, each with its own badge:
 *
 *   base       what the tier already includes, and the per-guard add-on
 *   additions  a feature granted above the tier, with who granted it and when
 *   removals   a base-plan feature the client asked to drop
 *
 * The additions and removals carry a PROVENANCE line — "added Sep 3 by Damian
 * Reid ahead of their upgrade conversation". That is not decoration: a
 * negotiated price nobody can account for is the thing that turns into a
 * dispute, and the board draws it because someone has to be able to answer
 * "why is this client paying this".
 *
 * NOTHING HERE WRITES. Adding or removing a line item changes what a client is
 * billed; it is a privileged, audited write with an effective date, and the
 * board's own controls are rendered inert saying so.
 */
const props = defineProps({
    tabs: { type: Array, required: true },
    clients: { type: Array, required: true },
    selected: { type: String, default: null },
    billing: { type: Object, default: null },
    writeDisabledReason: { type: String, required: true },
})

const rowCount = computed(
    () =>
        (props.billing?.base?.length ?? 0) +
        (props.billing?.additions?.length ?? 0) +
        (props.billing?.removals?.length ?? 0)
)

const state = useScreenState({
    rows: () => rowCount.value,
})
</script>

<template>
    <Head title="Client line items" />

    <GeminiConsole title="Platform settings">
        <SettingsTabs :tabs="tabs" />

        <!--
          The board draws every client as a chip and one as active. These are
          real links: the screen is per-client and the client is in the URL, so
          a chip that could not be opened in a new tab or bookmarked would be a
          filter pretending to be navigation.
        -->
        <div class="client-picker">
            <Link
                v-for="client in clients"
                :key="client.id"
                :href="client.href"
                class="client-picker-item"
                :class="{ active: client.active }"
            >{{ client.name }}</Link>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="6" :columns="3" />

        <!--
          No plan, or no client at all. Two different facts, and the second one
          is not an error: a platform with no clients yet has nothing to price.
        -->
        <EmptyState
            v-else-if="billing === null"
            variant="first-use"
            :title="clients.length === 0 ? 'No clients yet' : 'This client is not on a plan'"
            :body="
                clients.length === 0
                    ? 'Line items sit on top of a subscription. They can be recorded once a client is onboarded and placed on a tier.'
                    : 'Line items are priced against a base plan. Put this client on a tier first, and any additions or removals can be recorded here.'
            "
        />

        <div v-else class="invoice-panel">
            <div class="invoice-section-head">{{ billing.baseHead }}</div>

            <div v-for="(row, i) in billing.base" :key="'base-' + i" class="invoice-row">
                <div class="ir-txt">
                    <div class="ir1">{{ row.name }}</div>
                    <div class="ir2">{{ row.detail }}</div>
                </div>
                <div class="line-badge base">{{ row.badgeLabel }}</div>
                <div class="ir-price">{{ row.price }}</div>
            </div>

            <div class="invoice-section-head">Custom additions — single features added above their tier</div>

            <div v-for="(row, i) in billing.additions" :key="'add-' + i" class="invoice-row">
                <div class="ir-txt">
                    <div class="ir1">{{ row.name }}</div>
                    <div class="ir2">{{ row.detail }}</div>
                </div>
                <div class="line-badge added">{{ row.badgeLabel }}</div>
                <div class="ir-price">{{ row.price }}</div>
                <button type="button" class="remove-x" disabled :title="writeDisabledReason">
                    <BoardIcon name="close" :stroke="2.2" />
                </button>
            </div>

            <button type="button" class="add-feature-row" disabled :title="writeDisabledReason">
                <BoardIcon name="plus" :stroke="2" />
                <span>Add a single feature to this client's subscription</span>
            </button>

            <div class="invoice-section-head">Custom removals — base-plan features removed for this client</div>

            <div v-for="(row, i) in billing.removals" :key="'rem-' + i" class="invoice-row">
                <div class="ir-txt">
                    <div class="ir1">{{ row.name }}</div>
                    <div class="ir2">{{ row.detail }}</div>
                </div>
                <div class="line-badge removed">{{ row.badgeLabel }}</div>
                <div class="ir-price">{{ row.price }}</div>
                <!--
                  A removal's control RESTORES rather than removes, so the board
                  draws a tick on a tinted ground instead of a cross. Both
                  inline styles are the board's own.
                -->
                <button
                    type="button"
                    class="remove-x"
                    style="background: var(--navy-100)"
                    disabled
                    :title="writeDisabledReason"
                >
                    <BoardIcon name="restore" :stroke="2" />
                </button>
            </div>

            <div class="total-strip">
                <div class="ts1">Total monthly subscription — {{ billing.client }}</div>
                <div class="ts2">{{ billing.total }}</div>
            </div>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/*
 * Default-removal only.
 *
 * The board draws the client chips as <div>, and the add/remove controls as
 * <div> too. They are a link and buttons here so they can be reached by
 * keyboard and announced, and each arrives with browser chrome that would
 * change the pixels. The board's own .client-picker-item, .remove-x and
 * .add-feature-row rules supply everything visual.
 */
a.client-picker-item {
    text-decoration: none;
}

button.remove-x,
button.add-feature-row {
    border: 0;
    font: inherit;
    color: inherit;
    cursor: pointer;
}

/* .add-feature-row is a full-width row in the board; a <button> shrinks to
 * its content and centres its text. */
button.add-feature-row {
    width: 100%;
    text-align: left;
}

button.remove-x[disabled],
button.add-feature-row[disabled] {
    cursor: not-allowed;
}
</style>
