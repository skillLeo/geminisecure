<script setup>
import { Head, Link } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * Data & privacy — board screen community-admin-33.
 *
 * EVERY FIELD ON THIS SCREEN IS READ-ONLY, AND THE BOARD AGREES: it draws four
 * values and not one editable input. What it also draws is a "Save changes"
 * button, and that is the one thing not reproduced as live — a control that
 * saves nothing is worse than one that says why it does not.
 *
 * THE REASON IT IS READ-ONLY IS NOT "NOT BUILT YET". These four are policy the
 * estate has been TOLD rather than policy it sets: a seven-year retention
 * period matching statutory record-keeping, which roles may export a resident
 * list, what Gemini receives under the security service grant, and how a
 * resident asks for their own data. None of them has an owner on this platform,
 * and inventing an edit path for a rule nobody has been asked to set would let
 * an administrator quietly shorten a retention period a law fixes.
 *
 * "WHO CAN EXPORT RESIDENT LISTS" IS COMPUTED, NOT STORED. `Settings::
 * residentExportRoles()` reads the permission model, so this line cannot say
 * one thing while the matrix does another — which is the same rule board 24
 * follows and the reason D-048 made that screen read-only too.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    sections: { type: Array, required: true },
    groups: { type: Array, required: true },
})

useWireframe('community-admin-09-data-privacy-add-resident-and-meetings')

const state = useScreenState({
    rows: () => props.groups.length,
})

/**
 * Why the board's own save button is inert.
 *
 * Named once here rather than repeated, because it is the whole argument of
 * the screen and a reader hovering any part of it should get the same sentence.
 */
const NOTHING_TO_SAVE =
    'Nothing on this screen is editable, so there is nothing to save. These four are policy this estate ' +
    'has been told — a retention period statute fixes, a permission the role matrix decides, and a data ' +
    'grant Gemini holds under its own agreement — rather than settings this console owns.'
</script>

<template>
    <Head title="Settings" />

    <EstateConsole title="Settings" :estate-name="estate.name" active="settings">
        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="2" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Settings is not part of your role’s access"
            body="This screen states what happens to a resident's data and who may take a copy of it, so it opens only to a role that holds Settings."
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

            <div class="form-panel" style="max-width: 640px">
                <template v-for="group in groups" :key="group.title">
                    <div class="form-sec-head">{{ group.title }}</div>

                    <div v-for="field in group.fields" :key="field.key" class="m-field">
                        <label>{{ field.label }}</label>
                        <div class="m-input"><span>{{ field.value }}</span></div>
                    </div>
                </template>

                <button
                    type="button"
                    class="btn-primary-sm"
                    style="width: fit-content"
                    disabled
                    :title="NOTHING_TO_SAVE"
                >
                    <span>Save changes</span>
                </button>
            </div>
        </div>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws its seven nav items and its save
 * control as <div>s; here they are anchors and buttons.
 */
button.btn-primary-sm {
    border: 0;
    font: inherit;
    cursor: not-allowed;
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
</style>
