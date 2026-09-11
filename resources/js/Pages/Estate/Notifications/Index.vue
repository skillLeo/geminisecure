<script setup>
import { computed } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * The notification centre — the dashboard bell (12 §2, Wave 2).
 *
 * NOTHING HERE IS STORED. Every item is derived from the table that owns the
 * fact: a claim waiting for review is a row in `unit_claims`, a ticket past its
 * target is a row in `maintenance_tickets`. An item that resolves stops being
 * derived — a claim approved on board 34 is simply gone from this list the next
 * time it is drawn, with nothing to go back and tidy. A notifications table
 * would instead leave a row standing that says the claim still needs review,
 * and the reader would believe it.
 *
 * WHAT IS STORED IS THE READ MARK, per viewer. Two officers do not share an
 * inbox: what the Treasurer has read is not what the Secretary has read, and a
 * bell that cleared for everybody the moment one person looked would hide work
 * from the rest.
 *
 * EACH ITEM IS GATED ON ITS OWN RECORD. A notification is a summary, and a role
 * that may not read the record may not read the summary either — the server
 * asks the gate per item, so a Property Manager sees the tickets they hold and
 * never a unit claim.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    items: { type: Array, required: true },
    unread: { type: Number, required: true },
    dashboardHref: { type: String, required: true },
    path: { type: String, required: true },
})

useWireframe('community-admin-01-login-dashboard-structure-and-residents')

const state = useScreenState({
    rows: () => props.items.length,
})

const retry = () => router.reload()

const form = useForm({ keys: [] })

const unreadKeys = computed(() => props.items.filter((item) => !item.is_read).map((item) => item.key))

const markAll = () => {
    if (unreadKeys.value.length === 0) {
        return
    }

    form.keys = unreadKeys.value
    form.post(`${props.path}/read`, { preserveScroll: true })
}

const markOne = (item) => {
    if (item.is_read) {
        return
    }

    form.keys = [item.key]
    form.post(`${props.path}/read`, { preserveScroll: true })
}
</script>

<template>
    <Head title="Notifications" />

    <EstateConsole title="Notifications" :estate-name="estate.name" active="dashboard">
        <template #lead>
            <Link
                :href="dashboardHref"
                style="width:34px;height:34px;border-radius:50%;background:var(--navy-100);display:flex;align-items:center;justify-content:center;flex:0 0 auto;"
                title="Back to the dashboard"
                aria-label="Back to the dashboard"
            >
                <svg viewBox="0 0 24 24" fill="none" style="width:16px;height:16px;color:var(--navy-700);">
                    <polyline
                        points="15 18 9 12 15 6"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                </svg>
            </Link>
        </template>

        <template #actions>
            <button
                type="button"
                class="btn-outline-sm"
                :disabled="unreadKeys.length === 0 || form.processing"
                :title="unreadKeys.length === 0 ? 'You have looked at everything on this list.' : 'Mark all of these as seen. It is a note about you, not a change to the estate — nothing here is resolved by reading it.'"
                @click="markAll"
            >
                <span>Mark all as seen</span>
            </button>
        </template>

        <SkeletonRows v-if="state.isLoading.value" :rows="6" :columns="2" />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The list could not be read"
            body="Nothing on this screen changes the estate, so nothing has been lost and re-running the read is safe."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isEmpty.value || state.isEmptyFiltered.value"
            variant="first-use"
            title="Nothing is waiting on you"
            body="This list holds what still needs somebody in the modules your role reaches — a unit claim waiting for review, a maintenance ticket past its target, a deposit still held after a booking has ended. None of those is outstanding right now."
        />

        <template v-else>
            <p class="nt-lead">
                {{ unread }} of {{ items.length }} not yet looked at. Reading one does not resolve it — each of these is
                a live read of the record itself, and it leaves this list when the record changes.
            </p>

            <div v-for="item in items" :key="item.key" class="nt-row" :class="{ read: item.is_read }">
                <div class="nt-dot" :class="{ on: !item.is_read }" />

                <div class="nt-text">
                    <div class="nt-title">{{ item.title }}</div>
                    <div class="nt-detail">{{ item.detail }}{{ item.when ? ` · ${item.when}` : '' }}</div>
                </div>

                <button
                    type="button"
                    class="text-link-sm"
                    :disabled="item.is_read || form.processing"
                    :title="item.is_read ? 'You have looked at this one.' : 'Mark this one as seen.'"
                    @click="markOne(item)"
                >
                    {{ item.is_read ? 'Seen' : 'Mark seen' }}
                </button>
            </div>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * AUTHORED. No board draws this screen — the dashboard draws the bell that
 * leads here and nothing behind it. Kept to the tokens the boards define.
 */
button {
    font-family: inherit;
    cursor: pointer;
}

button[disabled] {
    cursor: not-allowed;
}

button.text-link-sm {
    border: 0;
    background: none;
    padding: 0;
}

.nt-lead {
    font-size: 11.5px;
    color: var(--slate-600);
    line-height: 1.55;
    margin: 0 0 14px;
}

.nt-row {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 12px;
    padding: 13px 15px;
    margin-bottom: 9px;
}

.nt-row.read {
    background: var(--navy-100);
    border-color: transparent;
}

.nt-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: transparent;
    margin-top: 5px;
    flex: 0 0 auto;
}

.nt-dot.on {
    background: var(--amber-600);
}

.nt-text {
    flex: 1 1 auto;
}

.nt-title {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-900);
    line-height: 1.5;
}

.nt-detail {
    font-size: 11px;
    color: var(--slate-500);
    line-height: 1.55;
}
</style>
