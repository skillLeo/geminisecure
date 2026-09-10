<script setup>
import { computed } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'

/**
 * Compliance action — board screen super-admin-21.
 *
 * The profile (19 and 23) SHOWS an open compliance case. This screen is where
 * it is acted on, and the difference matters: everything here either changes
 * the record or says plainly why it cannot.
 *
 * DOM, classes and label text are the board's, including the two inline grid
 * and flex styles, which are the board's own and stay inline rather than being
 * promoted to classes the design does not define.
 *
 * Four controls, and only one of them is live — which is the honest count
 * rather than a shortfall:
 *
 *   Suspend from active duty   REAL. A POST behind `guard_workforce.update`
 *                              that writes the guard's status and appends to
 *                              the audit log. The single destructive act on
 *                              this board, and the one it exists for.
 *
 *   Contact about renewal      REAL. A tel: or mailto: link built from the
 *                              record, so it dials the number on file rather
 *                              than a number somebody typed onto a board.
 *
 *   Post open shifts to cover  There is no shift roster yet. Disabled, saying
 *                              so, rather than a button that looks live.
 *
 *   Mark licence renewed       A renewal is a NEW EXPIRY DATE read off the
 *                              renewed certificate, not a flag. The same
 *                              refusal, in the same words, as the profile.
 *
 * D-034 governs what suspension does NOT do. The guard keeps their post and
 * their estate, so Phoenix Park Village 1 goes on seeing that the officer at
 * their Service Gate is the one who cannot legally stand it. Deployed means
 * posted at the estate, not compliant, and clearing the posting here would tidy
 * the client's screen while the problem stood at their gate.
 */
const props = defineProps({
    action: { type: Object, required: true },
    can_act: { type: Boolean, required: true },
})

const page = usePage()

/**
 * Five of the six.
 *
 * `empty` is a real state and not a contrivance: this URL is a guard's id, and
 * a guard whose licence is in order has no matter to act on. It is reached by
 * following a stale link, or by opening the case a colleague closed a minute
 * ago, and it must say so rather than draw an action panel over nothing.
 *
 * `empty-filtered` cannot happen. One guard, one matter, no query — there is no
 * filter that could have excluded anything, and drawing a "clear the filter"
 * panel on a screen with no filter would invent a control to explain a state
 * that does not exist. Forcing it falls through to the populated screen.
 */
const state = useScreenState({
    rows: () => (props.action.case === null ? 0 : 1),
})

const form = useForm({})

const suspend = () => form.post(`/guards/${props.action.id}/suspend`, { preserveScroll: true })

/**
 * Why "Suspend from active duty" cannot be pressed, or null when it can.
 *
 * The role's reason comes first: someone who may not act at all does not need
 * to be told the licence is fine, they need to be told the button is not
 * theirs. Everything after that is the service's own sentence, so the control
 * and the rule behind it cannot drift apart.
 */
const suspendBlocked = computed(() => {
    if (!props.can_act) {
        return 'Suspending an officer changes an employment record, so it needs the Guard workforce module at full access. Your role holds it for reading only.'
    }

    return props.action.suspend_blocked_reason
})

const renewalBlocked = computed(
    () =>
        `Recording a renewal needs the new expiry date printed on ${props.action.name}'s renewed PSRA licence, ` +
        'and the licence renewal form is not built yet. The open case stays on the PSRA compliance register.'
)

const retry = () => router.reload()
</script>

<template>
    <Head :title="`${action.name} — compliance action`" />

    <GeminiConsole :title="`${action.name} — compliance action`">
        <template #lead>
            <Link
                :href="`/guards/${action.id}`"
                class="topbar-back"
                :title="`Back to ${action.name}'s record`"
                :aria-label="`Back to ${action.name}'s record`"
            >
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
            <SkeletonRows :rows="6" :columns="2" />
        </div>

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="This compliance case is not part of your role's access"
            body="A compliance matter names the officer, the estate and the post it affects, so it opens only to roles that hold the Guard workforce module. Yours does not."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="This compliance case could not be loaded"
            body="The platform database did not answer. Nothing has been suspended and nothing has been recorded — the case is exactly as it was."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            :title="`There is no open compliance matter against ${action.name}`"
            body="A case opens by itself the moment a PSRA licence lapses or is found to have no expiry on file, and closes the moment a current one is recorded. There is nothing to act on here."
            action-label="Open the guard's record"
            @action="router.get(`/guards/${action.id}`)"
        />

        <template v-else>
            <!--
              What just happened, when something did. The board draws no
              confirmation because a still image never acts; this is the
              board's own neutral note box, not a colour of ours.
            -->
            <div v-if="page.props.flash.success" class="mfa-note">
                <BoardIcon name="check-ring" />
                <p>{{ page.props.flash.success }}</p>
            </div>

            <!--
              A refusal that reached the server anyway — a stale page, or a
              POST made by hand. It lands in the same red banner the case does.
            -->
            <div v-if="form.errors.guard" class="crit-banner">
                <BoardIcon name="warning" />
                <div>
                    <div class="cb1">Nothing was changed</div>
                    <div class="cb2">{{ form.errors.guard }}</div>
                </div>
            </div>

            <div class="crit-banner">
                <BoardIcon name="warning" />
                <div>
                    <div class="cb1">{{ action.case.headline }}</div>
                    <div class="cb2">{{ action.case.detail }}</div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px">
                <div>
                    <div class="info-panel">
                        <div class="info-panel-head">What this affects</div>
                        <div v-for="impact in action.impacts" :key="impact.title" class="impact-row">
                            <div class="impact-icon">
                                <BoardIcon :name="impact.icon" :stroke="1.6" />
                            </div>
                            <div class="impact-txt">
                                <div class="im1">{{ impact.title }}</div>
                                <div class="im2">{{ impact.meta }}</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div>
                    <div class="info-panel">
                        <div class="info-panel-head">Take action</div>
                        <div style="display: flex; flex-direction: column; gap: 10px">
                            <button
                                type="button"
                                class="stack-btn danger"
                                :disabled="suspendBlocked !== null || form.processing"
                                :title="suspendBlocked"
                                @click="suspend"
                            >
                                <BoardIcon name="x-circle" :stroke="1.7" />
                                <span>{{ form.processing ? 'Suspending…' : 'Suspend from active duty' }}</span>
                            </button>

                            <a v-if="action.contact_href" :href="action.contact_href" class="stack-btn outline">
                                <BoardIcon name="broadcast" :stroke="1.7" />
                                <span>Contact {{ action.first_name }} about renewal</span>
                            </a>
                            <button
                                v-else
                                type="button"
                                class="stack-btn outline"
                                disabled
                                title="No phone number or email address is on this guard's record"
                            >
                                <BoardIcon name="broadcast" :stroke="1.7" />
                                <span>Contact {{ action.first_name }} about renewal</span>
                            </button>

                            <button
                                type="button"
                                class="stack-btn outline"
                                disabled
                                title="Covering an uncovered post means posting a shift, and the shift roster is not built yet. Dispatch coverage shows which posts are standing short in the meantime."
                            >
                                <BoardIcon name="plus" :stroke="2" />
                                <span>Post open shifts to cover</span>
                            </button>

                            <button
                                type="button"
                                class="stack-btn outline"
                                disabled
                                :title="renewalBlocked"
                            >
                                <BoardIcon name="check-circle" />
                                <span>Mark licence renewed</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </GeminiConsole>
</template>

<style scoped>
/*
 * Reset only. The board draws all four action controls as <div>s and the back
 * chevron as an inline-styled <div>; the real buttons and links that replace
 * them arrive with a browser's own border, font, background and underline.
 * These rules take exactly those defaults back off, and introduce no colour,
 * spacing or size the board does not already declare.
 *
 * `.danger` and `.outline` are named rather than `button.stack-btn`, because a
 * scoped element selector outranks a single class: a blanket border reset would
 * beat .stack-btn.outline and strip the 1.5px edge that is the whole difference
 * between the variants. Each variant's own border is restated from the board's
 * own rule so the reset cannot silently remove it.
 */
a {
    text-decoration: none;
}

button {
    font-family: inherit;
}

/* The board's own .stack-btn.danger and .stack-btn.outline borders, restated so
 * the <button> default border is replaced rather than merely removed. */
button.stack-btn.danger {
    border: 1.5px solid var(--red-700);
}

button.stack-btn.outline {
    border: 1.5px solid var(--navy-200);
}

/* A disabled <button> is dimmed and greyed by the UA. The board draws these
 * controls at one weight, and the reason they cannot be pressed is on the
 * title rather than in the pixels. */
button.stack-btn[disabled] {
    opacity: 1;
    cursor: not-allowed;
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
