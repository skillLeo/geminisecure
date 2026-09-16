<script setup>
import { ref } from 'vue'
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import SettingsTabs from './SettingsTabs.vue'
import EmptyState from '../../../Components/EmptyState.vue'

/**
 * Platform admins — board 42's tab (12 §2, item 43).
 *
 * NO BOARD DRAWS THIS TAB'S BODY; boards 42 to 45 draw the tab. Built under
 * the same strip so the module reads as one.
 *
 * ACCOUNTS ARE ISSUED BY INVITATION, and nowhere else: a fourteen-day link that
 * names who sent it and which role it carries. This screen sends and withdraws
 * those, and moves an existing person between the console's roles, sets the
 * sites a site-scoped role covers, or suspends them.
 *
 * WHAT A ROLE CAN DO IS NOT CHANGED HERE. That is the role matrix's, and it is
 * a read. Nobody changes their own account, and the last active Director cannot
 * be demoted or suspended — the rows say so rather than failing on the press.
 */
const props = defineProps({
    tabs: { type: Array, required: true },
    people: { type: Array, required: true },
    invitations: { type: Array, required: true },
    roles: { type: Array, required: true },
    clients: { type: Array, required: true },
    canInvite: { type: Boolean, required: true },
    canManage: { type: Boolean, required: true },
    writeDisabledReason: { type: String, required: true },
})

const page = usePage()

/* ---- inviting ---- */
const inviting = ref(false)
const inviteForm = useForm({ email: '', role: props.roles[0]?.name ?? '' })
const invite = () =>
    inviteForm.post('/settings/admins/invitations', { preserveScroll: true, onSuccess: () => (inviting.value = false) })

const resend = (invitation) => router.post(`/settings/admins/invitations/${invitation.id}/resend`, {}, { preserveScroll: true })
const revoke = (invitation) => router.post(`/settings/admins/invitations/${invitation.id}/revoke`, {}, { preserveScroll: true })

/* ---- managing one person ---- */
const editing = ref(null)
const manageForm = useForm({ role: '', status: 'active', sites: [] })

const roleIsScoped = (name) => props.roles.find((role) => role.name === name)?.scoped ?? false

const edit = (person) => {
    if (editing.value === person.id) {
        editing.value = null

        return
    }

    editing.value = person.id
    manageForm.role = person.role ?? props.roles[0]?.name ?? ''
    manageForm.status = person.status === 'suspended' ? 'suspended' : 'active'
    manageForm.sites = [...person.sites]
}

const save = (person) =>
    manageForm.post(`/settings/admins/${person.id}`, { preserveScroll: true, onSuccess: () => (editing.value = null) })
</script>

<template>
    <Head title="Platform admins" />

    <GeminiConsole title="Platform settings">
        <template #actions>
            <button
                type="button"
                class="btn-primary-sm"
                :disabled="!canInvite"
                :title="canInvite ? 'Invite somebody to a Gemini Console role. The link lasts fourteen days.' : writeDisabledReason"
                @click="inviting = !inviting"
            >
                <span>Invite</span>
            </button>
        </template>

        <SettingsTabs :tabs="tabs" />

        <p v-if="page.props.flash?.success" class="ad-flash">{{ page.props.flash.success }}</p>
        <p v-if="page.props.errors?.admins" class="ad-error">{{ page.props.errors.admins }}</p>

        <form v-if="inviting" class="ad-panel" @submit.prevent="invite">
            <div class="ad-head">
                The person chooses their own password on a link only they receive. It names you as the inviter and the
                role it carries, and lapses after fourteen days.
            </div>
            <div class="ad-fields">
                <label>
                    <span>Email</span>
                    <input v-model="inviteForm.email" type="email" maxlength="190" required />
                </label>
                <label>
                    <span>Role</span>
                    <select v-model="inviteForm.role">
                        <option v-for="role in roles" :key="role.name" :value="role.name">{{ role.label }}</option>
                    </select>
                </label>
            </div>
            <p v-if="roleIsScoped(inviteForm.role)" class="ad-note">
                This role is scoped to assigned sites. Once the invitation is accepted, set the sites from their row below —
                until then they see nothing.
            </p>
            <div v-for="(message, key) in inviteForm.errors" :key="key" class="ad-error">{{ message }}</div>
            <div class="ad-actions">
                <button type="submit" class="btn-primary-sm" :disabled="inviteForm.processing"><span>Send invitation</span></button>
                <button type="button" class="text-link-sm" @click="inviting = false">Cancel</button>
            </div>
        </form>

        <div class="ad-panel">
            <div class="ad-section">People with a console account</div>

            <EmptyState
                v-if="people.length === 0"
                variant="first-use"
                title="Nobody holds a platform account"
                body="Accounts are issued by invitation and never self-created."
            />

            <div v-for="person in people" :key="person.id" class="ad-row-wrap">
                <div class="ad-row">
                    <div>
                        <div class="ad-name">
                            {{ person.name }}
                            <span v-if="person.status === 'suspended'" class="ad-pill off">Suspended</span>
                        </div>
                        <div class="ad-sub">{{ person.email }} · {{ person.role_label }} · {{ person.site_names }}</div>
                    </div>
                    <button
                        type="button"
                        class="text-link-sm"
                        :disabled="!canManage || person.reason !== null"
                        :title="!canManage ? writeDisabledReason : person.reason ?? 'Change this person\'s role, sites or standing.'"
                        @click="edit(person)"
                    >
                        {{ editing === person.id ? 'Close' : 'Change' }}
                    </button>
                </div>

                <form v-if="editing === person.id" class="ad-edit" @submit.prevent="save(person)">
                    <div class="ad-fields">
                        <label>
                            <span>Role</span>
                            <select v-model="manageForm.role">
                                <option v-for="role in roles" :key="role.name" :value="role.name">{{ role.label }}</option>
                            </select>
                        </label>
                        <label>
                            <span>Standing</span>
                            <select v-model="manageForm.status">
                                <option value="active">Active</option>
                                <option value="suspended">Suspended</option>
                            </select>
                        </label>
                    </div>
                    <fieldset v-if="roleIsScoped(manageForm.role)" class="ad-sites">
                        <legend>Sites this role covers</legend>
                        <label v-for="client in clients" :key="client.id" class="ad-site">
                            <input v-model="manageForm.sites" type="checkbox" :value="client.id" />
                            {{ client.name }}
                        </label>
                    </fieldset>
                    <div class="ad-actions">
                        <button type="submit" class="btn-primary-sm" :disabled="manageForm.processing"><span>Save</span></button>
                    </div>
                </form>
            </div>
        </div>

        <div class="ad-panel">
            <div class="ad-section">Invitations not yet accepted</div>
            <p v-if="invitations.length === 0" class="ad-note">No invitation is open.</p>
            <div v-for="invitation in invitations" :key="invitation.id" class="ad-row">
                <div>
                    <div class="ad-name">
                        {{ invitation.email }}
                        <span v-if="invitation.expired" class="ad-pill off">Lapsed</span>
                    </div>
                    <div class="ad-sub">
                        {{ invitation.role }} · sent by {{ invitation.invited_by }} · {{ invitation.expired ? 'lapsed' : 'open until' }}
                        {{ invitation.expires }}
                    </div>
                </div>
                <div class="ad-actions">
                    <button type="button" class="text-link-sm" :disabled="!canInvite" :title="canInvite ? 'Send a fresh link; the old one stops working.' : writeDisabledReason" @click="resend(invitation)">
                        Resend
                    </button>
                    <button type="button" class="text-link-sm" :disabled="!canInvite" :title="canInvite ? 'Withdraw the invitation.' : writeDisabledReason" @click="revoke(invitation)">
                        Withdraw
                    </button>
                </div>
            </div>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/*
 * AUTHORED. No board draws this tab's body. Kept to the tokens the Gemini
 * boards define; the buttons are the sheet's own classes.
 */
button {
    font-family: inherit;
    cursor: pointer;
}

button.btn-primary-sm {
    border: 0;
}

button.text-link-sm {
    border: 0;
    background: none;
    padding: 0;
}

button[disabled] {
    cursor: not-allowed;
}

.ad-panel {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 16px 18px;
    margin-bottom: 14px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.ad-head,
.ad-section {
    font-size: 12px;
    font-weight: 700;
    color: var(--navy-900);
    line-height: 1.5;
}

.ad-fields {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 320px));
    gap: 10px;
}

.ad-fields label {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
}

.ad-fields input,
.ad-fields select {
    height: 33px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12px;
    color: var(--navy-900);
}

.ad-row-wrap + .ad-row-wrap {
    border-top: 1px solid var(--navy-100);
}

.ad-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 8px 0;
}

.ad-name {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-900);
}

.ad-sub,
.ad-note {
    font-size: 11px;
    color: var(--slate-600);
    line-height: 1.55;
    margin: 0;
}

.ad-pill {
    font-size: 10px;
    font-weight: 700;
    border-radius: 20px;
    padding: 2px 8px;
    margin-left: 6px;
}

.ad-pill.off {
    background: var(--red-100);
    color: var(--red-700);
}

.ad-edit {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 4px 0 12px;
}

.ad-sites {
    border: 1px solid var(--navy-100);
    border-radius: 10px;
    padding: 8px 12px;
    display: flex;
    flex-wrap: wrap;
    gap: 6px 16px;
    font-size: 12px;
    color: var(--navy-900);
}

.ad-sites legend {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
}

.ad-actions {
    display: flex;
    align-items: center;
    gap: 14px;
}

.ad-flash,
.ad-error {
    font-size: 11.5px;
    font-weight: 600;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 12px;
}

.ad-flash {
    background: var(--success-100);
    color: var(--success-700);
}

.ad-error {
    background: var(--red-100);
    color: var(--red-700);
}
</style>
