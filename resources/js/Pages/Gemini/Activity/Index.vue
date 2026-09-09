<script setup>
import { Head, Link } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'

/**
 * Recent activity — board screen super-admin-03.
 *
 * The board's body is one card holding a list of rows and nothing else: no
 * search, no filter chips, no pager. That is reproduced as drawn. The full
 * standing record of who did what is the Access & audit log, which is its own
 * module with its own board and its own permission — this screen is the
 * platform's recent history, and the dashboard panel shows its first rows.
 *
 * The card's wrapper carries an inline style because the board's stylesheet
 * defines no class for it. Copied verbatim rather than given a class of my own.
 */
const props = defineProps({
    events: { type: Array, required: true },
})

const state = useScreenState({
    rows: () => props.events.length,
})
</script>

<template>
    <Head title="Recent activity" />

    <GeminiConsole title="Recent activity">
        <template #lead>
            <Link href="/dashboard" class="topbar-back" title="Back to dashboard" aria-label="Back to dashboard">
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

        <div style="background: var(--white); border: 1px solid var(--navy-100); border-radius: 16px; overflow: hidden">
            <div v-if="state.isLoading.value" style="padding: 8px 18px">
                <SkeletonRows :rows="8" :columns="3" />
            </div>

            <EmptyState
                v-else-if="state.isEmpty.value"
                variant="first-use"
                title="Nothing has happened yet"
                body="Onboarding, billing and deployment events appear here as they happen across every client."
            />

            <template v-else>
                <div v-for="event in events" :key="event.key" class="activity-row2">
                    <div class="a-icon2">
                        <BoardIcon :name="event.icon" :stroke="1.6" />
                    </div>
                    <div class="a-txt2">
                        <div class="at1">{{ event.title }}</div>
                        <div class="at2">{{ event.meta }}</div>
                    </div>
                    <div class="a-time">{{ event.when }}</div>
                </div>
            </template>
        </div>
    </GeminiConsole>
</template>

<style scoped>
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
