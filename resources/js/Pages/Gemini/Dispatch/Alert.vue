<script setup>
import { Head, router } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import { useLiveDispatch } from '../../../composables/useLiveDispatch.js'

/**
 * Panic alert response — board screen super-admin-17.
 *
 * DOM and class names are the board's. The two inline styles — the dimmed
 * pending timeline row and the message box — are the board's own, copied
 * rather than promoted to classes the design does not define.
 *
 * The board draws the four actions as <div>s. Two of them are real buttons
 * here that POST and change state; the other two are rendered disabled and
 * captioned, because nothing in this system records a second guard being
 * dispatched or an escalation to the JCF, and a life-safety button that
 * accepts a click and does nothing is the worst control on the screen.
 */
const props = defineProps({
    alert: { type: Object, required: true },
    timeline: { type: Array, required: true },
    actions: { type: Object, required: true },
    /** This alert's own estate, and no other. */
    estateIds: { type: Array, default: () => [] },
})

/**
 * The second life-safety surface. A dispatcher holding this open must see the
 * status change under them — a guard acknowledging on the ground, the alert
 * resolving — without reloading.
 *
 * It subscribes to one estate: this alert's own. The queue and the map watch
 * every estate a dispatcher covers, but this screen is about one incident at one
 * community, and listening wider would refresh it every time anything happened
 * anywhere.
 *
 * The poll stays behind the socket at thirty seconds and returns to three the
 * moment the channel drops. On a screen a dispatcher is holding open DURING an
 * incident, a socket that quietly stopped delivering would look exactly like an
 * alert nobody was responding to.
 */
useLiveDispatch({
    only: ['alert', 'timeline', 'actions'],
    intervalMs: 3000,
    estateIds: props.estateIds,
})

const acknowledge = () => {
    router.post(props.actions.acknowledge_url, {}, { preserveScroll: true })
}

/**
 * "Resolve & classify" — the classification is the note, and it is real.
 *
 * A native prompt rather than a modal, deliberately: the board draws one
 * button and no dialog, and inventing a panel here would mean authoring CSS
 * the design does not have. The note is stored on the alert, shown on the
 * timeline and written to the audit log.
 *
 * Cancel returns null and posts nothing. An empty string is a deliberate "no
 * note" and does post — closing an alert without a word is a choice a
 * dispatcher is allowed to make under pressure.
 */
const resolve = () => {
    const note = window.prompt(
        'Resolve this alert. Say how it was classified — this is recorded on the alert and in the audit log, '
            + 'and the alert cannot be reopened from this console.',
        '',
    )

    if (note === null) {
        return
    }

    router.post(props.actions.resolve_url, { note }, { preserveScroll: true })
}
</script>

<template>
    <Head :title="`${alert.headline} — ${alert.who}`" />

    <GeminiConsole :title="`${alert.headline} — ${alert.who}`">
        <div class="response-layout">
            <div>
                <div class="alert-hero">
                    <div class="ah-top">
                        <div>
                            <div class="ah1">{{ alert.headline }}</div>
                            <div class="ah2">{{ alert.subtitle }}</div>
                        </div>
                        <div class="ah-badge">{{ alert.status_label }}</div>
                    </div>
                    <div class="ah-stats">
                        <div>
                            <div class="ahs-v">{{ alert.fired }}</div>
                            <div class="ahs-l">Fired</div>
                        </div>
                        <div>
                            <div class="ahs-v">{{ alert.responder }}</div>
                            <div class="ahs-l">Nearest guard</div>
                        </div>
                        <div>
                            <div class="ahs-v">{{ alert.location_source }}</div>
                            <div class="ahs-l">Location source</div>
                        </div>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-head">Response timeline</div>
                    <!--
                      Every state this alert has actually reached, and the ones
                      it has not, drawn as pending. Nothing is narrated that the
                      row cannot prove.
                    -->
                    <div v-for="(step, i) in timeline" :key="i" class="timeline-row">
                        <div class="tl-dot-col">
                            <div class="tl-dot" :class="{ pending: step.pending }"></div>
                            <div v-if="i < timeline.length - 1" class="tl-line"></div>
                        </div>
                        <div class="tl-txt" :style="step.pending ? 'opacity:.5;' : null">
                            <div class="tl1">{{ step.title }}</div>
                            <div class="tl2">{{ step.meta }}</div>
                        </div>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-head">Live channel</div>
                    <div class="chat-thread">
                        <div>
                            <!--
                              A system notice, not a conversation. There is no
                              message model anywhere in this system, so drawing
                              a thread of plausible-looking traffic here would
                              be inventing a record of a response.
                            -->
                            <div class="chat-bubble dispatcher">
                                Messaging opens when the Guard App ships. Until then, reach
                                {{ alert.message_target }} by radio, and record the outcome with the
                                actions beside this panel.
                            </div>
                            <div class="chat-meta">Dispatch console · not a live channel</div>
                        </div>
                    </div>
                    <!-- The board's own inline style, verbatim: its stylesheet
                         defines no class for this box. -->
                    <div style="margin-top:12px;height:38px;border-radius:10px;background:var(--navy-100);display:flex;align-items:center;padding:0 13px;">
                        <input
                            type="text"
                            disabled
                            title="Messaging opens when the Guard App ships — there is nowhere for this to be delivered yet"
                            :placeholder="`Message ${alert.message_target}…`"
                            style="font-size:12px;color:var(--slate-500);"
                        />
                    </div>
                </div>
            </div>

            <div>
                <div class="panel">
                    <div class="panel-head">Resident</div>
                    <div class="info-row">
                        <div class="ir-l">Name</div>
                        <div class="ir-r">{{ alert.who }}</div>
                    </div>
                    <div class="info-row">
                        <div class="ir-l">Unit</div>
                        <div class="ir-r">{{ alert.unit }}</div>
                    </div>
                    <!--
                      The three below are answered honestly rather than filled
                      in. Household composition and emergency contacts live in
                      the estate's own database, which this console never opens,
                      and medical notes are not held centrally at all. A
                      plausible figure here is worse than none: a dispatcher
                      would act on it.
                    -->
                    <div class="info-row">
                        <div class="ir-l">Household</div>
                        <div class="ir-r">{{ alert.household }}</div>
                    </div>
                    <div class="info-row">
                        <div class="ir-l">Medical notes</div>
                        <div class="ir-r">{{ alert.medical_notes }}</div>
                    </div>
                    <div class="info-row">
                        <div class="ir-l">Emergency contact</div>
                        <div class="ir-r">{{ alert.emergency_contact }}</div>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-head">Actions</div>
                    <div class="action-btns">
                        <button
                            type="button"
                            class="action-btn primary"
                            :disabled="!actions.can_acknowledge"
                            :title="actions.acknowledge_title"
                            @click="acknowledge"
                        >
                            <BoardIcon name="check" :stroke="3" />
                            <span>{{ actions.acknowledge_label }}</span>
                        </button>

                        <button
                            type="button"
                            class="action-btn outline"
                            disabled
                            title="Available when the Guard App ships — dispatching a second guard reaches their handset, and nothing here records that assignment yet"
                        >
                            <BoardIcon name="guards" :stroke="1.7" />
                            <span>Dispatch a second guard</span>
                        </button>

                        <button
                            type="button"
                            class="action-btn danger"
                            disabled
                            title="Available when the JCF escalation integration ships — no escalation is recorded anywhere in this system yet"
                        >
                            <BoardIcon name="alert-strong" />
                            <span>Escalate to police / JCF</span>
                        </button>

                        <button
                            type="button"
                            class="action-btn outline"
                            :disabled="!actions.can_resolve"
                            :title="actions.resolve_title"
                            @click="resolve"
                        >
                            <BoardIcon name="check" :stroke="2" />
                            <span>Resolve &amp; classify</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/*
 * The only authored CSS on this screen, and every rule below REMOVES a browser
 * default rather than adding a style.
 *
 * The board draws the actions as <div>s and the message box as a <span> of
 * placeholder text. They are a <button> and an <input> here so they can be
 * operated — or visibly refuse to be — and the browser's own chrome for those
 * elements would otherwise show through and change the pixels the board
 * specifies. Each variant's background, border and colour still come from the
 * board's own rules, which are more specific than these and win.
 */
.action-btn {
    -webkit-appearance: none;
    appearance: none;
    font-family: inherit;
    cursor: pointer;
}

/*
 * Only the filled variants, on purpose.
 *
 * `.action-btn.outline` carries a 1.5px border the board draws, and a blanket
 * `.action-btn { border: 0 }` here would match its specificity exactly — which
 * of the two won would then depend on the order the bundler emitted them in,
 * and the outlined buttons would lose their border on some builds. Naming the
 * two variants that need the default border removed leaves the third alone.
 */
.action-btn.primary,
.action-btn.danger {
    border: 0;
}

/* The message box: an input dressed back down to the span it replaces.
 * `flex: 1` gives it the row's width so the placeholder cannot clip, where a
 * default input would size itself to about twenty characters. */
.panel input {
    flex: 1;
    min-width: 0;
    border: 0;
    outline: 0;
    background: transparent;
    font-family: inherit;
    padding: 0;
}

.panel input::placeholder {
    color: inherit;
    opacity: 1;
}

/* Deliberately inert, and visibly so: no silent click. Changes the cursor and
 * nothing that occupies space. */
.action-btn[disabled],
.panel input[disabled] {
    cursor: not-allowed;
}
</style>
