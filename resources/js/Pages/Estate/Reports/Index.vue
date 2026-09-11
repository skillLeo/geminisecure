<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * Reports — board screen community-admin-29.
 *
 * SEVEN CARDS, SEVEN "GENERATE" CONTROLS, AND NOT ONE OF THEM GENERATES. Every
 * one is drawn inert with its own sentence saying what it is waiting for, and
 * the sentences are all different because the situations are: four of the seven
 * have their data sitting in this estate's database already and three do not —
 * there is no budget model, no estate-side incident record and no document
 * store. "Not built yet" on all seven would tell a committee nothing.
 *
 * THE BOARD DRAWS `.rc-action` AS A DIV, AND HERE IT IS A BUTTON. That is the
 * rule this project has held to throughout: something that looks like a control
 * is a control, focusable and reachable by keyboard, and if it cannot act it
 * carries `disabled` and a title. A styled <div> that swallows a click is the
 * defect `gate:interactivity` exists to find.
 *
 * THE ICONS ARE THE BOARD'S OWN, ONE PER CARD. Seven different glyphs, restated
 * as real markup rather than injected as strings — a shared icon across two
 * cards would be visible in the diff, and `v-html` for an SVG is a habit worth
 * not starting.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    groups: { type: Array, required: true },
    canGenerate: { type: Boolean, required: true },
    reasons: { type: Object, required: true },
})

/*
 * Board 29 is drawn in "Reports Unit Claims and Notices" — its own sheet
 * defines `.report-group`, `.report-grid` and `.report-card`, and no other
 * sheet defines any of them. Checked against the sheet rather than inferred
 * from the sidebar item it sits under.
 */
useWireframe('community-admin-08-reports-unit-claims-and-notices')

/*
 * The catalogue is a fixed list and cannot be empty, so the only states this
 * screen can actually reach are populated, loading and denied. `?_state=empty`
 * still resolves — it falls through to the catalogue, which is the truthful
 * answer: there is no estate for which this screen has nothing to show.
 */
const page = usePage()

const state = useScreenState({
    rows: () => props.groups.length,
})

/**
 * What to say on a control that will not act.
 *
 * TWO DIFFERENT REFUSALS, AND A READER IS OWED THE ONE THAT APPLIES TO THEM. A
 * Secretary holds View on Reports and could not generate this even if it were
 * written; everybody else is looking at a report that is not written yet. Naming
 * the wrong one sends somebody to ask for access they already have — or to wait
 * for a feature that would still refuse them.
 */
const refusal = (card) => (props.canGenerate ? card.reason : props.reasons.access)

/** Where a built card leads. Null for one that has nothing to run. */
const reportHref = (card) =>
    props.canGenerate && card.built ? `${page.url.split('?')[0].replace(/\/$/, '')}/${card.key}` : null
</script>

<template>
    <Head title="Reports" />

    <EstateConsole title="Reports" :estate-name="estate.name" active="reports">
        <SkeletonRows v-if="state.isLoading.value" :rows="3" :columns="3" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Reports is not part of your role’s access"
            body="A report reads the whole estate at once — its books, its households and its ballots — so this catalogue opens only to a role that holds Reports."
        />

        <template v-else>
            <div v-for="group in groups" :key="group.heading" class="report-group">
                <div class="report-group-head">{{ group.heading }}</div>

                <div class="report-grid">
                    <div v-for="card in group.cards" :key="card.key" class="report-card">
                        <div class="rc-icon">
                            <svg viewBox="0 0 24 24" fill="none">
                                <template v-if="card.icon === 'currency'">
                                    <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H7" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
                                </template>
                                <template v-else-if="card.icon === 'clock'">
                                    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7"/>
                                    <path d="M12 7v5l3.5 2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
                                </template>
                                <template v-else-if="card.icon === 'calendar'">
                                    <rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.7"/>
                                    <path d="M3 10h18M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
                                </template>
                                <template v-else-if="card.icon === 'spanner'">
                                    <path d="M14.7 6.3a3 3 0 1 0-4.2 4.2l-7 7 2.3 2.3 7-7a3 3 0 0 0 4.2-4.2l-2.1 2.1-2-2z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
                                </template>
                                <template v-else-if="card.icon === 'shield'">
                                    <path d="M12 2 2 7v6c0 5.2 3.8 9 10 11 6.2-2 10-5.8 10-11V7l-10-5z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>
                                </template>
                                <template v-else-if="card.icon === 'people'">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="1.7"/>
                                    <circle cx="12" cy="7" r="4" stroke="currentColor" stroke-width="1.7"/>
                                </template>
                                <template v-else-if="card.icon === 'megaphone'">
                                    <path d="M4 11v2a1 1 0 0 0 1 1h2l4 4V6L7 10H5a1 1 0 0 0-1 1z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>
                                    <path d="M17 8a5 5 0 0 1 0 8" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
                                </template>
                            </svg>
                        </div>

                        <div class="rc-name">{{ card.name }}</div>
                        <div class="rc-desc">{{ card.description }}</div>

                        <!--
                          Five of the seven run (12 §1). The two that do not
                          have DATA GAPS rather than missing code — no approved
                          budget on this platform, and incidents recorded
                          centrally — and each says which under the cursor.
                        -->
                        <Link v-if="reportHref(card)" :href="reportHref(card)" class="rc-action">
                            {{ card.action }}
                        </Link>
                        <button v-else type="button" class="rc-action" disabled :title="refusal(card)">
                            {{ card.action }}
                        </button>
                    </div>
                </div>
            </div>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws `.rc-action` as a <div>; here it is a
 * real <button>, which arrives with a border, buttonface grey, an intrinsic
 * width and the browser's own font. The sheet's `.report-card .rc-action` rule
 * supplies everything visible — background, radius, height, colour, weight —
 * and nothing below reaches past a browser default, which is the mistake D-045
 * records.
 *
 * `width: 100%` is the one line that looks like styling and is not: the board's
 * div is a block and fills the card, while a button shrinks to its text. This
 * restores what the element was, rather than deciding anything new.
 */
button.rc-action {
    width: 100%;
    border: 0;
    font: inherit;
    font-size: 11.5px;
    font-weight: 700;
    cursor: not-allowed;
}

/* A card that runs is a link. The sheet's own .rc-action supplies everything
 * visible; only the anchor's underline and its inline width come off. */
a.rc-action {
    display: block;
    width: 100%;
    text-align: center;
    text-decoration: none;
}
</style>
