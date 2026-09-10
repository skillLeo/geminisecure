<script setup>
import { computed } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * The register — board screen community-admin-04.
 *
 * THE BALANCE COLUMN NEVER PRINTS A FIGURE THIS PAGE HAD TO WORK OUT.
 * `row.standing` is `Residents::standingOf()`, whole, for every row — the same
 * shape boards 31 and 38 read — so a restricted household's cell reads the
 * amber word here exactly as it does everywhere else that function is
 * consulted, and a viewer without `estate.dues_ledger.view` reads "Balance not
 * shown" rather than a number this page invented a reason to withhold. Nothing
 * here reaches past `row.standing.label` for a figure; `EstateResidentsTest`
 * scans every one of these rows for the vocabulary of money and for any digit
 * at all, against a household carrying a real balance.
 *
 * CONTENT RESIDUAL, RECORDED IN D-056 — the register holds up to
 * `Residents::LIST_LIMIT` (50) of however many households the estate actually
 * has; the board draws six as an illustration of the shape, with no pagination
 * control and no affordance for a second page. The two named residuals below
 * are D-056's own:
 *
 *   - Keith Walters's row reads "K. A. Walters", the register's own form of the
 *     name — board 31's whole subject is that variance, so the register cannot
 *     print the claimant's spelling without erasing the reason his card is amber.
 *   - This estate's other 427 households are real seeded rows, not padding:
 *     `EstateFinanceSeeder` gives every unit a household, and this page renders
 *     whichever the server's own ordering (newest active first) sends down —
 *     never a client-side slice pretending the register is smaller than it is.
 *
 * THE FREE-TEXT SEARCH BOX IS THE SHELL'S, NOT THIS PAGE'S. `EstateConsole`
 * draws it permanently disabled across every screen that asks for one — an
 * index across residents and units before it needs a box that returns nothing —
 * and this page does not reach around that to wire its own. The PHASE CHIPS
 * are different: they are real navigation, each a link to this same screen with
 * `?phase=` set, because `Residents::listBoard()` already takes that filter and
 * a chip that changed nothing on click would be exactly the silent control this
 * whole build exists to remove.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    pending: { type: Object, required: true },
    phase_chips: { type: Array, required: true },
    filters: { type: Object, required: true },
    rows: { type: Array, required: true },
    total: { type: Number, required: true },
    shown: { type: Number, required: true },
    /** Whether the VIEWER holds `estate.dues_ledger.view` — true for every row alike. */
    money_visible: { type: Boolean, required: true },
    canCreate: { type: Boolean, required: true },

    /*
     * `estate.residents.approve` — named `canReview` because that is what board
     * 4's own banner calls it, and it is a DIFFERENT permission from board 31's
     * own `canReview` (which is `update`, gating refuse/request-document there).
     * One label, two meanings, because the two screens are asking different
     * questions: this banner is asking who may DECIDE a claim, not who may act
     * on one at all.
     */
    canReview: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
})

/*
 * Which board's stylesheet this page wears. Boards 3, 4 and 38 all live in the
 * first Community Admin sheet, and naming the wrong one — or none — renders this
 * screen with no board CSS at all.
 */
useWireframe('community-admin-01-login-dashboard-structure-and-residents')

const page = usePage()

/**
 * Five of the six. `filtered` is true whenever a phase chip narrows the list —
 * the free-text box is inert, so a chip is the only filter this screen can
 * actually apply — and `empty-filtered` is what a phase with no households in
 * it looks like, as opposed to `empty`, which is this estate having none at
 * all.
 */
const state = useScreenState({
    rows: () => props.rows.length,
    filtered: () => props.filters.phase !== '',
})

const retry = () => router.reload()

/*
 * Where this console is rooted, read off the page's own URL.
 *
 * Production gives each estate its own hostname and no prefix; local serves
 * every estate from one host with the estate key in the path, as
 * /estate/{key}/residents. The URL that served this page already carries
 * whichever shape this environment uses, so cutting it at /residents is correct
 * in both — and unlike a tenant key read off a prop, it cannot address an
 * estate other than the one already open.
 */
const root = computed(() => page.url.slice(0, page.url.indexOf('/residents')))

const residentsPath = computed(() => `${root.value}/residents`)
const claimsPath = computed(() => `${root.value}/residents/claims`)
const newResidentPath = computed(() => `${root.value}/residents/new`)
const unitPath = (slug) => `${root.value}/residents/${slug}`

/** A phase chip's own href, carrying this filter and no other. */
const chipHref = (phase) => {
    const query = phase === '' ? '' : `?phase=${encodeURIComponent(phase)}`

    return `${residentsPath.value}${query}`
}

/**
 * Why "Add resident" cannot be pressed, or nothing when it can.
 *
 * Unlike the topbar actions on board 3, this one IS built — `/residents/new`
 * and `estate.residents.store` both exist — so the gate is the viewer's own
 * access rather than a feature that does not exist yet, and the server's own
 * sentence already says so.
 */
const addResidentBlockedBy = computed(() => (props.canCreate ? null : props.blockedReason))

/**
 * Why "Review now" cannot be pressed, or nothing when it can.
 *
 * No server sentence is written for this refusal, because `blockedReason` on
 * this action is about CREATING a resident and would say the wrong thing here.
 * Reaching the claims queue itself needs only the `view` this screen already
 * required — a Secretary or a Treasurer who opens `/residents/claims` directly
 * still gets to refuse a claim or ask for a document — but the banner's own
 * CTA is the decision-maker's entry point, and D-053's Approver tag is what it
 * is gated on.
 */
const NO_REVIEW_ACCESS =
    'Deciding a unit claim binds a person to a household, so acting on the queue from here needs Residents approve access. You are able to read this screen, and the claims themselves at /residents/claims.'

const reviewBlockedBy = computed(() => (props.canReview ? null : NO_REVIEW_ACCESS))

/** The header's own explanation for why a column full of "Balance not shown" is not a bug. */
const balanceHeaderTitle = computed(() =>
    props.money_visible
        ? null
        : 'A resident’s financial position needs Dues & ledger view access. You are able to read everything else on this register.'
)
</script>

<template>
    <Head title="Residents" />

    <EstateConsole
        title="Residents"
        :estate-name="estate.name"
        active="residents"
        search-placeholder="Search by name, lot, phase…"
    >
        <template #actions>
            <Link v-if="canCreate" :href="newResidentPath" class="btn-primary-sm">
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
                <span>Add resident</span>
            </Link>
            <button v-else type="button" class="btn-primary-sm" disabled :title="addResidentBlockedBy">
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
                <span>Add resident</span>
            </button>
        </template>

        <!-- The whole screen is one payload, so nothing on it arrives before the rest. -->
        <SkeletonRows v-if="state.isLoading.value" :rows="6" :columns="6" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Residents is not part of your role’s access"
            body="The register names every household on the estate and, for roles that hold it, what each owes. A committee officer or the estate administrator can grant Residents from the role access matrix."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The register could not be read"
            body="The estate database did not answer. No household has changed and no claim has moved — this is a read that failed, and re-running it is safe."
            action-label="Try again"
            @action="retry"
        />

        <template v-else>
            <div v-if="pending.count > 0" class="pending-banner">
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 9v4M12 17h.01" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" />
                    <path
                        d="M10.3 3.9L2.5 18a1.8 1.8 0 0 0 1.6 2.7h15.8a1.8 1.8 0 0 0 1.6-2.7L13.7 3.9a1.8 1.8 0 0 0-3.4 0z"
                        stroke="currentColor"
                        stroke-width="1.7"
                    />
                </svg>
                <div>
                    <div class="pb1">{{ pending.title }}</div>
                    <div class="pb2">{{ pending.body }}</div>
                </div>
                <Link v-if="canReview" :href="claimsPath" class="pb-btn">Review now</Link>
                <button v-else type="button" class="pb-btn" disabled :title="reviewBlockedBy">Review now</button>
            </div>

            <div class="filter-row">
                <Link
                    v-for="chip in phase_chips"
                    :key="chip.value"
                    :href="chipHref(chip.value)"
                    class="f-chip"
                    :class="{ active: filters.phase === chip.value }"
                >
                    {{ chip.label }}
                </Link>
            </div>

            <EmptyState
                v-if="state.isEmptyFiltered.value"
                variant="filtered"
                :title="`No household is filed in ${filters.phase}`"
                body="This phase exists on the estate's structure and holds no household today. Clear the chip to see every phase again."
                action-label="All phases"
                @action="router.get(chipHref(''))"
            />

            <EmptyState
                v-else-if="state.isEmpty.value"
                variant="first-use"
                title="No households are on the register yet"
                body="A resident is filed at a unit the estate already has, so the register stays empty until the first one is added."
                :action-label="canCreate ? 'Add resident' : null"
                @action="router.get(newResidentPath)"
            />

            <table v-else class="data-table">
                <thead>
                    <tr>
                        <th>Household</th>
                        <th>Unit</th>
                        <th>Members</th>
                        <th :title="balanceHeaderTitle">Balance</th>
                        <th>Status</th>
                        <th>Last active</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id">
                        <td>
                            <div class="res-cell">
                                <div class="res-avatar">{{ row.initials }}</div>
                                <div>
                                    <div class="res-name">{{ row.name }}</div>
                                    <div class="res-sub">{{ row.email ?? 'No email on file' }}</div>
                                </div>
                            </div>
                        </td>
                        <td>{{ row.unit }}</td>
                        <td>{{ row.members }}</td>

                        <!--
                          `standing.tone` is the only thing this cell reads to
                          decide its colour. A restricted household's tone is
                          'amber' and its label is D-025's own wording — never a
                          figure, so there is nothing here that could print one
                          by mistake.
                        -->
                        <td :class="{ 'standing-amber': row.standing.tone === 'amber' }">
                            {{ row.standing.label }}
                        </td>

                        <td>
                            <div class="status-badge" :class="row.verification">{{ row.verification_label }}</div>
                        </td>
                        <td>{{ row.last_active ?? 'Never' }}</td>
                        <td>
                            <div class="row-actions">
                                <Link
                                    :href="unitPath(row.unit_slug)"
                                    class="icon-btn"
                                    title="View household"
                                    aria-label="View household"
                                >
                                    <svg viewBox="0 0 24 24" fill="none">
                                        <path
                                            d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"
                                            stroke="currentColor"
                                            stroke-width="1.7"
                                        />
                                        <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.7" />
                                    </svg>
                                </Link>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <!--
              Authored: the board draws no footer and no count of what it is
              showing out of what, because it is a still image of six rows. A
              register that actually holds more than `shown` owes the reader
              that fact somewhere, and a caption under the table is the one
              place that does not touch the board's own geometry.
            -->
            <p v-if="!state.isEmpty.value && !state.isEmptyFiltered.value" class="register-caption">
                Showing {{ shown }} of {{ total }} household{{ total === 1 ? '' : 's' }}.
            </p>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only, and each removal names the element that needs it.
 *
 * The board draws its topbar action, its banner CTA, its phase chips and its
 * row action as <div>s; here they are links and buttons, each arriving with the
 * browser's own font, border and — for the anchors — an underline app.css has
 * already stripped on every board page. Nothing below introduces a colour, a
 * size or a weight the board does not already carry on the class.
 *
 * .btn-primary-sm, .pb-btn, .f-chip and .icon-btn none of them are given a
 * border by the board, which is what makes `border: 0` a removal here rather
 * than an override — see D-045.
 */
a.btn-primary-sm,
a.pb-btn,
a.f-chip {
    text-decoration: none;
}

button.btn-primary-sm,
button.pb-btn,
button.f-chip {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.btn-primary-sm[disabled],
button.pb-btn[disabled] {
    cursor: not-allowed;
}

a.icon-btn {
    display: flex;
}

/* Authored: the board draws no caption under its table at all. Kept to the
   register's own type scale — .ph-foot's 10.5px slate-500 — rather than
   inventing a new one. */
.register-caption {
    font-size: 10.5px;
    color: var(--slate-500);
    margin: 10px 2px 0;
}

/* Authored: the amber a restricted household's Balance cell reads in. No row
   in the seeded register carries it, so it touches nothing the board draws. */
.standing-amber {
    color: var(--amber-700);
    font-weight: 700;
}
</style>
