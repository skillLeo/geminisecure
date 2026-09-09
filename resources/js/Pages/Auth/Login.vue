<script setup>
import { computed } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import BrandMark from '../../Components/BrandMark.vue'

const props = defineProps({
    // Empty outside local. The server does not even query roles there.
    quickLoginRoles: { type: Array, default: () => [] },
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
    remember: false,
})

const submit = () => {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    })
}
</script>

<template>
    <Head title="Sign in" />

    <div class="login-screen">
        <div class="login-card">
            <div class="login-brand">
                <BrandMark colourway="login" />
                <div>
                    <div class="lb-title">GeminiSecure</div>
                    <div class="lb-sub">GEMINI CONSOLE</div>
                </div>
            </div>

            <h1>Sign in</h1>
            <p class="login-lede">
                Accounts are issued by invitation. If you do not have one, ask your
                administrator rather than looking for a sign-up link &mdash; there isn't one.
            </p>

            <form @submit.prevent="submit">
                <label class="field">
                    <span class="field-label">Work email</span>
                    <input v-model="form.email" type="email" autocomplete="username" required />
                </label>

                <label class="field">
                    <span class="field-label">Password</span>
                    <input v-model="form.password" type="password" autocomplete="current-password" required />
                </label>

                <!--
                  One message for both a wrong password and an unknown address:
                  distinguishing them turns this form into an oracle for which
                  addresses hold accounts.
                -->
                <div v-if="form.errors.email" class="field-error">{{ form.errors.email }}</div>

                <label class="remember">
                    <input v-model="form.remember" type="checkbox" />
                    <span>Keep me signed in on this device</span>
                </label>

                <button type="submit" class="btn-primary-sm login-submit" :disabled="form.processing">
                    <span>{{ form.processing ? 'Signing in…' : 'Sign in' }}</span>
                </button>
            </form>
        </div>

        <!--
          Quick login. Rendered only when the server sends roles, which it does
          only in local. In any other environment the array is empty and this
          whole block is absent from the DOM rather than merely hidden.
        -->
        <div v-if="quickLoginRoles.length > 0" class="quick-login">
            <div class="ql-head">
                <span class="ql-title">Quick sign-in</span>
                <span class="ql-warn">Local only</span>
            </div>
            <p class="ql-lede">
                One click signs you in as that role, to compare what each one sees. These
                accounts exist only on this machine and cannot be reached from the form above.
            </p>

            <div v-for="group in grouped" :key="group.console" class="ql-group">
                <div class="ql-group-label">{{ group.label }}</div>
                <div class="ql-buttons">
                    <a v-for="role in group.roles" :key="role.name" :href="`/dev/login/${role.name}`" class="ql-btn">
                        <span class="ql-btn-label">{{ role.label }}</span>
                        <span v-if="role.scope !== 'All sites'" class="ql-btn-scope">{{ role.scope }}</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Gradient stops copied from the wireframe login screen. They are deliberately
   NOT --purple-800/--purple-900, which are visibly different values. */
.login-screen {
    min-height: 100vh;
    background: linear-gradient(150deg, #2d1b69, #0b0a1f);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 22px;
    padding: 24px;
    flex-wrap: wrap;
}

.login-card {
    width: 100%;
    max-width: 420px;
    background: var(--white);
    border-radius: 20px;
    padding: 32px;
}

.login-brand {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 24px;
}

.login-brand :deep(.mark) {
    width: 34px;
    height: 34px;
    display: block;
}

.lb-title {
    font-family: 'Poppins', sans-serif;
    font-size: 15px;
    font-weight: 600;
    color: var(--navy-900);
}

.lb-sub {
    font-size: 9.5px;
    font-weight: 700;
    letter-spacing: 0.4px;
    color: var(--navy-600);
}

h1 {
    font-family: 'Poppins', sans-serif;
    font-size: 22px;
    font-weight: 600;
    color: var(--navy-900);
    margin-bottom: 8px;
}

.login-lede {
    font-size: 12.5px;
    color: var(--slate-500);
    line-height: 1.6;
    margin-bottom: 22px;
}

.field {
    display: block;
    margin-bottom: 14px;
}

.field-label {
    display: block;
    font-size: 11.5px;
    font-weight: 700;
    color: var(--navy-700);
    margin-bottom: 6px;
}

.field input {
    width: 100%;
    height: 42px;
    border: 1.5px solid var(--navy-200);
    border-radius: 12px;
    padding: 0 13px;
    font-family: 'Inter', sans-serif;
    font-size: 13px;
    color: var(--navy-900);
    outline: none;
}

.field input:focus {
    border-color: var(--navy-600);
    box-shadow: 0 0 0 3px rgba(25, 116, 210, 0.15);
}

.field-error {
    font-size: 12px;
    font-weight: 600;
    color: var(--red-700);
    background: var(--red-100);
    padding: 9px 12px;
    border-radius: 10px;
    margin-bottom: 14px;
}

.remember {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 18px;
    font-size: 12px;
    color: var(--slate-600);
}

.login-submit {
    width: 100%;
    justify-content: center;
}

.login-submit:disabled {
    opacity: 0.6;
    cursor: default;
}

/* --- Quick sign-in · local only ------------------------------------- */

.quick-login {
    width: 100%;
    max-width: 360px;
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid rgba(255, 255, 255, 0.14);
    border-radius: 20px;
    padding: 22px;
    align-self: stretch;
    max-height: 640px;
    overflow-y: auto;
}

.ql-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 8px;
}

.ql-title {
    font-family: 'Poppins', sans-serif;
    font-size: 14px;
    font-weight: 600;
    color: var(--white);
}

/*
 * Amber, not red. This is a caveat about where the feature works, not a
 * fault - and red is reserved for denial states across the whole system.
 */
.ql-warn {
    font-size: 9.5px;
    font-weight: 700;
    letter-spacing: 0.3px;
    text-transform: uppercase;
    color: var(--amber-700);
    background: var(--amber-100);
    border-radius: 100px;
    padding: 3px 9px;
}

.ql-lede {
    font-size: 11.5px;
    line-height: 1.55;
    color: rgba(255, 255, 255, 0.6);
    margin-bottom: 16px;
}

.ql-group + .ql-group {
    margin-top: 16px;
}

.ql-group-label {
    font-size: 9.5px;
    font-weight: 700;
    letter-spacing: 0.6px;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.4);
    margin-bottom: 8px;
}

.ql-buttons {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.ql-btn {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 9px 12px;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.08);
    text-decoration: none;
    transition: background 0.12s ease;
}

.ql-btn:hover {
    background: rgba(255, 182, 39, 0.16);
}

.ql-btn-label {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--white);
}

/* Only shown when the scope actually narrows something. */
.ql-btn-scope {
    font-size: 9.5px;
    font-weight: 700;
    color: var(--amber-500);
    white-space: nowrap;
}
</style>
