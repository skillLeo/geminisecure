<script setup>
import { computed } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import SourceBadge from '../../../Components/SourceBadge.vue'
import { useLifeSafetyPoll } from '../../../composables/useLifeSafetyPoll.js'

const props = defineProps({
    alert: { type: Object, required: true },
})

/**
 * The second life-safety surface. A dispatcher holding this screen open must
 * see the status change under them — a guard acknowledging on the ground, or
 * the alert resolving — without reloading. Stopgap until WebSockets (D-027).
 */
const { lastUpdated, polling, refresh } = useLifeSafetyPoll(['alert'], 3000)

const updatedAt = computed(() =>
    lastUpdated.value.toLocaleTimeString(undefined, { hour12: false }),
)

const isUrgent = computed(() => ['panic', 'duress'].includes(props.alert.kind))
</script>

<template>
    <Head :title="alert.kind_label" />

    <GeminiConsole :title="`${alert.kind_label} — ${alert.estate}`">
        <div class="alert-head">
            <div class="live-bar" :class="{ 'live-bar--stopped': !polling }">
                <span class="live-dot" :class="{ 'live-dot--stopped': !polling }" />
                <span v-if="polling">Live &mdash; last update {{ updatedAt }}</span>
                <span v-else>Paused. This alert is not updating.</span>
                <button type="button" class="live-refresh" @click="refresh">Refresh now</button>
            </div>
            <SourceBadge
                :source="alert.raised_by === 'Unknown' || alert.unit ? 'resident' : 'guard'"
                :simulated="alert.is_simulated"
            />
        </div>

        <div v-if="isUrgent" class="urgent-banner">
            <strong>{{ alert.kind_label }}.</strong>
            A person is asking for help. This outranks everything else in the queue.
        </div>

        <div class="panel">
            <div class="panel-head">
                <h2>Alert detail</h2>
                <Link href="/dispatch/alerts">Back to queue</Link>
            </div>

            <table class="data-table">
                <tbody>
                    <tr>
                        <td class="cell-strong">Type</td>
                        <td>{{ alert.kind_label }}</td>
                    </tr>
                    <tr>
                        <td class="cell-strong">Status</td>
                        <td><span class="status-badge" :class="alert.status_badge">{{ alert.status }}</span></td>
                    </tr>
                    <tr>
                        <td class="cell-strong">Raised by</td>
                        <td>{{ alert.raised_by }}</td>
                    </tr>
                    <tr>
                        <td class="cell-strong">Client</td>
                        <td>{{ alert.estate }}</td>
                    </tr>
                    <tr>
                        <td class="cell-strong">Unit</td>
                        <td class="cell-mono">{{ alert.unit ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="cell-strong">Received</td>
                        <td class="cell-mono">{{ alert.server_time }}</td>
                    </tr>
                    <tr>
                        <td class="cell-strong">Device reported</td>
                        <td class="cell-mono">
                            {{ alert.device_time ?? '—' }}
                            <!--
                              Shown, never silently reconciled. A device with a
                              wrong clock is evidence; correcting it destroys
                              the only record that it happened.
                            -->
                            <span v-if="alert.clock_skewed" class="skew-note">
                                disagrees with server time — recorded as sent, not corrected
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td class="cell-strong">Location</td>
                        <td>
                            <span v-if="alert.has_location" class="cell-mono">
                                {{ alert.latitude }}, {{ alert.longitude }}
                            </span>
                            <span v-else class="muted">
                                Not supplied. Location permission was refused or unavailable —
                                this degrades accuracy and never blocks a panic alert.
                            </span>
                        </td>
                    </tr>
                    <tr v-if="alert.captured_offline">
                        <td class="cell-strong">Capture</td>
                        <td><span class="status-badge pending">Captured offline, synced later</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </GeminiConsole>
</template>

<style scoped>
.alert-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    margin-bottom: 16px;
}

.urgent-banner {
    background: var(--red-100);
    color: var(--red-700);
    border-radius: 12px;
    padding: 13px 16px;
    font-size: 12.5px;
    line-height: 1.55;
    margin-bottom: 16px;
}

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
}

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

.skew-note {
    display: block;
    font-family: 'Inter', sans-serif;
    font-size: 10.5px;
    color: var(--amber-700);
    margin-top: 3px;
}

.muted {
    font-size: 11.5px;
    color: var(--slate-500);
    line-height: 1.5;
}
</style>
