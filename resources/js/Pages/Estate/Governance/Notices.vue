<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * Notices — board screen community-admin-32.
 *
 * THE SEEN BAR IS THE POINT OF THE SCREEN AND IT IS COUNTED, NOT STORED. Board
 * 32 draws 47%, 71% and 88%, and each is read receipts over the size of the
 * audience at the moment the page is drawn. Both halves move — the numerator
 * every time somebody opens the notice, the denominator every time a household
 * moves in — so a stored percentage would be a photograph of a number still
 * changing, and it is the photograph a secretary would act on.
 *
 * TWO BYLINE SHAPES, AND THE BOARD IS RIGHT ABOUT BOTH. Its gate closure reads
 * "Posted by Property Manager" and its AGM notice "Posted by Delroy Samuels" —
 * a role on one and a person on the other. That is not an inconsistency to
 * normalise: a gate closure belongs to the office, because whoever manages the
 * estate next month owns it too, while an AGM notice is signed personally. The
 * server composes the line; this page prints it.
 *
 * THE COMPOSER POSTS IMMEDIATELY, because the board's one button says "Post
 * notice". A draft state exists in the schema and nothing here creates one —
 * the honest position, since no estate has asked for drafts and a control
 * nobody drew is a control nobody wants.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    rows: { type: Array, required: true },
    audiences: { type: Array, required: true },
    kinds: { type: Array, required: true },
    canPost: { type: Boolean, required: true },
    /** The election the "Elections" tab opens — the latest this estate has held. */
    electionYear: { type: Number, required: true },
    reasons: { type: Object, required: true },
})

/*
 * Board 32 is drawn in "Reports Unit Claims and Notices" — its own sheet
 * defines `.notice-row`, `.notice-tag`, `.seen-bar` and `.compose-panel`, and
 * the governance sheet defines none of them. Checked against the sheet rather
 * than inferred from the sidebar item it sits under.
 */
useWireframe('community-admin-08-reports-unit-claims-and-notices')

const page = usePage()

const state = useScreenState({
    rows: () => props.rows.length,
})

const base = computed(() => page.url.split('/governance')[0])

const draft = reactive({
    kind: 'urgent',
    title: '',
    body: '',
    audience_scope: props.audiences[0]?.key ?? 'estate',
    audience_phase: props.audiences[0]?.phase ?? null,
})

/**
 * The audience field is one control over two stored columns.
 *
 * A notice is either estate-wide or scoped to one phase, and the composer shows
 * that as a single list — so the option's own index carries both halves and
 * neither can be set without the other. Choosing "Phase 3 only" and leaving the
 * scope on estate-wide is not a state a reader can produce.
 */
const chooseAudience = (event) => {
    const option = props.audiences[Number(event.target.value)]

    draft.audience_scope = option.key
    draft.audience_phase = option.phase
}

/*
 * The board draws the Title field with the amber focus ring, and the ring is
 * tracked from real focus events rather than hardcoded so it follows the caret.
 * Focused on mount because that is both what the board shows and where somebody
 * opening a composer wants to start typing — the same pattern the sign-in card
 * uses for its email field.
 */
const focusedField = ref(null)
const titleField = ref(null)

onMounted(() => titleField.value?.focus())

const post = () => {
    router.post(`${base.value}/governance/notices`, { ...draft }, {
        preserveScroll: true,
        onSuccess: () => {
            draft.title = ''
            draft.body = ''
        },
    })
}
</script>

<template>
    <Head title="Notices" />

    <EstateConsole title="Notices" :estate-name="estate.name" active="governance">
        <SkeletonRows v-if="state.isLoading.value" :rows="3" :columns="2" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Governance is not part of your role’s access"
            body="A notice is an announcement to every household on the estate, so posting and reading the log open only to a role that holds Governance."
        />

        <div v-else class="notices-grid">
            <div>
                <div class="subnav">
                    <div class="subnav-item active" aria-current="page">Notices</div>
                    <Link :href="`${base}/governance/meetings`" class="subnav-item">Meetings</Link>
                    <!--
                      An election is addressed by year, and /governance/elections
                      alone is not a route — so the tab is told which one, by the
                      same rule board 36's tab uses: the latest this estate has
                      held (`Governance::electionYear()`).
                    -->
                    <Link :href="`${base}/governance/elections/${electionYear}`" class="subnav-item">Elections</Link>
                </div>

                <EmptyState
                    v-if="state.isEmpty.value"
                    variant="first-use"
                    title="Nothing has been announced yet"
                    body="A notice tells every household on the estate something at once. Post the first one on the right — it appears here with a bar showing how much of the estate has read it."
                />

                <div v-else class="notice-card">
                    <div v-for="notice in rows" :key="notice.id" class="notice-row">
                        <div class="notice-tag" :class="notice.kind === 'urgent' ? 'urgent' : 'general'">
                            {{ notice.kind_label }}
                        </div>
                        <div class="notice-txt">
                            <div class="n1">{{ notice.title }}</div>
                            <div class="n2">{{ notice.body }}</div>
                            <div class="notice-meta">{{ notice.meta }}</div>
                            <!--
                              The board draws the bar and no number. The figure
                              is on the title, so a reader who wants to know
                              whether it is 47% of 433 or 47% of 12 can find out
                              without the screen carrying a second column.
                            -->
                            <div
                                class="seen-bar"
                                :title="`${notice.seen_count} of ${notice.audience_size} have read this — ${notice.seen_pct}%`"
                            >
                                <i :style="{ width: `${notice.seen_pct}%` }"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="compose-panel">
                <div class="compose-head">Post a notice</div>

                <div class="seg">
                    <button
                        v-for="kind in kinds"
                        :key="kind.key"
                        type="button"
                        class="seg-item"
                        :class="{ active: draft.kind === kind.key }"
                        :disabled="!canPost"
                        :title="canPost ? undefined : reasons.post"
                        @click="draft.kind = kind.key"
                    >
                        {{ kind.label }}
                    </button>
                </div>

                <div class="m-field">
                    <label for="notice-title">Title</label>
                    <div class="m-input" :class="{ focused: focusedField === 'title' }">
                        <input
                            id="notice-title"
                            ref="titleField"
                            v-model="draft.title"
                            type="text"
                            @focus="focusedField = 'title'"
                            @blur="focusedField = null"
                            maxlength="190"
                            :disabled="!canPost"
                            :title="canPost ? undefined : reasons.post"
                            placeholder="Water interruption — Phase 3"
                        />
                    </div>
                </div>

                <div class="m-field">
                    <label for="notice-body">Message</label>
                    <div class="m-textarea">
                        <textarea
                            id="notice-body"
                            v-model="draft.body"
                            maxlength="4000"
                            :disabled="!canPost"
                            :title="canPost ? undefined : reasons.post"
                            placeholder="NWC has scheduled a supply interruption for Phase 3 tomorrow, 9 AM–3 PM, for main line repairs."
                        ></textarea>
                    </div>
                </div>

                <div class="m-field">
                    <label for="notice-audience">Audience</label>
                    <div class="m-input">
                        <select
                            id="notice-audience"
                            :disabled="!canPost"
                            :title="canPost ? undefined : reasons.post"
                            @change="chooseAudience"
                        >
                            <option v-for="(option, index) in audiences" :key="index" :value="index">
                                {{ option.label }}
                            </option>
                        </select>
                    </div>
                </div>

                <button
                    type="button"
                    class="stack-btn primary"
                    style="width: 100%; justify-content: center; height: 44px"
                    :disabled="!canPost || draft.title.trim() === '' || draft.body.trim() === ''"
                    :title="canPost ? 'A notice needs a heading and something to say.' : reasons.post"
                    @click="post"
                >
                    <span>Post notice</span>
                </button>
            </div>
        </div>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws its two segment pills, its three fields
 * and its post control as <div>s and <span>s; here they are real controls, which
 * arrive with a border, buttonface grey, an intrinsic width and the browser's
 * own font. `.seg-item`, `.m-input`, `.m-textarea` and `.stack-btn` supply
 * everything visible, and nothing below reaches past a browser default — which
 * is the mistake D-045 records.
 */
button.seg-item,
button.subnav-item {
    border: 0;
    background: none;
    font: inherit;
    cursor: pointer;
}

button.subnav-item {
    cursor: not-allowed;
}

a.subnav-item {
    text-decoration: none;
}

button.stack-btn.primary {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.seg-item[disabled],
button.stack-btn.primary[disabled] {
    cursor: not-allowed;
}

/*
 * The board draws typed-looking text inside .m-input and .m-textarea as a
 * <span>. A real <input>, <textarea> and <select> do not inherit font from an
 * ancestor, so the board's own type values are restated for the live fields to
 * land where the spans did.
 */
.m-input input,
.m-input select,
.m-textarea textarea {
    flex: 1;
    width: 100%;
    min-width: 0;
    border: 0;
    outline: 0;
    background: none;
    appearance: none;
    font-family: 'Inter', sans-serif;
    font-size: 12.5px;
    color: var(--navy-900);
}

.m-textarea textarea {
    min-height: 80px;
    resize: vertical;
}

.m-input input::placeholder,
.m-textarea textarea::placeholder {
    color: var(--slate-500);
    opacity: 1;
}

/*
 * AUTHORED BELOW THIS LINE. The board sets the two-column grid and the list
 * card with inline styles rather than named classes, so the same values are
 * declared here; the compose heading likewise.
 */
.notices-grid {
    display: grid;
    grid-template-columns: 1.3fr 1fr;
    gap: 18px;
}

.notice-card {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    overflow: hidden;
}

.compose-head {
    font-family: 'Poppins', sans-serif;
    font-size: 14px;
    color: var(--navy-900);
    font-weight: 600;
    margin-bottom: 14px;
}
</style>
