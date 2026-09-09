<script setup>
import { Head } from '@inertiajs/vue3'
import GeminiConsole from '../../Layouts/GeminiConsole.vue'
import ModuleIcon from '../../Components/ModuleIcon.vue'
import EmptyState from '../../Components/EmptyState.vue'

defineProps({
    kpis: { type: Array, required: true },
    estates: { type: Array, required: true },
})
</script>

<template>
    <Head title="Dashboard" />

    <GeminiConsole title="Dashboard">
        <div class="kpi-row">
            <div v-for="kpi in kpis" :key="kpi.key" class="kpi-card" :class="{ warn: kpi.alert }">
                <div class="k-top">
                    <div class="kpi-icon">
                        <ModuleIcon :module="kpi.icon" />
                    </div>
                    <div v-if="kpi.trend" class="kpi-trend up">{{ kpi.trend }}</div>
                </div>
                <div class="k-val">{{ kpi.value }}</div>
                <div class="k-lbl">{{ kpi.label }}</div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">
                <h2>Recently provisioned estates</h2>
            </div>

            <EmptyState
                v-if="estates.length === 0"
                variant="first-use"
                title="No estates yet"
                body="Estates appear here once they are provisioned. Each one gets its own database and its own credentials, so provisioning is a deliberate step rather than a form submission."
                action-label="Provision an estate"
            />

            <table v-else class="data-table">
                <thead>
                    <tr>
                        <th>Estate</th>
                        <th>Subdomain</th>
                        <th>Status</th>
                        <th>Provisioned</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="estate in estates" :key="estate.id">
                        <td class="cell-strong">{{ estate.name }}</td>
                        <td class="cell-mono">{{ estate.id }}</td>
                        <td>
                            <span class="status-badge" :class="estate.status">{{ estate.status }}</span>
                        </td>
                        <td class="cell-mono">{{ estate.provisioned_at ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </GeminiConsole>
</template>
