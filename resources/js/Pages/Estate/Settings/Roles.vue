<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * Role access matrix — board screen community-admin-24.
 *
 * THIS SCREEN READS AND NEVER WRITES, AND THAT IS THE WHOLE SECURITY ARGUMENT
 * OF THE SETTINGS MODULE (D-048).
 * ======================================================================
 *
 * Board 24 draws sixty permission pills and not one control that changes any of
 * them — no button, no dropdown, no save. That is not an omission in the
 * drawing. Changing what a role may do is an `approve`-level act by the rule
 * this system applies to every irreversible thing (D-013), and no estate role
 * holds `estate.settings.approve` — not even the Community Super Admin, whose
 * Full cell on Settings carries no Approver tag, and D-008 is explicit that Full
 * without the tag does not grant approve.
 *
 * So this page has nothing to post to, and it is refused three independent ways
 * rather than one, because a settings screen is precisely where a privilege
 * escalation gets built by accident: no route in `routes/tenant.php` writes
 * `role_module_access`, no method on `App\Services\Estate\Settings` writes it
 * either, and `can_edit` arrives false for every one of the seven roles.
 * `EstateSettingsTest` proves all three across all seven rather than assuming
 * any of them, and fingerprints every row of the matrix before and after the
 * widest role has used every write this module ships.
 *
 * NOT ONE CONTROL BELOW CHANGES A PERMISSION. The only control on this screen is
 * the board's own "Invite user", which is inert for a reason that has nothing to
 * do with the matrix, and the settings column beside it. `can_edit` is read and
 * printed rather than ignored — the answer to "why can I not change this" has to
 * be on the screen that provokes the question.
 *
 * THIRTEEN MODULES AND SEVEN ROLES WHERE THE BOARD DREW TEN AND SIX. The three
 * extra modules are the Ruling 1 split (D-010, D-014): `payments`,
 * `vendor_costs` and `maintenance_budget` were separated out so a Property
 * Manager could see the costs they commission without ever seeing a resident's
 * financial position. The seventh role is the Community Super Admin, which board
 * 24's own audit note says it deliberately omits. Rendering all of them is the
 * more honest screen — a permission screen that hides three modules and a role
 * is not describing the permissions, which is the one thing a permission screen
 * must not do.
 *
 * WHERE THE DRAWN CELLS AND THE MODEL DISAGREE, RECORDED AND NOT RESOLVED
 * (D-044). Board 24 gives the Property Manager View on Dues & ledger and on
 * Accounting. The model gives them nothing on `dues_ledger`, `payments`,
 * `accounting_posting` and `payroll`, and `RbacMatrixSeeder` throws rather than
 * seeding otherwise. The model wins, this screen prints em dashes there, and
 * each of those cells carries a marker and the invariant note — because a
 * committee reading the matrix is exactly who needs to be told that this is a
 * platform invariant and not their estate's preference. Every other cell of
 * board 24 agrees with the model.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    /** The seven-item settings column, with this screen marked current. */
    sections: { type: Array, required: true },
    /** Full, View, Entry, — with the four descriptions the board prints. */
    legend: { type: Array, required: true },
    /** The seven estate roles, in the matrix's own order. */
    roles: { type: Array, required: true },
    /** One row per module, each carrying a cell per role in the same order. */
    rows: { type: Array, required: true },
    audit_note: { type: String, required: true },
    /** False for every estate role, and printed rather than assumed. */
    can_edit: { type: Boolean, required: true },
    read_only_reason: { type: String, required: true },
    invariant_note: { type: String, required: true },
    canInvite: { type: Boolean, required: true },
    /** Board 22 with its invite panel open — where this board's "Invite user" leads. */
    inviteHref: { type: String, required: true },
    inviteReason: { type: String, required: true },
})

/*
 * Which board's stylesheet this page wears. The ten Estate Console boards do
 * NOT share one sheet the way the nine Gemini boards do, so every estate page
 * has to name its own or it renders with no board CSS at all.
 */
useWireframe('community-admin-06-settings-estate-profile-users-and-roles')

const retry = () => router.reload()

/**
 * Which cells the Property Manager lock actually produced.
 *
 * TRUE ONLY WHERE THE MODEL AND THE BOARD DISAGREE, and the three conditions
 * are each doing work. `locked_financial` is the platform invariant carried on
 * the module; the role is the one it locks; and `none` is what the lock leaves
 * behind. A row the estate simply never granted — the Secretary on Dues &
 * ledger, say — is also an em dash, and marking it would tell a committee that
 * a decision they made was made for them.
 */
const isInvariantCell = (row, cell) =>
    row.locked_financial && cell.role === 'estate.property_manager' && cell.level === 'none'

/**
 * The board's own hard break in a column heading.
 *
 * Board 24 writes "Vice<br>President", "Property<br>Manager" and
 * "Admin<br>Assistant": the designer stacked every two-word role rather than
 * leaving a row of long single lines, and the heading band is two lines tall
 * because of it. Reproduced rather than left to the browser, because this table
 * carries an eighth column and every one of its role names happens to fit on one
 * line — which would make the band thirteen pixels shorter than the board's and
 * move all thirteen rows under it.
 *
 * TWO LINES AND NEVER THREE: the first word, then the rest. That is exactly what
 * the board does to its six, and it is what "Community Super Admin" — the
 * seventh, the one board 24 deliberately omits — needs in order to sit beside
 * them.
 */
const headLines = (label) => {
    const words = label.split(' ')

    return words.length < 2 ? [label] : [words[0], words.slice(1).join(' ')]
}

/*
 * "Invite user" is a link to board 22's panel for a viewer holding Settings
 * create, and an inert twin with the reason for the two officers who read
 * this matrix and may not issue a credential from it.
 */

/* ------------------------------------------------------------------ */
/* the six states */
/* ------------------------------------------------------------------ */

/*
 * Sources are functions, not values: useScreenState runs once during setup, and
 * a value read there would freeze on the first render.
 *
 * THE SIXTH IS REAL AND IS REACHED FROM DATA. A matrix is modules DOWN and roles
 * ACROSS, and both are read from the central catalogue filtered to the estate
 * console. A platform with modules and no estate role produces a table with rows
 * and no columns — emptiness the filter caused — which is a different sentence
 * from a console that has no modules at all.
 */
const state = useScreenState({
    rows: () => props.rows.length,
    filtered: () => props.roles.length === 0,
})
</script>

<template>
    <Head title="Settings" />

    <EstateConsole title="Settings" :estate-name="estate.name" active="settings">
        <template #actions>
            <!--
              No plus glyph on this board, unlike board 22's identical action —
              the board draws the icon there and only the word here, and the
              difference is the designer's rather than an omission.
            -->
            <Link v-if="canInvite" :href="inviteHref" class="btn-primary-sm">
                <span>Invite user</span>
            </Link>
            <button v-else type="button" class="btn-primary-sm" disabled :title="inviteReason">
                <span>Invite user</span>
            </button>
        </template>

        <div class="settings-layout">
            <!--
              The settings column, on every one of the four screens. The item
              the reader is on is text rather than a control — there is nowhere
              for it to lead — the three built siblings are real links, and the
              three unbuilt ones are visibly inert and each says what it is
              waiting on rather than linking into a 404.
            -->
            <div class="settings-nav">
                <template v-for="section in sections" :key="section.key">
                    <div v-if="section.active" class="settings-nav-item active" aria-current="page">
                        {{ section.label }}
                    </div>
                    <Link v-else :href="section.href" class="settings-nav-item">
                        {{ section.label }}
                    </Link>
                </template>
            </div>

            <div>
                <SkeletonRows v-if="state.isLoading.value" :rows="10" :columns="7" />

                <EmptyState
                    v-else-if="state.isDenied.value"
                    variant="denied"
                    title="Settings is not part of your role’s access"
                    body="The matrix is the contract that decides what every role on this estate may do, and reading it sits inside Settings — which the matrix itself gives to the Community Super Admin, the President and the Vice President only. That is the Settings row of this very table, doing exactly what it says."
                />

                <EmptyState
                    v-else-if="state.isError.value"
                    variant="error"
                    title="The role access matrix could not be read"
                    body="The permission model did not answer. Nothing has changed — this screen only ever reads, so there was nothing in flight to lose. Every role still holds exactly what it held."
                    action-label="Try again"
                    @action="retry"
                />

                <!--
                  Rows and no columns. See the note on `state`: modules exist and
                  no estate role does, which is the filter emptying the table
                  rather than a console with nothing in it.
                -->
                <EmptyState
                    v-else-if="state.isEmptyFiltered.value"
                    variant="filtered"
                    title="This console has modules and no roles to hold them"
                    body="A matrix is modules down and roles across, and there is not one estate role in the catalogue to put across the top. Until a role exists there is nothing for a permission to belong to, and nothing an estate could be told about who may do what."
                />

                <EmptyState
                    v-else-if="state.isEmpty.value"
                    variant="first-use"
                    title="This console has no modules yet"
                    body="The matrix is generated from the modules this console holds, and it holds none. Every screen in the Estate Console sits behind one of them, so a console with no modules is one nobody can reach any part of."
                />

                <template v-else>
                    <!--
                      The legend, in the board's own order: Full, View, Entry,
                      then no access. The order is the server's and the four
                      descriptions are the definitions the accounting note
                      treats as literal — "Entry" means data entry and no
                      approval, which is the posting/approval split the whole
                      double-entry workflow rests on.
                    -->
                    <div class="role-legend">
                        <div v-for="item in legend" :key="item.key" class="role-legend-item">
                            <div class="perm-pill" :class="item.key">{{ item.label }}</div>
                            <span>{{ item.description }}</span>
                        </div>
                    </div>

                    <table class="matrix-table">
                        <thead>
                            <tr>
                                <th>Module</th>
                                <th v-for="role in roles" :key="role.name" :title="`${role.label} — ${role.scope_label}`">
                                    <!--
                                      Stacked on two lines, as the board stacks
                                      its own — see headLines().

                                      The scope is on the title rather than in a
                                      .role-head-scope caption. The board defines
                                      that style and populates it on no role, and
                                      drawing seven captions the board draws none
                                      of would make the heading band half again
                                      as tall and move every row beneath it.
                                    -->
                                    <div class="role-head-name">
                                        <template v-for="(line, i) in headLines(role.label)" :key="line">
                                            <br v-if="i > 0" />{{ line }}
                                        </template>
                                    </div>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in rows" :key="row.key">
                                <td>
                                    <div class="mod-name">{{ row.label }}</div>
                                </td>

                                <td v-for="cell in row.cells" :key="`${row.key}-${cell.role}`">
                                    <div class="perm-pill" :class="cell.level">
                                        {{ cell.label }}

                                        <!--
                                          The Approver tag, on the four cells
                                          the board draws it on and on every
                                          other cell the model actually grants
                                          it to. It is read from `can_approve`
                                          rather than from a list of cells,
                                          because D-008 is that Full and Approver
                                          are separate grants — a Full cell with
                                          no tag runs a thing and does not
                                          declare it final.
                                        -->
                                        <span v-if="cell.can_approve" class="approver-tag">Approver</span>

                                        <!--
                                          WHERE THE BOARD AND THE MODEL DISAGREE,
                                          SAID ON THE CELL. Board 24 draws the
                                          Property Manager with View here; Ruling
                                          1 (D-010) closes it, the seeder throws
                                          rather than granting it, and the note
                                          under the table says why. Marked rather
                                          than left as a bare em dash somebody
                                          would read as this estate's own choice.
                                        -->
                                        <span
                                            v-if="isInvariantCell(row, cell)"
                                            class="inv-tag"
                                            :title="invariant_note"
                                        >
                                            Locked
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!--
                      Three sentences in the board's one note box rather than
                      three boxes. The first is board 24's own, verbatim; the
                      second is why nothing on this screen can change a cell;
                      the third is which cells the platform closed rather than
                      this estate.
                    -->
                    <div class="audit-note">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path
                                d="M12 2 2 7v6c0 5.2 3.8 9 10 11 6.2-2 10-5.8 10-11V7l-10-5z"
                                stroke="currentColor"
                                stroke-width="1.5"
                                stroke-linejoin="round"
                            />
                        </svg>
                        <div>
                            <p class="note-line">{{ audit_note }}</p>

                            <!--
                              `can_edit` is false for all seven roles and is
                              read rather than assumed — the day a client rules
                              that an estate may administer its own roles, this
                              sentence stops being drawn without anybody having
                              to remember it is here.
                            -->
                            <p v-if="!can_edit" class="note-line">{{ read_only_reason }}</p>

                            <p class="note-line">{{ invariant_note }}</p>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only above the authored line, and each removal names the
 * element that needs it.
 *
 * This screen draws exactly two kinds of control — the topbar action and the
 * settings column — because it is a read of the permission model and there is
 * nothing on it to press. The board draws both as <div>s.
 *
 * `font-family` and never `font`. Vue's scoped attribute lifts a bare `button`
 * selector to the same weight as a single class, so `font: inherit` would
 * out-specify `.settings-nav-item`'s own 12.5px and render it at the body's
 * size. The board declares no font-family on it, so that one property is the
 * whole of what is missing. See D-045.
 */
button {
    font-family: inherit;
}

/* .btn-primary-sm declares a background and a shadow and no border;
 * .settings-nav-item declares neither, and the amber face on .active is a
 * two-class rule no button here can match — the current section is drawn as
 * text. */
button.btn-primary-sm {
    border: 0;
}

button.settings-nav-item {
    border: 0;
    background: none;
    text-align: left;
}

button[disabled] {
    cursor: not-allowed;
}

/* =====================================================================
 * AUTHORED BELOW THIS LINE.
 * ===================================================================== */

/*
 * The invariant marker, in the shape the board already uses for a sub-tag on a
 * pill — `.approver-tag`'s geometry exactly, and deliberately NOT its amber.
 *
 * Amber on this board means a grant: Full, Owner, Approver. This says the
 * opposite — that a cell is closed by platform rule and not by the estate — so
 * it takes the slate the boards use for a fact nobody on this screen decided.
 */
.inv-tag {
    display: block;
    font-size: 8px;
    font-weight: 700;
    color: var(--slate-500);
    margin-top: 2px;
}

/*
 * Three sentences in the board's note box.
 *
 * The board's `.audit-note span` carries the type and the box carries the
 * colour; these are <p> rather than <span> so the three read as three, and they
 * restate that one rule on the element that took the job over. Nothing new:
 * same size, same colour, same line height.
 */
.note-line {
    font-size: 11px;
    color: var(--slate-600);
    line-height: 1.5;
    margin: 0;
}

.note-line + .note-line {
    margin-top: 7px;
}
</style>
