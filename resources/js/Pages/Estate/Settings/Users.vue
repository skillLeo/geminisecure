<script setup>
import { computed } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { pendingReason } from './sections'

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
 * BOTH CONTROLS ARE INERT AND BOTH SAY WHY. Inviting somebody issues a
 * credential to a person who is not yet a user, and changing somebody's role
 * changes what they may do to this estate's money — neither is a thing to build
 * behind a topbar button and a row link, and the server sends the sentence that
 * says so.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    /** The seven-item settings column, with this screen marked current. */
    sections: { type: Array, required: true },
    /** One row per active estate assignment, seniormost role first. */
    rows: { type: Array, required: true },
    /** The role the Owner badge landed on, or null on an estate with nobody. */
    owner_role: { type: String, default: null },
    canInvite: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
    reasons: { type: Object, required: true },
})

/*
 * Which board's stylesheet this page wears. The ten Estate Console boards do
 * NOT share one sheet the way the nine Gemini boards do, so every estate page
 * has to name its own or it renders with no board CSS at all.
 */
useWireframe('community-admin-06-settings-estate-profile-users-and-roles')

const retry = () => router.reload()

/**
 * Why Invite cannot be pressed.
 *
 * THE PERMISSION COMES FIRST, because it is a different thing to be told: a
 * President holds Settings as View, and "not built yet" would have them waiting
 * for a screen when what they lack is the permission to use it. Below that,
 * nobody can invite anybody yet, and the server's sentence says why an
 * invitation is more than a button.
 */
const inviteBlockedBy = computed(() => (props.canInvite ? props.reasons.invite : props.blockedReason))

/**
 * Why Manage cannot be pressed, on every row.
 *
 * Read from the server for the same reason the invite sentence is: it is the
 * answer to "why can I not change this person's role", and it has to read the
 * same here as it does on board 24 where the same question is asked about the
 * matrix itself.
 */
const manageBlockedBy = computed(() => (props.canInvite ? props.reasons.manage : props.blockedReason))

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
              Drawn live on the board and inert here, with the reason under the
              cursor. An invitation issues a credential to somebody who is not
              yet a user, so it needs the mailbox, the role and the expiry
              captured together and an email that actually leaves the building —
              a half-built invite is an account nobody can finish creating.
            -->
            <button type="button" class="btn-primary-sm" disabled :title="inviteBlockedBy">
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
                    <Link v-else-if="section.href" :href="section.href" class="settings-nav-item">
                        {{ section.label }}
                    </Link>
                    <button
                        v-else
                        type="button"
                        class="settings-nav-item"
                        disabled
                        :title="pendingReason(section.key)"
                    >
                        {{ section.label }}
                    </button>
                </template>
            </div>

            <div>
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
                        <tr v-for="row in rows" :key="row.id">
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
                                <!--
                                  `manage_href` is null on every row and is read
                                  rather than assumed: the day the screen behind
                                  it exists, this becomes a Link and the reason
                                  stops being drawn, in one place.
                                -->
                                <Link v-if="row.manage_href" :href="row.manage_href" class="text-link-sm">
                                    Manage
                                </Link>
                                <button
                                    v-else
                                    type="button"
                                    class="text-link-sm"
                                    disabled
                                    :title="manageBlockedBy"
                                    :aria-label="`Manage ${row.name}`"
                                >
                                    Manage
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
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

button[disabled] {
    cursor: not-allowed;
}
</style>
