<script setup>
import { computed } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
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
})

/*
 * Five of the six. A profile has no filter, so `empty-filtered` cannot happen
 * here — there is no query to have excluded anything, and rendering a "clear
 * the filter" panel on a screen with no filter would invent a control to
 * explain a state that does not exist. Forcing it falls through to the
 * populated screen, which is the honest answer.
 */
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
const renewalBlocked = computed(
    () =>
        `Recording a renewal needs the new expiry date printed on ${props.guard.name}'s renewed PSRA licence, ` +
        'and the licence renewal form is not built yet. The open case is on the PSRA compliance register.'
)

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
                        disabled
                        :title="renewalBlocked"
                    >
                        <BoardIcon name="check-circle" />
                        <span>Mark licence renewed</span>
                    </button>

                    <!--
                      Nothing writes a compliant guard's posting yet: there is
                      no reassignment route and no screen behind one. Disabled
                      and saying why, rather than a button that looks live and
                      does nothing.
                    -->
                    <button
                        v-else
                        type="button"
                        class="stack-btn primary"
                        disabled
                        title="Available when guard reassignment ships with the shift roster"
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
</style>
