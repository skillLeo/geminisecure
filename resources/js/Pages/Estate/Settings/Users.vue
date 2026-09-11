<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * Users & roles — board screen community-admin-22.
 *
 * WHO MAY REACH THIS ESTATE, AND WHAT EACH OF THEM MAY REACH. Every column
 * except the mailbox is DERIVED and none of it is stored twice: the initials are
 * cut from the name, the module list is the permission matrix read back, the
 * "All modules" sentinel is what that list says when a role holds every module,
 * and the amber Owner badge belongs to whoever holds the seniormost estate role
 * assigned here. Nothing on this page computes any of it — a page that
 * recomputed the module list would agree with the matrix until the day it did
 * not.
 *
 * THE LIST IS THE COMMITTEE AND NOT EVERYBODY WITH A KEY. Gemini's own Head of
 * Security holds an active assignment to this estate — that is how a platform
 * role is narrowed to the sites it covers, and it is what puts them on the
 * estate's dispatch — and `usersBoard()` filters them out by console. They are
 * not members of this community, they hold no estate role, and listing them
 * beside a Manage link would invite a committee to try to change the access of
 * somebody who does not work for them.
 *
 * FOUR THINGS BOARD 22 PRINTS THAT THIS PLATFORM DOES NOT HOLD (D-050), all of
 * them recorded rather than invented:
 *
 *   The green note. The board says Tracey Reid's role "has been consolidated"
 *   from two former titles. No role merge ever happened in this system, there is
 *   no history to resolve prior audit attribution against, and printing a claim
 *   about a named person's role history on an audit-adjacent screen would be
 *   fabricating a record. It is not drawn, and the table sits where it sat.
 *
 *   Her badge. The board reads "Treasurer & Accountant"; the estate catalogue
 *   holds seven roles and that is not one of them. It reads "Treasurer", which
 *   is the role she holds and the role every permission on this screen derives
 *   from.
 *
 *   The fourth committee member. The board draws Delroy Samuels, Secretary. He
 *   is not seeded, and seeding him would put a fourth contact on the Gemini
 *   client-detail board — a measured screen this wave does not own.
 *
 *   The mailboxes. The board's are @phoenixpark1.org and the seeded ones are
 *   @phoenixpark.test, deliberately unroutable because they are what the
 *   quick-login authenticates as. The .org domain is used where it belongs: it
 *   is the estate's published enquiries address on board 21.
 *
 * INVITING IS BUILT (12 §2, Wave 1). The topbar action opens a panel — an
 * address and one of this estate's seven roles, with the modules that role
 * reaches shown before the press — and the invitation names its inviter, this
 * estate and the role, and stands fourteen days. The open ones are listed
 * under the committee with resend and withdraw; the link itself is never on
 * this screen, because it is the credential until it is accepted.
 *
 * MANAGE IS STILL INERT AND SAYS WHY: changing somebody's role changes what
 * they may do to this estate's money, and the server sends the sentence.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    /** The seven-item settings column, with this screen marked current. */
    sections: { type: Array, required: true },
    /** One row per active estate assignment, seniormost role first. */
    rows: { type: Array, required: true },
    /** The role the Owner badge landed on, or null on an estate with nobody. */
    owner_role: { type: String, default: null },
    /** This console's roles, each with the modules it reaches. */
    roles: { type: Array, required: true },
    /** Invitations to this estate not yet accepted, newest first. Never the token. */
    invitations: { type: Array, required: true },
    canInvite: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
    canManage: { type: Boolean, required: true },
    manageBlockedReason: { type: String, required: true },
    /** Why the seniormost officer's row cannot be managed from here. */
    ownerReason: { type: String, required: true },
})

const page = usePage()

/*
 * Open when arrived at from board 24's "Invite user" (`?invite=1`), closed
 * otherwise. Local state: a panel is not something to bookmark.
 */
const inviting = ref(new URLSearchParams(page.url.split('?')[1] ?? '').get('invite') === '1' && props.canInvite)

const inviteForm = useForm({
    email: '',
    role: props.roles[0]?.name ?? '',
})

/** What the chosen role reaches, shown before the invitation goes out. */
const chosenRole = computed(() => props.roles.find((role) => role.name === inviteForm.role) ?? null)

const settingsRoot = computed(() => page.url.split('/settings')[0])

const openInvite = () => {
    if (!props.canInvite) {
        return
    }

    inviting.value = !inviting.value
    inviteForm.clearErrors()
}

const closeInvite = () => {
    inviting.value = false
    inviteForm.reset()
    inviteForm.clearErrors()
}

const submitInvite = () => {
    if (!props.canInvite || inviteForm.email.trim() === '') {
        return
    }

    inviteForm.post(`${settingsRoot.value}/settings/users/invitations`, {
        preserveScroll: true,
        onSuccess: () => closeInvite(),
    })
}

const resend = (invitation) => {
    router.post(`${settingsRoot.value}/settings/users/invitations/${invitation.id}/resend`, {}, { preserveScroll: true })
}

const revoke = (invitation) => {
    router.post(`${settingsRoot.value}/settings/users/invitations/${invitation.id}/revoke`, {}, { preserveScroll: true })
}

/*
 * Which board's stylesheet this page wears. The ten Estate Console boards do
 * NOT share one sheet the way the nine Gemini boards do, so every estate page
 * has to name its own or it renders with no board CSS at all.
 */
useWireframe('community-admin-06-settings-estate-profile-users-and-roles')

const retry = () => router.reload()

/**
 * Why Manage cannot be pressed, on every row.
 *
 * Read from the server for the same reason the invite sentence is: it is the
 * answer to "why can I not change this person's role", and it has to read the
 * same here as it does on board 24 where the same question is asked about the
 * matrix itself.
 */
/*
 * WHY A ROW CANNOT BE MANAGED, or null when it can.
 *
 * THREE DIFFERENT ANSWERS AND A READER IS OWED THE ONE THAT APPLIES. A viewer
 * without Settings update is refused for a reason that will still be true
 * tomorrow. The estate's seniormost officer cannot be managed from here at all
 * — demoting themselves or being suspended would leave nobody who could undo it
 * — and that is a rule rather than a permission. Everybody else can.
 */
const manageBlockedBy = (row) => {
    if (!props.canManage) {
        return props.manageBlockedReason
    }

    return row.manageable ? null : props.ownerReason
}

const managing = ref(null)

const manageForm = useForm({ role: '', status: 'active' })

const openManage = (row) => {
    if (manageBlockedBy(row) !== null) {
        return
    }

    if (managing.value === row.id) {
        managing.value = null

        return
    }

    manageForm.clearErrors()
    manageForm.role = row.role
    manageForm.status = row.status === 'suspended' ? 'suspended' : 'active'
    managing.value = row.id
}

/** What the press will do, in the words the row already uses. */
const manageReadBack = (row) => {
    const role = props.roles.find((option) => option.name === manageForm.role)
    const roleLabel = role?.label ?? manageForm.role

    const access =
        manageForm.status === 'suspended'
            ? 'and their account is suspended — they cannot sign in, and the record of what they held stays'
            : 'and their account is active'

    return `${row.name} becomes ${roleLabel}, ${access}.`
}

const submitManage = (row) => {
    manageForm.post(`${settingsRoot.value}/settings/users/${row.assignment_id}`, {
        preserveScroll: true,
        onSuccess: () => {
            managing.value = null
        },
    })
}

/**
 * A status the board's stylesheet has no badge for.
 *
 * It draws four Active users and defines `.status-badge.active` and nothing
 * else. Invited and Suspended are real states of an estate account, and neither
 * is a fault to flag in red — one is somebody who has not finished signing up
 * and the other is access an estate has withdrawn on purpose — so both take the
 * neutral navy the boards use for a fact that is simply so. Declared inline for
 * the same reason board 26 declares its amber inline: borrowing a variant named
 * for something else puts the wrong word's colour on the right fact.
 */
const NEUTRAL_BADGE = 'background:var(--navy-100);color:var(--slate-600);'

/* ------------------------------------------------------------------ */
/* the six states */
/* ------------------------------------------------------------------ */

/*
 * Sources are functions, not values: useScreenState runs once during setup, and
 * a value read there would freeze on the first render.
 *
 * THE SIXTH IS FORCEABLE ONLY, AND IT SAYS SOMETHING TRUE WHEN IT IS. This
 * screen draws no search box and no filter chips, so nothing a reader can press
 * empties the list. But the list IS filtered — by console, to this community's
 * own roles — and an estate whose only active assignments belong to Gemini's
 * staff has a list that is empty for a reason worth stating rather than one that
 * has never been filled. The payload cannot tell the two apart, because what it
 * carries is the rows that survived the filter and not the ones that did not, so
 * `filtered` is false from data and the state is reached with
 * `?_state=empty-filtered` in local. Guessing it from `owner_role` would make
 * the honest first-use empty unreachable, which is the state an estate actually
 * arrives in.
 */
const state = useScreenState({
    rows: () => props.rows.length,
    filtered: () => false,
})
</script>

<template>
    <Head title="Settings" />

    <EstateConsole title="Settings" :estate-name="estate.name" active="settings">
        <template #actions>
            <!--
              Live for a viewer holding Settings create; the inert twin says
              which access the two officers who read this screen lack.
            -->
            <button
                type="button"
                class="btn-primary-sm"
                :disabled="!canInvite"
                :title="canInvite ? 'Send somebody an invitation to hold a role on this estate. It names you, the estate and the role, and stands fourteen days.' : blockedReason"
                @click="openInvite"
            >
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
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
                <p v-if="page.props.flash?.success" class="inv-flash">{{ page.props.flash.success }}</p>
                <p v-if="page.props.errors?.invitation" class="inv-refusal">{{ page.props.errors.invitation }}</p>

                <!--
                  The invite panel — authored, closed on a fresh GET. The role's
                  reach is printed under the choice, because the person pressing
                  this is handing out access and should see what before, not
                  after.
                -->
                <form v-if="inviting" class="inv-panel" @submit.prevent="submitInvite">
                    <div class="inv-head">Invite somebody to {{ estate.name }}</div>

                    <div class="inv-fields">
                        <div class="inv-field">
                            <label for="inv-email">Email address — the invitation goes here, and it becomes their sign-in</label>
                            <input id="inv-email" v-model="inviteForm.email" type="email" required maxlength="190" autocomplete="off" />
                        </div>

                        <div class="inv-field">
                            <label for="inv-role">Role</label>
                            <select id="inv-role" v-model="inviteForm.role" required>
                                <option v-for="role in roles" :key="role.name" :value="role.name">{{ role.label }}</option>
                            </select>
                        </div>
                    </div>

                    <p v-if="chosenRole" class="inv-reach">
                        <strong>{{ chosenRole.label }}</strong> reaches: {{ chosenRole.modules || 'nothing yet' }}.
                    </p>

                    <div v-if="inviteForm.errors.email" class="inv-error">{{ inviteForm.errors.email }}</div>
                    <div v-if="inviteForm.errors.role" class="inv-error">{{ inviteForm.errors.role }}</div>

                    <div class="inv-actions">
                        <button
                            type="submit"
                            class="btn-primary-sm"
                            :disabled="inviteForm.processing || inviteForm.email.trim() === ''"
                            :title="inviteForm.email.trim() === '' ? 'Enter the address to invite.' : 'Send the invitation. It stands for fourteen days.'"
                        >
                            <span>{{ inviteForm.processing ? 'Sending…' : 'Send invitation' }}</span>
                        </button>
                        <button type="button" class="text-link-sm" @click="closeInvite">Cancel</button>
                    </div>
                </form>

                <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="5" />

                <EmptyState
                    v-else-if="state.isDenied.value"
                    variant="denied"
                    title="Settings is not part of your role’s access"
                    body="Who may reach this estate, and what each of them may reach, sits inside Settings — and the matrix gives that module to the Community Super Admin, the President and the Vice President only. A permission change is made by Gemini Security against the platform matrix, so it is not something this console can grant you."
                />

                <EmptyState
                    v-else-if="state.isError.value"
                    variant="error"
                    title="The user list could not be read"
                    body="The estate database did not answer. Nobody’s access has changed and no role has moved — this is a read that failed, and re-running it is safe."
                    action-label="Try again"
                    @action="retry"
                />

                <!--
                  Filtered empty: assignments exist and none of them is this
                  community's. See the note on `state` above — no control here
                  produces it, and it is true when it is forced.
                -->
                <EmptyState
                    v-else-if="state.isEmptyFiltered.value"
                    variant="filtered"
                    title="Everybody assigned to this estate works for Gemini Security"
                    body="This list holds the committee, and no committee role has been assigned here yet. Gemini’s own staff can hold an assignment to an estate — that is how a platform role is narrowed to the sites it covers — and they are deliberately not listed, because their access is Gemini’s to grant and not this community’s to change."
                />

                <EmptyState
                    v-else-if="state.isEmpty.value"
                    variant="first-use"
                    title="Nobody can reach this estate yet"
                    body="Not one committee role has been assigned here, so there is nobody to read the ledger, run a meeting or answer a maintenance ticket. An estate’s first user is created with the estate rather than invited into it."
                />

                <table v-else class="data-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Modules</th>
                            <th>Status</th>

                            <!-- The board's fifth header is empty: it sits over
                                 the row action and names nothing. -->
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="row in rows" :key="row.id">
                        <tr>
                            <td>
                                <div class="res-cell">
                                    <div class="res-avatar">{{ row.initials }}</div>
                                    <div>
                                        <div class="res-name">{{ row.name }}</div>
                                        <div class="res-sub">{{ row.email }}</div>
                                    </div>
                                </div>
                            </td>

                            <!--
                              "President · Owner" arrives as one string, middot
                              and all, because the rule for who gets the second
                              half is the matrix's and not this template's. The
                              amber variant follows the same flag.
                            -->
                            <td>
                                <div class="role-badge" :class="{ owner: row.is_owner }">{{ row.role_badge }}</div>
                            </td>

                            <!-- Every module this role reaches, or the sentinel
                                 when that is all of them. Derived, both of
                                 them. -->
                            <td :title="row.module_list.join(', ')">{{ row.modules }}</td>

                            <td>
                                <div
                                    class="status-badge"
                                    :class="row.status"
                                    :style="row.status === 'active' ? undefined : NEUTRAL_BADGE"
                                >
                                    {{ row.status_label }}
                                </div>
                            </td>

                            <td>
                                <button
                                    type="button"
                                    class="text-link-sm"
                                    :disabled="manageBlockedBy(row) !== null"
                                    :title="manageBlockedBy(row) ?? `Change ${row.name}'s role, or withdraw their access. Both are recorded with who made the change and when.`"
                                    :aria-label="`Manage ${row.name}`"
                                    @click="openManage(row)"
                                >
                                    Manage
                                </button>
                            </td>
                        </tr>

                        <!--
                          AUTHORED. The board draws a row nobody is managing, so
                          it has no panel. Two acts on one: a role change changes
                          what they may do, a suspension stops them doing
                          anything, and nothing here deletes a person from an
                          estate's history.
                        -->
                        <tr v-if="managing === row.id" class="manage-row">
                            <td :colspan="6">
                                <form class="manage-panel" @submit.prevent="submitManage(row)">
                                    <div class="manage-head">
                                        {{ row.name }} — {{ row.email }}. A role decides which modules they reach;
                                        suspending withdraws the account without removing who held what and when.
                                    </div>

                                    <div class="manage-fields">
                                        <div class="manage-field">
                                            <label :for="`mg-role-${row.id}`">Role</label>
                                            <select :id="`mg-role-${row.id}`" v-model="manageForm.role" required>
                                                <option v-for="option in roles" :key="option.name" :value="option.name">
                                                    {{ option.label }}
                                                </option>
                                            </select>
                                        </div>
                                        <div class="manage-field">
                                            <label :for="`mg-status-${row.id}`">Account</label>
                                            <select :id="`mg-status-${row.id}`" v-model="manageForm.status" required>
                                                <option value="active">Active</option>
                                                <option value="suspended">Suspended — cannot sign in</option>
                                            </select>
                                        </div>
                                    </div>

                                    <p class="manage-read">{{ manageReadBack(row) }}</p>

                                    <div v-if="manageForm.errors.role" class="manage-error">
                                        {{ manageForm.errors.role }}
                                    </div>

                                    <div class="manage-actions">
                                        <button
                                            type="submit"
                                            class="btn-primary-sm"
                                            :disabled="manageForm.processing"
                                            title="Save the change. It goes on the estate's audit log with who made it."
                                        >
                                            <span>{{ manageForm.processing ? 'Saving…' : 'Save' }}</span>
                                        </button>
                                        <button type="button" class="text-link-sm" @click="managing = null">
                                            Cancel
                                        </button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    </template>
                    </tbody>
                </table>

                <!--
                  The invitations still open, under the committee they will
                  join. Absent when there are none, rather than a heading over
                  nothing. A lapsed one stays until it is withdrawn or resent,
                  because an invitation that quietly vanished would read as one
                  that was accepted.
                -->
                <template v-if="!state.isLoading.value && !state.isDenied.value && !state.isError.value && invitations.length > 0">
                    <div class="inv-section-head">Invitations open</div>

                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Invited</th>
                                <th>Role</th>
                                <th>Sent by</th>
                                <th>Stands until</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="invitation in invitations" :key="invitation.id">
                                <td>{{ invitation.email }}</td>
                                <td><div class="role-badge">{{ invitation.role_label }}</div></td>
                                <td>{{ invitation.invited_by }} · {{ invitation.sent_on }}</td>
                                <td>
                                    <div
                                        class="status-badge"
                                        :style="invitation.expired ? 'background:var(--red-100);color:var(--red-700);' : NEUTRAL_BADGE"
                                    >
                                        {{ invitation.expired ? `Lapsed ${invitation.expires_on}` : invitation.expires_on }}
                                    </div>
                                </td>
                                <td>
                                    <div class="inv-row-actions">
                                        <button
                                            type="button"
                                            class="text-link-sm"
                                            :disabled="!canInvite"
                                            :title="canInvite ? 'Send it again with a fresh link and a fresh fortnight. The earlier link stops working.' : blockedReason"
                                            @click="resend(invitation)"
                                        >
                                            Resend
                                        </button>
                                        <button
                                            type="button"
                                            class="text-link-sm"
                                            :disabled="!canInvite"
                                            :title="canInvite ? 'Withdraw it. The link stops working and no account is created.' : blockedReason"
                                            @click="revoke(invitation)"
                                        >
                                            Withdraw
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </template>
            </div>
        </div>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only, and each removal names the element that needs it.
 *
 * The board draws its topbar action, its seven settings items and its four row
 * links as <div>s; here they are buttons and links. A <button> arrives wearing a
 * border, a face and the browser's own font, and app.css has already taken the
 * UA's underline and blue off every anchor on a board page.
 *
 * `font-family` and never `font`. Vue's scoped attribute lifts a bare `button`
 * selector to the same weight as a single class, so `font: inherit` here would
 * out-specify `.settings-nav-item`'s own 12.5px and `.text-link-sm`'s own 700
 * and render both at the body's size and weight. The board declares no
 * font-family on any of these, so that one property is the whole of what is
 * missing. See D-045.
 *
 * Padding and margin need no removing — the board's own `*` reset already takes
 * both off every element on the page, buttons included.
 */
button {
    font-family: inherit;
}

/*
 * NONE OF THESE THREE IS GIVEN A BORDER BY THE BOARD. .btn-primary-sm declares
 * a background and a shadow and no border; .settings-nav-item and .text-link-sm
 * declare neither. The amber face on .settings-nav-item.active is a two-class
 * rule no button here can ever match — the current section is drawn as text.
 */
button.btn-primary-sm {
    border: 0;
}

button.settings-nav-item,
button.text-link-sm {
    border: 0;
    background: none;
    text-align: left;
}

button.btn-primary-sm,
button.text-link-sm {
    cursor: pointer;
}

button[disabled] {
    cursor: not-allowed;
}

/*
 * AUTHORED BELOW THIS LINE. The board is a still image of a committee nobody
 * has invited anybody to, so it draws no panel, no flash, no refusal and no
 * open invitations. Kept to the tokens and the shapes the estate boards use.
 */
.inv-flash,
.inv-refusal {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 14px;
}

.inv-flash {
    background: var(--green-100);
    color: var(--green-700);
}

/* The manage panel, opened under its own row. */
.manage-row td {
    background: var(--navy-100);
    padding: 14px 16px;
}

.manage-panel {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.manage-head {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--navy-800);
    line-height: 1.55;
    max-width: 760px;
}

.manage-fields {
    display: flex;
    flex-wrap: wrap;
    gap: 11px;
}

.manage-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 220px;
}

.manage-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.manage-field select {
    height: 33px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12px;
    color: var(--navy-900);
}

.manage-read {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--navy-900);
    line-height: 1.5;
    margin: 0;
    background: var(--white);
    border-radius: 10px;
    padding: 10px 13px;
}

.manage-error {
    font-size: 11px;
    font-weight: 600;
    color: var(--red-700);
    line-height: 1.5;
}

.manage-actions {
    display: flex;
    align-items: center;
    gap: 14px;
}

.inv-refusal {
    background: var(--red-100);
    color: var(--red-700);
}

.inv-panel {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 18px;
    margin-bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 11px;
}

.inv-head {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-800);
}

.inv-fields {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 11px;
}

.inv-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.inv-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.inv-field input,
.inv-field select {
    height: 34px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

.inv-reach {
    font-size: 11.5px;
    color: var(--slate-600);
    line-height: 1.5;
    margin: 0;
}

.inv-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--red-700);
    line-height: 1.5;
}

.inv-actions {
    display: flex;
    align-items: center;
    gap: 14px;
}

.inv-section-head {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--slate-500);
    text-transform: uppercase;
    letter-spacing: 0.3px;
    margin: 18px 0 10px;
}

.inv-row-actions {
    display: flex;
    gap: 12px;
}
</style>
