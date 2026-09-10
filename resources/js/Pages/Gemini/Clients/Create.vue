<script setup>
import { computed } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'

/**
 * Onboard new client — board screen super-admin-08.
 *
 * THIS DOES NOT PROVISION A DATABASE. `estate:provision` creates a MySQL
 * database and a dedicated user with its own grants; that is an operator act
 * with a command behind it, run deliberately and watched while it runs. A web
 * form doing it as a side effect would build infrastructure on a button press,
 * and a failure halfway would leave a half-built estate nobody knew to check.
 *
 * So this records the CLIENT — the tenant, its site, the subscription agreed
 * and the person who signed — and the success message says the command the
 * operator runs next. The board's own note says the same thing in its words.
 *
 * NOTHING BILLS. The subscription is written in `onboarding`; the act that
 * starts charging is completing onboarding, which has its own checklist on the
 * client record.
 *
 * The projection uses the same arithmetic the invoice does, so what an account
 * manager quotes at signing is what the client is later invoiced.
 */
const props = defineProps({
    plans: { type: Array, required: true },
    guard_rate_minor: { type: Number, required: true },
    currency: { type: String, required: true },
    onboarding_example: { type: String, default: null },
})

const form = useForm({
    name: '',
    address: '',
    units: '',
    phases: 1,
    plan_id: props.plans[1]?.id ?? props.plans[0]?.id ?? null,
    guards: 0,
    term_months: 24,
    contact_name: '',
    contact_email: '',
    contact_phone: '',
})

/*
 * There is no subdomain field, because the board draws none — and that is
 * right rather than an omission. A subdomain becomes a hostname and a database
 * name, and it is chosen when the operator runs `estate:provision`, which is
 * the step that actually creates them. The server derives a suggestion from
 * the name so the client record has an id, and the success message prints the
 * exact command with it in, so the operator sees and can change it before any
 * infrastructure exists.
 */
const selectedPlan = computed(() => props.plans.find((p) => p.id === form.plan_id) ?? null)

const money = (minor) =>
    new Intl.NumberFormat('en-JM', {
        style: 'currency',
        currency: props.currency,
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(minor / 100)

const units = computed(() => Number(form.units) || 0)
const guards = computed(() => Number(form.guards) || 0)
const subscriptionMinor = computed(() => units.value * (selectedPlan.value?.price_minor ?? 0))
const addOnMinor = computed(() => guards.value * props.guard_rate_minor)

const submit = () => form.post('/clients/new', { preserveScroll: true })
</script>

<template>
    <Head title="Onboard new client" />

    <GeminiConsole title="Onboard new client">
        <template #lead>
            <Link href="/clients" class="topbar-back" title="Back to clients" aria-label="Back to clients">
                <svg viewBox="0 0 24 24" fill="none">
                    <polyline
                        points="15 18 9 12 15 6"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                </svg>
            </Link>
        </template>

        <div class="form-layout">
            <form class="form-panel" @submit.prevent="submit">
                <div class="form-sec-head">Estate details</div>

                <div class="m-field">
                    <label for="name">Estate name</label>
                    <div class="m-input" :class="{ focused: form.name !== '' }">
                        <input id="name" v-model="form.name" type="text" required />
                    </div>
                    <div v-if="form.errors.name" class="field-error">{{ form.errors.name }}</div>
                </div>

                <!--
                  One field, as the board draws it: "Mandeville, Manchester".
                  The street line and the parish are stored separately because
                  clients are listed and reported by parish, so the server
                  splits on the last comma. Asking for two fields would be a
                  storage decision leaking onto a form.
                -->
                <div class="m-field">
                    <label for="address">Address</label>
                    <div class="m-input">
                        <input
                            id="address"
                            v-model="form.address"
                            type="text"
                            required
                            title="Street or district, then the parish — e.g. Mandeville, Manchester"
                        />
                    </div>
                    <div v-if="form.errors.address" class="field-error">{{ form.errors.address }}</div>
                </div>

                <div class="m-two-col">
                    <div class="m-field">
                        <label for="units">Total units</label>
                        <div class="m-input">
                            <input id="units" v-model="form.units" type="number" min="1" required />
                        </div>
                        <div v-if="form.errors.units" class="field-error">{{ form.errors.units }}</div>
                    </div>

                    <div class="m-field">
                        <label for="phases">Number of phases</label>
                        <div class="m-input">
                            <input id="phases" v-model="form.phases" type="number" min="1" required />
                        </div>
                        <div v-if="form.errors.phases" class="field-error">{{ form.errors.phases }}</div>
                    </div>
                </div>

                <div class="form-sec-head">Subscription</div>

                <div class="seg" role="radiogroup" aria-label="Subscription tier">
                    <button
                        v-for="plan in plans"
                        :key="plan.id"
                        type="button"
                        role="radio"
                        :aria-checked="form.plan_id === plan.id"
                        class="seg-item"
                        :class="{ active: form.plan_id === plan.id }"
                        @click="form.plan_id = plan.id"
                    >
                        {{ plan.name }}
                    </button>
                </div>
                <div v-if="form.errors.plan_id" class="field-error">{{ form.errors.plan_id }}</div>

                <div class="m-two-col">
                    <div class="m-field">
                        <label for="guards">Guards to assign</label>
                        <div class="m-input">
                            <input id="guards" v-model="form.guards" type="number" min="0" required />
                        </div>
                        <div v-if="form.errors.guards" class="field-error">{{ form.errors.guards }}</div>
                    </div>

                    <div class="m-field">
                        <label for="term_months">Contract term</label>
                        <div class="m-input">
                            <select id="term_months" v-model="form.term_months">
                                <option value="">Monthly rolling</option>
                                <option :value="12">12 months</option>
                                <option :value="24">24 months</option>
                                <option :value="36">36 months</option>
                            </select>
                        </div>
                        <div v-if="form.errors.term_months" class="field-error">{{ form.errors.term_months }}</div>
                    </div>
                </div>

                <div class="form-sec-head">Primary contact</div>

                <div class="m-field">
                    <label for="contact_name">Name &amp; role</label>
                    <div class="m-input">
                        <input
                            id="contact_name"
                            v-model="form.contact_name"
                            type="text"
                            placeholder="Full name — role (e.g. President)"
                            required
                        />
                    </div>
                    <div v-if="form.errors.contact_name" class="field-error">{{ form.errors.contact_name }}</div>
                </div>

                <div class="m-two-col">
                    <div class="m-field">
                        <label for="contact_email">Email</label>
                        <div class="m-input">
                            <input
                                id="contact_email"
                                v-model="form.contact_email"
                                type="email"
                                placeholder="contact@example.org"
                                required
                            />
                        </div>
                        <div v-if="form.errors.contact_email" class="field-error">{{ form.errors.contact_email }}</div>
                    </div>

                    <div class="m-field">
                        <label for="contact_phone">Phone</label>
                        <div class="m-input">
                            <input
                                id="contact_phone"
                                v-model="form.contact_phone"
                                type="tel"
                                placeholder="876 555 0000"
                                required
                            />
                        </div>
                        <div v-if="form.errors.contact_phone" class="field-error">{{ form.errors.contact_phone }}</div>
                    </div>
                </div>

                <button type="submit" class="stack-btn primary" style="width: 100%" :disabled="form.processing">
                    <BoardIcon name="plus" :stroke="2" />
                    <span>{{ form.processing ? 'Recording…' : 'Start onboarding' }}</span>
                </button>
            </form>

            <div class="preview-panel">
                <div class="preview-head">Projected MRR</div>

                <div style="background: var(--navy-100); border-radius: 12px; padding: 13px">
                    <div
                        style="
                            display: flex;
                            justify-content: space-between;
                            font-size: 12px;
                            color: var(--navy-900);
                            padding: 5px 0;
                        "
                    >
                        <span>{{ selectedPlan?.name }} — {{ units }} units × {{ money(selectedPlan?.price_minor ?? 0) }}</span>
                        <b>{{ money(subscriptionMinor) }}</b>
                    </div>
                    <div
                        style="
                            display: flex;
                            justify-content: space-between;
                            font-size: 12px;
                            color: var(--navy-900);
                            padding: 5px 0;
                        "
                    >
                        <span>Security add-on — {{ guards }} × {{ money(guard_rate_minor) }}</span>
                        <b>{{ money(addOnMinor) }}</b>
                    </div>
                    <div
                        style="
                            display: flex;
                            justify-content: space-between;
                            font-size: 13px;
                            font-weight: 700;
                            color: var(--navy-900);
                            padding: 9px 0 0;
                            border-top: 1px solid var(--navy-600);
                            margin-top: 6px;
                        "
                    >
                        <span>Total once active</span>
                        <b>{{ money(subscriptionMinor + addOnMinor) }}</b>
                    </div>
                </div>

                <div class="preview-note">
                    This creates the client record and generates its own Estate Console. Billing won't start until
                    onboarding is marked complete<template v-if="onboarding_example"> — same as
                    {{ onboarding_example }} is now</template>.
                </div>
            </div>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws the tier segments, every field and the
 * submit as <div>s; here they are radio buttons, inputs, a select and a button,
 * each bringing the browser's own chrome. The board's .seg-item, .m-input and
 * .stack-btn rules supply everything visual.
 */
button.seg-item {
    border: 0;
    background: transparent;
    font: inherit;
    color: inherit;
    cursor: pointer;
}

.m-input input,
.m-input select {
    flex: 1;
    min-width: 0;
    width: 100%;
    border: 0;
    outline: 0;
    background: transparent;
    font: inherit;
    color: inherit;
    padding: 0;
    appearance: none;
    -webkit-appearance: none;
}

.m-input input::placeholder {
    color: inherit;
    opacity: 1;
}

.m-input input[type='number']::-webkit-outer-spin-button,
.m-input input[type='number']::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

button.stack-btn {
    border: 0;
    font: inherit;
    cursor: pointer;
}

/* Authored: the board draws one filled-in happy path and has no error state to
 * copy. Kept to the tokens the boards do define, and placed under its own
 * field rather than in a summary. */
.field-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--red-700);
    margin-top: 5px;
    line-height: 1.45;
}

.topbar-back {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: var(--navy-100);
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
}

.topbar-back svg {
    width: 16px;
    height: 16px;
    color: var(--navy-700);
}
</style>
