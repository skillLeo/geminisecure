<script setup>
import { computed } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * One meeting, whole — the detail behind a board 36 row (12 §2, Wave 2 item 21).
 *
 * NO BOARD DRAWS THIS SCREEN, and it is not a fidelity target. It is built in
 * board 36's own sheet so the register and the meeting behind a row read as one.
 *
 * THE QUORUM IS THE REGISTER'S, not this page's. It is counted from attendance
 * by the same method that prints board 36's badge, so the two can never
 * disagree; attendance is shown as counts, because a count is what quorum turns
 * on and a list of who stayed home is not what a meeting record is for.
 *
 * THE PAPERS ARE LISTED WITH THEIR RETENTION. An agenda or minutes issued as a
 * PDF is kept seven years (12 §1), and "was the agenda sent?" is answered here.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    meeting: { type: Object, required: true },
    documents: { type: Array, required: true },
    canPublish: { type: Boolean, required: true },
    publishReason: { type: String, required: true },
})

useWireframe('community-admin-09-data-privacy-add-resident-and-meetings')

const page = usePage()

const root = computed(() => {
    const cut = page.url.indexOf('/governance')

    return cut === -1 ? '' : page.url.slice(0, cut)
})

const governance = (suffix) => `${root.value}/governance${suffix}`

const paperForm = useForm({})

const askForPaper = () => {
    paperForm.post(governance(`/meetings/${props.meeting.id}/${props.meeting.has_minutes ? 'minutes' : 'agenda'}`), {
        preserveScroll: true,
    })
}

const publish = () => {
    if (!props.canPublish) {
        return
    }

    router.post(governance(`/meetings/${props.meeting.id}/publish`), {}, { preserveScroll: true })
}
</script>

<template>
    <Head :title="meeting.title" />

    <EstateConsole :title="meeting.title" :estate-name="estate.name" active="governance">
        <template #lead>
            <Link
                :href="governance('/meetings')"
                style="width:34px;height:34px;border-radius:50%;background:var(--navy-100);display:flex;align-items:center;justify-content:center;flex:0 0 auto;"
                title="Back to the meeting register"
                aria-label="Back to the meeting register"
            >
                <svg viewBox="0 0 24 24" fill="none" style="width:16px;height:16px;color:var(--navy-700);">
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
                v-if="!meeting.published"
                type="button"
                class="btn-primary-sm"
                :disabled="!canPublish"
                :title="canPublish ? `Publish ${meeting.title} to ${meeting.audience.toLowerCase()}. It cannot be taken back.` : publishReason"
                @click="publish"
            >
                <span>Publish</span>
            </button>
            <button
                v-else
                type="button"
                class="btn-outline-sm"
                :disabled="paperForm.processing"
                :title="`Issue the ${meeting.has_minutes ? 'minutes' : 'agenda'} as a PDF. It is rendered by a worker and kept for seven years.`"
                @click="askForPaper"
            >
                <span>{{ meeting.has_minutes ? 'Issue minutes' : 'Issue agenda' }}</span>
            </button>
        </template>

        <p v-if="page.props.flash?.success" class="mt-flash">{{ page.props.flash.success }}</p>
        <p v-if="page.props.errors?.document" class="mt-refusal">{{ page.props.errors.document }}</p>
        <p v-if="page.props.errors?.starts_at" class="mt-refusal">{{ page.props.errors.starts_at }}</p>

        <div class="mt-grid">
            <div class="mt-card">
                <div class="mt-head">The meeting</div>
                <dl class="mt-dl">
                    <dt>Type</dt>
                    <dd>{{ meeting.type_label }}</dd>
                    <dt>When</dt>
                    <dd>{{ meeting.when }}</dd>
                    <dt>Where</dt>
                    <dd>
                        {{ meeting.venue ?? 'No venue recorded' }}
                        <div v-if="meeting.virtual_link" class="mt-muted">Online: {{ meeting.virtual_link }}</div>
                    </dd>
                    <dt>Audience</dt>
                    <dd>{{ meeting.audience }}</dd>
                    <dt>Status</dt>
                    <dd>{{ meeting.status_label }}</dd>
                    <template v-if="meeting.notice">
                        <dt>Notice</dt>
                        <dd>{{ meeting.notice }}</dd>
                    </template>
                    <dt>Recording</dt>
                    <dd>{{ meeting.recording }}</dd>
                </dl>
            </div>

            <div class="mt-card">
                <div class="mt-head">Quorum</div>
                <div class="status-badge" :class="meeting.quorum.state">{{ meeting.quorum.label }}</div>
                <dl class="mt-dl" style="margin-top: 10px">
                    <dt>Rule</dt>
                    <dd>{{ meeting.quorum.basis }} — {{ meeting.quorum.required }} needed</dd>
                    <dt>Present</dt>
                    <dd>{{ meeting.quorum.present }}</dd>
                    <dt>Apologies</dt>
                    <dd>{{ meeting.quorum.apologies }}</dd>
                    <dt>Absent</dt>
                    <dd>{{ meeting.quorum.absent }}</dd>
                </dl>
            </div>
        </div>

        <div class="mt-card">
            <div class="mt-head">Agenda</div>
            <p v-if="meeting.agenda.length === 0" class="mt-muted">No agenda items have been recorded for this meeting.</p>
            <ol v-else class="mt-agenda">
                <li v-for="(item, i) in meeting.agenda" :key="i">
                    <span v-if="item.time" class="mt-time">{{ item.time }}</span>
                    {{ item.text }}
                    <span v-if="item.motion" class="mt-muted"> · Motion {{ item.motion }}</span>
                </li>
            </ol>
        </div>

        <div class="mt-card">
            <div class="mt-head">Minutes</div>
            <template v-if="meeting.minutes">
                <div class="mt-muted">
                    {{ meeting.minutes.adopted }}<template v-if="meeting.minutes.recorded_by"> · recorded by {{ meeting.minutes.recorded_by }}</template>
                </div>
                <div class="mt-minutes">{{ meeting.minutes.body }}</div>
            </template>
            <p v-else class="mt-muted">No minutes have been recorded. They are issued once the meeting has been held and minuted.</p>
        </div>

        <div class="mt-card">
            <div class="mt-head">Papers issued</div>
            <p v-if="documents.length === 0" class="mt-muted">No agenda or minutes have been issued as a document yet.</p>
            <div v-for="doc in documents" :key="doc.id" class="mt-doc">
                <div>
                    <div class="mt-doc-title">{{ doc.title }}</div>
                    <div class="mt-muted">{{ doc.status_line }} · kept until {{ doc.retain_until }}</div>
                </div>
                <a v-if="doc.is_ready" :href="`${root}/documents/${doc.id}`" class="text-link-sm">Download</a>
                <span v-else class="mt-muted">{{ doc.status === 'failed' ? 'Not issued' : 'Being produced' }}</span>
            </div>
        </div>
    </EstateConsole>
</template>

<style scoped>
/*
 * AUTHORED. No board draws this screen. The badge and buttons are board 36's
 * sheet's own classes; the cards, the definition lists and the notices are kept
 * to the tokens the boards define.
 */
button.btn-primary-sm,
button.btn-outline-sm {
    font: inherit;
    cursor: pointer;
}

button.btn-primary-sm {
    border: 0;
}

button[disabled] {
    cursor: not-allowed;
}

/* A held meeting that missed its quorum — as on the register. */
.status-badge.failed {
    background: var(--red-100);
    color: var(--red-700);
}

.mt-grid {
    display: grid;
    grid-template-columns: 1.4fr 1fr;
    gap: 14px;
}

.mt-card {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 14px;
    padding: 16px 18px;
    margin-bottom: 14px;
}

.mt-head {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-900);
    margin-bottom: 10px;
}

.mt-dl {
    display: grid;
    grid-template-columns: 110px 1fr;
    gap: 7px 14px;
    margin: 0;
    font-size: 12px;
}

.mt-dl dt {
    color: var(--slate-500);
    font-weight: 700;
}

.mt-dl dd {
    margin: 0;
    color: var(--navy-900);
}

.mt-muted {
    font-size: 11px;
    color: var(--slate-500);
    line-height: 1.6;
}

.mt-agenda {
    margin: 0;
    padding-left: 18px;
    font-size: 12px;
    color: var(--navy-900);
    line-height: 1.8;
}

.mt-time {
    font-weight: 700;
    color: var(--navy-700);
    margin-right: 6px;
}

.mt-minutes {
    white-space: pre-line;
    font-size: 12px;
    color: var(--navy-900);
    line-height: 1.7;
    margin-top: 8px;
}

.mt-doc {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 8px 0;
    border-top: 1px solid var(--navy-100);
}

.mt-doc:first-of-type {
    border-top: 0;
}

.mt-doc-title {
    font-size: 12px;
    font-weight: 600;
    color: var(--navy-900);
}

.mt-flash,
.mt-refusal {
    font-size: 12px;
    font-weight: 600;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 12px;
}

.mt-flash {
    background: var(--success-100);
    color: var(--success-700);
}

.mt-refusal {
    background: var(--red-100);
    color: var(--red-700);
}
</style>
