<script setup>
import { reactive } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * Notification defaults — board screen community-admin-30.
 *
 * EIGHTEEN SWITCHES, ONE SUBMIT, and the board is right to draw it that way.
 * Feature toggles arm-and-confirm per row because each one turns a module on or
 * off for four hundred and fifty households; nothing here does that. These
 * decide who is emailed, texted or pushed about something that has already
 * happened, which is a contact preference in the same register as the estate's
 * enquiries mailbox on board 21 — so it is one "Save changes" and one audit
 * entry, gated on `update` rather than `configure`.
 *
 * THE POSTED ARRAY IS NOT THE ALLOWLIST. `Settings::saveNotifications()` walks
 * its own event catalogue and reads each key out of what arrives, so an extra
 * key in the request body reaches nothing. A settings form that trusted its own
 * payload would be a way to write a row nobody drew.
 *
 * A SWITCH A ROLE CANNOT CHANGE IS INERT WITH THE REASON ON IT, not absent. A
 * President can read this screen and cannot save it — the matrix gives Settings
 * Full only to the Community Super Admin — and taking the switches away would
 * hide what the estate's own defaults actually are from an officer entitled to
 * know them.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    sections: { type: Array, required: true },
    groups: { type: Array, required: true },
    canEdit: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
})

/*
 * SHEET 08, NOT THE SETTINGS SHEET ITS SIX SIBLINGS WEAR.
 *
 * Board 30 draws the settings layout and the settings nav, so the obvious guess
 * is board 21's sheet — and it is wrong. `.notif-row`, `.notif-panel`,
 * `.channel-sw` and `.channel-switches` are defined only in "Reports Unit Claims
 * and Notices", which is where board 30 was drawn; sheet 06 defines `.switch`
 * and `.knob` and none of the rows around them. Named wrongly, the six rows had
 * no styles at all and the page came out 1140px against a 900px board.
 *
 * Sheet 08 carries `.settings-layout` and `.settings-nav-item` too, so nothing
 * is lost by wearing it — checked, rather than assumed, after board 38 spent a
 * whole measurement on exactly this.
 */
useWireframe('community-admin-08-reports-unit-claims-and-notices')

const state = useScreenState({
    rows: () => props.groups.length,
})

/*
 * The working copy. Eighteen switches submitted together need somewhere to hold
 * what has been flipped before "Save changes" is pressed — the server's own
 * values are the starting point and are not written back until it is.
 */
const draft = reactive(
    Object.fromEntries(
        props.groups.flatMap((group) =>
            group.rows.flatMap((row) => row.channels.map((channel) => [`${row.key}.${channel.key}`, channel.enabled])),
        ),
    ),
)

const toggle = (row, channel) => {
    if (!props.canEdit) {
        return
    }

    draft[`${row.key}.${channel.key}`] = !draft[`${row.key}.${channel.key}`]
}

const save = () => {
    router.post(
        window.location.pathname,
        {
            defaults: Object.entries(draft).reduce((carry, [path, enabled]) => {
                const [event, channel] = path.split('.')

                carry[event] = { ...(carry[event] ?? {}), [channel]: enabled }

                return carry
            }, {}),
        },
        { preserveScroll: true },
    )
}
</script>

<template>
    <Head title="Settings" />

    <EstateConsole title="Settings" :estate-name="estate.name" active="settings">
        <template #actions>
            <button
                type="button"
                class="btn-primary-sm"
                :disabled="!canEdit"
                :title="canEdit ? undefined : blockedReason"
                @click="save"
            >
                <span>Save changes</span>
            </button>
        </template>

        <SkeletonRows v-if="state.isLoading.value" :rows="6" :columns="4" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Settings is not part of your role’s access"
            body="Notification defaults decide what every household on the estate is contacted about, so they open only to a role that holds Settings."
        />

        <div v-else class="settings-layout">
            <div class="settings-nav">
                <template v-for="section in sections" :key="section.key">
                    <div v-if="section.active" class="settings-nav-item active" aria-current="page">
                        {{ section.label }}
                    </div>
                    <Link v-else-if="section.href" :href="section.href" class="settings-nav-item">
                        {{ section.label }}
                    </Link>
                    <button
                        v-else
                        type="button"
                        class="settings-nav-item"
                        disabled
                        title="Not built yet — this settings screen is still being delivered."
                    >
                        {{ section.label }}
                    </button>
                </template>
            </div>

            <div>
                <div v-for="group in groups" :key="group.heading" class="notif-group">
                    <div class="notif-group-head">{{ group.heading }}</div>
                    <div class="notif-panel">
                        <div v-for="row in group.rows" :key="row.key" class="notif-row">
                            <div class="notif-txt">
                                <div class="nt1">{{ row.label }}</div>
                                <div class="nt2">{{ row.description }}</div>
                            </div>
                            <div class="channel-switches">
                                <div v-for="channel in row.channels" :key="channel.key" class="channel-sw">
                                    <button
                                        type="button"
                                        class="switch"
                                        :class="{ on: draft[`${row.key}.${channel.key}`] }"
                                        role="switch"
                                        :aria-checked="draft[`${row.key}.${channel.key}`]"
                                        :aria-label="`${row.label} — ${channel.label}`"
                                        :disabled="!canEdit"
                                        :title="canEdit ? undefined : blockedReason"
                                        @click="toggle(row, channel)"
                                    >
                                        <span class="knob"></span>
                                    </button>
                                    <span>{{ channel.label }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws its save action, its seven nav items
 * and all eighteen switches as <div>s; here they are real controls, which
 * arrive with a border, buttonface grey and the browser's own font. The board's
 * own .btn-primary-sm, .settings-nav-item and .switch supply everything
 * visible, and nothing below reaches past a browser default — D-045.
 */
button.btn-primary-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.settings-nav-item {
    border: 0;
    background: none;
    font: inherit;
    width: 100%;
    text-align: left;
    cursor: not-allowed;
}

a.settings-nav-item {
    text-decoration: none;
    display: block;
}

button.switch {
    border: 0;
    padding: 0;
    cursor: pointer;
}

button.btn-primary-sm[disabled],
button.switch[disabled] {
    cursor: not-allowed;
}
</style>
