<script setup>
import { computed } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SourceBadge from '../../../Components/SourceBadge.vue'
import { useLifeSafetyPoll } from '../../../composables/useLifeSafetyPoll.js'

const props = defineProps({
    alerts: { type: Array, required: true },
    anyReal: { type: Boolean, required: true },
})

/**
 * A dispatcher must never need to refresh to see a panic.
 *
 * Stopgap until WebSockets are live (D-027). Only `alerts` is re-fetched, so
 * scroll position and focus survive.
 */
const { lastUpdated, polling, refresh } = useLifeSafetyPoll(['alerts', 'anyReal'], 3000)

const updatedAt = computed(() =>
    lastUpdated.value.toLocaleTimeString(undefined, { hour12: false }),
)

const urgentCount = computed(
    () => props.alerts.filter((a) => a.kind === 'panic' || a.kind === 'duress').length,
)
</script>

<template>
    <Head title="Active alerts" />

    <GeminiConsole title="Active alerts">
        <div class="alerts-head">
            <p class="alerts-lede">
                Panic and duress first, then medical, then everything else. Ordered by what is
                being asked for rather than by when it arrived, because the newest alert is not
                always the most urgent one.
            </p>
            <SourceBadge source="guard" :simulated="!anyReal" />
        </div>

        <!--
          Live status. Stated on the screen rather than assumed, because a
          dispatcher needs to know whether what they are looking at is current
          — and needs to notice immediately if it stops being so.
        -->
        <div class="live-bar" :class="{ 'live-bar--stopped': !polling }">
            <span class="live-dot" :class="{ 'live-dot--stopped': !polling }" />
            <span v-if="polling">Live &mdash; checking every 3 seconds. Last update {{ updatedAt }}.</span>
            <span v-else>Paused. This queue is not updating.</span>
            <button type="button" class="live-refresh" @click="refresh">Refresh now</button>
            <span v-if="urgentCount > 0" class="live-urgent">
                {{ urgentCount }} panic or duress outstanding
            </span>
        </div>

        <EmptyState
            v-if="alerts.length === 0"
            variant="first-use"
            title="No active alerts"
            body="Panic and duress alerts appear here the moment a device raises one, ahead of everything else in the queue. An empty queue means nothing is outstanding — resolved alerts move to the incident log rather than disappearing."
        />

        <table v-else class="data-table">
            <thead>
                <tr>
                    <th>Raised</th>
                    <th>Type</th>
                    <th>From</th>
                    <th>Client</th>
                    <th>Unit</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="alert in alerts" :key="alert.id" :class="{ urgent: alert.kind === 'panic' || alert.kind === 'duress' }">
                    <td class="cell-mono">{{ alert.server_time }}</td>
                    <td class="cell-strong">{{ alert.kind_label }}</td>
                    <td>{{ alert.raised_by }}</td>
                    <td>{{ alert.estate }}</td>
                    <td class="cell-mono">{{ alert.unit ?? '—' }}</td>
                    <td><span class="status-badge" :class="alert.status_badge">{{ alert.status }}</span></td>
                    <td>
                        <div class="row-flags">
                            <!--
                              Device-time disagreement and offline capture are
                              shown, never silently reconciled. A device with a
                              wrong clock is a fact worth surfacing.
                            -->
                            <span v-if="alert.captured_offline" class="flag">Offline</span>
                            <span v-if="alert.clock_skewed" class="flag">Clock skew</span>
                            <span v-if="alert.is_simulated" class="flag flag--sim">Simulated</span>
                            <Link :href="`/dispatch/alerts/${alert.id}`" class="text-link-sm">Open alert</Link>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </GeminiConsole>
</template>

<style scoped>
.alerts-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 24px;
    margin-bottom: 16px;
}

.alerts-lede {
    font-size: 12.5px;
    color: var(--slate-600);
    line-height: 1.6;
    max-width: 620px;
}

/* --- Live status ------------------------------------------------------ */

.live-bar {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 11.5px;
    color: var(--slate-600);
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 100px;
    padding: 7px 14px;
    margin-bottom: 16px;
}

/*
 * Amber when stopped, not red. A paused queue is a caveat the dispatcher must
 * notice, but red is reserved for a denial or a fault, and this is neither.
 */
.live-bar--stopped {
    background: var(--amber-100);
    border-color: var(--amber-500);
    color: var(--amber-700);
    font-weight: 600;
}

.live-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--success-600);
    flex: 0 0 auto;
    animation: live-pulse 3s ease-in-out infinite;
}

.live-dot--stopped {
    background: var(--amber-700);
    animation: none;
}

/* One pulse per poll, so the cadence is visible rather than claimed. */
@keyframes live-pulse {
    0%,
    100% {
        opacity: 1;
    }
    50% {
        opacity: 0.25;
    }
}

@media (prefers-reduced-motion: reduce) {
    .live-dot {
        animation: none;
    }
}

.live-refresh {
    border: none;
    background: transparent;
    padding: 0;
    font-family: 'Inter', sans-serif;
    font-size: 11.5px;
    font-weight: 700;
    color: var(--navy-600);
    cursor: pointer;
}

.live-urgent {
    margin-left: auto;
    font-weight: 700;
    color: var(--red-700);
}

.urgent td:first-child {
    box-shadow: inset 3px 0 0 var(--red-700);
}

.row-flags {
    display: flex;
    gap: 6px;
    justify-content: flex-end;
}

.flag {
    font-size: 9.5px;
    font-weight: 700;
    color: var(--slate-600);
    background: var(--navy-100);
    border-radius: 20px;
    padding: 3px 8px;
    white-space: nowrap;
}

.flag--sim {
    color: var(--amber-700);
    background: var(--amber-100);
}
</style>
