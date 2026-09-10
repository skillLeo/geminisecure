<script setup>
import { Head } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import SettingsTabs from './SettingsTabs.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'

/**
 * Subscription package builder — board screen super-admin-43.
 *
 * THE TEMPLATE, NOT A CLIENT. The warning banner the board draws is the whole
 * point of the screen: a row here changes what every current and future client
 * on that tier receives. One client's exceptions are screen 44, and the banner
 * says so in the board's own words.
 *
 * Three kinds of cell, and the difference between two of them matters:
 *
 *   locked  a core feature. In every package, and no package may drop it, so it
 *           is drawn as a FACT rather than as a setting that happens to be on.
 *           Rendering it as an ordinary tick would invite someone to reach for
 *           a switch that must not exist.
 *   on/off  a real per-tier inclusion
 *   value   a tier-graded value ("Logo only", "Full theme") rather than a yes
 *
 * NOTHING HERE WRITES. The save control is inert and says why: repricing what a
 * tier includes is a privileged, audited write with an effective date, and it
 * does not get built as a side effect of the screen that displays it.
 *
 * The board's table is drawn at 1440x1140. The emphasis on the top tier's
 * column comes from the plan ordering, not from the name "Premium", so renaming
 * or adding a tier above it moves the emphasis with it.
 */
const props = defineProps({
    tabs: { type: Array, required: true },
    plans: { type: Array, required: true },
    features: { type: Array, required: true },
    saveDisabledReason: { type: String, required: true },
})

const state = useScreenState({
    rows: () => props.features.length,
})
</script>

<template>
    <Head title="Package builder" />

    <GeminiConsole title="Platform settings">
        <template #actions>
            <!--
              The board draws a save control on this screen. It is inert: see
              the class docblock. Never a silent click.
            -->
            <button type="button" class="btn-primary-sm" disabled :title="saveDisabledReason">
                <BoardIcon name="check" :stroke="2" />
                <span>Save package changes</span>
            </button>
        </template>

        <SettingsTabs :tabs="tabs" />

        <div class="warn-banner">
            <BoardIcon name="warning" :stroke="1.7" />
            <div>
                <div class="wb1">This edits the template, not one client</div>
                <div class="wb2">
                    Toggling a row here changes what's included by default for every current and future client on
                    that tier. For one-off changes to a single client, use Client Line Items instead.
                </div>
            </div>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="8" :columns="4" />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="No package features defined"
            body="A tier's contents are built from the platform's feature catalogue. Until one exists there is nothing to include or exclude."
        />

        <table v-else class="pkg-table">
            <thead>
                <tr>
                    <th>Feature</th>
                    <th v-for="plan in plans" :key="plan.id" :class="{ 'premium-col': plan.emphasised }">
                        {{ plan.name }}
                        <br />
                        <span style="font-weight: 500">{{ plan.price }}</span>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="feature in features" :key="feature.key">
                    <td>
                        <div class="pkg-feat-name">{{ feature.label }}</div>
                        <div v-if="feature.sub" class="pkg-feat-sub">{{ feature.sub }}</div>
                    </td>
                    <td v-for="(cell, i) in feature.cells" :key="i">
                        <!--
                          A tier-graded value, not a tick. The board tints the
                          top tier's tag; that emphasis is carried on the cell
                          rather than recomputed here.
                        -->
                        <div
                            v-if="cell.variant === 'value'"
                            class="branding-tag"
                            :style="cell.emphasised ? 'color:var(--navy-600);' : undefined"
                        >
                            {{ cell.value }}
                        </div>

                        <div v-else class="pkg-check" :class="cell.variant">
                            <BoardIcon v-if="cell.variant !== 'off'" name="check" :stroke="3" />
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </GeminiConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws its save control as a <div>; as a real
 * <button> it arrives with a border, a system font and buttonface grey.
 * .btn-primary-sm supplies everything else.
 */
button.btn-primary-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.btn-primary-sm[disabled] {
    cursor: not-allowed;
}
</style>
