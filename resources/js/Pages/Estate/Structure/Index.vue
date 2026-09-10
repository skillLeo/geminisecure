<script setup>
import { computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * Estate structure — board screen community-admin-03.
 *
 * EVERY FIGURE ON A CARD BUT TWO IS DERIVED. `Residents::structureBoard()` runs
 * one GROUP BY over `units` for the unit count, the occupied count, the vacant
 * count and the occupancy bar; only the block count and the officer count are
 * stored columns, because nothing else in this database can answer those. This
 * page does no arithmetic of its own — every number it prints already arrived
 * formatted (`tag`, `foot`, `occupancy_pct`), because a page that reached past
 * the payload to recompute one would be a second place for it to disagree.
 *
 * BOTH TOPBAR CONTROLS AND THE DASHED CARD ARE THE SAME NOT-BUILT ACT. Adding a
 * phase and importing a unit list both bring addresses into existence — the
 * thing a household is filed at and dues are billed to — which is a form with a
 * lot range decided on it, not a button. All three affordances the board draws
 * for it (`Import CSV`, `Add phase`, `Add another phase`) are wired to the same
 * `estate.estate_structure.create` gate: a role that lacks even View access
 * never reaches this screen at all (403 on the route), so the reason on these
 * controls is always one of the two `reasons` the server sends — never a third,
 * locally-invented sentence.
 *
 * CONTENT RESIDUAL, RECORDED IN D-056 — Phase 5 draws 70 units, 78 occupied and
 * 0 vacant, which is arithmetic no estate can produce (occupied cannot exceed
 * the unit count). `EstateFinanceSeeder` clamps this estate's Phase 5 to 70 / 70
 * / 0, so this page reads 70 occupied and a 100% bar rather than the board's 78
 * — reproducing the seeded truth rather than the board's uncorrected figure, per
 * D-044.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    /** One card per phase, in ascending `sequence` — the board's own order. */
    phases: { type: Array, required: true },

    /*
     * `totals` and `unplaced_units` are declared and not rendered. Board 3 draws
     * no estate-wide total of its own — that KPI belongs to the dashboard, which
     * is what `structureBoard()`'s doc comment ties them to — and an undeclared
     * prop on this fragment's root would fall through as a stray HTML attribute
     * rather than be silently ignored. Phoenix Park's five cards sum to exactly
     * 450, so `unplaced_units` is 0 today; it exists so a card list that quietly
     * summed to less than the estate would have somewhere to say so, on the
     * screen that gets built to show it.
     */
    totals: { type: Object, required: true },
    unplaced_units: { type: Number, required: true },

    canCreate: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
    reasons: { type: Object, required: true },
})

/*
 * Which board's stylesheet this page wears. Boards 3, 4 and 38 all live in the
 * first Community Admin sheet, and naming the wrong one — or none — renders this
 * screen with no board CSS at all.
 */
useWireframe('community-admin-01-login-dashboard-structure-and-residents')

/**
 * Five of the six. This screen carries no search and no filter — the board
 * draws none, and none of the domain_needs imply one — so `empty-filtered`
 * cannot occur from real data; the composable still lets `?_state=` force it in
 * local, and it must still say something true when it does.
 */
const state = useScreenState({
    rows: () => props.phases.length,
})

const retry = () => router.reload()

/**
 * Why the two topbar controls and the dashed card cannot be pressed.
 *
 * THE VIEWER'S OWN ACCESS COMES FIRST. A role that reaches this screen at all
 * already holds at least View — the route itself refuses anyone who does not —
 * so `blockedReason` only ever fires for a President or a Vice President, who
 * may read the estate's layout and not change it (D-009's Community Super Admin
 * and D-044's Property Manager both hold `create`, per the matrix). Below that,
 * neither action exists yet regardless of role, and the server's own two
 * sentences say why a phase or a unit list needs a form rather than a button.
 */
const addPhaseReason = computed(() => (props.canCreate ? props.reasons.add : props.blockedReason))
const importReason = computed(() => (props.canCreate ? props.reasons.import : props.blockedReason))
</script>

<template>
    <Head title="Estate structure" />

    <EstateConsole title="Estate structure" :estate-name="estate.name" active="estate_structure">
        <template #actions>
            <button type="button" class="btn-outline-sm" disabled :title="importReason">
                <svg viewBox="0 0 24 24" fill="none">
                    <path
                        d="M12 16V4m0 0L8 8m4-4l4 4M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                </svg>
                <span>Import CSV</span>
            </button>

            <button type="button" class="btn-primary-sm" disabled :title="addPhaseReason">
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
                <span>Add phase</span>
            </button>
        </template>

        <!-- The whole screen is one payload, so nothing on it arrives before the rest. -->
        <SkeletonRows v-if="state.isLoading.value" :rows="2" :columns="3" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Estate structure is not part of your role’s access"
            body="A phase card names how many addresses the estate has and how many are occupied, so it opens only to roles that hold Estate structure. A committee officer or the estate administrator can grant it from the role access matrix."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The estate's layout could not be read"
            body="The estate database did not answer. No phase has changed and no unit has moved — this is a read that failed, and re-running it is safe."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="This estate has no phases yet"
            body="A phase is what gives a block of addresses a name — Phase 1, Phase 2 — and every unit, household and dues charge is filed under one. There is nothing to file anything under until the first phase exists."
        />

        <div v-else class="phase-grid">
            <div v-for="phase in phases" :key="phase.id" class="phase-card">
                <div class="ph-top">
                    <div class="ph-name">{{ phase.name }}</div>
                    <div class="phase-tag">{{ phase.tag }}</div>
                </div>
                <div class="ph-stats">
                    <div class="ph-stat">
                        <div class="psv">{{ phase.occupied }}</div>
                        <div class="psl">OCCUPIED</div>
                    </div>
                    <div class="ph-stat">
                        <div class="psv">{{ phase.vacant }}</div>
                        <div class="psl">VACANT</div>
                    </div>
                </div>
                <div class="occ-bar"><i :style="{ width: phase.occupancy_pct + '%' }"></i></div>
                <div class="ph-foot">{{ phase.foot }}</div>
            </div>

            <!--
              The dashed placeholder. The board draws it as a bare div because a
              mockup has no disabled state to show; here it is the same
              not-built control as the topbar's "Add phase", wrapped around the
              board's own icon and caption rather than left an inert picture.
            -->
            <button
                type="button"
                class="phase-card"
                style="
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    border-style: dashed;
                    border-color: var(--navy-200);
                    background: none;
                    cursor: not-allowed;
                "
                disabled
                :title="addPhaseReason"
            >
                <span style="display: flex; flex-direction: column; align-items: center; gap: 8px">
                    <span class="kpi-icon" style="width: 44px; height: 44px">
                        <svg viewBox="0 0 24 24" fill="none" style="width: 20px; height: 20px; display: block">
                            <path
                                d="M12 5v14M5 12h14"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                            />
                        </svg>
                    </span>
                    <span style="font-size: 12px; color: var(--slate-500); font-weight: 700">Add another phase</span>
                </span>
            </button>
        </div>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only, and each removal names the element that needs it.
 *
 * The board draws its two topbar actions and its dashed placeholder as <div>s;
 * here they are three buttons, and a real <button> arrives wearing the
 * browser's own font, a border and buttonface grey. .btn-outline-sm and
 * .btn-primary-sm already supply everything visible for the first two.
 *
 * THE DASHED CARD IS THE ONE ELEMENT HERE THAT NEEDS A BORDER RESET NAMED
 * CAREFULLY: .phase-card DOES draw a border (1px solid navy-100) and the board
 * overrides it inline to a dashed navy-200 edge, which is reproduced on the
 * template above via the same inline style rather than removed here — see
 * D-045. Only the button chrome underneath that border is reset.
 */
button {
    font-family: inherit;
}

button.btn-outline-sm,
button.btn-primary-sm {
    border: 0;
}

button.phase-card {
    text-align: center;
    width: 100%;
}

button[disabled] {
    cursor: not-allowed;
}
</style>
