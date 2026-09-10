<script setup>
import { Head } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import Subnav from './Subnav.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'

/**
 * Standing orders library — board screen super-admin-25.
 *
 * TWO KINDS OF SET, and the difference is the screen. A MASTER TEMPLATE applies
 * to every post at every client and nobody in particular acknowledges it. A
 * POST-SPECIFIC set belongs to one gate and has to be acknowledged by the guard
 * standing it — so an unacknowledged one means a guard working to orders they
 * have not read, which the board marks in red.
 *
 * That red state is COMPUTED, not stored: a set is unacknowledged because no
 * acknowledgement row exists for its current version, and it becomes
 * unacknowledged again the moment the version is bumped. Storing a flag would
 * mean remembering to clear it on every revision, and the revision nobody
 * cleared it on is the one that matters.
 *
 * NOTHING HERE WRITES. Publishing a new order set changes what guards are
 * legally instructed to do at a gate; it wants a review and an acknowledgement
 * cycle before it wants a button.
 */
const props = defineProps({
    sets: { type: Array, required: true },
    writeDisabledReason: { type: String, required: true },
})

const state = useScreenState({
    rows: () => props.sets.length,
})
</script>

<template>
    <Head title="Standing orders" />

    <GeminiConsole title="Standing orders">
        <template #actions>
            <button type="button" class="btn-primary-sm" disabled :title="writeDisabledReason">
                <BoardIcon name="plus" :stroke="2" />
                <span>New order set</span>
            </button>
        </template>

        <Subnav active="standing-orders" />

        <SkeletonRows v-if="state.isLoading.value" :rows="5" :columns="3" />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="No standing orders published"
            body="Standing orders are the written instructions a guard works to at a post. Until a set exists, guards are working to nothing written down."
        />

        <div v-for="set in sets" v-else :key="set.id" class="order-set-card">
            <div class="order-set-icon">
                <BoardIcon :name="set.icon" :stroke="1.7" />
            </div>
            <div class="order-set-info">
                <div class="order-set-name">{{ set.name }}</div>
                <div class="order-set-meta">{{ set.meta }}</div>
            </div>
            <!--
              The board tints the unassigned badge red inline, because its
              stylesheet has no variant for it. Copied verbatim rather than
              given a class of my own.
            -->
            <div
                class="order-set-badge"
                :class="{ template: set.variant !== 'active' }"
                :style="set.variant === 'unassigned' ? 'background:var(--red-100);color:var(--red-700);' : undefined"
            >
                {{ set.badge }}
            </div>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/* Default-removal: the board's action is a <div>; as a <button> it brings a
 * border, a system font and buttonface grey. .btn-primary-sm does the rest. */
button.btn-primary-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.btn-primary-sm[disabled] {
    cursor: not-allowed;
}
</style>
