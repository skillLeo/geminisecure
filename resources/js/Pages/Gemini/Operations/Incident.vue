<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import Subnav from './Subnav.vue'

/**
 * One incident — board 27's "View" (12 §2, item 27).
 *
 * NO BOARD DRAWS THIS SCREEN, and it is not a fidelity target. It is built in
 * the operations sheet, under the operations subnav, so it reads as the log's
 * own record.
 *
 * A RESOLUTION CLOSES THE INCIDENT, AND IS FINAL. The form asks for what was
 * done, because "resolved" with nothing after it reads the same as open to
 * whoever comes to it later. A closed incident shows its resolution and offers
 * nothing: it is not rewritten, and something that happens again is logged
 * again, with its own date.
 */
const props = defineProps({
    incident: { type: Object, required: true },
    canResolve: { type: Boolean, required: true },
    writeDisabledReason: { type: String, required: true },
})

const page = usePage()

const form = useForm({ resolution: '' })

const resolve = () => form.post(`/guards/incidents/${props.incident.id}/resolve`, { preserveScroll: true })
</script>

<template>
    <Head :title="`Incident — ${incident.kind}`" />

    <GeminiConsole title="Security incident">
        <template #lead>
            <Link href="/guards/incidents" class="inc-back" title="Back to the incident log" aria-label="Back to the incident log">
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

        <Subnav active="incidents" />

        <p v-if="page.props.flash?.success" class="inc-flash">{{ page.props.flash.success }}</p>

        <div class="inc-card">
            <div class="inc-top">
                <div>
                    <div class="inc-kind">{{ incident.kind }}</div>
                    <div class="inc-sub">{{ incident.estate }} · {{ incident.occurred_at }}</div>
                </div>
                <div class="inc-badges">
                    <div class="severity-badge" :class="incident.severity">{{ incident.severity_label }}</div>
                    <div class="status-badge" :class="incident.status">{{ incident.status_label }}</div>
                </div>
            </div>

            <dl class="inc-dl">
                <dt>Guard on post</dt>
                <dd>{{ incident.guard }}</dd>
                <dt>Logged</dt>
                <dd>{{ incident.logged_at ?? 'Not recorded' }} by {{ incident.logged_by }}</dd>
                <dt>What happened</dt>
                <dd class="inc-text">{{ incident.detail ?? 'No account recorded.' }}</dd>
                <template v-if="incident.resolution">
                    <dt>What was done</dt>
                    <dd class="inc-text">{{ incident.resolution }}</dd>
                    <dt>Closed</dt>
                    <dd>{{ incident.closed_at ?? 'Not recorded' }}</dd>
                </template>
            </dl>
        </div>

        <form v-if="incident.status === 'open'" class="inc-card" @submit.prevent="resolve">
            <div class="inc-head">Close this incident</div>
            <p class="inc-note">
                Record what was done. Closing is final — a closed incident is not rewritten, and anything that happens
                again is logged again.
            </p>
            <textarea
                v-model="form.resolution"
                rows="4"
                maxlength="4000"
                :disabled="!canResolve"
                :title="canResolve ? undefined : writeDisabledReason"
                aria-label="What was done"
            />
            <div v-if="form.errors.resolution" class="inc-error">{{ form.errors.resolution }}</div>
            <div>
                <button
                    type="submit"
                    class="btn-primary-sm"
                    :disabled="!canResolve || form.processing"
                    :title="canResolve ? 'Close the incident with what was done.' : writeDisabledReason"
                >
                    <span>{{ form.processing ? 'Closing…' : 'Close incident' }}</span>
                </button>
            </div>
        </form>
    </GeminiConsole>
</template>

<style scoped>
/*
 * AUTHORED. No board draws this screen. Badges are the operations sheet's own
 * classes; the card, the notes and the form are kept to the tokens the Gemini
 * boards define.
 */
.inc-back {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: var(--navy-100);
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
}

.inc-back svg {
    width: 16px;
    height: 16px;
    color: var(--navy-700);
}

button.btn-primary-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.btn-primary-sm[disabled] {
    cursor: not-allowed;
}

.inc-card {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 16px 18px;
    margin-bottom: 14px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-width: 860px;
}

.inc-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 14px;
}

.inc-kind {
    font-size: 15px;
    font-weight: 700;
    color: var(--navy-900);
}

.inc-sub {
    font-size: 11.5px;
    color: var(--slate-500);
}

.inc-badges {
    display: flex;
    gap: 8px;
}

.inc-dl {
    display: grid;
    grid-template-columns: 130px 1fr;
    gap: 8px 14px;
    margin: 0;
    font-size: 12px;
}

.inc-dl dt {
    color: var(--slate-500);
    font-weight: 700;
}

.inc-dl dd {
    margin: 0;
    color: var(--navy-900);
}

.inc-text {
    white-space: pre-line;
    line-height: 1.6;
}

.inc-head {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-900);
}

.inc-note {
    font-size: 11px;
    color: var(--slate-600);
    line-height: 1.6;
    margin: 0;
}

textarea {
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 8px 10px;
    font: inherit;
    font-size: 12px;
    color: var(--navy-900);
}

.inc-flash,
.inc-error {
    font-size: 11.5px;
    font-weight: 600;
    border-radius: 10px;
    padding: 9px 13px;
}

.inc-flash {
    margin: 0 0 14px;
    background: var(--success-100);
    color: var(--success-700);
}

.inc-error {
    background: var(--red-100);
    color: var(--red-700);
}
</style>
