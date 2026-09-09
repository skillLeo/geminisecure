<script setup>
import { Head } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import Subnav from './Subnav.vue'
import { useScreenState } from '../../../composables/useScreenState'

/**
 * Cross-client guard roster — board screen super-admin-24.
 *
 * DOM and class names are the board's. The grid is a week of standing
 * assignments: posts down the left grouped by client, days across the top, and
 * the guard who stands that post in each cell. A cell reading Open is a post
 * nobody is standing that day, and it carries the reason on hover — an expired
 * licence and an empty post look identical on the grid and are entirely
 * different problems.
 *
 * Nothing on this screen writes. The board's actions — drag to assign, fill a
 * gap, copy last week, publish — all belong to a roster editor that is not
 * built, so the one control the topbar draws says so rather than swallowing
 * the click.
 */
const props = defineProps({
    /** Column headings, Monday first. */
    days: { type: Array, required: true },
    /** "Sep 7 – Sep 13, 2026". */
    week: { type: String, required: true },
    /** One entry per client, each with its rota lines. */
    groups: { type: Array, required: true },
    kpis: { type: Array, required: true },
    /** Whether the viewer's role narrows this to their assigned sites. */
    scoped: { type: Boolean, default: false },
})

const state = useScreenState({
    rows: () => props.groups.length,
    // A Head of Security sees their assigned sites and nothing else, which is
    // a narrowed view rather than an empty platform: telling them "no posts are
    // staffed yet" when four are staffed outside their scope would be a lie
    // about the company, so the narrowed case gets its own words.
    filtered: () => props.scoped,
})
</script>

<template>
    <Head title="Cross-client roster" />

    <GeminiConsole title="Guard workforce">
        <!--
          The board's own topbar button. Posting an open shift writes a roster
          record, there is no roster editor and no route behind it, so it is
          disabled and says why rather than looking live and doing nothing.
        -->
        <template #actions>
            <button
                type="button"
                class="btn-primary-sm"
                disabled
                title="Available when the roster editor ships — posting an open shift writes to the rota"
            >
                <BoardIcon name="plus" :stroke="2" />
                <span>Post open shift</span>
            </button>
        </template>

        <Subnav active="roster" />

        <!--
          Loading. The grid's own panel, holding a skeleton of the shape the
          rota arrives in — eight columns, one post and seven days.
        -->
        <div v-if="state.isLoading.value" class="roster-grid">
            <SkeletonRows :rows="6" :columns="8" />
        </div>

        <template v-else>
            <div class="kpi-row">
                <div
                    v-for="kpi in kpis"
                    :key="kpi.label"
                    class="kpi-card"
                    :class="{ warn: kpi.warn }"
                    :title="kpi.title"
                >
                    <div class="k-top">
                        <div class="kpi-icon">
                            <BoardIcon :name="kpi.icon" :stroke="kpi.stroke" />
                        </div>
                    </div>
                    <div class="k-val">{{ kpi.value }}</div>
                    <div class="k-lbl">{{ kpi.label }}</div>
                </div>
            </div>

            <EmptyState
                v-if="state.isDenied.value"
                variant="denied"
                title="You cannot see the cross-client rota"
                body="The rota shows every post at every client. Your role covers guard records rather than deployment, so it stops at the guard directory."
            />

            <EmptyState
                v-else-if="state.isError.value"
                variant="error"
                title="The rota could not be loaded"
                body="Nothing has been changed and no shift has been lost. The assignment register is central, so this is a read that failed rather than a roster that is missing."
            />

            <EmptyState
                v-else-if="state.isEmptyFiltered.value"
                variant="filtered"
                title="No posts are staffed at your sites"
                body="Your role covers the sites you are assigned to, and none of them has a guard posted yet. Posts at other clients are outside your scope rather than missing."
            />

            <EmptyState
                v-else-if="state.isEmpty.value"
                variant="first-use"
                title="No posts are staffed yet"
                body="A post appears on the rota once a guard is posted to it. Assign a guard from their profile and the post takes its line here, for every day of the week."
            />

            <!--
              The week the grid covers rides on the day headings rather than
              taking a caption of its own. The board draws no room for one, and
              a rota with no date on it anywhere is a rota someone will read as
              this week when it is last week's.
            -->
            <template v-else>
                <div v-for="group in groups" :key="group.id" class="client-group">
                    <div class="client-group-head">
                        <div class="cg-dot"></div>
                        <h4>{{ group.name }}</h4>
                    </div>
                    <div class="roster-grid">
                        <div class="roster-head-row">
                            <div>Post</div>
                            <div v-for="day in days" :key="day" :title="week">{{ day }}</div>
                        </div>
                        <div v-for="line in group.lines" :key="line.key" class="roster-row2">
                            <div class="roster-post">{{ line.post }}</div>
                            <div v-for="(cell, index) in line.cells" :key="index" class="roster-cell">
                                <div class="shift-chip" :class="cell.tone" :title="cell.title">{{ cell.label }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </template>
    </GeminiConsole>
</template>

<style scoped>
/*
 * One rule, and it removes a browser default rather than adding a style.
 *
 * The board draws the topbar action as a <div>. It is a real <button> here so
 * it can be focused and so its disabled state is announced, and a button
 * arrives with a border and a font of the browser's own. The board's
 * .btn-primary-sm already declares everything else.
 */
button.btn-primary-sm {
    border: 0;
    font-family: inherit;
}
</style>
