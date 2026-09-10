<script setup>
import { computed } from 'vue'
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * Schedule a meeting — board screen community-admin-12.
 *
 * A DRAFT, AND ONLY A DRAFT. `Governance::scheduleMeeting()`'s own docblock:
 * "writes a draft and nothing else." Saving this form never tells a single
 * household anything — that is a second, separate act, `publishMeeting()`,
 * taken from the register (`Meetings.vue`) once the draft is visible
 * alongside the meetings already announced. Board 12 draws one button and it
 * posts to `meeting.store`; there is no publish control on this screen at
 * all, and `canPublish` below is declared and never rendered for exactly
 * that reason.
 *
 * THE NOTICE PERIOD IS NEVER CHECKED HERE. A secretary sketching an AGM for a
 * fortnight's time has done nothing wrong, and a form that refused to save
 * would lose the agenda they had just typed — so nothing on this screen
 * blocks a save over the statutory notice period. What it DOES do is put the
 * same rule on the Date field's own title, before anything is typed, so the
 * refusal `publishMeeting()` will eventually give is legible a step earlier
 * rather than only after a draft has been saved and re-opened on the
 * register. See `dateHint` below.
 *
 * FOUR FIELDS ARE REAL DEFAULTS AND THREE ARE DELIBERATELY BLANK. Meeting
 * title, audience, quorum and recording all arrive from
 * `Governance::schedulerDefaults()`, counted or configured from real rows —
 * the estate's own phase count, its configured quorum percentage, its
 * recording policy. Date, time and the agenda arrive as nothing, because
 * nobody has chosen a date, a time or an order of business yet, and
 * defaulting any of them the way board 12's own mock-up shows them would put
 * a decision on the record that no secretary made — the same reasoning
 * NewCharge.vue's due-date field already follows.
 *
 * THE QUORUM FIELD IS A READOUT, NOT A CONTROL. `scheduleMeeting()` always
 * writes `$settings->meeting_quorum_percent` onto the row, whatever a
 * request carries — a secretary choosing a different threshold per meeting
 * would be deciding a governance rule this screen has no authority to set.
 * So it is a `readonly` input, styled exactly as every other field, carrying
 * the reason on its own title rather than pretending to be an editable one.
 */
const props = defineProps({
    estate: { type: Object, required: true },

    /** Board 12's segmented control, in `Meeting::TYPES`' own order. */
    types: { type: Array, required: true },

    /** Every phase this estate has, for the Audience field's phase-scoped options. */
    phases: { type: Array, required: true },

    /** What this blank form starts holding — see the note above. */
    defaults: { type: Object, required: true },

    eligibleHouseholds: { type: Number, required: true },

    /** What a save must not silently ignore, though it may never refuse on it. */
    notice: { type: Object, required: true },

    canSchedule: { type: Boolean, required: true },

    /*
     * Declared and deliberately not rendered. Publishing happens on the
     * meeting register and nowhere else — a draft this screen has not yet
     * saved has nothing to publish, so there is no publish control here for
     * this flag to gate. An undeclared prop would fall through as an HTML
     * attribute rather than be ignored, so it is named here with its reason
     * instead of dropped.
     */
    canPublish: { type: Boolean, required: true },

    blockedReason: { type: String, required: true },
})

/*
 * Boards 9 to 12 share this sheet. Naming the wrong one renders this screen
 * with no form panel, no segmented control and no agenda rows at all.
 */
useWireframe('community-admin-03-elections-nominations-results-and-meetings')

const page = usePage()

/*
 * Where this console is rooted, read off the page's own URL — production
 * gives each estate its own hostname and local puts the estate key in the
 * path, so cutting the URL that served this page is right in both and cannot
 * address an estate other than the one already open.
 */
const root = computed(() => {
    const cut = page.url.indexOf('/governance')

    return cut === -1 ? '' : page.url.slice(0, cut)
})

const governance = (suffix) => `${root.value}/governance${suffix}`

/*
 * Always populated. This is a blank form and not a list with a record behind
 * it — there is nothing here for `empty` to mean, and nothing here a filter
 * could apply to — so both are reachable only by forcing `?_state=`, exactly
 * as the sibling governance screens leave `denied` and `error` forceable
 * rather than wired to a signal nothing on this route ever sends.
 */
const state = useScreenState({ rows: () => 1 })

const form = useForm({
    type: props.defaults.type,
    title: props.defaults.title,

    // Blank, deliberately — see the docblock above.
    starts_on: '',
    starts_on_time: '',

    audience_scope: props.defaults.audience_scope,
    phase: null,

    recording_enabled: props.defaults.recording_enabled,
    recording_consent_notice: props.defaults.recording_consent_notice,

    // Empty — board 12's four rows are the design system's own illustration
    // of a filled-in agenda, not a draft anybody here has started.
    agenda: [],
})

/*
 * The one field the server actually asks for, composed from the two a date
 * input and a time input can bind to. `starts_on` and `starts_on_time` never
 * travel themselves — the server asked for one moment, not two strings.
 */
form.transform((data) => ({
    type: data.type,
    title: data.title,
    starts_at: data.starts_on === '' ? '' : `${data.starts_on}T${data.starts_on_time || '00:00'}`,
    audience_scope: data.audience_scope,
    phase: data.phase,
    recording_enabled: data.recording_enabled,
    recording_consent_notice: data.recording_consent_notice,
    agenda: data.agenda,
}))

/* ------------------------------------------------------------------ */
/* the segmented control */
/* ------------------------------------------------------------------ */

const selectType = (value) => {
    form.type = value
}

/* ------------------------------------------------------------------ */
/* audience — one field on the board, two columns on the row */
/* ------------------------------------------------------------------ */

const audienceOptions = computed(() => [
    { value: 'whole_estate', label: props.defaults.audience_label },
    ...props.phases.map((phase) => ({ value: `phase:${phase}`, label: `${phase} only` })),
    { value: 'committee', label: 'Committee members' },
])

const audienceChoice = computed({
    get: () => (form.audience_scope === 'phase' ? `phase:${form.phase ?? ''}` : form.audience_scope),
    set: (value) => {
        if (value.startsWith('phase:')) {
            form.audience_scope = 'phase'
            form.phase = value.slice('phase:'.length)
        } else {
            form.audience_scope = value
            form.phase = null
        }
    },
})

/* ------------------------------------------------------------------ */
/* recording — one field on the board, two columns on the row */
/* ------------------------------------------------------------------ */

const recordingChoice = computed({
    get: () => (form.recording_enabled ? 'on' : 'off'),
    set: (value) => {
        form.recording_enabled = value === 'on'
        form.recording_consent_notice = value === 'on'
    },
})

/* ------------------------------------------------------------------ */
/* the agenda */
/* ------------------------------------------------------------------ */

const addAgendaItem = () => {
    form.agenda.push({ start_time: '', text: '' })
}

const removeAgendaItem = (index) => {
    form.agenda.splice(index, 1)
}

/** Every `agenda.*` error the server sent back, keyed as it sent them. */
const agendaErrors = computed(() => Object.entries(form.errors).filter(([key]) => key.startsWith('agenda.')))

/* ------------------------------------------------------------------ */
/* the notice period, surfaced before it is a refusal */
/* ------------------------------------------------------------------ */

/**
 * Why the Date field carries the title it does.
 *
 * `Governance::publishMeeting()` is what actually refuses a date inside the
 * statutory notice period, and drafting is never refused for it. This is the
 * same rule said a step earlier, on the field a secretary is about to type
 * into, so the refusal is legible before anything is saved rather than
 * after — and it duplicates nothing, because saving this form never checks
 * it.
 */
const dateHint = computed(() => {
    const typeLabel = props.types.find((t) => t.value === form.type)?.label ?? 'This meeting'

    if (!props.notice.enforced) {
        return `When ${typeLabel} takes place. Publishing it — a later, separate act — is not held to a notice period on this estate.`
    }

    const days = props.notice.days[form.type]
    const earliest = props.notice.earliest[form.type]

    return (
        `When ${typeLabel} takes place. It can be drafted for any date — publishing it is the act the notice ` +
        `period guards, and ${typeLabel} needs ${days} day${days === 1 ? '' : 's'}' notice: the earliest date ` +
        `it could be published from today is ${earliest}.`
    )
})

/** Why the Quorum field cannot be typed into. */
const quorumReason = computed(
    () =>
        `${props.defaults.quorum_label}, fixed by the estate's own governance settings — a secretary choosing ` +
        `a different threshold per meeting would be deciding a rule nobody in this estate agreed to. Measured ` +
        `against the ${props.eligibleHouseholds} households on the register today.`
)

/* ------------------------------------------------------------------ */
/* the preview panel */
/* ------------------------------------------------------------------ */

const eyebrow = computed(() => props.types.find((t) => t.value === form.type)?.eyebrow ?? '')

/**
 * The board's own card title: the estate, the type, the year.
 *
 * "Phoenix Park Village 1 — AGM 2026". Composed here rather than on the
 * server because it joins three facts this screen already has — the estate's
 * own name, the type the secretary just picked, the year off the date they
 * just typed — and joining given facts is this card's own presentation of
 * them, not a fourth one.
 */
const previewTitle = computed(() => {
    const typeLabel = props.types.find((t) => t.value === form.type)?.label ?? ''
    const year = form.starts_on === '' ? new Date().getFullYear() : Number(form.starts_on.slice(0, 4))

    return `${props.estate.name} — ${typeLabel} ${year}`
})

const weekdayMonth = new Intl.DateTimeFormat('en-JM', { weekday: 'long', day: 'numeric', month: 'long' })

/** "10:00" (24-hour, off the native time input) to "10:00 AM". */
const timeOfDay = (time) => {
    const [hourStr, minute] = time.split(':')
    const hour = Number(hourStr)
    const period = hour >= 12 ? 'PM' : 'AM'
    const twelve = hour % 12 === 0 ? 12 : hour % 12

    return `${twelve}:${minute} ${period}`
}

/** "Saturday 27 September · 10:00 AM · Whole estate", built from whatever is filled in so far. */
const previewSummary = computed(() => {
    const parts = []

    if (form.starts_on !== '') {
        parts.push(weekdayMonth.format(new Date(`${form.starts_on}T00:00:00`)))
    }

    if (form.starts_on_time !== '') {
        parts.push(timeOfDay(form.starts_on_time))
    }

    parts.push(audienceOptions.value.find((option) => option.value === audienceChoice.value)?.label ?? '')

    return parts.filter(Boolean).join(' · ')
})

const submit = () => {
    /*
     * A form submits on Enter from any field, and a disabled button does not
     * stop it. The route is gated on `estate.governance.create`, so this is
     * only about not sending a draft that is going to bounce.
     */
    if (!props.canSchedule) {
        return
    }

    form.post(governance('/meetings'), { preserveScroll: true })
}
</script>

<template>
    <Head title="Schedule a meeting" />

    <EstateConsole title="Schedule a meeting" :estate-name="estate.name" active="governance">
        <template #actions>
            <button
                type="submit"
                form="meeting-form"
                class="btn-primary-sm"
                :disabled="!canSchedule || form.processing"
                :title="
                    canSchedule
                        ? 'Save this meeting as a draft. It notifies nobody until it is published from the meeting register.'
                        : blockedReason
                "
            >
                <svg viewBox="0 0 24 24" fill="none">
                    <rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.7" />
                    <path d="M3 10h18M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                </svg>
                <span>{{ form.processing ? 'Saving…' : 'Schedule meeting' }}</span>
            </button>
        </template>

        <!-- The whole screen is one payload, so nothing on it arrives before the rest. -->
        <SkeletonRows v-if="state.isLoading.value" :rows="6" :columns="2" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Scheduling a meeting is not part of your role’s access"
            body="A meeting binds the estate to a date, an audience and a quorum rule, so drafting one opens only to roles that hold Governance. A committee officer or the estate administrator can grant it from the role access matrix."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="This form could not be opened"
            body="The estate's own settings — its phases, its quorum rule, its notice periods — did not answer. Nothing has been drafted and nothing has been lost; re-running the read is safe."
            action-label="Try again"
            @action="router.reload()"
        />

        <!--
          Five of the six. The board draws one blank form and nothing to
          filter it by, so `empty-filtered` cannot occur here — forcing it
          falls through to the form below, exactly as it falls through on the
          sibling governance screens where the board draws no filter. `empty`
          is forceable too, though nothing on this route ever sends it for
          real: a blank form is always ready to fill in.
        -->
        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="Nothing to draft yet"
            body="A meeting starts as a blank form, and this is already one — title, audience, quorum and recording carry the estate's own defaults, and there is no earlier step before it."
        />

        <form
            v-else
            id="meeting-form"
            style="display: grid; grid-template-columns: 1.3fr 1fr; gap: 18px"
            @submit.prevent="submit"
        >
            <div class="form-panel">
                <div class="seg" role="radiogroup" aria-label="What kind of meeting this is">
                    <button
                        v-for="type in types"
                        :key="type.value"
                        type="button"
                        class="seg-item"
                        :class="{ active: form.type === type.value }"
                        role="radio"
                        :aria-checked="form.type === type.value"
                        @click="selectType(type.value)"
                    >
                        {{ type.label }}
                    </button>
                </div>

                <div class="m-field">
                    <label for="meeting-title">Meeting title</label>
                    <!--
                      The board draws this field .focused — its own
                      illustration of a title somebody has already typed —
                      and the ring follows the field having a value, exactly
                      as the sibling form pages bind it (see NewCharge.vue's
                      Unit field).
                    -->
                    <div class="m-input" :class="{ focused: form.title !== '' }">
                        <span>
                            <input id="meeting-title" v-model="form.title" type="text" required maxlength="160" />
                        </span>
                    </div>
                    <div v-if="form.errors.title" class="act-error">{{ form.errors.title }}</div>
                </div>

                <div class="m-two-col">
                    <div class="m-field">
                        <label for="starts-on">Date</label>
                        <div class="m-input">
                            <span>
                                <input
                                    id="starts-on"
                                    v-model="form.starts_on"
                                    type="date"
                                    required
                                    :title="dateHint"
                                />
                            </span>
                        </div>
                        <div v-if="form.errors.starts_at" class="act-error">{{ form.errors.starts_at }}</div>
                    </div>

                    <div class="m-field">
                        <label for="starts-on-time">Time</label>
                        <div class="m-input">
                            <span>
                                <input id="starts-on-time" v-model="form.starts_on_time" type="time" required />
                            </span>
                        </div>
                    </div>
                </div>

                <div class="m-field">
                    <label for="audience">Audience</label>
                    <div class="m-input">
                        <span>
                            <select id="audience" v-model="audienceChoice">
                                <option v-for="option in audienceOptions" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>
                        </span>
                    </div>
                    <div v-if="form.errors.audience_scope" class="act-error">{{ form.errors.audience_scope }}</div>
                    <div v-if="form.errors.phase" class="act-error">{{ form.errors.phase }}</div>
                </div>

                <div class="m-two-col">
                    <div class="m-field">
                        <label for="quorum">Quorum threshold</label>
                        <div class="m-input">
                            <span>
                                <input
                                    id="quorum"
                                    type="text"
                                    :value="defaults.quorum_label"
                                    readonly
                                    :title="quorumReason"
                                />
                            </span>
                        </div>
                    </div>

                    <div class="m-field">
                        <label for="recording">Recording</label>
                        <div class="m-input">
                            <span>
                                <select id="recording" v-model="recordingChoice">
                                    <option value="on">Enabled, with consent notice</option>
                                    <option value="off">Disabled</option>
                                </select>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="m-field">
                    <label>Agenda</label>

                    <div v-for="(item, index) in form.agenda" :key="index" class="agenda-item">
                        <div class="ai-time">
                            <input v-model="item.start_time" type="time" :aria-label="`Time for agenda item ${index + 1}`" />
                        </div>
                        <div class="ai-txt">
                            <input
                                v-model="item.text"
                                type="text"
                                maxlength="200"
                                required
                                placeholder="What this item covers"
                                :aria-label="`Agenda item ${index + 1}`"
                            />
                        </div>
                        <button
                            type="button"
                            class="ai-remove"
                            title="Remove this agenda item"
                            :aria-label="`Remove agenda item ${index + 1}`"
                            @click="removeAgendaItem(index)"
                        >
                            <svg viewBox="0 0 24 24" fill="none">
                                <path
                                    d="M6 6l12 12M18 6L6 18"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                />
                            </svg>
                        </button>
                    </div>

                    <div v-for="[key, message] in agendaErrors" :key="key" class="act-error">{{ message }}</div>

                    <button type="button" class="add-agenda" @click="addAgendaItem">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                        </svg>
                        <span>Add agenda item</span>
                    </button>
                </div>
            </div>

            <div class="form-panel">
                <div
                    style="
                        font-family: 'Poppins', sans-serif;
                        font-size: 13.5px;
                        color: var(--navy-900);
                        font-weight: 600;
                        margin-bottom: 14px;
                    "
                >
                    Preview
                </div>

                <div
                    style="
                        background: linear-gradient(135deg, var(--navy-600), var(--navy-800));
                        border-radius: 14px;
                        padding: 16px;
                        color: var(--white);
                        margin-bottom: 16px;
                    "
                >
                    <div style="font-size: 10px; color: var(--amber-500); font-weight: 700">{{ eyebrow }}</div>
                    <div style="font-family: 'Poppins', sans-serif; font-size: 15px; font-weight: 600; margin-top: 4px">
                        {{ previewTitle }}
                    </div>
                    <div style="font-size: 11px; color: rgba(255, 255, 255, 0.7); margin-top: 4px">
                        {{ previewSummary }}
                    </div>
                </div>

                <div style="font-size: 11.5px; color: var(--slate-500); line-height: 1.6">
                    Residents will see this on their Notices feed and in Meetings once scheduled. Only verified
                    households in the selected audience can join — there's no shareable link.
                </div>
            </div>
        </form>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only, and each removal names the element that needs it.
 *
 * The board draws every field as a <div class="m-input"><span>value</span></div>,
 * the segments as <div class="seg-item">, and the agenda's remove button and
 * "Add agenda item" as <div>s. Here they are real controls, and each arrives
 * wearing the browser's own border, background and font — the date and time
 * fields also carry their own picker chrome, which nothing below removes,
 * because the board draws no picker to compare it against and a native one
 * is what tells a secretary the field is a date. Nothing below introduces a
 * colour, size or weight the board does not already declare on `.m-input`,
 * `.seg-item` or `.agenda-item`: the control sits inside the board's own
 * <span> or <div>, so those rules already supply the type and `font: inherit`
 * picks it up.
 */
.m-input span {
    flex: 1;
    min-width: 0;
}

.m-input input,
.m-input select {
    width: 100%;
    border: 0;
    background: transparent;
    font: inherit;
    color: inherit;
    padding: 0;
    appearance: none;
    -webkit-appearance: none;
}

.m-input input[readonly] {
    cursor: default;
}

button.seg-item {
    border: 0;
    background: transparent;
    font: inherit;
    color: inherit;
    cursor: pointer;
}

.ai-time input,
.ai-txt input {
    width: 100%;
    border: 0;
    background: transparent;
    font: inherit;
    color: inherit;
    padding: 0;
}

.ai-txt input::placeholder {
    color: var(--slate-300);
    opacity: 1;
}

button.ai-remove,
button.add-agenda {
    border: 0;
    background: none;
    cursor: pointer;
}

/* The board draws this square white, not transparent. */
button.ai-remove {
    background: var(--white);
}

button.btn-primary-sm {
    border: 0;
    cursor: pointer;
}

button[disabled] {
    cursor: not-allowed;
}

/*
 * AUTHORED BELOW THIS LINE. Per-field errors, kept to the same tokens and
 * size as the sibling governance screens' `.act-error` — board 12 draws no
 * error at all, because nothing has ever been typed into it.
 */
.act-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--red-700);
    line-height: 1.5;
    margin-top: 4px;
}
</style>
