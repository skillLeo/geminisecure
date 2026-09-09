<script setup>
import { computed, ref } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import ModuleIcon from '../../../Components/ModuleIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'

const props = defineProps({
    guards: { type: Array, required: true },
    summary: { type: Object, required: true },
    scope: { type: String, required: true },
})

const search = ref('')

const filtered = computed(() => {
    const term = search.value.trim().toLowerCase()

    if (!term) {
        return props.guards
    }

    return props.guards.filter(
        (g) =>
            g.name.toLowerCase().includes(term) ||
            g.psra_number.toLowerCase().includes(term) ||
            g.estate.toLowerCase().includes(term),
    )
})

const isFirstUse = computed(() => props.guards.length === 0)
const isFilteredEmpty = computed(() => props.guards.length > 0 && filtered.value.length === 0)

const kpis = computed(() => [
    { key: 'total', icon: 'guard_workforce', value: props.summary.total, label: 'Guards on roster' },
    { key: 'active', icon: 'guard_workforce', value: props.summary.active, label: 'Active' },
    { key: 'leave', icon: 'guard_workforce', value: props.summary.on_leave, label: 'On leave' },
    {
        key: 'compliance',
        icon: 'access_audit_log',
        value: props.summary.compliance,
        label: 'Licences needing attention',
        alert: props.summary.compliance > 0,
    },
])
</script>

<template>
    <Head title="Guard workforce" />

    <GeminiConsole title="Guard workforce">
        <div class="kpi-row">
            <div v-for="kpi in kpis" :key="kpi.key" class="kpi-card" :class="{ warn: kpi.alert }">
                <div class="k-top">
                    <div class="kpi-icon"><ModuleIcon :module="kpi.icon" /></div>
                </div>
                <div class="k-val">{{ kpi.value }}</div>
                <div class="k-lbl">{{ kpi.label }}</div>
            </div>
        </div>

        <div class="list-head">
            <div class="top-search list-search">
                <svg viewBox="0 0 24 24" fill="none">
                    <circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.8" />
                    <path d="M21 21l-4.3-4.3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                </svg>
                <input v-model="search" type="search" placeholder="Search by name, PSRA number or client" />
            </div>

            <Link href="/guards/compliance" class="btn-outline-sm">
                <span>PSRA compliance</span>
            </Link>

            <div v-if="scope === 'assigned_sites'" class="scope-note">Assigned sites only</div>
        </div>

        <EmptyState
            v-if="isFirstUse"
            variant="first-use"
            title="No guards on the roster yet"
            body="Guards are Gemini Security employees, added centrally and then posted to a client. Once added they appear here with their PSRA licence and current post."
            action-label="Add a guard"
        />

        <EmptyState
            v-else-if="isFilteredEmpty"
            variant="filtered"
            :title="`No guards match “${search}”`"
            body="Every other guard is still on the roster. Clear the search to see them all."
            action-label="Clear search"
            @action="search = ''"
        />

        <table v-else class="data-table">
            <thead>
                <tr>
                    <th>Guard</th>
                    <th>PSRA number</th>
                    <th>Client</th>
                    <th>Post</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="guard in filtered" :key="guard.id">
                    <td>
                        <div class="res-cell">
                            <div class="res-avatar">{{ guard.initials }}</div>
                            <div>
                                <div class="res-name">{{ guard.name }}</div>
                                <div class="res-sub">{{ guard.employment_type }}</div>
                            </div>
                        </div>
                    </td>
                    <td class="cell-mono">{{ guard.psra_number }}</td>
                    <td>{{ guard.estate }}</td>
                    <td>{{ guard.post }}</td>
                    <td><span class="status-badge" :class="guard.status_badge">{{ guard.status_label }}</span></td>
                    <td>
                        <Link :href="`/guards/${guard.id}`" class="text-link-sm">
                            {{ guard.status === 'licence_expired' ? 'Review' : 'View' }}
                        </Link>
                    </td>
                </tr>
            </tbody>
        </table>
    </GeminiConsole>
</template>

<style scoped>
.list-head {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 16px;
}

.list-search {
    max-width: 380px;
}

.list-search input {
    flex: 1;
    border: none;
    background: transparent;
    outline: none;
    font-family: 'Inter', sans-serif;
    font-size: 12.5px;
    color: var(--navy-900);
}

.list-search input::placeholder {
    color: var(--slate-500);
}

.scope-note {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--amber-700);
    background: var(--amber-100);
    padding: 6px 12px;
    border-radius: 100px;
}
</style>
