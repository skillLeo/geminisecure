<script setup>
import { computed, ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
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
    canSend: { type: Boolean, required: true },
    reasons: { type: Object, required: true },
})

/*
 * The ten estate boards are ten stylesheets that disagree with each other, so
 * each is scoped to its own body class and a screen names the board it
 * reproduces. Without this line the page renders with no board CSS at all.
 */
useWireframe('community-admin-02-arrears-ledger-payment-plan-and-dunning')

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

const saveBlockedBy = computed(() => (props.canSend ? props.reasons.saveTemplate : NO_CREATE_ACCESS))

const newTemplateBlockedBy = computed(() => (props.canSend ? props.reasons.newTemplate : NO_CREATE_ACCESS))
</script>

<template>
    <Head title="Dunning &amp; reminders" />

    <!--
      No search box and no profile chip: this board's topbar carries its title
      and one action, and rendering either would move the action out of place.
    -->
    <EstateConsole title="Dunning &amp; reminders" :estate-name="estate.name" active="dues_ledger">
        <template #actions>
            <button type="button" class="btn-primary-sm" disabled :title="newTemplateBlockedBy">
                <BoardIcon name="plus" :stroke="2" />
                <span>New template</span>
            </button>
        </template>

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
                            disabled
                            :title="saveBlockedBy"
                        >
                            <span>Save template</span>
                        </button>
                    </div>
                </template>

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

/* Both inert controls say why under the cursor; the cursor says it too. */
button[disabled] {
    cursor: not-allowed;
}
</style>
