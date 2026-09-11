<script setup>
import { computed, ref } from 'vue'
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
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
 * BOTH WRITES ARE BUILT (12 §2, Wave 1). Adding a phase decides its blocks and
 * its lot range together and creates every lot in one transaction. Importing a
 * unit list is two presses: a preview that validates every row and shows each
 * problem against its line, and a commit that is offered only when no row has
 * one — a bad file is rejected whole. All three affordances the board draws
 * (`Import CSV`, `Add phase`, `Add another phase`) sit on the same
 * `estate.estate_structure.create` gate, and the inert twin carries the reason.
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
    /** The previewed import between its two presses, or null. */
    preview: { type: Object, default: null },
})

/*
 * Which board's stylesheet this page wears. Boards 3, 4 and 38 all live in the
 * first Community Admin sheet, and naming the wrong one — or none — renders this
 * screen with no board CSS at all.
 */
useWireframe('community-admin-01-login-dashboard-structure-and-residents')

const page = usePage()

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

/** Where this console is rooted — everything up to /estate. */
const root = computed(() => {
    const cut = page.url.indexOf('/estate')

    return cut === -1 ? '' : page.url.slice(0, cut)
})

/* ------------------------------------------------------------------ */
/* add phase */
/* ------------------------------------------------------------------ */

const adding = ref(false)

const phaseForm = useForm({
    name: '',
    block_count: 1,
    lot_from: '',
    lot_to: '',
    street: '',
})

phaseForm.transform((data) => ({
    ...data,
    lot_from: data.lot_from === '' ? null : Number(data.lot_from),
    lot_to: data.lot_to === '' ? null : Number(data.lot_to),
}))

/** What the range will create, read back before the press. */
const lotsPreview = computed(() => {
    const from = Number(phaseForm.lot_from)
    const to = Number(phaseForm.lot_to)

    if (phaseForm.lot_from === '' && phaseForm.lot_to === '') {
        return 'No lots yet — they can be imported under this phase later.'
    }

    if (!Number.isInteger(from) || !Number.isInteger(to) || from < 1 || to < from) {
        return 'A lot range runs upward from 1, with both ends filled in.'
    }

    return `${to - from + 1} lot${to - from === 0 ? '' : 's'}, Lot ${from} to Lot ${to}, all vacant until a household is filed at each.`
})

const openAdding = () => {
    if (!props.canCreate) {
        return
    }

    adding.value = !adding.value
    importing.value = false
    phaseForm.clearErrors()
}

const closeAdding = () => {
    adding.value = false
    phaseForm.reset()
    phaseForm.clearErrors()
}

const submitPhase = () => {
    if (!props.canCreate || phaseForm.name.trim() === '') {
        return
    }

    phaseForm.post(`${root.value}/estate/phases`, {
        preserveScroll: true,
        onSuccess: () => closeAdding(),
    })
}

/* ------------------------------------------------------------------ */
/* import CSV — preview, then commit */
/* ------------------------------------------------------------------ */

const importing = ref(props.preview !== null)

const importForm = useForm({ file: null })

const fileChosen = (event) => {
    importForm.file = event.target.files?.[0] ?? null
}

const submitPreview = () => {
    if (!props.canCreate || importForm.file === null) {
        return
    }

    importForm.post(`${root.value}/estate/import/preview`, { forceFormData: true })
}

const committing = ref(false)

const commitImport = () => {
    if (!props.canCreate || !props.preview?.valid) {
        return
    }

    committing.value = true

    router.post(`${root.value}/estate/import/commit`, { token: props.preview.token }, {
        onFinish: () => (committing.value = false),
    })
}

const discardPreview = () => {
    router.visit(`${root.value}/estate`)
}
</script>

<template>
    <Head title="Estate structure" />

    <EstateConsole title="Estate structure" :estate-name="estate.name" active="estate_structure">
        <template #actions>
            <button
                type="button"
                class="btn-outline-sm"
                :disabled="!canCreate"
                :title="canCreate ? 'Import a unit list from a CSV — reference, phase, street, type, status. Every row is checked and shown before anything is written; a file with one bad row is rejected whole.' : blockedReason"
                @click="importing = !importing; adding = false"
            >
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

            <button
                type="button"
                class="btn-primary-sm"
                :disabled="!canCreate"
                :title="canCreate ? 'Add a phase, with its blocks and its lot range decided together. Every lot is created at once, or none is.' : blockedReason"
                @click="openAdding"
            >
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
                <span>Add phase</span>
            </button>
        </template>

        <p v-if="page.props.flash?.success" class="str-flash">{{ page.props.flash.success }}</p>
        <p v-if="page.props.errors?.import" class="str-refusal">{{ page.props.errors.import }}</p>

        <!-- The add-phase panel — authored, closed on a fresh GET. -->
        <form v-if="adding" class="str-panel" @submit.prevent="submitPhase">
            <div class="str-head">Add a phase</div>

            <div class="str-fields">
                <div class="str-field">
                    <label for="ph-name">Name</label>
                    <input id="ph-name" v-model="phaseForm.name" type="text" required maxlength="64" placeholder="Phase 6" />
                </div>
                <div class="str-field">
                    <label for="ph-blocks">Blocks</label>
                    <input id="ph-blocks" v-model.number="phaseForm.block_count" type="number" min="0" max="200" required />
                </div>
                <div class="str-field">
                    <label for="ph-from">Lots from</label>
                    <input id="ph-from" v-model="phaseForm.lot_from" type="number" min="1" placeholder="451" />
                </div>
                <div class="str-field">
                    <label for="ph-to">to</label>
                    <input id="ph-to" v-model="phaseForm.lot_to" type="number" min="1" placeholder="520" />
                </div>
                <div class="str-field str-field--wide">
                    <label for="ph-street">Street — optional, put on every lot in the range</label>
                    <input id="ph-street" v-model="phaseForm.street" type="text" maxlength="120" placeholder="Hillside Drive" />
                </div>
            </div>

            <p class="str-note">{{ lotsPreview }}</p>

            <div v-if="phaseForm.errors.name" class="str-error">{{ phaseForm.errors.name }}</div>
            <div v-if="phaseForm.errors.lot_to" class="str-error">{{ phaseForm.errors.lot_to }}</div>

            <div class="str-actions">
                <button
                    type="submit"
                    class="btn-primary-sm"
                    :disabled="phaseForm.processing || phaseForm.name.trim() === ''"
                    :title="phaseForm.name.trim() === '' ? 'Name the phase.' : 'Add the phase and every lot in the range, in one transaction.'"
                >
                    <span>{{ phaseForm.processing ? 'Adding…' : 'Add phase' }}</span>
                </button>
                <button type="button" class="text-link-sm" @click="closeAdding">Cancel</button>
            </div>
        </form>

        <!-- The import — the file, or the preview of it. -->
        <div v-if="importing && preview === null" class="str-panel">
            <form @submit.prevent="submitPreview">
                <div class="str-head">Import a unit list</div>
                <p class="str-note">
                    A CSV with a header row — <strong>reference, phase, street, type, status</strong>. Reference and phase are
                    required; type is residential or commercial, status is occupied or vacant. Every row is checked and shown
                    before anything is written. A file with one bad row is rejected whole.
                </p>
                <div class="str-fields">
                    <div class="str-field str-field--wide">
                        <label for="imp-file">File</label>
                        <input id="imp-file" type="file" accept=".csv,text/csv" required @change="fileChosen" />
                    </div>
                </div>
                <div v-if="importForm.errors.file" class="str-error">{{ importForm.errors.file }}</div>
                <div class="str-actions">
                    <button
                        type="submit"
                        class="btn-primary-sm"
                        :disabled="importForm.processing || importForm.file === null"
                        :title="importForm.file === null ? 'Choose a CSV file.' : 'Check every row and show the preview. Nothing is written yet.'"
                    >
                        <span>{{ importForm.processing ? 'Checking…' : 'Preview' }}</span>
                    </button>
                    <button type="button" class="text-link-sm" @click="importing = false">Cancel</button>
                </div>
            </form>
        </div>

        <div v-else-if="preview !== null" class="str-panel">
            <div class="str-head">
                {{ preview.file }} — {{ preview.row_count }} row{{ preview.row_count === 1 ? '' : 's' }},
                <template v-if="preview.valid">every one passes.</template>
                <template v-else>{{ preview.errors.length }} problem{{ preview.errors.length === 1 ? '' : 's' }}. Nothing will be imported until the file passes whole.</template>
            </div>

            <p v-if="preview.new_phases.length > 0" class="str-note">
                New phase{{ preview.new_phases.length === 1 ? '' : 's' }} the file names, created on import:
                {{ preview.new_phases.join(', ') }}.
            </p>

            <ul v-if="preview.errors.length > 0" class="str-errors">
                <li v-for="(problem, i) in preview.errors" :key="i">Line {{ problem.line }} — {{ problem.message }}</li>
            </ul>

            <table v-if="preview.rows.length > 0" class="data-table str-preview">
                <thead>
                    <tr><th>Line</th><th>Reference</th><th>Phase</th><th>Street</th><th>Type</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <tr v-for="row in preview.rows" :key="row.line">
                        <td>{{ row.line }}</td>
                        <td>{{ row.reference }}</td>
                        <td>{{ row.phase }}</td>
                        <td>{{ row.street ?? '' }}</td>
                        <td>{{ row.type }}</td>
                        <td>{{ row.status }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-if="preview.row_count > preview.rows.length" class="str-note">
                The first {{ preview.rows.length }} of {{ preview.row_count }} rows are shown. All {{ preview.row_count }} were checked.
            </p>

            <div class="str-actions">
                <button
                    type="button"
                    class="btn-primary-sm"
                    :disabled="!preview.valid || committing"
                    :title="preview.valid ? `Import all ${preview.row_count} rows, in one transaction.` : 'The file has rows that fail. Fix it and preview it again — a list is imported whole or not at all.'"
                    @click="commitImport"
                >
                    <span>{{ committing ? 'Importing…' : `Import ${preview.row_count} unit${preview.row_count === 1 ? '' : 's'}` }}</span>
                </button>
                <button type="button" class="text-link-sm" @click="discardPreview">Discard</button>
            </div>
        </div>

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
            body="A phase is what gives a block of addresses a name — Phase 1, Phase 2 — and every unit, household and dues charge is filed under one. There is nothing to file anything under until the first phase exists. Add one above, or import the unit list."
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
              mockup has no disabled state to show; here it is the same control
              as the topbar's "Add phase", wrapped around the board's own icon
              and caption.
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
                "
                :disabled="!canCreate"
                :title="canCreate ? 'Add a phase, with its blocks and its lot range decided together.' : blockedReason"
                @click="openAdding"
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
    cursor: pointer;
}

button.phase-card {
    text-align: center;
    width: 100%;
    cursor: pointer;
}

button.text-link-sm {
    border: 0;
    background: none;
    padding: 0;
    cursor: pointer;
}

button[disabled] {
    cursor: not-allowed;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws a layout nobody is changing, so it
 * has no panel, no preview, no flash and no refusal. Kept to the tokens the
 * boards define and the shapes they already use.
 */
.str-flash,
.str-refusal {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 14px;
}

.str-flash {
    background: var(--green-100);
    color: var(--green-700);
}

.str-refusal {
    background: var(--red-100);
    color: var(--red-700);
}

.str-panel {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 18px;
    margin-bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 11px;
}

.str-panel form {
    display: flex;
    flex-direction: column;
    gap: 11px;
}

.str-head {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-800);
    line-height: 1.5;
}

.str-fields {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 11px;
}

.str-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.str-field--wide {
    grid-column: span 2;
}

.str-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.str-field input {
    height: 34px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

.str-field input[type='file'] {
    padding: 6px 10px;
    line-height: 20px;
}

.str-note {
    font-size: 11.5px;
    color: var(--slate-600);
    line-height: 1.6;
    margin: 0;
    max-width: 820px;
}

.str-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--red-700);
    line-height: 1.5;
}

.str-errors {
    margin: 0;
    padding: 10px 14px 10px 28px;
    background: var(--red-100);
    color: var(--red-700);
    border-radius: 10px;
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.6;
    max-height: 220px;
    overflow: auto;
}

.str-preview {
    max-height: 320px;
    display: block;
    overflow: auto;
}

.str-actions {
    display: flex;
    align-items: center;
    gap: 14px;
}
</style>
