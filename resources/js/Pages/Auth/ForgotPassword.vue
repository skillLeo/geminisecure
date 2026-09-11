<script setup>
import { computed, onMounted, ref } from 'vue'
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import BrandMark from '../../Components/BrandMark.vue'
import { useWireframe } from '../../composables/useWireframe'

/**
 * Forgot password — both doors. Ruled (12 §2, Wave 1).
 *
 * THE SAME CARD AS SIGN-IN, on whichever door it was reached from, drawn from
 * that door's own sheet: no board draws this screen, and a reset request that
 * looked like a different product would read as a phishing page to exactly
 * the person it is for.
 *
 * ONE SENTENCE WHATEVER HAPPENED. The server answers "if that address holds an
 * account, a link has been sent" to every request — known address, unknown
 * address, suspended account, throttled retry — and this page prints exactly
 * that. It never learns which case it was, so it cannot leak it.
 */
const props = defineProps({
    door: { type: Object, required: true },
})

useWireframe(
    props.door.key === 'estate'
        ? 'community-admin-01-login-dashboard-structure-and-residents'
        : 'super-admin-01-login-dashboard-and-activity'
)

const page = usePage()

const sent = computed(() => page.props.flash?.status ?? null)

const form = useForm({ email: '' })

const focusedField = ref(null)
const emailField = ref(null)

onMounted(() => emailField.value?.focus())

const submit = () => {
    form.post(props.door.forgot_href, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    })
}
</script>

<template>
    <Head title="Forgot password" />

    <div class="login-screen" style="height: 900px">
        <div class="login-card">
            <div class="login-mark">
                <BrandMark :colourway="door.colourway" />
                <div class="lm1">GeminiSecure</div>
                <div class="lm2">{{ door.mark }}</div>
            </div>

            <form @submit.prevent="submit">
                <!--
                  The confirmation, in the door's own note style. The same words
                  for every address, because the server sends the same words.
                -->
                <div v-if="sent" class="mfa-note">
                    <svg viewBox="0 0 24 24" fill="none">
                        <polyline
                            points="20 6 9 17 4 12"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                    <p>{{ sent }}</p>
                </div>

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
                        <div class="cb2">Enter the address your account was invited on.</div>
                    </div>
                </div>

                <p class="fp-lead">
                    Enter the address on your account. If it holds one, a link to choose a new password is sent to it —
                    it works for thirty minutes and once.
                </p>

                <div class="field">
                    <label for="forgot-email">{{ door.email_label }}</label>
                    <div class="l-input" :class="{ focused: focusedField === 'email' }">
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="2" y="4" width="20" height="16" rx="2" stroke="currentColor" stroke-width="1.6" />
                            <path d="M2 6l10 7 10-7" stroke="currentColor" stroke-width="1.6" />
                        </svg>
                        <input
                            id="forgot-email"
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

                <div class="l-row">
                    <Link :href="door.login_href" class="l-forgot">Back to sign in</Link>
                </div>

                <button type="submit" class="l-btn" :disabled="form.processing">
                    {{ form.processing ? 'Sending…' : 'Send reset link' }}
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

/* Authored: the one sentence this card carries that the sign-in card does not. */
.fp-lead {
    font-size: 12px;
    color: var(--slate-600);
    line-height: 1.6;
    margin: 0 0 16px;
}
</style>
