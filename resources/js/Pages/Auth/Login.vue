<script setup>
import { computed, onMounted, ref } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import BrandMark from '../../Components/BrandMark.vue'
import { useWireframe } from '../../composables/useWireframe'

/**
 * Sign in — board screen super-admin-01.
 *
 * This page wears the board class itself rather than going through
 * GeminiConsole: the sign-in screen has no sidebar and no topbar, so it is not
 * a console page, it is the door to one.
 *
 * The card's DOM, classes and label text are the board's. Three things the
 * board draws as static decoration are real controls here — the two fields and
 * the submit button — and the only CSS in this file takes the browser's
 * default styling for those controls back off so the board's own .l-input and
 * .l-btn rules are what is seen.
 *
 * The board draws no "create account" link, which matches the platform: there
 * is no registration route anywhere in this application and there must never
 * be one. Accounts are issued by invitation.
 */
useWireframe('super-admin-01-login-dashboard-and-activity')

const props = defineProps({
    // Empty outside local. The server does not even query roles there.
    quickLoginRoles: { type: Array, default: () => [] },
    // A message from the quick-login bypass, e.g. a role with no estate.
    quickLoginNotice: { type: String, default: null },
})

const CONSOLE_LABELS = {
    gemini: 'Gemini Console',
    estate: 'Estate Console',
}

const grouped = computed(() => {
    const groups = new Map()

    for (const role of props.quickLoginRoles) {
        if (!groups.has(role.console)) {
            groups.set(role.console, [])
        }
        groups.get(role.console).push(role)
    }

    return [...groups].map(([console, roles]) => ({
        console,
        label: CONSOLE_LABELS[console] ?? console,
        roles,
    }))
})

const form = useForm({
    email: '',
    password: '',
})

/*
 * The board draws the email field in its focused state. That state is tracked
 * from real focus events rather than hardcoded, so the ring follows the
 * caret — and the field is focused on mount, which is both what the board
 * shows and where anyone arriving here wants to start typing.
 */
const focusedField = ref(null)
const emailField = ref(null)

onMounted(() => emailField.value?.focus())

const submit = () => {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    })
}
</script>

<template>
    <Head title="Sign in" />

    <!-- height:900px is the board's own inline style on this element. -->
    <div class="login-screen" style="height: 900px">
        <div class="login-card">
            <div class="login-mark">
                <BrandMark colourway="login" />
                <div class="lm1">GeminiSecure</div>
                <div class="lm2">GEMINI CONSOLE &middot; INTERNAL</div>
            </div>

            <form @submit.prevent="submit">
                <!--
                  One message for both a wrong password and an unknown address.
                  Distinguishing them turns this form into an oracle for which
                  addresses hold accounts on the platform, so the second line
                  stays generic too.
                -->
                <div v-if="form.errors.email" class="crit-banner">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M12 9v4M12 17h.01" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" />
                        <path
                            d="M10.3 3.9L2.5 18a1.8 1.8 0 0 0 1.6 2.7h15.8a1.8 1.8 0 0 0 1.6-2.7L13.7 3.9a1.8 1.8 0 0 0-3.4 0z"
                            stroke="currentColor"
                            stroke-width="1.7"
                        />
                    </svg>
                    <div>
                        <div class="cb1">{{ form.errors.email }}</div>
                        <div class="cb2">
                            Accounts are issued by invitation. If you cannot get in, ask your administrator.
                        </div>
                    </div>
                </div>

                <div class="field">
                    <label for="login-email">Work email</label>
                    <div class="l-input" :class="{ focused: focusedField === 'email' }">
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="2" y="4" width="20" height="16" rx="2" stroke="currentColor" stroke-width="1.6" />
                            <path d="M2 6l10 7 10-7" stroke="currentColor" stroke-width="1.6" />
                        </svg>
                        <input
                            id="login-email"
                            ref="emailField"
                            v-model="form.email"
                            type="email"
                            autocomplete="username"
                            required
                            @focus="focusedField = 'email'"
                            @blur="focusedField = null"
                        />
                    </div>
                </div>

                <div class="field">
                    <label for="login-password">Password</label>
                    <div class="l-input" :class="{ focused: focusedField === 'password' }">
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="3" y="11" width="18" height="10" rx="2" stroke="currentColor" stroke-width="1.6" />
                            <path d="M7 11V7a5 5 0 0 1 10 0v4" stroke="currentColor" stroke-width="1.6" />
                        </svg>
                        <input
                            id="login-password"
                            v-model="form.password"
                            type="password"
                            autocomplete="current-password"
                            required
                            @focus="focusedField = 'password'"
                            @blur="focusedField = null"
                        />
                    </div>
                </div>

                <div class="mfa-note">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path
                            d="M12 2 2 7v6c0 5.2 3.8 9 10 11 6.2-2 10-5.8 10-11V7l-10-5z"
                            stroke="currentColor"
                            stroke-width="1.6"
                            stroke-linejoin="round"
                        />
                    </svg>
                    <p>
                        This console holds data for every client estate on the platform. Two-factor authentication is
                        required for every sign-in, no exceptions.
                    </p>
                </div>

                <button type="submit" class="l-btn" :disabled="form.processing">
                    {{ form.processing ? 'Signing in…' : 'Continue with 2FA' }}
                </button>
            </form>

            <div class="l-foot">Gemini Security Limited &middot; Internal use only</div>
        </div>
    </div>

    <!--
      Quick sign-in. Rendered only when the server sends roles, which it does
      only in local; in any other environment the array is empty and this block
      is absent from the DOM rather than merely hidden.

      It sits BELOW the 900px login screen, not beside it, so it is outside the
      1440x900 region the board is diffed against and cannot move a single
      pixel of the card. Scroll to reach it.
    -->
    <div v-if="quickLoginRoles.length > 0" class="content">
        <div v-if="quickLoginNotice" class="crit-banner">
            <svg viewBox="0 0 24 24" fill="none">
                <path d="M12 9v4M12 17h.01" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" />
                <path
                    d="M10.3 3.9L2.5 18a1.8 1.8 0 0 0 1.6 2.7h15.8a1.8 1.8 0 0 0 1.6-2.7L13.7 3.9a1.8 1.8 0 0 0-3.4 0z"
                    stroke="currentColor"
                    stroke-width="1.7"
                />
            </svg>
            <div>
                <div class="cb1">{{ quickLoginNotice }}</div>
                <div class="cb2">Nothing was signed in. Pick another role below.</div>
            </div>
        </div>

        <div class="warn-banner">
            <svg viewBox="0 0 24 24" fill="none">
                <path d="M12 9v4M12 17h.01" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" />
                <path
                    d="M10.3 3.9L2.5 18a1.8 1.8 0 0 0 1.6 2.7h15.8a1.8 1.8 0 0 0 1.6-2.7L13.7 3.9a1.8 1.8 0 0 0-3.4 0z"
                    stroke="currentColor"
                    stroke-width="1.7"
                />
            </svg>
            <div>
                <div class="wb1">Quick sign-in &middot; local development only</div>
                <div class="wb2">
                    One click signs you in as that role, to compare what each one sees. These accounts exist only on
                    this machine, hold an unusable random password, and cannot be reached from the form above.
                </div>
            </div>
        </div>

        <div v-for="group in grouped" :key="group.console" class="report-group">
            <div class="report-group-head">{{ group.label }}</div>
            <div class="report-grid">
                <a
                    v-for="role in group.roles"
                    :key="role.name"
                    class="report-card"
                    :href="`/dev/login/${role.name}`"
                >
                    <div class="rc-name">{{ role.label }}</div>
                    <div class="rc-desc">{{ role.scope }}</div>
                    <div class="rc-action">Sign in as this role</div>
                </a>
            </div>
        </div>
    </div>
</template>

<style scoped>
/*
 * The only authored CSS on this screen, and only where a static thing on the
 * board had to become a real control.
 *
 * The board draws both fields as a <div> holding a <span> of typed-looking
 * text, and the submit as a <div>. A real <input> and a real <button> bring
 * the browser's own border, background, font and intrinsic width with them,
 * which would show through and change the pixels. These rules take exactly
 * those defaults back off. They introduce no colour, spacing or size the board
 * does not already declare.
 */

/* The type values here are the board's own `.l-input span` rule — 13.5px /
 * 500 / --navy-900. An <input> does not inherit font from its ancestor, so
 * they have to be restated for the live field to land where the span did. */
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

/* A <button> sizes to its content even at display:flex, so width has to be
 * restored to the block width the board's <div> had. Everything else the
 * board's own .l-btn rule already sets. */
.l-btn {
    width: 100%;
    border: 0;
    appearance: none;
}

/* Local-only quick sign-in: the board draws these cards as <div>, and a link
 * underlines every line inside itself. Removing that one default. */
a.report-card {
    text-decoration: none;
}
</style>
