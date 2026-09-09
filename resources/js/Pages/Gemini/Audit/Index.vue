<script setup>
import { computed, ref } from 'vue'
import { Head } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'

const props = defineProps({
    entries: { type: Array, required: true },
})

const search = ref('')

const filtered = computed(() => {
    const term = search.value.trim().toLowerCase()

    if (!term) {
        return props.entries
    }

    return props.entries.filter(
        (e) =>
            e.actor.toLowerCase().includes(term) ||
            e.action.toLowerCase().includes(term) ||
            e.estate.toLowerCase().includes(term),
    )
})

const isFirstUse = computed(() => props.entries.length === 0)
const isFilteredEmpty = computed(() => props.entries.length > 0 && filtered.value.length === 0)
</script>

<template>
    <Head title="Access & audit log" />

    <GeminiConsole title="Access &amp; audit log">
        <div class="audit-lede">
            Every role assignment, permission change and administrative action, in the order it
            happened. Entries are never editable &mdash; not by an estate, not by platform staff,
            and not by a database administrator. The log is insert-only at the grant layer and
            again at a database trigger.
        </div>

        <div class="list-head">
            <div class="top-search list-search">
                <svg viewBox="0 0 24 24" fill="none">
                    <circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.8" />
                    <path d="M21 21l-4.3-4.3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                </svg>
                <input v-model="search" type="search" placeholder="Search by admin, action or client" />
            </div>
        </div>

        <EmptyState
            v-if="isFirstUse"
            variant="first-use"
            title="Nothing has been recorded yet"
            body="The audit log fills as administrative actions are taken: a role reassigned, a permission changed, an estate provisioned. An empty log means no such action has happened, not that entries were removed — they cannot be."
        />

        <EmptyState
            v-else-if="isFilteredEmpty"
            variant="filtered"
            :title="`No entries match “${search}”`"
            body="Every other entry is still recorded. Clear the search to see the full log."
            action-label="Clear search"
            @action="search = ''"
        />

        <table v-else class="data-table">
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Admin</th>
                    <th>Action</th>
                    <th>Client affected</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="entry in filtered" :key="entry.id">
                    <td class="cell-mono">{{ entry.timestamp }}</td>
                    <td>
                        <div class="res-name">{{ entry.actor }}</div>
                        <div v-if="entry.actor_role" class="res-sub">{{ entry.actor_role }}</div>
                    </td>
                    <td class="cell-mono">{{ entry.action }}</td>
                    <td>{{ entry.estate }}</td>
                    <td class="cell-mono">{{ entry.entity ?? '—' }}</td>
                </tr>
            </tbody>
        </table>
    </GeminiConsole>
</template>

<style scoped>
.audit-lede {
    font-size: 12.5px;
    color: var(--slate-600);
    line-height: 1.6;
    max-width: 760px;
    margin-bottom: 16px;
}

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
</style>
