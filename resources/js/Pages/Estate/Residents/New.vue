<script setup>
import { computed } from 'vue'
import { Head, Link, usePage, useForm } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * Add resident — board screen community-admin-34.
 *
 * THE UNIT IS NOT CREATED HERE. `Residents::add()` looks up the phase and lot
 * against the estate's own units and refuses when neither exists — a lot typed
 * in error would otherwise become a house — so the form posts the phase and the
 * lot as typed and lets the server say so if they do not resolve; nothing here
 * pre-validates that pairing against a units list it was not given.
 *
 * THE BIOMETRIC CONSENT CHECKBOX IS AUTHORED, NOT ON THE BOARD (D-022). It
 * ships off — `biometric_consent.default` is false — and nothing turns it on
 * for a person but that person: `Residents::enrolBiometrics()` refuses without
 * it, and no estate setting grants it on anybody's behalf. The board draws no
 * control for it at all, so this one is placed where it changes the fewest
 * pixels of the happy path: below the segmented control, before the submit
 * button the board already draws primary and full width.
 *
 * THE PREVIEW'S PRONOUN IS GONE ON PURPOSE. The board's own copy is gendered —
 * "she", "her" — because it was drawn against one named person (Simone
 * Barrett); a template filling in whoever was just typed cannot know a
 * pronoun, so `{first}` and `{lot}` are the only two blanks `newResidentBoard()`
 * leaves, and this page fills neither with an invented "they". D-056 records
 * the residual.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    phases: { type: Array, required: true },
    verification: { type: Array, required: true },
    default_verification: { type: String, required: true },
    biometric_consent: { type: Object, required: true },
    preview: { type: Object, required: true },
    canCreate: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
})

/*
 * Board 34 lives in the ninth Community Admin sheet — "Data Privacy, Add
 * Resident and Meetings" — the same one board 35's New Charge wears, which is
 * where its .form-layout, .m-field and .seg classes come from.
 */
useWireframe('community-admin-09-data-privacy-add-resident-and-meetings')

const page = usePage()

/*
 * Where this console is rooted, read off the page's own URL.
 *
 * Production gives each estate its own hostname and no prefix; local serves
 * every estate from one host with the estate key in the path, as
 * /estate/{key}/residents/new. Cutting the current URL at /residents is
 * correct in both.
 */
const root = computed(() => page.url.slice(0, page.url.indexOf('/residents')))
const residentsPath = computed(() => `${root.value}/residents`)

const form = useForm({
    phase: props.phases[0]?.value ?? '',
    lot: '',
    full_name: '',
    email: '',
    phone: '',
    verification: props.default_verification,
    biometric_consent: props.biometric_consent.default,
})

/**
 * "Lot 112" from whatever is typed, mirroring `Residents::lotReference()`.
 *
 * Cosmetic only — the field posts what was typed and the server is what
 * actually normalises it. Reproduced here so the preview reads the same
 * address the register will file the resident under, without the two ever
 * having to agree by coincidence.
 */
const lotLabel = (raw) => {
    const trimmed = raw.trim()

    if (trimmed === '') {
        return ''
    }

    return /^lot\s/i.test(trimmed) ? `Lot ${trimmed.slice(4).trim()}` : `Lot ${trimmed}`
}

/** The board's preview note, with `{first}` and `{lot}` filled from what is typed. */
const previewText = computed(() => {
    const first = form.full_name.trim().split(/\s+/)[0] || 'This resident'
    const lot = lotLabel(form.lot) || 'their unit'

    return props.preview.template.replace('{first}', first).replace('{lot}', lot)
})

const submitBlockedBy = computed(() => (props.canCreate ? null : props.blockedReason))

const submit = () => {
    /*
     * A form submits on Enter from any field and a disabled button does not
     * stop it. The route is gated on `estate.residents.create` and the service
     * refuses a lot the estate does not have, so this is only about not
     * sending a resident that is going to bounce.
     */
    if (submitBlockedBy.value !== null) {
        return
    }

    form.post(residentsPath.value, { preserveScroll: true })
}
</script>

<template>
    <Head title="Add resident" />

    <EstateConsole title="Add resident" :estate-name="estate.name" active="residents">
        <template #lead>
            <Link
                :href="residentsPath"
                style="width: 34px; height: 34px; border-radius: 50%; background: var(--navy-100); display: flex; align-items: center; justify-content: center; flex: 0 0 auto"
                title="Back to Residents"
                aria-label="Back to Residents"
            >
                <svg viewBox="0 0 24 24" fill="none" style="width: 16px; height: 16px; color: var(--navy-700)">
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
                <p v-if="page.props.flash?.success" class="new-resident-flash">{{ page.props.flash.success }}</p>

                <div class="form-sec-head">Unit</div>
                <div class="m-two-col">
                    <div class="m-field">
                        <label for="phase">Phase</label>
                        <div class="m-input">
                            <span>
                                <select id="phase" v-model="form.phase" required>
                                    <option v-for="option in phases" :key="option.value" :value="option.value">
                                        {{ option.label }}
                                    </option>
                                </select>
                            </span>
                        </div>
                        <div v-if="form.errors.phase" class="new-resident-error">{{ form.errors.phase }}</div>
                    </div>

                    <div class="m-field">
                        <label for="lot">Lot number</label>
                        <div class="m-input" :class="{ focused: form.lot !== '' }">
                            <span>
                                <input
                                    id="lot"
                                    v-model="form.lot"
                                    type="text"
                                    required
                                    maxlength="32"
                                    placeholder="112"
                                />
                            </span>
                        </div>
                        <div v-if="form.errors.lot" class="new-resident-error">{{ form.errors.lot }}</div>
                    </div>
                </div>

                <div class="form-sec-head">Primary resident</div>
                <div class="m-field">
                    <label for="full_name">Full name</label>
                    <div class="m-input" :class="{ focused: form.full_name !== '' }">
                        <span>
                            <input
                                id="full_name"
                                v-model="form.full_name"
                                type="text"
                                required
                                maxlength="160"
                                placeholder="Full name"
                            />
                        </span>
                    </div>
                    <div v-if="form.errors.full_name" class="new-resident-error">{{ form.errors.full_name }}</div>
                </div>

                <div class="m-two-col">
                    <div class="m-field">
                        <label for="email">Email</label>
                        <div class="m-input">
                            <span>
                                <input
                                    id="email"
                                    v-model="form.email"
                                    type="email"
                                    maxlength="190"
                                    placeholder="name@email.com"
                                />
                            </span>
                        </div>
                        <div v-if="form.errors.email" class="new-resident-error">{{ form.errors.email }}</div>
                    </div>

                    <div class="m-field">
                        <label for="phone">Phone</label>
                        <div class="m-input">
                            <span>
                                <input
                                    id="phone"
                                    v-model="form.phone"
                                    type="tel"
                                    maxlength="40"
                                    placeholder="876 555 0000"
                                />
                            </span>
                        </div>
                        <div v-if="form.errors.phone" class="new-resident-error">{{ form.errors.phone }}</div>
                    </div>
                </div>

                <div class="form-sec-head">Verification</div>
                <div class="seg" role="radiogroup" aria-label="How this resident's identity is established">
                    <button
                        v-for="option in verification"
                        :key="option.value"
                        type="button"
                        class="seg-item"
                        :class="{ active: form.verification === option.value }"
                        role="radio"
                        :aria-checked="form.verification === option.value"
                        @click="form.verification = option.value"
                    >
                        {{ option.label }}
                    </button>
                </div>

                <!--
                  AUTHORED — see the file docblock. Kept to the label size the
                  board's own .m-field label uses, so it reads as part of this
                  form rather than as a control from somewhere else.
                -->
                <label class="biometric-consent">
                    <input v-model="form.biometric_consent" type="checkbox" />
                    <span>{{ biometric_consent.label }}</span>
                </label>
                <p class="biometric-consent-note">{{ biometric_consent.note }}</p>

                <button
                    type="submit"
                    class="stack-btn primary"
                    style="width: 100%"
                    :disabled="submitBlockedBy !== null || form.processing"
                    :title="submitBlockedBy ?? 'Put this resident on the estate\'s register.'"
                >
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                    </svg>
                    <span>{{ form.processing ? 'Adding…' : 'Add resident' }}</span>
                </button>
            </form>

            <div class="preview-panel">
                <div class="preview-head">{{ preview.head }}</div>
                <div class="preview-note">{{ previewText }}</div>
            </div>
        </div>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only.
 *
 * The board draws every field as a <div class="m-input"><span>value</span></div>,
 * the verification choice as two <div class="seg-item">s and the submit as a
 * <div>. Here they are real controls, each arriving with the browser's own
 * border, background, font and (for the select) its own caret. Nothing below
 * introduces a colour, size or weight — the control sits INSIDE the board's own
 * <span>, so `.m-input span` supplies its type and `font: inherit` picks it up.
 */
.m-input span {
    flex: 1;
    min-width: 0;
}

.m-input input,
.m-input select {
    width: 100%;
    border: 0;
    background: transparent;
    font: inherit;
    color: inherit;
    padding: 0;
    appearance: none;
    -webkit-appearance: none;
}

.m-input input::placeholder {
    color: var(--slate-500);
    opacity: 1;
}

button.seg-item {
    border: 0;
    background: transparent;
    font: inherit;
    color: inherit;
    cursor: pointer;
}

button.stack-btn {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.stack-btn[disabled] {
    cursor: not-allowed;
}

.new-resident-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--red-700);
    margin-top: 5px;
    line-height: 1.45;
}

/*
 * AUTHORED BELOW THIS LINE — the board draws no flash and no consent control at
 * all. Kept to the tokens the board does define: the label size is
 * .m-field label's own 11.5px/700/slate-600, and the note is .preview-note's
 * 12px line height at a smaller size, since it is a footnote rather than the
 * form's own instruction.
 */
.new-resident-flash {
    font-size: 11.5px;
    font-weight: 600;
    color: #15803d;
    line-height: 1.5;
    margin: 0 0 14px;
}

.biometric-consent {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    font-size: 11.5px;
    font-weight: 700;
    color: var(--slate-600);
    margin: 4px 0 4px;
    cursor: pointer;
}

.biometric-consent input {
    margin-top: 2px;
}

.biometric-consent-note {
    font-size: 11px;
    color: var(--slate-500);
    line-height: 1.55;
    margin: 0 0 15px;
}
</style>
