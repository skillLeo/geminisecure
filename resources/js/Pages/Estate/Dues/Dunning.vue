<script setup>
import { computed, ref } from 'vue'
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useWireframe } from '../../../composables/useWireframe'
import { useScreenState } from '../../../composables/useScreenState'

/**
 * Dunning & reminders — board screen community-admin-08.
 *
 * TWO PANES THAT MUST NEVER READ EACH OTHER, and that is the whole screen. On
 * the left is what the estate SENT; on the right is what it WILL send. The
 * template is editable and the log is not, because a dispute about an arrear is
 * settled from the words a resident actually received — so `dunning_notices`
 * carries its own finished subject and body, frozen by `Collections::send` at
 * the moment of sending, and nothing anywhere re-renders a logged notice from
 * the wording that happens to be in force today.
 *
 * THE LOG IS CHRONOLOGICAL, WHICH THE BOARD IS NOT. The board draws Lot 9's
 * "Yesterday" above Lot 21's "Today", and there is no column for a hand-set
 * position; a delivery log that is not in time order cannot be read at all. The
 * server orders by `sent_at` and the drawn sequence is a mock's arrangement.
 * Reproduced as the server sends it rather than shuffled to match the picture.
 *
 * NO SUB-NAVIGATION. Boards 5 and 6 each draw a tab strip above their content
 * and this one draws none — `.content` holds a single `.main-grid` and nothing
 * else (brief-08, and the board itself). The strip is 40px tall and would push
 * every row of the log and every line of the editor down by that much, so
 * adding one would not be a tab strip, it would be a different screen. The way
 * back to the arrears list is the sidebar item this screen sits under.
 *
 * NOTHING HERE POSTS A JOURNAL ENTRY. Chasing a debt does not restate it: the
 * receivable was recognised when the charge was raised, and this module is a
 * communications log over the AR sub-ledger. The Balance column is read from
 * the ledger when the screen is drawn, which is why it cannot drift from the
 * arrears board beside it.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    /** The most recent notices, newest first. Each row is a fact, not a render. */
    sends: { type: Array, required: true },
    /** The active ladder, in stage order — board 8's three tabs. */
    templates: { type: Array, required: true },
    /** The closed catalogue of merge tokens a template may use. */
    mergeFields: { type: Array, required: true },
    /** The channel combinations this estate can send on, key → label. */
    channels: { type: Object, required: true },
    /** Proposed steps waiting on the committee. Nothing here sends. */
    drafts: { type: Array, required: true },
    canSend: { type: Boolean, required: true },
    canAdopt: { type: Boolean, required: true },
    adoptBlockedReason: { type: String, required: true },
})

/*
 * The ten estate boards are ten stylesheets that disagree with each other, so
 * each is scoped to its own body class and a screen names the board it
 * reproduces. Without this line the page renders with no board CSS at all.
 */
useWireframe('community-admin-02-arrears-ledger-payment-plan-and-dunning')

const page = usePage()

/*
 * Five of the six states. The log carries no filter — there is no query on this
 * screen that could have excluded a send — so `empty-filtered` cannot arise
 * from the data. It is still handled below rather than falling through to a
 * table: forcing it in local development draws the same first-use panel, since
 * offering to clear a filter that does not exist would invent a control to
 * explain a state nobody reached.
 */
const state = useScreenState({
    rows: () => props.sends.length,
})

const retry = () => router.reload()

/**
 * The Balance column: whole dollars, comma-grouped, no decimals.
 *
 * From MINOR UNITS — integer cents — divided at the last possible moment and
 * never added to afterwards. The estate's ledger is denominated in JMD and the
 * board prints a bare $, which is how Jamaica writes its own currency
 * domestically; reproduced rather than corrected to "J$".
 *
 * This is NOT the figure the resident was quoted. That one is rendered into the
 * notice body at send time and frozen there. This is what the unit owes now,
 * summed from posted journal lines, which is the number a treasurer reading the
 * log beside the arrears board needs the two screens to agree on.
 */
const amount = (minor) => `$${Math.round(minor / 100).toLocaleString('en-US')}`

/**
 * What each delivery state means, under the cursor.
 *
 * DELIVERY IS FIVE FACTS, NOT TWO. queued, sent, delivered, failed and bounced
 * are different things to a treasurer chasing a household: queued means nothing
 * has left the building, delivered means a channel confirmed arrival, and the
 * gap between them is where an unanswered reminder actually sits. The pill's
 * text is the state's own word in every case — "Queued", "Sent", "Delivered",
 * and for a failure its own detail, "SMS failed" rather than "Failed", because
 * a multi-channel step can fail on one leg and land on the others.
 *
 * Keyed on the label because the raw `delivery_state` is not on this payload:
 * `Collections::dunningBoard` sends the rendered label and a two-valued tone,
 * and the board draws exactly two pill grounds — green for anything on its way,
 * red for anything that failed — so a per-state colour would be a style no
 * board has drawn. Anything absent from this map is a failure that named
 * itself, and `tone` says which side of the line it fell on.
 */
const DELIVERY_MEANING = {
    Queued: 'Written to the log and not yet handed to a channel. Nothing has reached the resident.',
    Sent: 'Handed to the channel, which has not yet said what became of it.',
    Delivered: 'The channel confirmed it arrived.',
    Bounced: 'It reached nowhere and came back.',
}

const deliveryMeaning = (row) =>
    DELIVERY_MEANING[row.status] ??
    (row.tone === 'failed'
        ? 'A channel refused this notice. The step still went out on whichever legs it had left.'
        : 'This notice is on its way.')

/*
 * Which tab opens.
 *
 * The board draws "Reminder 1" selected rather than the first tab, and there is
 * a rule behind it rather than a designer's whim: "Day 20" fires BEFORE
 * anything is overdue — a pre-due courtesy with `days_overdue` of zero — so it
 * is not a rung of the escalation ladder. The editor opens on the first step
 * that chases an actual arrear, which is what a treasurer came here to reword.
 */
const firstLadderStep = props.templates.find((template) => template.days_overdue > 0)

const selectedId = ref((firstLadderStep ?? props.templates[0])?.id ?? null)

const selected = computed(() => props.templates.find((template) => template.id === selectedId.value) ?? null)

/** A merge token, anywhere in a body: {resident_first_name} and its kind. */
const TOKEN = /(\{[a-z0-9_]+\})/gi

/**
 * The template body, split into plain runs and merge tokens.
 *
 * Split rather than substituted, and rendered as text nodes rather than through
 * v-html: the raw token text is what the template stores and what the estate is
 * editing, and a body that arrived carrying markup would otherwise render it.
 *
 * ANY TOKEN-SHAPED RUN IS BOLDED, not only the five in the catalogue. The
 * catalogue is closed server-side, and a token outside it is left standing when
 * the notice renders — so a stray one is going to arrive on a resident's phone
 * as its own literal text. Amber in the editor is the earliest place that
 * mistake is legible, and the resident is the last person who should find it.
 */
const bodyParts = computed(() =>
    (selected.value?.body ?? '')
        .split(TOKEN)
        .filter((part) => part !== '')
        .map((part) => ({ text: part, token: /^\{[a-z0-9_]+\}$/i.test(part) }))
)

/**
 * Why the two write controls cannot be pressed.
 *
 * TWO DIFFERENT REFUSALS, and the permission is the one to show first. `canSend`
 * is `estate.dues_ledger.create` — the gate on the send route, and the same
 * permission that covers changing what a household is told about its debt. A
 * viewer without it is refused for a reason that will still be true on the day
 * the editor ships, so telling them the feature is coming would be telling them
 * about a control they will still not be allowed to press.
 */
const NO_CREATE_ACCESS =
    'Wording a dunning notice decides what a household is told about its debt, so it needs Dues & ledger create access. Your role can read this log and not change what the estate sends.'

/* ------------------------------------------------------------------ */
/* the editor (12 §1) */
/* ------------------------------------------------------------------ */

/*
 * A SAVE IS A DRAFT, ALWAYS. Nothing this panel does changes what the estate
 * sends: the collections run reads the ladder in force, and a step reworded at
 * four in the afternoon does not change what the four-o'clock run sends. What
 * puts a wording in force is the committee, against a resolution reference, on
 * the list below.
 */
const editing = ref(false)

const draftForm = useForm({
    template_id: null,
    label: '',
    stage: 1,
    channel: 'email',
    subject: '',
    body: '',
    days_overdue: 0,
})

/** Reword the step on display. The wording in force keeps sending meanwhile. */
const openEdit = () => {
    if (!props.canSend || !selected.value) {
        return
    }

    draftForm.clearErrors()
    Object.assign(draftForm, {
        template_id: selected.value.id,
        label: selected.value.label,
        stage: selected.value.stage,
        channel: selected.value.channel,
        subject: selected.value.subject,
        body: selected.value.body,
        days_overdue: selected.value.days_overdue,
    })
    editing.value = true
}

/** Propose a rung the estate does not have. */
const openNew = () => {
    if (!props.canSend) {
        return
    }

    draftForm.clearErrors()
    Object.assign(draftForm, {
        template_id: null,
        label: '',
        stage: Math.max(0, ...props.templates.map((template) => template.stage)) + 1,
        channel: 'email',
        subject: '',
        body: '',
        days_overdue: 0,
    })
    editing.value = true
}

/*
 * The tokens in the box that this estate cannot fill, found here so the mistake
 * is legible before the save rather than on a resident's phone. The server
 * refuses them too — this is the earliest warning, not the guard.
 */
const strayTokens = computed(() => {
    const found = `${draftForm.body} ${draftForm.subject}`.match(/\{[a-z0-9_]+\}/gi) ?? []

    return [...new Set(found.filter((token) => !props.mergeFields.includes(token.toLowerCase())))]
})

const submitDraft = () => {
    draftForm.post(`${duesPath.value}/dunning/drafts`, {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = false
        },
    })
}

/* Adoption — the committee's act, one resolution reference per draft. */
const adoptingId = ref(null)

const adoptForm = useForm({ resolution_reference: '' })

const openAdopt = (draft) => {
    if (!props.canAdopt) {
        return
    }

    adoptForm.clearErrors()
    adoptForm.resolution_reference = draft.resolution_reference ?? ''
    adoptingId.value = adoptingId.value === draft.id ? null : draft.id
}

const submitAdopt = (draft) => {
    if (adoptForm.resolution_reference.trim() === '') {
        return
    }

    adoptForm.post(`${duesPath.value}/dunning/drafts/${draft.id}/adopt`, {
        preserveScroll: true,
        onSuccess: () => {
            adoptingId.value = null
            adoptForm.reset()
        },
    })
}

/** Where this console is rooted, read off the page's own URL. */
const duesPath = computed(() => `${page.url.slice(0, page.url.indexOf('/finance'))}/finance`)
</script>

<template>
    <Head title="Dunning &amp; reminders" />

    <!--
      No search box and no profile chip: this board's topbar carries its title
      and one action, and rendering either would move the action out of place.
    -->
    <EstateConsole title="Dunning &amp; reminders" :estate-name="estate.name" active="dues_ledger">
        <template #actions>
            <button
                type="button"
                class="btn-primary-sm"
                :disabled="!canSend"
                :title="canSend ? 'Word a step the estate does not have yet. It saves as a draft and sends nothing until the committee puts it in force.' : NO_CREATE_ACCESS"
                @click="openNew"
            >
                <BoardIcon name="plus" :stroke="2" />
                <span>New template</span>
            </button>
        </template>

        <p v-if="page.props.flash?.success" class="dun-flash">{{ page.props.flash.success }}</p>

        <SkeletonRows v-if="state.isLoading.value" :rows="5" :columns="6" />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The dunning log could not be read"
            body="No notice has been sent and none has been withdrawn — nothing on this screen writes anything. This is a read that failed, and re-running it is safe."
            action-label="Try again"
            @action="retry"
        />

        <!--
          Above the panes on purpose. A dunning log names the households the
          estate is chasing and what each of them was told, so a refusal must
          not be drawn under a table that has already answered the question.
        -->
        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="The dunning log is not yours to see"
            body="It names every household the estate is chasing and what each was told, so it opens only to roles holding the Dues & ledger module. Yours does not — a committee officer or the estate administrator can grant it from the role access matrix."
        />

        <div v-else class="main-grid" style="display: grid; grid-template-columns: 1.3fr 1fr; gap: 18px">
            <div>
                <div class="panel-head" style="margin-bottom: 12px">
                    <h3 style="font-family: 'Poppins', sans-serif; font-size: 14.5px; color: var(--navy-900); font-weight: 600">
                        Recent sends
                    </h3>
                </div>

                <!--
                  The two empties are one screen here. `empty-filtered` is
                  reachable only by forcing it in local development, because the
                  log has no filter to have excluded anything, and a panel
                  offering to clear one would name a control this board does not
                  draw.
                -->
                <EmptyState
                    v-if="state.isEmpty.value || state.isEmptyFiltered.value"
                    variant="first-use"
                    title="No reminder has been sent from this estate"
                    body="This log holds one row for every dunning notice that leaves the estate: the step it was, the channels it went out on, the balance the ledger carried at that moment, and the exact wording the resident received. Nothing has been sent yet, so there is nothing here to settle a dispute from."
                />

                <!--
                  THE LOG IS NEVER RENDERED FROM THE TEMPLATE, and this is where
                  it would be tempting. Each row already names its step, the
                  editor across the grid already holds that step's wording, and
                  joining the two would put the message text in this table with
                  no extra payload at all. It would also be wrong the first time
                  anybody edited a template: `dunning_notices` stores the
                  finished subject and body AS SENT because the log exists to
                  settle a dispute about what a resident was actually told, and
                  a log that followed the wording would show them a message they
                  never received. Nothing on this screen may reach across the
                  grid for a word of it.
                -->
                <table v-else class="data-table">
                    <thead>
                        <tr>
                            <th>Unit</th>
                            <th>Step</th>
                            <th>Channel</th>
                            <th>Sent</th>
                            <th>Balance</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in sends" :key="row.id">
                            <!-- The lot without its phase prefix, as the board prints it. -->
                            <td>{{ row.unit }}</td>

                            <!-- The step the notice WAS, copied onto the row at send
                                 time. Not the label of the template it came from:
                                 "Reminder 2" and "Reminder 5" went out under wording
                                 grouped as "Reminder 1" and "Reminder 4+", and asked
                                 in a year which step ran, the row answers alone. -->
                            <td>{{ row.step }}</td>

                            <td>{{ row.channel }}</td>
                            <td>{{ row.sent }}</td>
                            <td>{{ amount(row.balance_minor) }}</td>
                            <td>
                                <div class="dun-status" :class="row.tone" :title="deliveryMeaning(row)">
                                    {{ row.status }}
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div>
                <div class="panel-head" style="margin-bottom: 12px">
                    <h3 style="font-family: 'Poppins', sans-serif; font-size: 14.5px; color: var(--navy-900); font-weight: 600">
                        Message templates
                    </h3>
                </div>

                <template v-if="selected">
                    <!--
                      Local state, not a link, and the one place this screen
                      differs from board 5's filter chips deliberately. Those
                      chips filter the query, because the ageing cards above them
                      would otherwise report the estate while the list reported
                      one phase. A tab here chooses which wording is on display
                      and changes nothing the server computed — least of all the
                      log, which is every step the estate sent whatever tab is
                      open.
                    -->
                    <div class="tmpl-tabs">
                        <button
                            v-for="template in templates"
                            :key="template.id"
                            type="button"
                            class="tmpl-tab"
                            :class="{ active: template.id === selectedId }"
                            @click="selectedId = template.id"
                        >
                            {{ template.label }}
                        </button>
                    </div>

                    <div class="tmpl-editor">
                        <div class="tmpl-body">
                            <template v-for="(part, i) in bodyParts" :key="i">
                                <b v-if="part.token">{{ part.text }}</b>
                                <template v-else>{{ part.text }}</template>
                            </template>
                        </div>

                        <!--
                          A catalogue, not a control strip. Each chip names a
                          token a template MAY use, and every one of them has to
                          be resolvable against a unit at the instant of sending
                          — a token nobody can resolve is a sentence with a hole
                          in it arriving on a resident's phone. They are not
                          buttons because there is no editable field to insert
                          one into: the body above is the wording as stored, and
                          the editor that would take a click lands with this
                          board's write path.
                        -->
                        <div class="merge-row">
                            <div v-for="field in mergeFields" :key="field" class="merge-chip">{{ field }}</div>
                        </div>

                        <button
                            type="button"
                            class="stack-btn primary"
                            style="width: 100%; justify-content: center"
                            :disabled="!canSend"
                            :title="canSend ? 'Reword this step. It saves as a draft — what the estate sends does not change until the committee puts the new wording in force.' : NO_CREATE_ACCESS"
                            @click="openEdit"
                        >
                            <span>Save template</span>
                        </button>
                    </div>
                </template>

                <!--
                  AUTHORED. The board draws a rate card nobody is editing, so
                  it has neither of the two panels below.

                  The editor. Every save is a draft: the collections run reads
                  the ladder in force and never a draft, so a final demand
                  reworded at four in the afternoon does not change what the
                  four-o'clock run sends.
                -->
                <form v-if="editing" class="dun-panel" @submit.prevent="submitDraft">
                    <div class="dun-head">
                        {{ draftForm.template_id === null ? 'A step the estate does not have yet.' : `Reword ${draftForm.label}.` }}
                        It saves as a draft. Nothing the estate sends changes until the committee puts it in force
                        against a resolution.
                    </div>

                    <div class="dun-fields">
                        <div class="dun-field dun-field--wide">
                            <label for="dt-label">What this step is called</label>
                            <input id="dt-label" v-model="draftForm.label" type="text" required maxlength="80" placeholder="Final demand" />
                        </div>
                        <div class="dun-field">
                            <label for="dt-stage">Stage — the rung</label>
                            <input id="dt-stage" v-model.number="draftForm.stage" type="number" min="0" max="20" required />
                        </div>
                        <div class="dun-field">
                            <label for="dt-days">Fires at, days past due</label>
                            <input id="dt-days" v-model.number="draftForm.days_overdue" type="number" min="0" max="365" required />
                        </div>
                        <div class="dun-field dun-field--wide">
                            <label for="dt-channel">How it goes out</label>
                            <select id="dt-channel" v-model="draftForm.channel" required>
                                <option v-for="(label, key) in channels" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </div>
                        <div class="dun-field dun-field--wide">
                            <label for="dt-subject">Subject line</label>
                            <input id="dt-subject" v-model="draftForm.subject" type="text" required maxlength="190" />
                        </div>
                        <div class="dun-field dun-field--full">
                            <label for="dt-body">The notice</label>
                            <textarea id="dt-body" v-model="draftForm.body" rows="7" required maxlength="4000"></textarea>
                        </div>
                    </div>

                    <div class="merge-row">
                        <div v-for="field in mergeFields" :key="field" class="merge-chip">{{ field }}</div>
                    </div>

                    <p v-if="strayTokens.length" class="dun-error">
                        {{ strayTokens.join(', ') }} cannot be filled in against a household, so it would arrive on a
                        resident's phone as its own literal text.
                    </p>

                    <div v-if="draftForm.errors.body" class="dun-error">{{ draftForm.errors.body }}</div>
                    <div v-if="draftForm.errors.label" class="dun-error">{{ draftForm.errors.label }}</div>

                    <div class="dun-actions">
                        <button
                            type="submit"
                            class="btn-primary-sm"
                            :disabled="draftForm.processing || draftForm.label.trim() === '' || draftForm.body.trim() === ''"
                            :title="draftForm.label.trim() === '' || draftForm.body.trim() === '' ? 'A step has a name and a body.' : 'Save it as a draft for the committee.'"
                        >
                            <span>{{ draftForm.processing ? 'Saving…' : 'Save as draft' }}</span>
                        </button>
                        <button type="button" class="text-link-sm" @click="editing = false">Cancel</button>
                    </div>
                </form>

                <!--
                  Drafts waiting on the committee. Adopted ones are not here —
                  what they say is readable from the ladder itself, and leaving
                  a decided thing on a "waiting" list would misreport it.
                -->
                <div v-if="drafts.length" class="dun-drafts">
                    <div class="dun-head">Waiting on the committee — none of these is being sent.</div>

                    <div v-for="draft in drafts" :key="draft.id" class="dun-draft">
                        <div class="dun-draft-top">
                            <div>
                                <div class="dun-draft-name">{{ draft.target }}</div>
                                <div class="dun-draft-sub">
                                    Stage {{ draft.stage }} · {{ draft.channel_label }} · day {{ draft.days_overdue }} ·
                                    drafted by {{ draft.drafted_by }}{{ draft.drafted_at ? ` on ${draft.drafted_at}` : '' }}
                                </div>
                            </div>
                            <button
                                type="button"
                                class="text-link-sm"
                                :disabled="!canAdopt"
                                :title="canAdopt ? 'Put this wording in force against the committee resolution that agreed it.' : adoptBlockedReason"
                                @click="openAdopt(draft)"
                            >
                                Put in force
                            </button>
                        </div>

                        <form v-if="adoptingId === draft.id" class="dun-adopt" @submit.prevent="submitAdopt(draft)">
                            <label :for="`dr-ref-${draft.id}`">
                                The committee resolution that agreed it — the minute or resolution number from the
                                estate's own book.
                            </label>
                            <input
                                :id="`dr-ref-${draft.id}`"
                                v-model="adoptForm.resolution_reference"
                                type="text"
                                required
                                maxlength="80"
                                placeholder="Res. 2026-14"
                            />
                            <div v-if="adoptForm.errors.resolution_reference" class="dun-error">
                                {{ adoptForm.errors.resolution_reference }}
                            </div>
                            <div class="dun-actions">
                                <button
                                    type="submit"
                                    class="btn-primary-sm"
                                    :disabled="adoptForm.processing || adoptForm.resolution_reference.trim() === ''"
                                    :title="adoptForm.resolution_reference.trim() === '' ? 'Name the resolution.' : 'Put this wording in force. Notices already sent keep the wording they went out with.'"
                                >
                                    <span>{{ adoptForm.processing ? 'Putting in force…' : 'Put in force' }}</span>
                                </button>
                                <button type="button" class="text-link-sm" @click="adoptingId = null">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!--
                  An estate with no active step. The ladder is data, not code —
                  a step carries its own stage, channels and wording — so an
                  estate mid-onboarding can reach this screen with nothing to
                  word, and a bare heading over white space would read as a
                  screen that failed rather than one with nothing in it yet.
                -->
                <EmptyState
                    v-else
                    variant="first-use"
                    title="No dunning step is set up"
                    body="The escalation ladder has no active step, so there is no wording to edit and nothing for the estate to send. Each step carries its own stage, its own channels and its own text — an advance notice before the due date, then the reminders that follow it."
                />
            </div>
        </div>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws its topbar action, its template tabs
 * and its save control as <div>s; here they are real buttons, and a <button>
 * arrives wearing the system UI font and a border of the browser's own.
 * Everything visible still comes from the board's .btn-primary-sm, .tmpl-tab
 * and .stack-btn.
 *
 * THE BORDER RESETS NAME THE VARIANTS THAT HAVE NO BORDER, and nothing else.
 * A blanket `button { border: 0 }` would out-specify any board rule that draws
 * one — .btn-outline-sm carries 1.5px solid var(--navy-200) and would lose it
 * silently, which is a real fidelity defect small enough to survive a passing
 * measurement (see the comment in Gemini/Reports/ReportShell.vue). This screen
 * draws none of those, and the resets below are still written variant by
 * variant so that adding one later cannot quietly strip it.
 *
 * BACKGROUND IS RESET NOWHERE. .tmpl-tab declares navy-100 and .tmpl-tab.active
 * navy-600; a scoped `button.tmpl-tab { background: none }` ties with the first
 * on specificity, wins on order, and rubs the ground off every inactive tab.
 * .btn-primary-sm and .stack-btn.primary declare their own faces too, so the
 * browser's buttonface never shows through anywhere here.
 *
 * Not one declaration below introduces a colour, a size or a spacing.
 */
button {
    font: inherit;
}

button.btn-primary-sm,
button.tmpl-tab,
button.stack-btn.primary {
    border: 0;
}

button[disabled] {
    cursor: not-allowed;
}

button.text-link-sm {
    border: 0;
    background: none;
    padding: 0;
    cursor: pointer;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws a ladder nobody is editing and no
 * draft waiting on anybody, so it has none of this. Kept to the tokens the
 * boards define and to the shapes they already use.
 */
.dun-flash {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 14px;
    background: var(--green-100);
    color: var(--green-700);
}

.dun-panel,
.dun-drafts {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 16px;
    margin-top: 14px;
    display: flex;
    flex-direction: column;
    gap: 11px;
}

.dun-head {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--navy-800);
    line-height: 1.5;
}

.dun-fields {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
}

.dun-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.dun-field--wide,
.dun-field--full {
    grid-column: span 2;
}

.dun-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.dun-field input,
.dun-field select {
    height: 32px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12px;
    color: var(--navy-900);
}

.dun-field textarea {
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 9px 10px;
    font: inherit;
    font-size: 12px;
    line-height: 1.6;
    color: var(--navy-900);
    resize: vertical;
}

.dun-error {
    font-size: 11px;
    font-weight: 600;
    color: var(--red-700);
    line-height: 1.5;
}

.dun-actions {
    display: flex;
    align-items: center;
    gap: 13px;
}

.dun-draft {
    border-top: 1px solid var(--navy-100);
    padding-top: 11px;
    display: flex;
    flex-direction: column;
    gap: 9px;
}

.dun-draft-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}

.dun-draft-name {
    font-size: 12px;
    font-weight: 700;
    color: var(--navy-900);
    line-height: 1.5;
}

.dun-draft-sub {
    font-size: 10.5px;
    color: var(--slate-500);
    line-height: 1.5;
}

.dun-adopt {
    display: flex;
    flex-direction: column;
    gap: 6px;
    background: var(--navy-100);
    border-radius: 10px;
    padding: 11px 13px;
}

.dun-adopt label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-600);
    line-height: 1.5;
}

.dun-adopt input {
    height: 32px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12px;
    color: var(--navy-900);
}
</style>
