<script setup>
import { Head, Link } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'

defineProps({
    guards: { type: Array, required: true },
    warningDays: { type: Number, required: true },
})

/**
 * Licence state drives the badge, not employment status.
 *
 * A guard can be Active and hold an expired licence — that combination is
 * exactly what this screen exists to surface, so the two must not be collapsed
 * into one pill.
 */
const badgeFor = (state) => (state === 'expired' ? 'expired' : state === 'expiring' ? 'soon' : 'ok')
const labelFor = (state) =>
    state === 'expired' ? 'Expired' : state === 'expiring' ? 'Expiring soon' : 'Unknown'
</script>

<template>
    <Head title="PSRA compliance" />

    <GeminiConsole title="PSRA compliance">
        <div class="compliance-lede">
            Licences that have lapsed or expire within {{ warningDays }} days. A guard may hold
            an active posting and an expired licence at the same time; that is the case this
            screen exists to catch.
        </div>

        <EmptyState
            v-if="guards.length === 0"
            variant="first-use"
            title="Every licence is current"
            body="No guard on your roster has a PSRA licence that has lapsed or expires within the next 30 days. This screen fills as renewal dates approach."
        />

        <table v-else class="data-table">
            <thead>
                <tr>
                    <th>Guard</th>
                    <th>PSRA number</th>
                    <th>Client</th>
                    <th>Expires</th>
                    <th>Licence</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="guard in guards" :key="guard.id">
                    <td>
                        <div class="res-cell">
                            <div class="res-avatar">{{ guard.initials }}</div>
                            <div>
                                <div class="res-name">{{ guard.name }}</div>
                                <div class="res-sub">{{ guard.post }}</div>
                            </div>
                        </div>
                    </td>
                    <td class="cell-mono">{{ guard.psra_number }}</td>
                    <td>{{ guard.estate }}</td>
                    <td class="cell-mono">{{ guard.expires_on ?? '—' }}</td>
                    <td>
                        <span class="status-badge" :class="badgeFor(guard.licence_state)">
                            {{ labelFor(guard.licence_state) }}
                        </span>
                    </td>
                    <td><Link :href="`/guards/${guard.id}`" class="text-link-sm">Take action</Link></td>
                </tr>
            </tbody>
        </table>
    </GeminiConsole>
</template>

<style scoped>
.compliance-lede {
    font-size: 12.5px;
    color: var(--slate-600);
    line-height: 1.6;
    max-width: 720px;
    margin-bottom: 16px;
}
</style>
