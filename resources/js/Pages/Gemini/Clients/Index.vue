<script setup>
import { computed, ref } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'

const props = defineProps({
    estates: { type: Array, required: true },
    scope: { type: String, required: true },
})

const search = ref('')

const filtered = computed(() => {
    const term = search.value.trim().toLowerCase()

    if (!term) {
        return props.estates
    }

    return props.estates.filter(
        (e) => e.name.toLowerCase().includes(term) || e.id.toLowerCase().includes(term),
    )
})

/**
 * Two distinct empty states, never conflated: nothing exists at all, versus
 * nothing matches the current search. See EmptyState.
 */
const isFirstUse = computed(() => props.estates.length === 0)
const isFilteredEmpty = computed(() => props.estates.length > 0 && filtered.value.length === 0)
</script>

<template>
    <Head title="Clients" />

    <GeminiConsole title="Clients">
        <div class="list-head">
            <div class="top-search list-search">
                <svg viewBox="0 0 24 24" fill="none">
                    <circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.8" />
                    <path d="M21 21l-4.3-4.3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                </svg>
                <input v-model="search" type="search" placeholder="Search estates by name or subdomain" />
            </div>

            <div v-if="scope === 'assigned_sites'" class="scope-note">
                Showing your assigned sites only
            </div>
        </div>

        <EmptyState
            v-if="isFirstUse"
            variant="first-use"
            title="No estates yet"
            body="An estate is provisioned with its own database and its own credentials. Once provisioned it appears here with its subdomain and status."
            action-label="Provision an estate"
        />

        <EmptyState
            v-else-if="isFilteredEmpty"
            variant="filtered"
            :title="`No estates match “${search}”`"
            body="Every other estate is still here. Clear the search to see the full list."
            action-label="Clear search"
            @action="search = ''"
        />

        <div v-else class="panel">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Estate</th>
                        <th>Subdomain</th>
                        <th>Status</th>
                        <th>Provisioned</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="estate in filtered" :key="estate.id">
                        <td class="cell-strong">
                            <Link :href="`/clients/${estate.id}`">{{ estate.name }}</Link>
                        </td>
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
