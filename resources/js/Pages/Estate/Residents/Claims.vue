<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * Unit claim review — board screen community-admin-31.
 *
 * EVERY CARD IS A COMPARISON, AND `mismatch` IS DERIVED SERVER-SIDE, NEVER HERE.
 * `claim.submitted.mismatch` and `claim.record.mismatch` are independent
 * booleans off `Residents::claimsBoard()` — card 1 flags only the right column,
 * card 2 flags neither, card 3 flags both — and this page does no comparing of
 * its own. A page that re-derived "does the name match" from the two columns'
 * own rows would be a second place for that judgement to drift from the one a
 * reviewer is actually shown.
 *
 * THE ARREARS SUFFIX ON A NAME IS Q-014, NOT THIS PAGE'S DECISION. Where the
 * third card's On-record row reads "Tanya Simms — 90+ days arrears" on the
 * board, this screen prints whatever `row.value` the server composed —
 * `Residents::recordNameWithStanding()` appends the bucket only for a viewer
 * holding `estate.dues_ledger.view`, per `ARREARS_FLAG_NEEDS_LEDGER_ACCESS`.
 * Measured as the Property Manager, board 31's own persona and the role Q-014
 * is about, that suffix is absent — the identity flag stays and the money does
 * not, which is the whole point of the flag standing at `true`.
 *
 * TWO GATES, NOT ONE — D-013's WHOLE REASON FOR EXISTING. Approving a claim
 * binds a person to a household and needs `estate.residents.approve`; refusing
 * one and asking for a document are both `estate.residents.update` and need
 * only that. The Secretary holds plain Full on Residents (D-053) — every
 * refuse and every document request on this screen and neither approval — and
 * this page asks the two permissions separately for exactly that household.
 *
 * REFUSING NEEDS A REASON THE BOARD DRAWS NO FIELD FOR. `Residents::rejectClaim()`
 * throws on an empty one, so a plain "Reject" button that posted nothing would
 * bounce every time it was pressed. The reason field is AUTHORED — opened only
 * once Reject is pressed, never on the still image this board is — the same
 * shape Facilities/Ticket.vue uses for its own reopen reason.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    title: { type: String, required: true },
    pending_count: { type: Number, required: true },
    claims: { type: Array, required: true },
    canApprove: { type: Boolean, required: true },
    canReview: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
})

/*
 * Board 31 lives in the eighth Community Admin sheet, alongside the rest of
 * "Reports, Unit Claims and Notices" — a different sheet from the residents
 * directory and detail screens either side of it in the sidebar.
 */
useWireframe('community-admin-08-reports-unit-claims-and-notices')

const page = usePage()

/**
 * Five of the six. This screen has no filter of its own — every claim shown is
 * every pending claim there is — so `empty-filtered` cannot occur from real
 * data; the composable still lets `?_state=` force it in local.
 */
const state = useScreenState({
    rows: () => props.claims.length,
})

const retry = () => router.reload()

/*
 * Where this console is rooted, read off the page's own URL. Production gives
 * each estate its own hostname and no prefix; local serves every estate from
 * one host with the estate key in the path, as /estate/{key}/residents/claims.
 * Cutting the current URL at /residents is correct in both.
 */
const root = computed(() => page.url.slice(0, page.url.indexOf('/residents')))
const residentsPath = computed(() => `${root.value}/residents`)
const claimPath = (id, action) => `${root.value}/residents/claims/${id}/${action}`

/**
 * Why an action cannot be pressed, keyed on which one it is.
 *
 * THE APPROVE REFUSAL IS THE SERVER'S OWN SENTENCE — it already names the
 * approve gate exactly. The refuse-and-document refusal is authored here
 * because no server sentence on this payload is about `update`; `blockedReason`
 * is written for approve and would misname the gate if reused for the other two.
 */
const NO_REVIEW_ACCESS =
    'Refusing a claim or asking for a document changes its record, so it needs Residents update access. You are able to read this screen.'

const blockedFor = (key) => {
    if (key === 'approve') {
        return props.canApprove ? null : props.blockedReason
    }

    return props.canReview ? null : NO_REVIEW_ACCESS
}

/* ------------------------------------------------------------------ */
/* approve and request-document: one press, nothing else to type */
/* ------------------------------------------------------------------ */

const approve = (claim) => {
    if (blockedFor('approve') !== null) {
        return
    }

    router.post(claimPath(claim.id, 'approve'), {}, { preserveScroll: true })
}

const requestDocument = (claim) => {
    if (blockedFor('document') !== null) {
        return
    }

    // The service defaults the kind to "photo ID" when none is sent, and the
    // board draws no field to type one into — so none is sent.
    router.post(claimPath(claim.id, 'document'), {}, { preserveScroll: true })
}

/* ------------------------------------------------------------------ */
/* reject: authored, because a reason is required and the board has no field */
/* ------------------------------------------------------------------ */

/** Which claim's refuse-reason panel is open, or null. */
const rejecting = ref(null)

const rejectForm = useForm({ reason: '' })

const openReject = (claim) => {
    if (blockedFor('reject') !== null) {
        return
    }

    rejecting.value = claim.id
    rejectForm.reset()
    rejectForm.clearErrors()
}

const cancelReject = () => {
    rejecting.value = null
    rejectForm.reset()
    rejectForm.clearErrors()
}

const submitReject = (claim) => {
    rejectForm.post(claimPath(claim.id, 'reject'), {
        preserveScroll: true,
        onSuccess: () => {
            rejecting.value = null
        },
    })
}

/** Which action a card's outline button is — 'reject' or 'document'. */
const outlineKey = (claim) => claim.actions.find((a) => a.style === 'outline')?.key ?? 'document'

const handleOutline = (claim) => {
    const key = outlineKey(claim)

    if (key === 'reject') {
        openReject(claim)
    } else {
        requestDocument(claim)
    }
}
</script>

<template>
    <Head :title="title" />

    <EstateConsole :title="title" :estate-name="estate.name" active="residents">
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

        <!-- The whole screen is one payload, so nothing on it arrives before the rest. -->
        <SkeletonRows v-if="state.isLoading.value" :rows="3" :columns="2" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Unit claims are not part of your role’s access"
            body="A claim names who is asking to be recognised against a household and what the register already holds, so it opens only to roles that hold Residents. A committee officer or the estate administrator can grant it from the role access matrix."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The claim queue could not be read"
            body="The estate database did not answer. No claim has been decided and nobody has been bound to a household — this is a read that failed, and re-running it is safe."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="No unit claims are waiting on a decision"
            body="A claim is raised when somebody's own account of a household doesn't match the register exactly. There is nothing self-reported that needs reconciling right now."
            action-label="Back to Residents"
            @action="router.get(residentsPath)"
        />

        <template v-else>
            <p v-if="page.props.flash?.success" class="claims-flash">{{ page.props.flash.success }}</p>
            <p v-if="page.props.errors?.claim" class="claims-refusal">{{ page.props.errors.claim }}</p>

            <div v-for="claim in claims" :key="claim.id" class="claim-card">
                <div class="claim-top">
                    <div class="claim-title">{{ claim.title }}</div>
                    <div class="status-badge" :class="claim.status">{{ claim.status_label }}</div>
                </div>

                <div class="compare-grid">
                    <div class="compare-col" :class="{ mismatch: claim.submitted.mismatch }">
                        <div class="cc-lbl">{{ claim.submitted.label }}</div>
                        <div v-for="row in claim.submitted.rows" :key="row.label" class="compare-row">
                            <span>{{ row.label }}</span><span>{{ row.value }}</span>
                        </div>
                    </div>
                    <div class="compare-col" :class="{ mismatch: claim.record.mismatch }">
                        <div class="cc-lbl">{{ claim.record.label }}</div>
                        <div v-for="row in claim.record.rows" :key="row.label" class="compare-row">
                            <span>{{ row.label }}</span><span>{{ row.value }}</span>
                        </div>
                    </div>
                </div>

                <form
                    v-if="rejecting === claim.id"
                    class="reject-panel"
                    @submit.prevent="submitReject(claim)"
                >
                    <div class="m-field">
                        <label :for="`reject-reason-${claim.id}`">Reason for refusing</label>
                        <div class="m-input" :class="{ focused: rejectForm.reason !== '' }">
                            <span>
                                <input
                                    :id="`reject-reason-${claim.id}`"
                                    v-model="rejectForm.reason"
                                    type="text"
                                    required
                                    maxlength="190"
                                    placeholder="Why this claim is being refused"
                                />
                            </span>
                        </div>
                        <div v-if="rejectForm.errors.reason" class="reject-error">{{ rejectForm.errors.reason }}</div>
                    </div>
                    <div class="claim-actions">
                        <button type="button" class="text-link-sm" @click="cancelReject">Cancel</button>
                        <button
                            type="submit"
                            class="stack-btn primary"
                            :disabled="rejectForm.processing"
                            title="Refuse this claim and keep the reason on the record."
                        >
                            <span>{{ rejectForm.processing ? 'Refusing…' : 'Confirm refusal' }}</span>
                        </button>
                    </div>
                </form>

                <div v-else class="claim-actions">
                    <button
                        type="button"
                        class="stack-btn outline"
                        :disabled="blockedFor(outlineKey(claim)) !== null"
                        :title="
                            blockedFor(outlineKey(claim)) ??
                            (outlineKey(claim) === 'reject'
                                ? 'Refuse this claim. The register already agrees with this household, so the only question left is whether the estate does too — refusing asks for the reason on the record.'
                                : 'Ask the claimant for a photo ID before this goes further. Nothing about the household is decided yet.')
                        "
                        @click="handleOutline(claim)"
                    >
                        <svg v-if="outlineKey(claim) === 'reject'" viewBox="0 0 24 24" fill="none">
                            <path
                                d="M6 6l12 12M18 6L6 18"
                                stroke="currentColor"
                                stroke-width="2.2"
                                stroke-linecap="round"
                            />
                        </svg>
                        <svg v-else viewBox="0 0 24 24" fill="none">
                            <path
                                d="M4 11v2a1 1 0 0 0 1 1h2l4 4V6L7 10H5a1 1 0 0 0-1 1z"
                                stroke="currentColor"
                                stroke-width="1.7"
                                stroke-linejoin="round"
                            />
                        </svg>
                        <span>{{ outlineKey(claim) === 'reject' ? 'Reject' : 'Request ID document' }}</span>
                    </button>

                    <button
                        type="button"
                        class="stack-btn primary"
                        :disabled="blockedFor('approve') !== null"
                        :title="
                            blockedFor('approve') ??
                            'Bind this claimant to the household. Whose guest passes they may issue and whose gate they may be admitted at both follow from this — it cannot be undone by an edit afterwards.'
                        "
                        @click="approve(claim)"
                    >
                        <svg viewBox="0 0 24 24" fill="none">
                            <polyline
                                points="20 6 9 17 4 12"
                                stroke="currentColor"
                                stroke-width="3"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                        <span>Approve claim</span>
                    </button>
                </div>
            </div>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only, and each removal names the element that needs it.
 *
 * The board draws its back affordance and every card's two actions as <div>s;
 * here they are a link and a set of buttons, each arriving with the browser's
 * own font, border and buttonface grey. .stack-btn declares a background for
 * .primary and both a background and a border for .outline — its own border IS
 * the variant, so only the primary style and the authored panel's own controls
 * are reset here. See D-045.
 */
button.stack-btn.primary {
    border: 0;
}

button.stack-btn {
    font: inherit;
    cursor: pointer;
}

button.stack-btn[disabled] {
    cursor: not-allowed;
}

button.text-link-sm {
    border: 0;
    background: none;
    padding: 0;
    font: inherit;
    cursor: pointer;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws no flash, no refusal and no reject
 * panel of any kind, because it is a still image of a screen nobody has pressed
 * anything on. Kept to the tokens the boards do define — the reason field
 * borrows .m-field/.m-input from this same sheet, and the panel sits in the
 * same card padding .claim-actions already uses.
 */
.claims-flash,
.claims-refusal {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 14px;
}

.claims-flash {
    background: var(--green-100);
    color: var(--green-700);
}

.claims-refusal {
    background: var(--red-100);
    color: var(--red-700);
}

.reject-panel {
    display: flex;
    flex-direction: column;
    gap: 11px;
}

.reject-panel .m-field {
    margin-bottom: 0;
}

.reject-panel input {
    width: 100%;
    border: 0;
    background: transparent;
    font: inherit;
    color: inherit;
    padding: 0;
}

.reject-error {
    font-size: 11px;
    font-weight: 600;
    color: var(--red-700);
    margin-top: 5px;
}
</style>
