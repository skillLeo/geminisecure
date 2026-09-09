<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'

/**
 * PSRA compliance — board screen super-admin-20.
 *
 * DOM and class names are the board's. The board's Guard cell here carries the
 * name alone, without the second line the directory's rows have, and that
 * difference is reproduced rather than tidied away. The board draws no search
 * field on this topbar either, so none is rendered: the directory next door is
 * where a guard is looked up by name.
 *
 * The register lists every licence, soonest expiry first, not only the ones
 * that have already lapsed. A compliance officer reads down this table to see
 * when the next thing goes wrong, and a table holding only today's failures
 * could not answer that.
 *
 * Licence state drives the badge, not employment status: a guard can be Active
 * and hold an expired licence, which is exactly the case this screen exists to
 * surface, so the two are never collapsed into one pill.
 *
 * The sub-navigation is duplicated from the directory rather than shared. Four
 * of its six destinations do not exist yet and the two that do disagree about
 * which item is current, so a shared component would have to be told both —
 * more plumbing than the six lines it would save.
 */
defineProps({
    guards: { type: Array, required: true },
})

const subnav = [
    { label: 'Directory', href: '/guards' },
    { label: 'Roster', reason: 'Available when the shift roster ships' },
    { label: 'Standing orders', reason: 'Available when standing orders ship' },
    { label: 'Gate activity', reason: 'Available when the Guard App ships' },
    { label: 'Compliance', href: '/guards/compliance', active: true },
    { label: 'Incidents', reason: 'Available when incident reporting ships' },
]

/**
 * The whole row opens the profile, not only the action link.
 *
 * A click that landed on a link inside the row is left to it rather than being
 * hijacked by the row underneath.
 */
const open = (event, guard) => {
    if (event.target.closest('a')) {
        return
    }

    router.get(`/guards/${guard.id}`)
}
</script>

<template>
    <Head title="PSRA compliance" />

    <GeminiConsole title="PSRA compliance">
        <!--
          The board's own topbar button. There is no export endpoint yet, so it
          is disabled and says why rather than producing a file that is not
          there.
        -->
        <template #actions>
            <button
                type="button"
                class="btn-outline-sm"
                disabled
                title="Available when licence register exports ship"
            >
                <BoardIcon name="export" :stroke="1.8" />
                <span>Export</span>
            </button>
        </template>

        <div class="subnav">
            <template v-for="item in subnav" :key="item.label">
                <Link v-if="item.href" :href="item.href" class="subnav-item" :class="{ active: item.active }">
                    {{ item.label }}
                </Link>
                <button v-else type="button" class="subnav-item" disabled :title="item.reason">
                    {{ item.label }}
                </button>
            </template>
        </div>

        <EmptyState
            v-if="guards.length === 0"
            variant="first-use"
            title="No licences on the register"
            body="Every guard on the roster carries a PSRA licence, and each one appears here with its renewal date as soon as the guard is added."
        />

        <table v-else class="data-table">
            <thead>
                <tr>
                    <th>Guard</th>
                    <th>PSRA number</th>
                    <th>Client</th>
                    <th>Expiry date</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="guard in guards" :key="guard.id" @click="open($event, guard)">
                    <td>
                        <div class="res-cell">
                            <div class="res-avatar">{{ guard.initials }}</div>
                            <div class="res-name">{{ guard.name }}</div>
                        </div>
                    </td>
                    <td>{{ guard.psra_number }}</td>
                    <td>{{ guard.estate }}</td>
                    <td>{{ guard.expires_on }}</td>
                    <td>
                        <div class="status-badge" :class="guard.licence_badge">{{ guard.licence_label }}</div>
                    </td>
                    <td>
                        <Link :href="`/guards/${guard.id}`" class="text-link-sm">{{ guard.action }}</Link>
                    </td>
                </tr>
            </tbody>
        </table>
    </GeminiConsole>
</template>

<style scoped>
/*
 * Reset only, as on the directory. The board draws its sub-navigation items,
 * its topbar button and its row actions as <div>s; the real links and disabled
 * buttons that replace them arrive with an underline, a border, a button face
 * and a font of the browser's own. These rules remove exactly those.
 *
 * A scoped element selector carries an attribute and so outranks a single
 * class, which is why colour is never reset here and why the background is
 * taken off only .subnav-item, the one control whose board rule declares none.
 * .btn-outline-sm sets its own white and its own border, and they are left be.
 */
a {
    text-decoration: none;
}

button {
    font-family: inherit;
}

button.subnav-item {
    border: 0;
    background: none;
}
</style>
