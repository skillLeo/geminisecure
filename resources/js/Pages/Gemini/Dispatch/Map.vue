<script setup>
import { computed, onMounted, onBeforeUnmount, ref } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import SourceBadge from '../../../Components/SourceBadge.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useLiveDispatch } from '../../../composables/useLiveDispatch.js'

/**
 * Dispatch live map — board screen super-admin-12.
 *
 * The board draws this map BY HAND: absolutely positioned <div>s on a CSS grid
 * background, with the region boxes, the site cards and the pins each carrying
 * their own inline left/top. There is no map library anywhere in it, and none
 * is introduced here — leaflet is installed, and substituting a tile layer for
 * markup the designer drew would replace the design rather than build it.
 *
 * So the inline positions below are the board's own, reproduced verbatim, with
 * real estates, real shifts and real alerts placed into them. What each pin
 * MEANS is the post the guard is standing, which the label says outright; it is
 * not a coordinate, and this platform holds none.
 */
const props = defineProps({
    /** Dispatch's own sections. */
    sections: { type: Array, required: true },
    /** The one alert being asked for right now, or null on a quiet platform. */
    banner: { type: Object, default: null },
    kpis: { type: Array, required: true },
    /** Parish boxes: the board's geometry, this platform's parishes. */
    regions: { type: Array, required: true },
    /** Site cards and the pins around each of them. */
    sites: { type: Array, required: true },
    legend: { type: Array, required: true },
    onDuty: { type: Array, required: true },
    /** The estates whose alert channel this screen may listen on. */
    estateIds: { type: Array, required: true },
    /** Whether this viewer's role narrows the platform to assigned sites. */
    scoped: { type: Boolean, default: false },
    /**
     * Whether the device-originated data on this screen came from a real
     * handset or from the simulator. See App\Support\SourceBadge.
     */
    sourceBadge: { type: Object, required: true },
})

/**
 * THE POLL IS THE GUARANTEE. THE SOCKET IS THE SPEED.
 *
 * One private channel per estate on screen and no others, running ALONGSIDE the
 * poll rather than instead of it: on a screen where a missed panic is a person
 * waiting, quiet-because-nothing-happened and quiet-because-the-socket-dropped
 * must never look the same.
 *
 * Five seconds is the promise this screen's pill makes to a dispatcher whenever
 * the socket is down. With it up, `useLiveDispatch` backs the poll off to a
 * thirty-second heartbeat — still there, still the thing that would notice a
 * socket that had silently stopped, and no longer re-asking the server twelve
 * times a minute for an answer the push has already given.
 */
const { poll, streaming } = useLiveDispatch({
    only: ['banner', 'kpis', 'regions', 'sites', 'onDuty'],
    intervalMs: 5000,
    estateIds: props.estateIds,
})

/*
 * A ticking clock, so "how long since the last refresh" is a live figure rather
 * than one frozen at mount. Without it a poll that silently stopped would leave
 * the pill claiming the screen was live indefinitely.
 */
const tick = ref(Date.now())
let ticker = null

onMounted(() => {
    ticker = setInterval(() => {
        tick.value = Date.now()
    }, 1000)
})

onBeforeUnmount(() => clearInterval(ticker))

/** Four missed intervals. One late response is a slow request; four is a fault. */
const secondsSinceRefresh = computed(() => Math.round((tick.value - poll.lastUpdated.value.getTime()) / 1000))

/*
 * Measured against the rate ACTUALLY IN FORCE, not a fixed twenty seconds.
 * Once the socket backs the poll off to a thirty-second heartbeat, a screen
 * that still called twenty seconds stale would spend most of its life claiming
 * to be broken while working perfectly.
 */
const stale = computed(() => secondsSinceRefresh.value >= (poll.currentIntervalMs.value / 1000) * 4)

/**
 * What the status pill says, and it never says "live" when it is not.
 *
 * The board's wording is the healthy case and it is literally true: this screen
 * re-asks the server every five seconds whether or not the socket is up, so the
 * promise holds on the poll alone. The socket only makes it faster, which is
 * why losing it changes the hover text rather than the headline — and why the
 * poll falling behind changes the headline outright.
 */
const liveLabel = computed(() => {
    if (stale.value) {
        return `Stale · last update ${secondsSinceRefresh.value}s ago`
    }

    // The board's own wording is the socket-down case, and it stays literally
    // true: with no channel this screen really does re-ask every five seconds.
    return streaming.value ? 'Live · alerts push instantly' : 'Live · updates every 5s'
})

const liveReason = computed(() => {
    if (stale.value) {
        return `The refresh has not completed for ${secondsSinceRefresh.value} seconds. Click to retry now.`
    }

    return streaming.value
        ? 'Alerts arrive instantly over the live channel, with a thirty-second refresh behind it so a '
          + 'socket that stops delivering cannot look like a quiet night. Click to refresh now.'
        : 'Refreshing every five seconds. The live alert channel is not connected, so this poll is the '
          + 'only notifier. Click to refresh now.'
})

const state = useScreenState({
    rows: () => props.sites.length,
    filtered: () => props.scoped,
})
</script>

<template>
    <Head title="Dispatch — live map" />

    <GeminiConsole title="Dispatch — live map">
            <template #byline>
                <SourceBadge v-bind="sourceBadge" />
            </template>

        <template #actions>
            <!--
              The board draws this as a <div>. It is a real button because it
              is the one control on the screen that answers "is this thing
              still working" — and the honest answer to that question has to be
              actionable, not decorative.
            -->
            <button type="button" class="btn-outline-sm" :title="liveReason" @click="poll.refresh()">
                <svg viewBox="0 0 24 24" fill="none">
                    <circle cx="6" cy="6" r="4" :fill="stale ? '#64748B' : '#B91C1C'" />
                </svg>
                <span>{{ liveLabel }}</span>
            </button>
        </template>

        <div class="subnav">
            <template v-for="section in sections" :key="section.label">
                <Link v-if="section.href" :href="section.href" class="subnav-item" :class="{ active: section.active }">
                    {{ section.label }}
                </Link>
                <button v-else type="button" class="subnav-item" disabled :title="section.reason">
                    {{ section.label }}
                </button>
            </template>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="9" :columns="4" />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The map could not be drawn"
            body="The roster and the alert queue could not be read just now. Nothing has been lost — alerts are still being received and are still in the queue. This screen retries every five seconds."
            action-label="Retry now"
            @action="poll.refresh()"
        />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Dispatch is not yours to watch"
            body="The live map is limited to roles holding dispatch access. Your role can be granted it from the role access matrix by a platform administrator."
        />

        <EmptyState
            v-else-if="state.isEmptyFiltered.value"
            variant="filtered"
            title="No sites in your assignment"
            body="Your role watches assigned sites rather than the whole platform, and none of the estates assigned to you is provisioned yet. Other sites may be live; they are simply not yours to see."
            action-label="Open the alerts queue"
            @action="router.visit('/dispatch/alerts')"
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="No sites are being monitored yet"
            body="An estate appears on this map from the moment it is provisioned, with a card for the site and a pin for every guard standing a post there. Provision the first client and it will be here."
            action-label="Go to clients"
            @action="router.visit('/clients')"
        />

        <template v-else>
            <!--
              The banner IS the alert. Drawn only when one is open, because a
              red interruption bar that is permanently on screen has stopped
              interrupting anybody.
            -->
            <div v-if="banner" class="live-banner">
                <div class="lb-dot"></div>
                <div>
                    <div class="lb1">{{ banner.title }}</div>
                    <div class="lb2">{{ banner.meta }}</div>
                </div>
                <Link :href="banner.href" class="lb-btn">Open alert</Link>
            </div>

            <div class="kpi-row">
                <div v-for="kpi in kpis" :key="kpi.label" class="kpi-card">
                    <div class="k-top">
                        <div class="kpi-icon" :class="kpi.tone">
                            <BoardIcon :name="kpi.icon" :stroke="kpi.stroke" />
                        </div>
                    </div>
                    <div class="k-val">{{ kpi.value }}</div>
                    <div class="k-lbl">{{ kpi.label }}</div>
                </div>
            </div>

            <div class="map-panel">
                <div class="map-canvas">
                    <template v-for="region in regions" :key="region.key">
                        <div
                            class="map-region"
                            :style="`left:${region.box.left}px;top:${region.box.top}px;width:${region.box.width}px;height:${region.box.height}px;`"
                        ></div>
                        <div
                            class="map-region-label"
                            :style="`left:${region.labelAt.left}px;top:${region.labelAt.top}px;`"
                        >
                            {{ region.label }}
                        </div>
                    </template>

                    <template v-for="site in sites" :key="site.key">
                        <!--
                          The board draws the site card as a <div>, and so does
                          this: opening a client from a dispatch map is not what
                          the card is for, and the pins beside it are the things
                          a dispatcher reaches for. The guard rows in the side
                          panel are the links.
                        -->
                        <div
                            class="site-cluster"
                            :style="`left:${site.cluster.left}px;top:${site.cluster.top}px;`"
                        >
                            <div class="sc-name">{{ site.name }}</div>
                            <div class="sc-tier">{{ site.tier }}</div>
                        </div>

                        <div
                            v-for="pin in site.pins"
                            :key="pin.key"
                            class="pin"
                            :style="`left:${pin.left}px;top:${pin.top}px;`"
                        >
                            <div class="pin-dot" :class="pin.dot"></div>
                            <div :class="pin.labelClass" :style="pin.labelStyle">{{ pin.label }}</div>
                        </div>
                    </template>
                </div>

                <div>
                    <div class="map-legend">
                        <h4>Status</h4>
                        <div v-for="row in legend" :key="row.label" class="legend-row">
                            <div class="legend-dot" :style="`background:${row.colour};`"></div>
                            {{ row.label }}
                        </div>
                    </div>

                    <!--
                      Every guard on duty, whether or not a pin could be drawn
                      for them. The canvas has as many slots as the board drew;
                      this list has no limit, so nobody standing a post can be
                      missing from the screen because the picture ran out of
                      room.
                    -->
                    <div class="map-side-panel">
                        <h4>On duty now</h4>
                        <Link v-for="row in onDuty" :key="row.key" :href="row.href" class="msp-row">
                            <div class="msp-dot" :style="`background:${row.colour};`"></div>
                            <div class="msp-txt">
                                <div class="mt1">{{ row.name }}</div>
                                <div class="mt2">{{ row.where }}</div>
                            </div>
                        </Link>
                    </div>
                </div>
            </div>
        </template>
    </GeminiConsole>
</template>

<style scoped>
/*
 * The only authored CSS on this screen, and every rule REMOVES a browser
 * default rather than adding a style.
 *
 * The board draws the section tabs, the status pill and the "on duty" rows as
 * <div>s, which carry no chrome of their own. Here they are a <button>, a
 * <button> and an <a>, so that they can be operated — and a <button> arrives
 * with the system UI font and a pointer that says "not a control". Nothing
 * below introduces a colour, a size or a spacing the board does not declare.
 */
.subnav-item,
.btn-outline-sm {
    -webkit-appearance: none;
    appearance: none;
    border: 0;
    font-family: inherit;
    cursor: pointer;
}

/*
 * Written as :not(.active) deliberately.
 *
 * A plain `.subnav-item { background: none }` carries the same specificity as
 * the board's own `.subnav-item.active`, so which one won would depend on the
 * order the bundler happened to emit them in — and on the build where this file
 * landed second, the active tab's white pill would vanish. Only the inactive
 * tabs need the button face removed, so only they are named.
 */
.subnav-item:not(.active) {
    background: none;
}

.subnav-item[disabled] {
    cursor: not-allowed;
}

/* The board sets .btn-outline-sm's own border and background; only the UA's
 * border needed removing above, and this puts the board's back. Stated here
 * rather than left to specificity luck for the same reason as the tabs. */
.btn-outline-sm {
    border: 1.5px solid var(--navy-200);
}
</style>
