<script setup>
import { computed } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'

/**
 * Add guard — board screen super-admin-22.
 *
 * A REAL FORM. Every field is a live input, the submit posts, and the
 * validation that matters is the licence expiry: an expired PSRA licence makes
 * a guard un-rosterable from the moment they exist, so the service refuses it
 * rather than creating an employee into a state they can never work from. The
 * field-level error says so in words, not as "invalid date".
 *
 * THE PANEL BESIDE THE FORM IS COMPUTED, NOT COPY.
 *
 * The board writes "This adds a 3rd guard to Emerald Heights, taking their
 * Security Provider add-on from $9,000/mo to $13,500/mo starting next billing
 * cycle." That is a real consequence of the selection, and it comes from the
 * chosen client's own guard count and the platform's per-guard rate — so it
 * changes as the picker changes. Hardcoding it would be a promise about money
 * that stops being true the moment anyone selects a different estate.
 *
 * Where no client is selected the panel says only what is certain: the invite
 * goes out and onboarding starts. It never asserts a billing change that
 * depends on a client nobody has picked.
 */
const props = defineProps({
    clients: { type: Array, required: true },
    employment_types: { type: Array, required: true },
})

const form = useForm({
    full_name: '',
    psra_number: '',
    psra_expires_on: '',
    phone: '',
    employment_type: props.employment_types[0]?.value ?? '',
    tenant_id: '',
    post_id: '',
    standard_rate: '',
})

const selectedClient = computed(() => props.clients.find((c) => c.id === form.tenant_id) ?? null)

/*
 * Posts belong to a client. Offering every post on the platform would let
 * someone assign a guard to a gate at an estate they did not select, which the
 * service would then refuse — a refusal the form should never have invited.
 */
const posts = computed(() => selectedClient.value?.posts ?? [])

/* Changing client invalidates the post beneath it. */
const onClientChange = () => {
    form.post_id = ''
}

const firstName = computed(() => form.full_name.trim().split(' ')[0] || 'They')

const submit = () => form.post('/guards/new', { preserveScroll: true })
</script>

<template>
    <Head title="Add guard" />

    <GeminiConsole title="Add guard">
        <template #lead>
            <Link href="/guards" class="topbar-back" title="Back to guard workforce" aria-label="Back to guard workforce">
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
                <div class="form-sec-head">Guard details</div>

                <div class="m-field">
                    <label for="full_name">Full name</label>
                    <div class="m-input" :class="{ focused: form.full_name !== '' }">
                        <input id="full_name" v-model="form.full_name" type="text" autocomplete="name" required />
                    </div>
                    <div v-if="form.errors.full_name" class="field-error">{{ form.errors.full_name }}</div>
                </div>

                <div class="m-two-col">
                    <div class="m-field">
                        <label for="psra_number">PSRA number</label>
                        <div class="m-input">
                            <input id="psra_number" v-model="form.psra_number" type="text" required />
                        </div>
                        <div v-if="form.errors.psra_number" class="field-error">{{ form.errors.psra_number }}</div>
                    </div>

                    <div class="m-field">
                        <label for="psra_expires_on">Licence expiry</label>
                        <div class="m-input">
                            <input id="psra_expires_on" v-model="form.psra_expires_on" type="date" required />
                        </div>
                        <div v-if="form.errors.psra_expires_on" class="field-error">
                            {{ form.errors.psra_expires_on }}
                        </div>
                    </div>
                </div>

                <div class="m-two-col">
                    <div class="m-field">
                        <label for="phone">Phone</label>
                        <div class="m-input">
                            <input id="phone" v-model="form.phone" type="tel" autocomplete="tel" required />
                        </div>
                        <div v-if="form.errors.phone" class="field-error">{{ form.errors.phone }}</div>
                    </div>

                    <div class="m-field">
                        <label for="employment_type">Employment type</label>
                        <div class="m-input">
                            <select id="employment_type" v-model="form.employment_type" required>
                                <option v-for="type in employment_types" :key="type.value" :value="type.value">
                                    {{ type.label }}
                                </option>
                            </select>
                        </div>
                        <div v-if="form.errors.employment_type" class="field-error">
                            {{ form.errors.employment_type }}
                        </div>
                    </div>
                </div>

                <div class="form-sec-head">Assignment</div>

                <div class="m-two-col">
                    <div class="m-field">
                        <label for="tenant_id">Client</label>
                        <div class="m-input">
                            <select id="tenant_id" v-model="form.tenant_id" @change="onClientChange">
                                <!-- A guard hired between postings is a real
                                     state the roster already draws, so no
                                     client is a valid answer. -->
                                <option value="">Not posted yet</option>
                                <option v-for="client in clients" :key="client.id" :value="client.id">
                                    {{ client.name }}
                                </option>
                            </select>
                        </div>
                        <div v-if="form.errors.tenant_id" class="field-error">{{ form.errors.tenant_id }}</div>
                    </div>

                    <div class="m-field">
                        <label for="post_id">Post</label>
                        <div class="m-input">
                            <select
                                id="post_id"
                                v-model="form.post_id"
                                :disabled="posts.length === 0"
                                :title="
                                    form.tenant_id === ''
                                        ? 'Choose a client first — posts belong to one estate'
                                        : posts.length === 0
                                          ? 'This client has no active posts to assign to'
                                          : undefined
                                "
                            >
                                <option value="">No post yet</option>
                                <option v-for="post in posts" :key="post.id" :value="post.id">{{ post.name }}</option>
                            </select>
                        </div>
                        <div v-if="form.errors.post_id" class="field-error">{{ form.errors.post_id }}</div>
                    </div>
                </div>

                <div class="m-field">
                    <label for="standard_rate">Standard rate</label>
                    <div class="m-input">
                        <input
                            id="standard_rate"
                            v-model="form.standard_rate"
                            type="text"
                            inputmode="decimal"
                            required
                        />
                    </div>
                    <div v-if="form.errors.standard_rate" class="field-error">{{ form.errors.standard_rate }}</div>
                </div>

                <button type="submit" class="stack-btn primary" style="width: 100%" :disabled="form.processing">
                    <BoardIcon name="plus" :stroke="2" />
                    <span>{{ form.processing ? 'Adding guard…' : 'Add guard' }}</span>
                </button>
            </form>

            <div class="preview-panel">
                <div class="preview-head">What happens next</div>
                <div class="preview-note">
                    {{ firstName }} will receive a Guard App invite by SMS to complete onboarding — device enrolment,
                    biometric setup, and standing orders acknowledgement.<template
                        v-if="selectedClient && selectedClient.addon_now && selectedClient.addon_next"
                    >
                        This adds a {{ selectedClient.next_ordinal }} guard to {{ selectedClient.name }}, taking their
                        Security Provider add-on from {{ selectedClient.addon_now }} to
                        {{ selectedClient.addon_next }} starting next billing cycle.</template
                    >
                </div>
            </div>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/*
 * Default-removal only.
 *
 * The board draws every field as a <div class="m-input"> wrapping a <span> of
 * typed text, and the submit as a <div>. Here they are real inputs, selects
 * and a button, each arriving with the browser's own chrome — a border, a
 * background, Arial, an inset shadow on the select, a spinner on the date
 * field. These rules take those defaults off so the board's .m-input and
 * .stack-btn rules are what is seen. Nothing adds a colour, size or spacing
 * the board does not declare.
 */
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

/* Chrome paints its own calendar glyph inside a date input. The board draws
 * none, and it would sit outside the field's own padding. */
.m-input input[type='date']::-webkit-calendar-picker-indicator {
    display: none;
}

.m-input select[disabled] {
    cursor: not-allowed;
}

button.stack-btn {
    border: 0;
    font: inherit;
    cursor: pointer;
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

/*
 * Field-level errors. The board has no error state to copy — it draws one
 * filled-in happy path — so this is authored, and kept to the tokens the
 * boards do define. It sits under the field it belongs to rather than in a
 * summary at the top, because a message four fields away from its cause is a
 * message the reader has to hunt for.
 */
.field-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--red-700);
    margin-top: 5px;
    line-height: 1.45;
}
</style>
