<script setup>
import { Head } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import Subnav from './Subnav.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'

/**
 * Security incident log — board screen super-admin-27.
 *
 * The record a security company is judged on: by an insurer after a claim, by a
 * client deciding whether to renew, and by the PSRA. These are incidents on
 * posts Gemini staffs involving guards it employs, which is why the log is
 * central and readable across clients without opening an estate database.
 *
 * OPEN IS A STATE WITH CONSEQUENCES. An incident stays open until somebody
 * records what was done about it, and the badge says so because an incident
 * nobody closed is the one that gets asked about later.
 *
 * DURESS ALERTS ARE NOT INCIDENTS. The board spends its closing panel saying
 * none are on record, and that is the reassurance rather than an empty state:
 * a duress alert is a life-safety event with its own table, its own broadcast
 * channel and its own response screen. Folding them into a case log would put
 * a panic button behind case management.
 */
const props = defineProps({
    incidents: { type: Array, required: true },
    duress_count: { type: Number, required: true },
    writeDisabledReason: { type: String, required: true },
})

const state = useScreenState({
    rows: () => props.incidents.length,
})
</script>

<template>
    <Head title="Security incident log" />

    <GeminiConsole title="Security incident log">
        <template #actions>
            <button type="button" class="btn-primary-sm" disabled :title="writeDisabledReason">
                <BoardIcon name="plus" :stroke="2" />
                <span>Log incident</span>
            </button>
        </template>

        <Subnav active="incidents" />

        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="6" />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="No incidents recorded"
            body="Nothing has been logged against a Gemini-staffed post. Incidents appear here as they are recorded, across every client."
        />

        <table v-else class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Client</th>
                    <th>Type</th>
                    <th>Guard involved</th>
                    <th>Severity</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="incident in incidents" :key="incident.id">
                    <td>{{ incident.date }}</td>
                    <td>{{ incident.estate }}</td>
                    <td>{{ incident.kind }}</td>
                    <td>{{ incident.guard }}</td>
                    <td>
                        <div class="severity-badge" :class="incident.severity">{{ incident.severity_label }}</div>
                    </td>
                    <td>
                        <div class="status-badge" :class="incident.status">{{ incident.status_label }}</div>
                    </td>
                    <td>
                        <button type="button" class="text-link-sm" disabled :title="writeDisabledReason">View</button>
                    </td>
                </tr>
            </tbody>
        </table>

        <!--
          The duress panel. Shown when nothing has been triggered, because
          "nothing has happened" is the single most reassuring thing this screen
          can report and a blank space does not report it.
        -->
        <div v-if="duress_count === 0" class="empty-state">
            <BoardIcon name="shield" :stroke="1.7" />
            <div class="es1">No duress alerts on record</div>
            <div class="es2">
                Every guard's duress button is monitored live through Gate Activity. Nothing has been triggered across
                any client to date.
            </div>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws the topbar action and the row link as
 * <div>s; as real controls they arrive with a border, a face and Arial, and
 * the row one sits inline on a baseline where the board's is a block.
 */
button.btn-primary-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.text-link-sm {
    display: block;
    border: 0;
    background: transparent;
    font-family: inherit;
    line-height: inherit;
    text-align: inherit;
}

button.btn-primary-sm[disabled],
button.text-link-sm[disabled] {
    cursor: not-allowed;
}
</style>
