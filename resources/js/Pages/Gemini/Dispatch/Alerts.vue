<script setup>
import { computed } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import SourceBadge from '../../../Components/SourceBadge.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import { useLiveDispatch } from '../../../composables/useLiveDispatch.js'

/**
 * Active alerts queue — board screen super-admin-14.
 *
 * DOM and class names are the board's. The inline style on the rows' container
 * is the board's too, kept inline rather than promoted to a class, because the
 * board's stylesheet defines none for it and inventing one would be authoring
 * CSS the design does not have.
 *
 * The board draws each row and each tab as a <div>. Here they are links and
 * buttons, because a queue whose rows cannot be opened is a picture of a queue.
 */
const props = defineProps({
    /** Dispatch's own sections. Only Alerts is built; the rest say so. */
    sections: { type: Array, required: true },
    /** The queue's filter tabs. Filtering happens in SQL, not here. */
    tabs: { type: Array, required: true },
    tab: { type: String, required: true },
    alerts: { type: Array, required: true },
    /** The estates whose alert channel this screen may listen on. */
    estateIds: { type: Array, default: () => [] },
    /**
     * Whether the alerts in this queue came from real handsets or from the
     * simulator. See App\Support\SourceBadge.
     */
    sourceBadge: { type: Object, required: true },
})

/**
 * A dispatcher must never need to refresh to see a panic.
 *
 * THIS SCREEN WAS THE LAST ONE IN DISPATCH WITHOUT A SOCKET, which was the
 * wrong way round: the live map and the alertness board both pushed, and the one
 * screen whose entire job is to show a panic the moment it arrives sat on a
 * three-second poll it inherited from before Reverb was installed (D-027, since
 * superseded by D-032).
 *
 * It pushes now, and it still polls — at thirty seconds while the socket is up,
 * back to three the instant it is not. The poll is what would notice a socket
 * that had quietly stopped delivering, so it cannot be the thing the socket
 * switches off.
 *
 * Only `alerts` is re-fetched, so the scroll position and the open tab survive —
 * the tab because it lives in the URL, which the partial reload replays.
 */
useLiveDispatch({
    only: ['alerts'],
    intervalMs: 3000,
    estateIds: props.estateIds,
})

const filtered = computed(() => props.tab !== 'all')
</script>

<template>
    <Head title="Dispatch — active alerts" />

    <GeminiConsole title="Dispatch — active alerts">
            <template #byline>
                <SourceBadge v-bind="sourceBadge" />
            </template>

        <div class="subnav">
            <template v-for="section in sections" :key="section.label">
                <Link v-if="section.href" :href="section.href" class="subnav-item" :class="{ active: section.active }">
                    {{ section.label }}
                </Link>
                <!--
                  Disabled and captioned, never silently inert. A dispatcher who
                  was told the live map exists should see where it will be and
                  read why it is not there yet.
                -->
                <button v-else type="button" class="subnav-item" disabled :title="section.reason">
                    {{ section.label }}
                </button>
            </template>
        </div>

        <div class="subnav">
            <Link
                v-for="filter in tabs"
                :key="filter.label"
                :href="filter.href"
                class="subnav-item"
                :class="{ active: filter.active }"
            >
                {{ filter.label }}
            </Link>
        </div>

        <EmptyState
            v-if="alerts.length === 0 && filtered"
            variant="filtered"
            title="Nothing in this tab"
            body="Alerts exist, but none of them match this filter right now. The queue is ordered by what is being asked for rather than by when it arrived, so an empty tab here does not mean an empty queue."
            action-label="Show all alerts"
            @action="router.visit('/dispatch/alerts')"
        />

        <EmptyState
            v-else-if="alerts.length === 0"
            variant="first-use"
            title="No active alerts"
            body="Panic and duress alerts appear here the moment a device raises one, ahead of everything else in the queue. An empty queue means nothing is outstanding — closed alerts move to the Resolved tab rather than disappearing."
        />

        <!-- The board's own inline style, verbatim: its stylesheet defines no
             class for the rows' container. -->
        <div v-else style="background:var(--white);border:1px solid var(--navy-100);border-radius:16px;overflow:hidden;">
            <Link
                v-for="alert in alerts"
                :key="alert.key"
                :href="alert.href"
                class="alert-row"
                :class="{ urgent: alert.urgent }"
            >
                <div class="alert-icon" :class="{ urgent: alert.urgent }">
                    <BoardIcon :name="alert.icon" :stroke="alert.icon_stroke" />
                </div>
                <div class="alert-txt">
                    <div class="al1">
                        {{ alert.title }}
                        <span :class="alert.tag_class">{{ alert.tag }}</span>
                    </div>
                    <!--
                      Offline capture, a disagreeing device clock and simulated
                      origin all appear in this line. Each changes how far the
                      timestamp beside it can be trusted, and none of them is
                      quietly reconciled away.
                    -->
                    <div class="al2">{{ alert.meta }}</div>
                </div>
                <div class="alert-status" :class="alert.status_class">{{ alert.status }}</div>
            </Link>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/*
 * The only authored CSS on this screen, and every rule below REMOVES a browser
 * default rather than adding a style.
 *
 * The board draws the tabs and the rows as <div>s, which carry no default
 * chrome. They are a <button> and an <a> here so they can be operated, and the
 * browser's own styling for those elements — an anchor's underline, a button's
 * border, background and system font — would otherwise show through and change
 * the pixels the board specifies.
 */
.subnav-item,
.alert-row {
    text-decoration: none;
}

/* The row's children each set their own colour; this only stops an anchor's
 * default link blue reaching anything they miss. */
.alert-row {
    color: inherit;
}

.subnav-item {
    -webkit-appearance: none;
    appearance: none;
    border: 0;
    font-family: inherit;
    cursor: pointer;
}

/*
 * Written as :not(.active) on purpose.
 *
 * A plain `.subnav-item { background: none }` would carry the same specificity
 * as the board's `.subnav-item.active`, and which of the two won would then
 * depend on the order the bundler happened to emit them in — the active tab's
 * white pill would vanish on a build where this file landed second. The
 * non-active tabs are the only ones that need the button face removed, so this
 * says so and never touches the active rule.
 */
.subnav-item:not(.active) {
    background: none;
}

/* Matches the shell's treatment of a control that is deliberately not ready.
 * Changes the cursor and nothing that occupies space. */
.subnav-item[disabled] {
    cursor: not-allowed;
}
</style>
