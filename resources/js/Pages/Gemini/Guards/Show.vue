<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'

/**
 * Guard profile — board screens super-admin-19 AND super-admin-23.
 *
 * ONE component, two boards. 19 draws Marcus Whyte, who is compliant; 23 draws
 * Devon Palmer, whose PSRA licence has lapsed. The difference between those two
 * boards is not styling and is not a second component — it is two FEATURE
 * STATES the record itself decides:
 *
 *   UN-ROSTERABLE          the Build Spec: "Expired licence sets un-rosterable
 *                          — a hard block, not a warning." The hero takes the
 *                          board's own .suspended treatment, the licence stat
 *                          stops promising a future expiry, and the reassign
 *                          control is GONE rather than offered and refused.
 *                          Offering to post an unlicensed officer somewhere
 *                          else is the one thing this screen must not do.
 *
 *   OPEN COMPLIANCE CASE   the timeline becomes the case file. Compliance
 *                          events lead it, because someone opening this record
 *                          has to know the officer cannot legally be on post
 *                          before they read which post that is, and the panel
 *                          heading admits they are there.
 *
 * D-034: an expired licence is exactly what a client should be able to see
 * about the guard standing at their gate, so none of this is softened.
 *
 * DOM and class names are the board's, including the inline grid style on the
 * two-column row, which is the board's own and is kept inline rather than
 * promoted to a class the design does not define.
 *
 * Two of the board's values have no column behind them and are not invented
 * here: the hero's standard hourly rate is replaced by the gross on the guard's
 * most recent payslip, and the employment panel's bank account and NIS number
 * by the employee number and email that the record actually holds.
 *
 * The status pill uses the board's own .status-badge classes rather than the
 * hard-coded colour inline style the board carries. That style states one
 * status whatever the guard's really is, which on the wrong officer would be a
 * lie told in the most prominent place on the screen.
 */
const props = defineProps({
    guard: { type: Object, required: true },
    history: { type: Array, required: true },
    /** The clients and posts this officer can be moved to (12 §2, Wave 5). */
    clientOptions: { type: Array, default: () => [] },
    postOptions: { type: Array, default: () => [] },
    canAct: { type: Boolean, default: false },
    actBlockedReason: { type: String, default: '' },
    /** A handset waiting to replace this officer's bound one (13 D1). */
    rebindRequests: { type: Array, default: () => [] },
})

/*
 * A REBIND IS A SUPERVISOR'S DECISION (13 D1). A guard who already has a bound
 * handset and enrols another is not rebound until somebody here says so — a lost
 * phone reported by the guard and a code used by somebody else look identical
 * from the enrolment endpoint. The decision is recorded against the officer's
 * current or next shift.
 */
const decideRebind = (request, approve) => {
    const note = approve ? '' : window.prompt('Why is this handset refused? The officer is told.')

    if (!approve && note === null) {
        return
    }

    router.post(`/guards/${props.guard.id}/device-rebinds/${request.id}/${approve ? 'approve' : 'deny'}`, { note: note ?? '' }, { preserveScroll: true })
}

/*
 * Five of the six. A profile has no filter, so `empty-filtered` cannot happen
 * here — there is no query to have excluded anything, and rendering a "clear
 * the filter" panel on a screen with no filter would invent a control to
 * explain a state that does not exist. Forcing it falls through to the
 * populated screen, which is the honest answer.
 */
const page = usePage()

const state = useScreenState({
    rows: () => props.history.length,
})

/**
 * Why "Mark licence renewed" cannot be pressed.
 *
 * A renewal is a NEW EXPIRY DATE read off the renewed certificate, not a flag.
 * Recording one without that date would write a compliance fact nobody has
 * seen, so the control says what it needs and where the case lives instead.
 */
/* ------------------------------------------------------------------ */
/* the two writes (12 §2, Wave 5) */
/* ------------------------------------------------------------------ */

/*
 * A RENEWAL IS A NEW EXPIRY DATE READ OFF THE CERTIFICATE, not a flag — which
 * is exactly what this control's old reason said it needed. The panel asks for
 * the date and the number, and the server refuses a date that does not extend
 * the licence on file: one dated on or before it is a typo that would leave the
 * officer non-compliant while the screen said otherwise.
 */
const renewing = ref(false)

const renewalPrompt = computed(
    () =>
        `Record ${props.guard.name}'s renewed PSRA licence — the new expiry date read off the certificate, and the number printed on it.`
)

const renewForm = useForm({ psra_expires_on: '', psra_number: props.guard.psra_number ?? '' })

const submitRenewal = () => {
    if (renewForm.psra_expires_on === '') {
        return
    }

    renewForm.post(`/guards/${props.guard.id}/licence`, {
        preserveScroll: true,
        onSuccess: () => {
            renewing.value = false
        },
    })
}

/*
 * THE POST GOES WITH THE CLIENT. An officer who keeps a post at an estate they
 * no longer work would appear on that client's coverage board as standing a
 * gate — the same failure D-034 records for suspension, in the other direction.
 */
const moving = ref(false)

const moveForm = useForm({ tenant_id: props.guard.tenant_id ?? '', post_id: '' })

const postsForClient = computed(() =>
    props.postOptions.filter((option) => String(option.tenant_id) === String(moveForm.tenant_id))
)

const submitMove = () => {
    moveForm.post(`/guards/${props.guard.id}/reassign`, {
        preserveScroll: true,
        onSuccess: () => {
            moving.value = false
        },
    })
}

const retry = () => router.reload()
</script>

<template>
    <Head :title="guard.name" />

    <GeminiConsole :title="guard.name">
        <template #lead>
            <Link href="/guards" class="topbar-back" title="Back to the guard directory" aria-label="Back to the guard directory">
                <svg viewBox="0 0 24 24" fill="none">
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

        <div v-if="state.isLoading.value" class="info-panel">
            <SkeletonRows :rows="8" :columns="2" />
        </div>

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="This guard's record is not part of your role's access"
            body="An employment record carries a licence, a posting and a pay figure, so it opens only to roles that hold the Guard workforce module. Yours does not."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="This guard's record could not be loaded"
            body="The platform database did not answer. Nothing has been changed, and nothing about this guard has been lost — the record is still there to be read."
            action-label="Try again"
            @action="retry"
        />

        <template v-else>
            <div class="detail-head">
                <div class="hero-card" :class="guard.hero_class">
                    <div class="hero-top">
                        <div>
                            <div class="hero-name">{{ guard.name }}</div>
                            <div class="hero-sub">{{ guard.subtitle }}</div>
                        </div>
                        <div class="status-badge" :class="guard.status_badge">{{ guard.status_label }}</div>
                    </div>
                    <div class="hero-stats">
                        <div v-for="stat in guard.stats" :key="stat.label" class="hero-stat">
                            <div class="hs-v">{{ stat.value }}</div>
                            <div class="hs-l">{{ stat.label }}</div>
                        </div>
                    </div>
                </div>
                <div class="action-stack">
                    <!--
                      UN-ROSTERABLE: the reassign control is not disabled here,
                      it is absent, and the compliance action stands in its
                      place. A hard block means there is no posting to move this
                      officer to, so a greyed "Reassign client" would be
                      offering a thing that is not merely unbuilt but forbidden.
                    -->
                    <button
                        v-if="!guard.rosterable"
                        type="button"
                        class="stack-btn outline"
                        :disabled="!canAct"
                        :title="canAct ? renewalPrompt : actBlockedReason"
                        @click="renewing = !renewing"
                    >
                        <BoardIcon name="check-circle" />
                        <span>Mark licence renewed</span>
                    </button>

                    <!--
                      A compliant officer can be moved. A blocked one cannot,
                      and the control is ABSENT rather than greyed there: there
                      is no posting to move them to, so offering it would be
                      offering a thing that is forbidden rather than unbuilt.
                    -->
                    <button
                        v-else
                        type="button"
                        class="stack-btn primary"
                        :disabled="!canAct"
                        :title="canAct ? 'Move this officer to another client, or off one. Their post goes with the client, and any future shifts at the previous one are opened on the rota.' : actBlockedReason"
                        @click="moving = !moving"
                    >
                        <BoardIcon name="guards" :stroke="1.7" />
                        <span>Reassign client</span>
                    </button>

                    <a v-if="guard.contact_href" :href="guard.contact_href" class="stack-btn outline">
                        <BoardIcon name="broadcast" :stroke="1.7" />
                        <span>Contact guard</span>
                    </a>
                    <button
                        v-else
                        type="button"
                        class="stack-btn outline"
                        disabled
                        title="No phone number or email address is on this guard's record"
                    >
                        <BoardIcon name="broadcast" :stroke="1.7" />
                        <span>Contact guard</span>
                    </button>
                </div>
            </div>

            <p v-if="page.props.flash?.success" class="gd-flash">{{ page.props.flash.success }}</p>

            <!-- AUTHORED (13 D1): only when a handset is waiting to be rebound. -->
            <div v-for="request in rebindRequests" :key="request.id" class="gd-panel">
                <div class="gd-head">
                    New handset waiting — {{ request.label ?? request.platform }} · requested {{ request.requested_at }}.
                    Approving replaces the handset bound now; its token stops working when the new one collects its own.
                </div>
                <div class="gd-rebind-actions">
                    <button type="button" class="btn-outline-sm" :disabled="!canAct" :title="canAct ? 'Refuse this handset, with a note the officer sees.' : actBlockedReason" @click="decideRebind(request, false)">
                        <span>Refuse</span>
                    </button>
                    <button type="button" class="btn-primary-sm" :disabled="!canAct" :title="canAct ? 'Bind this handset to the officer, recorded against their shift.' : actBlockedReason" @click="decideRebind(request, true)">
                        <span>Approve handset</span>
                    </button>
                </div>
            </div>

            <!--
              AUTHORED. The board draws an officer nobody is acting on, so it
              has neither panel.
            -->
            <form v-if="renewing" class="gd-panel" @submit.prevent="submitRenewal">
                <div class="gd-head">
                    A renewal is the new expiry date read off the certificate, not a flag. A date that does not extend
                    the licence on file is refused — it would leave this officer non-compliant while the screen said
                    otherwise.
                </div>

                <div class="gd-fields">
                    <div class="gd-field">
                        <label for="gd-expires">New expiry date</label>
                        <input id="gd-expires" v-model="renewForm.psra_expires_on" type="date" required />
                    </div>
                    <div class="gd-field">
                        <label for="gd-number">PSRA number on the certificate</label>
                        <input id="gd-number" v-model="renewForm.psra_number" type="text" required maxlength="40" />
                    </div>
                </div>

                <div v-if="renewForm.errors.psra_expires_on" class="gd-error">
                    {{ renewForm.errors.psra_expires_on }}
                </div>

                <div class="gd-actions">
                    <button
                        type="submit"
                        class="btn-primary-sm"
                        :disabled="renewForm.processing || renewForm.psra_expires_on === ''"
                        title="Record the renewal. A suspension, if there is one, stays — that was a decision somebody took."
                    >
                        <span>{{ renewForm.processing ? 'Recording…' : 'Record renewal' }}</span>
                    </button>
                    <button type="button" class="text-link-sm" @click="renewing = false">Cancel</button>
                </div>
            </form>

            <form v-if="moving" class="gd-panel" @submit.prevent="submitMove">
                <div class="gd-head">
                    The post goes with the client. An officer who kept a post at an estate they no longer work would
                    show on that client's coverage board as standing a gate — and any future shifts there are opened on
                    the rota so the estate can fill them.
                </div>

                <div class="gd-fields">
                    <div class="gd-field">
                        <label for="gd-client">Client</label>
                        <select id="gd-client" v-model="moveForm.tenant_id">
                            <option value="">No client — off posting</option>
                            <option v-for="client in clientOptions" :key="client.id" :value="client.id">
                                {{ client.name }}
                            </option>
                        </select>
                    </div>
                    <div class="gd-field">
                        <label for="gd-post">Post</label>
                        <select id="gd-post" v-model="moveForm.post_id">
                            <option value="">No post yet</option>
                            <option v-for="option in postsForClient" :key="option.id" :value="option.id">
                                {{ option.name }}
                            </option>
                        </select>
                    </div>
                </div>

                <div v-if="moveForm.errors.tenant_id" class="gd-error">{{ moveForm.errors.tenant_id }}</div>

                <div class="gd-actions">
                    <button type="submit" class="btn-primary-sm" :disabled="moveForm.processing" title="Move this officer.">
                        <span>{{ moveForm.processing ? 'Moving…' : 'Reassign' }}</span>
                    </button>
                    <button type="button" class="text-link-sm" @click="moving = false">Cancel</button>
                </div>
            </form>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px">
                <div>
                    <div class="info-panel">
                        <div class="info-panel-head">Employment</div>
                        <div v-for="row in guard.employment" :key="row.label" class="info-row2">
                            <span>{{ row.label }}</span>
                            <span>{{ row.value }}</span>
                        </div>
                    </div>
                    <div class="info-panel">
                        <div class="info-panel-head">Current assignment</div>
                        <div v-for="row in guard.assignment" :key="row.label" class="info-row2">
                            <span>{{ row.label }}</span>
                            <span>{{ row.value }}</span>
                        </div>
                    </div>
                </div>
                <div>
                    <div class="tl2">
                        <div class="info-panel-head">{{ guard.history_head }}</div>

                        <EmptyState
                            v-if="state.isEmpty.value"
                            variant="first-use"
                            title="Nothing on file yet"
                            body="A posting, a licence check and a compliance action each write a line here. This record has none of them yet."
                        />

                        <div v-for="(event, i) in state.isEmpty.value ? [] : history" :key="i" class="tl2-row">
                            <div class="tl2-dot" :class="{ danger: event.danger }"></div>
                            <div class="tl2-txt">
                                <div class="tt1">{{ event.title }}</div>
                                <div class="tt2">{{ event.meta }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </GeminiConsole>
</template>

<style scoped>
/*
 * The board draws both action-stack buttons as <div>s. One is a real link that
 * dials or mails the guard, the other a disabled button until reassignment
 * exists. These rules take back off only what the browser adds — the link's
 * underline, the button's font family, and the border on the one variant whose
 * board rule declares none. Everything visible still comes from .stack-btn.
 *
 * The border reset names .primary rather than .stack-btn because a scoped
 * element selector outranks a single class: `button.stack-btn { border: 0 }`
 * would beat .stack-btn.outline and strip the outline variant of the 1.5px
 * edge that is the whole difference between the two.
 */
a {
    text-decoration: none;
}

button {
    font-family: inherit;
}

button.stack-btn.primary {
    border: 0;
}

/*
 * The back chevron. The board draws it as an inline-styled <div> because its
 * stylesheet has no class for it; those exact declarations are reproduced here
 * on a real <a> so the control can be clicked, focused and opened in a new tab.
 */
.topbar-back {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: var(--navy-100);
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
}

.topbar-back svg {
    width: 16px;
    height: 16px;
    color: var(--navy-700);
}

button.btn-primary-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.btn-primary-sm[disabled] {
    cursor: not-allowed;
}

button.text-link-sm {
    border: 0;
    background: none;
    padding: 0;
    font-family: inherit;
    line-height: inherit;
    cursor: pointer;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws an officer nobody is acting on, so
 * it has no panel and no flash. Kept to the tokens the Gemini boards define.
 */
.gd-rebind-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 10px;
}

.gd-flash {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 14px;
    background: var(--success-100);
    color: var(--success-700);
}

.gd-panel {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 16px;
    margin-bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.gd-head {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--navy-800);
    line-height: 1.55;
    max-width: 800px;
}

.gd-fields {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
}

.gd-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.gd-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.gd-field input,
.gd-field select {
    height: 33px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12px;
    color: var(--navy-900);
}

.gd-error {
    font-size: 11px;
    font-weight: 600;
    color: var(--red-700);
    line-height: 1.5;
}

.gd-actions {
    display: flex;
    align-items: center;
    gap: 14px;
}
</style>
