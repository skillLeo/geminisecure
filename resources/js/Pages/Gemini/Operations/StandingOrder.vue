<script setup>
import { ref } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import Subnav from './Subnav.vue'

/**
 * One order set — its text, every version, and who has acknowledged which
 * (12 §2, item 28).
 *
 * NO BOARD DRAWS THIS SCREEN, and it is not a fidelity target. It is built in
 * the operations sheet under the operations subnav, so it reads as the
 * library's own record.
 *
 * AN ACKNOWLEDGEMENT BELONGS TO A VERSION. A guard who acknowledged version 2
 * is shown as having done exactly that — not as current — once version 3 is in
 * force, and the text of every version is kept so what they agreed to can be
 * read. Revising asks every guard on post again; recording a review that changes
 * nothing does not.
 */
const props = defineProps({
    set: { type: Object, required: true },
    canUpdate: { type: Boolean, required: true },
    writeDisabledReason: { type: String, required: true },
})

const page = usePage()

const revising = ref(false)
const shownVersion = ref(null)

const today = new Date().toISOString().slice(0, 10)

const form = useForm({
    body: props.set.body,
    summary: props.set.summary,
    effective_on: today,
    change_note: '',
})

const revise = () =>
    form.post(`/guards/standing-orders/${props.set.id}/revise`, {
        preserveScroll: true,
        onSuccess: () => (revising.value = false),
    })

const markReviewed = () => router.post(`/guards/standing-orders/${props.set.id}/reviewed`, {}, { preserveScroll: true })
</script>

<template>
    <Head :title="set.title" />

    <GeminiConsole title="Standing orders">
        <template #lead>
            <Link href="/guards/standing-orders" class="so-back" title="Back to the library" aria-label="Back to the library">
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

        <template #actions>
            <button
                type="button"
                class="btn-outline-sm"
                :disabled="!canUpdate"
                :title="canUpdate ? 'Record that these orders were read through and still say the right thing. Nobody is asked to acknowledge again.' : writeDisabledReason"
                @click="markReviewed"
            >
                <span>Mark reviewed</span>
            </button>
            <button
                type="button"
                class="btn-primary-sm"
                :disabled="!canUpdate"
                :title="canUpdate ? 'Publish the next version. Every guard on post acknowledges it afresh.' : writeDisabledReason"
                @click="revising = !revising"
            >
                <span>Revise</span>
            </button>
        </template>

        <Subnav active="standing-orders" />

        <p v-if="page.props.flash?.success" class="so-flash">{{ page.props.flash.success }}</p>
        <p v-if="page.props.errors?.orders" class="so-error">{{ page.props.errors.orders }}</p>

        <div class="so-card">
            <div class="so-title">{{ set.title }}</div>
            <div class="so-sub">
                {{ set.category_label }} · {{ set.where }} · Version {{ set.version }} · in effect from
                {{ set.effective_on }} · reviewed {{ set.reviewed_on }}
            </div>
            <pre class="so-body">{{ set.body }}</pre>
        </div>

        <form v-if="revising" class="so-card" @submit.prevent="revise">
            <div class="so-head">Publish version {{ set.version + 1 }}</div>
            <p class="so-note">
                Every acknowledgement of version {{ set.version }} stays on record against version {{ set.version }}, and
                every guard on post is asked to acknowledge the new one.
            </p>
            <label class="so-label" for="so-note">What changed</label>
            <input id="so-note" v-model="form.change_note" type="text" maxlength="300" required />
            <template v-if="set.company_wide">
                <label class="so-label" for="so-summary">Where they apply, in one line</label>
                <input id="so-summary" v-model="form.summary" type="text" maxlength="190" />
            </template>
            <label class="so-label" for="so-effective">Takes effect</label>
            <input id="so-effective" v-model="form.effective_on" type="date" :min="today" required />
            <label class="so-label" for="so-body">The orders</label>
            <textarea id="so-body" v-model="form.body" rows="10" maxlength="20000" required />
            <div v-for="(message, key) in form.errors" :key="key" class="so-error">{{ message }}</div>
            <div class="so-actions">
                <button type="submit" class="btn-primary-sm" :disabled="form.processing">
                    <span>{{ form.processing ? 'Publishing…' : `Publish version ${set.version + 1}` }}</span>
                </button>
                <button type="button" class="text-link-sm" @click="revising = false">Cancel</button>
            </div>
        </form>

        <div class="so-card">
            <div class="so-head">Acknowledgement</div>
            <p v-if="set.company_wide" class="so-note">
                Company-wide orders are part of every guard's induction and are not acknowledged post by post.
            </p>
            <template v-else>
                <p v-if="set.guards_on_post.length === 0" class="so-note">
                    No guard is posted here, so nobody can acknowledge these orders yet.
                </p>
                <div v-for="guard in set.guards_on_post" :key="guard.name" class="so-row">
                    <div>
                        <div class="so-name">{{ guard.name }}</div>
                        <div class="so-note">
                            {{ guard.line }}<template v-if="!guard.can_stand"> · cannot currently stand this post</template>
                        </div>
                    </div>
                    <span class="so-pill" :class="{ ok: guard.acknowledged }">
                        {{ guard.acknowledged ? 'Current' : 'Outstanding' }}
                    </span>
                </div>
            </template>
        </div>

        <div class="so-card">
            <div class="so-head">Versions</div>
            <div v-for="v in set.versions" :key="v.version" class="so-version">
                <div class="so-row">
                    <div>
                        <div class="so-name">Version {{ v.version }} · in effect from {{ v.effective_on }}</div>
                        <div class="so-note">
                            {{ v.change_note ?? 'First published version' }} · published by {{ v.published_by }}
                        </div>
                    </div>
                    <button
                        type="button"
                        class="text-link-sm"
                        @click="shownVersion = shownVersion === v.version ? null : v.version"
                    >
                        {{ shownVersion === v.version ? 'Hide text' : 'Read text' }}
                    </button>
                </div>
                <pre v-if="shownVersion === v.version" class="so-body">{{ v.body }}</pre>
            </div>

            <div v-if="set.acknowledgements.length" class="so-head" style="margin-top: 12px">Every acknowledgement</div>
            <div v-for="(ack, i) in set.acknowledgements" :key="i" class="so-note">
                {{ ack.guard }} acknowledged version {{ ack.version }} on {{ ack.at }}<template v-if="!ack.current"> — superseded</template>
            </div>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/*
 * AUTHORED. No board draws this screen. Kept to the tokens the Gemini boards
 * define; the buttons are the sheet's own classes.
 */
.so-back {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: var(--navy-100);
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
}

.so-back svg {
    width: 16px;
    height: 16px;
    color: var(--navy-700);
}

button.btn-primary-sm,
button.btn-outline-sm,
button.text-link-sm {
    font: inherit;
    cursor: pointer;
}

button.btn-primary-sm {
    border: 0;
}

button.text-link-sm {
    border: 0;
    background: transparent;
}

button[disabled] {
    cursor: not-allowed;
}

.so-card {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 16px 18px;
    margin-bottom: 14px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    max-width: 900px;
}

.so-title {
    font-size: 15px;
    font-weight: 700;
    color: var(--navy-900);
}

.so-sub,
.so-note {
    font-size: 11px;
    color: var(--slate-600);
    line-height: 1.6;
    margin: 0;
}

.so-head {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-900);
}

.so-body {
    white-space: pre-wrap;
    font-family: inherit;
    font-size: 12px;
    line-height: 1.7;
    color: var(--navy-900);
    background: var(--navy-100);
    border-radius: 10px;
    padding: 12px 14px;
    margin: 4px 0 0;
}

.so-label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
}

.so-card input,
.so-card textarea {
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 7px 10px;
    font: inherit;
    font-size: 12px;
    color: var(--navy-900);
}

.so-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 6px 0;
}

.so-version + .so-version {
    border-top: 1px solid var(--navy-100);
}

.so-name {
    font-size: 12px;
    font-weight: 600;
    color: var(--navy-900);
}

.so-pill {
    font-size: 10.5px;
    font-weight: 700;
    border-radius: 20px;
    padding: 3px 10px;
    background: var(--red-100);
    color: var(--red-700);
}

.so-pill.ok {
    background: var(--success-100);
    color: var(--success-700);
}

.so-actions {
    display: flex;
    align-items: center;
    gap: 14px;
}

.so-flash,
.so-error {
    font-size: 11.5px;
    font-weight: 600;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 12px;
}

.so-flash {
    background: var(--success-100);
    color: var(--success-700);
}

.so-error {
    background: var(--red-100);
    color: var(--red-700);
}
</style>
