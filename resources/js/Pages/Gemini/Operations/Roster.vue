<script setup>
import { ref } from 'vue'
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
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

    /** One day's rota, and what is open on it (12 §2, Wave 5). */
    day: { type: Object, required: true },
    /** Whether a day was asked for — the day view is drawn only then, or when a shift is open. */
    dayRequested: { type: Boolean, default: false },
    /** The seven dates behind the grid's day headings. */
    weekDates: { type: Array, default: () => [] },
    posts: { type: Array, required: true },
    officers: { type: Array, required: true },
    canWrite: { type: Boolean, required: true },
    writeBlockedReason: { type: String, required: true },
})

const page = usePage()

/* ------------------------------------------------------------------ */
/* posting and filling a shift (12 §2, Wave 5) */
/* ------------------------------------------------------------------ */

/*
 * AN OPEN SHIFT IS A REAL ROW WITH NO OFFICER ON IT. A post needing somebody is
 * a fact this console has to hold: a placeholder guard would show an empty gate
 * as manned, and not recording the gap at all would leave it in a dispatcher's
 * head. The coverage board reads `actual_start`, so an open shift shows as
 * uncovered without any change there — nobody clocked in because nobody was
 * rostered, which is exactly what it should say.
 */
const posting = ref(false)

const shiftForm = useForm({
    post_id: props.posts[0]?.id ?? null,
    starts_at: '',
    ends_at: '',
    reason: '',
})

const submitShift = () => {
    if (shiftForm.starts_at === '' || shiftForm.ends_at === '') {
        return
    }

    shiftForm.post('/guards/roster/shifts', {
        preserveScroll: true,
        onSuccess: () => {
            posting.value = false
            shiftForm.reset()
            shiftForm.post_id = props.posts[0]?.id ?? null
        },
    })
}

/* Filling one. A suspended officer is ABSENT from the list rather than listed
 * and refused: offering somebody who cannot legally stand a post would invite
 * the breach the compliance screens exist to prevent. */
const assigning = ref(null)

const assignForm = useForm({ guard_id: props.officers[0]?.id ?? null })

const openAssign = (row) => {
    if (!props.canWrite) {
        return
    }

    assignForm.clearErrors()
    assigning.value = assigning.value === row.id ? null : row.id
}

const submitAssign = (row) => {
    assignForm.post(`/guards/roster/shifts/${row.id}/assign`, {
        preserveScroll: true,
        onSuccess: () => {
            assigning.value = null
        },
    })
}

/** Which day the rota below is showing. A real GET, so it can be bookmarked. */
const dayHref = (offset) => {
    const on = new Date(`${props.day.date}T00:00:00`)
    on.setDate(on.getDate() + offset)

    return `/guards/roster?date=${on.toISOString().slice(0, 10)}`
}

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
          The board's own topbar button. It posts an OPEN shift — a post, a
          window and nobody yet — which is the row the coverage board then
          shows as uncovered until somebody is put on it.
        -->
        <template #actions>
            <button
                type="button"
                class="btn-primary-sm"
                :disabled="!canWrite || posts.length === 0"
                :title="canWrite ? 'Post a shift with no officer on it. The coverage board shows it as uncovered until somebody is assigned, which is what a gap should look like.' : writeBlockedReason"
                @click="posting = !posting"
            >
                <BoardIcon name="plus" :stroke="2" />
                <span>Post open shift</span>
            </button>
        </template>

        <Subnav active="roster" />

        <p v-if="page.props.flash?.success" class="rst-flash">{{ page.props.flash.success }}</p>
        <p v-if="page.props.errors.starts_at" class="rst-refusal">{{ page.props.errors.starts_at }}</p>
        <p v-if="page.props.errors.guard_id" class="rst-refusal">{{ page.props.errors.guard_id }}</p>

        <!--
          AUTHORED. The board draws a week nobody is editing, so it has neither
          the panel nor the day list below.
        -->
        <form v-if="posting" class="rst-panel" @submit.prevent="submitShift">
            <div class="rst-head">
                A shift is posted forward, never into the past — a shift for last Tuesday would invent a gap nobody
                could have filled, and the coverage board would report an estate uncovered on a night it was covered.
            </div>

            <div class="rst-fields">
                <div class="rst-field rst-field--wide">
                    <label for="rs-post">Post</label>
                    <select id="rs-post" v-model="shiftForm.post_id" required>
                        <option v-for="post in posts" :key="post.id" :value="post.id">{{ post.label }}</option>
                    </select>
                </div>
                <div class="rst-field">
                    <label for="rs-from">Starts</label>
                    <input id="rs-from" v-model="shiftForm.starts_at" type="datetime-local" required />
                </div>
                <div class="rst-field">
                    <label for="rs-to">Ends</label>
                    <input id="rs-to" v-model="shiftForm.ends_at" type="datetime-local" required />
                </div>
                <div class="rst-field rst-field--wide">
                    <label for="rs-why">Why it is open — optional, read by whoever picks it up</label>
                    <input id="rs-why" v-model="shiftForm.reason" type="text" maxlength="190" />
                </div>
            </div>

            <div class="rst-actions">
                <button
                    type="submit"
                    class="btn-primary-sm"
                    :disabled="shiftForm.processing || shiftForm.starts_at === '' || shiftForm.ends_at === ''"
                    title="Put it on the rota, open."
                >
                    <span>{{ shiftForm.processing ? 'Posting…' : 'Post open shift' }}</span>
                </button>
                <button type="button" class="text-link-sm" @click="posting = false">Cancel</button>
            </div>
        </form>

        <div v-if="dayRequested || day.open > 0" class="rst-day">
            <div class="rst-day-top">
                <a :href="dayHref(-1)" class="text-link-sm" title="The day before">← Previous day</a>
                <span class="rst-day-label">
                    {{ day.date }} — {{ day.rows.length }} shift(s), {{ day.open }} open
                </span>
                <a :href="dayHref(1)" class="text-link-sm" title="The day after">Next day →</a>
            </div>

            <p v-if="day.rows.length === 0" class="rst-none">
                Nothing is rostered for this day. That is a real answer — an unstaffed day is not a screen that failed.
            </p>

            <template v-for="row in day.rows" :key="row.id">
                <div class="rst-row" :class="{ open: row.is_open }">
                    <span class="rst-post">{{ row.post }} · {{ row.estate }}</span>
                    <span class="rst-window">{{ row.window }}</span>
                    <span class="rst-who">
                        {{ row.officer ?? 'Open' }}
                        <span v-if="row.released_reason" class="rst-why">{{ row.released_reason }}</span>
                    </span>
                    <button
                        v-if="row.is_open"
                        type="button"
                        class="text-link-sm"
                        :disabled="!canWrite || officers.length === 0"
                        :title="canWrite ? (officers.length === 0 ? 'No officer on this platform can be posted — every one is suspended or inactive.' : 'Put an officer on this shift. A suspended officer is not on the list, because posting one would be rostering somebody who may not legally stand it.') : writeBlockedReason"
                        @click="openAssign(row)"
                    >
                        Assign
                    </button>
                </div>

                <form v-if="assigning === row.id" class="rst-assign" @submit.prevent="submitAssign(row)">
                    <label :for="`rs-guard-${row.id}`">Officer</label>
                    <select :id="`rs-guard-${row.id}`" v-model="assignForm.guard_id" required>
                        <option v-for="officer in officers" :key="officer.id" :value="officer.id">
                            {{ officer.label }}
                        </option>
                    </select>
                    <button type="submit" class="btn-primary-sm" :disabled="assignForm.processing" title="Put them on it.">
                        <span>{{ assignForm.processing ? 'Assigning…' : 'Assign' }}</span>
                    </button>
                    <button type="button" class="text-link-sm" @click="assigning = null">Cancel</button>
                </form>
            </template>
        </div>

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
                            <!--
                              Each heading opens its own day's rota (12 §2,
                              Wave 5) — the link sits inside the board's own
                              cell so the grid keeps its geometry.
                            -->
                            <div v-for="(label, i) in days" :key="label" :title="week">
                                <Link
                                    v-if="weekDates[i]"
                                    :href="`/guards/roster?date=${weekDates[i]}`"
                                    class="rst-day-link"
                                    :title="`Open the rota for ${weekDates[i]} — every shift that day, and what is still open.`"
                                >
                                    {{ label }}
                                </Link>
                                <template v-else>{{ label }}</template>
                            </div>
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
    cursor: pointer;
}

button.btn-primary-sm[disabled] {
    cursor: not-allowed;
}

button.text-link-sm {
    border: 0;
    background: none;
    padding: 0;
    font: inherit;
    cursor: pointer;
}

button.text-link-sm[disabled] {
    cursor: not-allowed;
}

a.text-link-sm {
    text-decoration: none;
}

/* The day heading's link wears the heading's own type — colour, size and
 * weight inherited — so the grid reads exactly as the board draws it. */
a.rst-day-link {
    color: inherit;
    font: inherit;
    text-decoration: none;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws a week nobody is editing, so it has
 * no panel, no flash and no day list. Kept to the tokens the Gemini boards
 * define. An OPEN row is amber because it is a condition somebody must act on,
 * not a failure — the same reading the arrears buckets get.
 */
.rst-flash,
.rst-refusal {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 14px;
}

.rst-flash {
    background: var(--success-100);
    color: var(--success-700);
}

.rst-refusal {
    background: var(--red-100);
    color: var(--red-700);
}

.rst-panel,
.rst-day {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 16px;
    margin-bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.rst-head {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--navy-800);
    line-height: 1.55;
    max-width: 800px;
}

.rst-fields {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
}

.rst-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.rst-field--wide {
    grid-column: span 2;
}

.rst-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.rst-field input,
.rst-field select {
    height: 33px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12px;
    color: var(--navy-900);
}

.rst-actions {
    display: flex;
    align-items: center;
    gap: 14px;
}

.rst-day-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
}

.rst-day-label {
    font-size: 12px;
    font-weight: 700;
    color: var(--navy-900);
}

.rst-none {
    font-size: 11.5px;
    color: var(--slate-500);
    line-height: 1.6;
    margin: 0;
}

.rst-row {
    display: flex;
    align-items: baseline;
    gap: 14px;
    border-top: 1px solid var(--navy-100);
    padding-top: 8px;
    font-size: 11.5px;
    color: var(--navy-900);
    line-height: 1.55;
}

.rst-row.open .rst-who {
    color: var(--amber-700);
    font-weight: 700;
}

.rst-post {
    flex: 1 1 40%;
    font-weight: 600;
}

.rst-window {
    flex: 0 0 190px;
    color: var(--slate-600);
}

.rst-who {
    flex: 1 1 30%;
}

.rst-why {
    display: block;
    font-size: 10.5px;
    font-weight: 400;
    color: var(--slate-500);
}

.rst-assign {
    display: flex;
    align-items: center;
    gap: 10px;
    background: var(--navy-100);
    border-radius: 10px;
    padding: 10px 13px;
}

.rst-assign label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-600);
}

.rst-assign select {
    height: 31px;
    border: 1px solid var(--navy-200);
    border-radius: 8px;
    background: var(--white);
    padding: 0 9px;
    font: inherit;
    font-size: 11.5px;
    color: var(--navy-900);
}
</style>
