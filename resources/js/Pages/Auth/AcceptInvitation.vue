<script setup>
import { onMounted, ref } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import BrandMark from '../../Components/BrandMark.vue'
import { useWireframe } from '../../composables/useWireframe'

/**
 * Accept an invitation — the one way an account comes into existence.
 *
 * DRAWN ON THE DOOR THE LINK LANDED ON, from that door's own sheet: the person
 * arriving here has never seen this platform, and the card that greets them
 * names the community they are joining, who asked, and what role. A link that
 * opens nothing draws the same card with the reason and no form — there is no
 * account to create.
 *
 * NAME AND PASSWORD, NOTHING ELSE. The address is the invitation's and is shown
 * read-only; the role is the inviter's decision and is shown as a fact. The
 * password is chosen here, once, on a link only this address received — so the
 * account is active the moment it exists and the person is signed in.
 */
const props = defineProps({
    door: { type: Object, required: true },
    token: { type: String, required: true },
    /** Null when the token opens nothing; `refusal` says why. */
    invitation: { type: Object, default: null },
    refusal: { type: String, default: null },
})

useWireframe(
    props.door.key === 'estate'
        ? 'community-admin-01-login-dashboard-structure-and-residents'
        : 'super-admin-01-login-dashboard-and-activity'
)

const form = useForm({
    name: '',
    password: '',
    password_confirmation: '',
})

const focusedField = ref(null)
const nameField = ref(null)

onMounted(() => nameField.value?.focus())

const submit = () => {
    form.post(`${props.door.login_href.replace(/\/login$/, '')}/invitations/${props.token}`, {
        onFinish: () => form.reset('password', 'password_confirmation'),
    })
}
</script>

<template>
    <Head title="Accept invitation" />

    <div class="login-screen" style="height: 900px">
        <div class="login-card">
            <div class="login-mark">
                <BrandMark :colourway="door.colourway" />
                <div class="lm1">GeminiSecure</div>
                <div class="lm2">{{ door.mark }}</div>
            </div>

            <!-- A link that opens nothing: the reason, and the way to sign in. -->
            <template v-if="refusal">
                <div class="crit-banner">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M12 9v4M12 17h.01" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" />
                        <path
                            d="M10.3 3.9L2.5 18a1.8 1.8 0 0 0 1.6 2.7h15.8a1.8 1.8 0 0 0 1.6-2.7L13.7 3.9a1.8 1.8 0 0 0-3.4 0z"
                            stroke="currentColor"
                            stroke-width="1.7"
                        />
                    </svg>
                    <div>
                        <div class="cb1">{{ refusal }}</div>
                        <div class="cb2">Accounts are issued by invitation. Nothing was created.</div>
                    </div>
                </div>

                <div class="l-row">
                    <Link :href="door.login_href" class="l-forgot">Go to sign in</Link>
                </div>
            </template>

            <form v-else @submit.prevent="submit">
                <div v-if="form.errors.name" class="crit-banner">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M12 9v4M12 17h.01" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" />
                        <path
                            d="M10.3 3.9L2.5 18a1.8 1.8 0 0 0 1.6 2.7h15.8a1.8 1.8 0 0 0 1.6-2.7L13.7 3.9a1.8 1.8 0 0 0-3.4 0z"
                            stroke="currentColor"
                            stroke-width="1.7"
                        />
                    </svg>
                    <div>
                        <div class="cb1">{{ form.errors.name }}</div>
                    </div>
                </div>

                <!-- Who asked, for what, and how long the link stands — the door's own note style. -->
                <div class="mfa-note">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="1.6" />
                        <circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="1.6" />
                        <path d="M19 8v6M22 11h-6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                    </svg>
                    <p>
                        <strong>{{ invitation.inviter }}</strong> has invited you to
                        <strong>{{ invitation.where }}</strong> as <strong>{{ invitation.role }}</strong>. This link
                        stands until {{ invitation.expires_on }}.
                    </p>
                </div>

                <div class="field">
                    <label for="invite-email">{{ door.email_label }}</label>
                    <div class="l-input">
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="2" y="4" width="20" height="16" rx="2" stroke="currentColor" stroke-width="1.6" />
                            <path d="M2 6l10 7 10-7" stroke="currentColor" stroke-width="1.6" />
                        </svg>
                        <input
                            id="invite-email"
                            :value="invitation.email"
                            type="email"
                            readonly
                            title="The address this invitation was sent to. It becomes the address you sign in with."
                        />
                    </div>
                </div>

                <div class="field">
                    <label for="invite-name">Your name, as the committee will see it</label>
                    <div class="l-input" :class="{ focused: focusedField === 'name' }">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="1.6" />
                            <circle cx="12" cy="7" r="4" stroke="currentColor" stroke-width="1.6" />
                        </svg>
                        <input
                            id="invite-name"
                            ref="nameField"
                            v-model="form.name"
                            type="text"
                            autocomplete="name"
                            required
                            maxlength="120"
                            @focus="focusedField = 'name'"
                            @blur="focusedField = null"
                        />
                    </div>
                </div>

                <div class="field">
                    <label for="invite-password">Choose a password — at least ten characters</label>
                    <div class="l-input" :class="{ focused: focusedField === 'password' }">
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="3" y="11" width="18" height="10" rx="2" stroke="currentColor" stroke-width="1.6" />
                            <path d="M7 11V7a5 5 0 0 1 10 0v4" stroke="currentColor" stroke-width="1.6" />
                        </svg>
                        <input
                            id="invite-password"
                            v-model="form.password"
                            type="password"
                            autocomplete="new-password"
                            required
                            minlength="10"
                            @focus="focusedField = 'password'"
                            @blur="focusedField = null"
                        />
                    </div>
                    <div v-if="form.errors.password" class="ai-error">{{ form.errors.password }}</div>
                </div>

                <div class="field">
                    <label for="invite-confirm">Again, to be sure</label>
                    <div class="l-input" :class="{ focused: focusedField === 'confirm' }">
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="3" y="11" width="18" height="10" rx="2" stroke="currentColor" stroke-width="1.6" />
                            <path d="M7 11V7a5 5 0 0 1 10 0v4" stroke="currentColor" stroke-width="1.6" />
                        </svg>
                        <input
                            id="invite-confirm"
                            v-model="form.password_confirmation"
                            type="password"
                            autocomplete="new-password"
                            required
                            minlength="10"
                            @focus="focusedField = 'confirm'"
                            @blur="focusedField = null"
                        />
                    </div>
                </div>

                <button type="submit" class="l-btn" :disabled="form.processing">
                    {{ form.processing ? 'Creating your account…' : 'Accept and sign in' }}
                </button>
            </form>

            <div class="l-foot">{{ door.foot }}</div>
        </div>
    </div>
</template>

<style scoped>
/* The same default-removal the sign-in card makes, for the same reasons. */
.l-input input {
    flex: 1;
    min-width: 0;
    border: 0;
    outline: 0;
    background: none;
    font-family: 'Inter', sans-serif;
    font-size: 13.5px;
    font-weight: 500;
    color: var(--navy-900);
}

.l-input input[readonly] {
    color: var(--slate-500);
}

.l-btn {
    width: 100%;
    border: 0;
    appearance: none;
}

a.l-forgot {
    text-decoration: none;
    font-family: 'Inter', sans-serif;
    font-size: 11.5px;
    font-weight: 700;
    color: var(--amber-600);
}

/* Authored: the field-level error under the password. */
.ai-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--red-700);
    line-height: 1.5;
    margin-top: 6px;
}
</style>
