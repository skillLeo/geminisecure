<script setup>
import { Head } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SourceBadge from '../../../Components/SourceBadge.vue'

defineProps({
    alerts: { type: Array, required: true },
    anyReal: { type: Boolean, required: true },
})
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
