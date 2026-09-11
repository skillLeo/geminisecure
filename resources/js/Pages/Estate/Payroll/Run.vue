<script setup>
import { computed, ref } from 'vue'
import { Head, router, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * Pay run approval — board screen community-admin-15.
 *
 * THE FOUR SUMMARY BOXES AND THE SEVEN COLUMNS ARE THE SAME FIGURES TWICE, AND
 * THE WHOLE POINT OF THIS SCREEN IS THAT THEY AGREE. `Payroll::runBoard()` sums
 * both from the payslip lines rather than reading the run header for one and the
 * lines for the other — that would make them agree by construction and prove
 * nothing. `EstatePayrollTest` then re-sums the same lines in raw SQL and checks
 * the header against them, so a run whose stored total drifted from its own
 * payslips fails a test rather than being read by a committee.
 *
 * THE PAYE COLUMN WILL NOT MATCH THE BOARD, AND THE CLIENT HAS RULED WHY.
 * Board 15 draws PAYE as 25% of gross less all three other deductions, charged
 * on the whole once TAJ's FORTNIGHTLY threshold is passed. The ruling on Q-002
 * (D-082): PAYE is 25% of the amount above the MONTHLY threshold, a band on the
 * excess — the board's samples were wrong and this calculator right. Board 15
 * would withhold J$85,392 a month where the ruling asks J$5,230. Boards 13, 15
 * and 16 need redrawing; they have not been touched.
 *
 * "APPROVE & SUBMIT FOR PAYMENT" IS THE IRREVERSIBLE ONE. The server decides
 * whether this viewer may press it and says why not — the role does not hold
 * approval, the viewer prepared this run, or its rate card is unverified — and
 * each is a different person's problem, so a greyed button with no sentence would
 * send somebody to the wrong one. On the estate's FIRST live run it also asks for
 * the acknowledgement Part F of the ruling requires: a tick naming the card the
 * run was reconciled against, which the server refuses the approval without.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    run: { type: Object, required: true },
    summary: { type: Array, required: true },
    lines: { type: Array, required: true },
    canApprove: { type: Boolean, required: true },
    approvalReason: { type: String, default: null },
    /** Part F of the Q-002 ruling — `required` on the estate's first live run. */
    acknowledgement: { type: Object, required: true },
    /** The two files a run leaves in, and what each is for. */
    exportFormats: { type: Array, required: true },
    canExport: { type: Boolean, required: true },
    exportBlockedReason: { type: String, required: true },
})

useWireframe('community-admin-04-payroll-runs-exceptions-and-filings')

const page = usePage()

const state = useScreenState({
    rows: () => props.lines.length,
})

const base = computed(() => page.url.split('/payroll')[0])
const changes = ref('')
const asking = ref(false)

/* ------------------------------------------------------------------ */
/* the two files a run leaves in (12 §1) */
/* ------------------------------------------------------------------ */

/*
 * CHOSEN, NEVER DEFAULTED. A credit file for the bank and a summary for the
 * accountant are different documents — one is an instruction to move money and
 * carries account numbers, the other a record of what was paid and carries
 * deductions — so the control opens a choice rather than guessing.
 */
const choosingFormat = ref(false)

/*
 * NOTHING BUT AN APPROVED RUN LEAVES. A file built from a draft is an
 * instruction to pay figures the committee has not seen. The server refuses it
 * too; this says so before the press rather than after it.
 */
const exportBlockedBy = computed(() => {
    if (!props.canExport) {
        return props.exportBlockedReason
    }

    return props.run.status === 'paid'
        ? null
        : `${props.run.period} has not been approved. A file built from a draft would be an instruction to pay figures nobody has agreed.`
})

const formatHref = (format) => `${base.value}/payroll/runs/${props.run.slug}/export?format=${format.key}`

/**
 * The board writes whole dollars and the payslips carry cents.
 *
 * Both are shown: the summary boxes round, because four boxes read at a glance
 * do not need eight digits, and the table does not, because a payslip is what
 * somebody is handed and a rounded deduction is a payslip that does not add up.
 */
const rounded = (minor) => `$${(minor / 100).toLocaleString('en-JM', { maximumFractionDigits: 0 })}`

const exact = (minor) =>
    `$${(minor / 100).toLocaleString('en-JM', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

const box = (entry) => (entry.value_minor === undefined ? entry.value : rounded(entry.value_minor))

const reconciled = ref(false)

/** Why the approve control will not act, or undefined when it will. */
const approveTitle = computed(() => {
    if (!props.canApprove) {
        return props.approvalReason ?? undefined
    }

    if (props.acknowledgement.required && !reconciled.value) {
        return 'This is the estate’s first live pay run. Tick the reconciliation acknowledgement first.'
    }

    return undefined
})

const approve = () => {
    router.post(
        `${base.value}/payroll/runs/${props.run.slug}/approve`,
        { reconciled: reconciled.value },
        { preserveScroll: true },
    )
}

const requestChanges = () => {
    router.post(
        `${base.value}/payroll/runs/${props.run.slug}/changes`,
        { reason: changes.value },
        { preserveScroll: true, onSuccess: () => { changes.value = ''; asking.value = false } },
    )
}
</script>

<template>
    <Head :title="`${run.period} Pay Run`" />

    <EstateConsole :title="`${run.period} Pay Run`" :estate-name="estate.name" active="payroll">
        <template #actions>
            <button
                type="button"
                class="btn-outline-sm"
                :disabled="exportBlockedBy !== null"
                :title="exportBlockedBy ?? 'Two files, and they are different documents: a summary for the accountant, a credit file for the bank. Choose which.'"
                @click="choosingFormat = !choosingFormat"
            >
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 3v13m0 0l-4-4m4 4l4-4M5 21h14" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <span>Export</span>
            </button>
        </template>

        <!--
          AUTHORED. The board draws a run nobody is exporting, so it has no
          panel. Each format says what is in it AND what is not — the whole
          reason there are two.
        -->
        <div v-if="choosingFormat && exportBlockedBy === null" class="exp-panel">
            <div class="exp-head">
                What this file is for. A pay run leaves as one of two documents, and the estate records that it left,
                who took it and how many payslips were in it.
            </div>

            <a v-for="format in exportFormats" :key="format.key" :href="formatHref(format)" class="exp-option">
                <div class="exp-label">{{ format.label }}</div>
                <div class="exp-detail">{{ format.description }}</div>
            </a>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="7" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Payroll is not part of your role’s access"
            body="A pay run shows what four people earn and what has been withheld from each of them, so it opens only to a role that holds Payroll."
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="This run has not been calculated"
            body="No payslips have been worked out yet. Clear the run's exceptions first, then calculate — nothing can be approved until there are figures to approve."
        />

        <template v-else>
            <div class="approval-banner">
                <svg viewBox="0 0 24 24" fill="none">
                    <circle cx="9" cy="8" r="3.4" stroke="currentColor" stroke-width="1.7" />
                    <path d="M3 20c0-3.3 2.7-5.4 6-5.4s6 2.1 6 5.4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                    <path d="M17 11a3 3 0 1 0 0-6M18 20c0-2.6-1-4.3-2.6-5.1" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                </svg>
                <div>
                    <div class="ab1">
                        <template v-if="run.status === 'paid'">
                            Approved by {{ run.approved_by }} on {{ run.approved_at }}
                        </template>
                        <template v-else>Prepared by {{ run.prepared_by }} — awaiting your approval</template>
                    </div>
                    <div class="ab2">
                        <template v-if="run.status === 'paid'">
                            This run has been posted. A pay run is approved once; a correction is a later run.
                        </template>
                        <template v-else>
                            As a second approver, this run cannot be disbursed until you review and approve it.
                        </template>
                    </div>
                </div>
            </div>

            <div class="sum-row">
                <div v-for="entry in summary" :key="entry.key" class="sum-box">
                    <div class="sv">{{ box(entry) }}</div>
                    <div class="sl">{{ entry.label }}</div>
                </div>
            </div>

            <table class="data-table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Gross</th>
                        <th>NIS</th>
                        <th>NHT</th>
                        <th>Ed. Tax</th>
                        <th>PAYE</th>
                        <th>Net</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="line in lines" :key="line.id">
                        <td>
                            <div class="res-cell">
                                <div class="res-avatar">{{ line.initials }}</div>
                                <div>
                                    <div class="res-name">{{ line.name }}</div>
                                    <div class="res-sub">{{ line.role }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="num-cell">{{ exact(line.gross_minor) }}</td>
                        <td class="num-cell">{{ exact(line.nis_minor) }}</td>
                        <td class="num-cell">{{ exact(line.nht_minor) }}</td>
                        <td class="num-cell">{{ exact(line.education_tax_minor) }}</td>
                        <!--
                          A nil PAYE carries the reason on it. `PayrollCalculator`
                          writes the sentence naming the threshold, because a bare
                          0.00 on a payslip reads as a defect to the person
                          holding it.
                        -->
                        <td class="num-cell" :title="line.paye_note ?? undefined">{{ exact(line.paye_minor) }}</td>
                        <td class="num-cell">{{ exact(line.net_minor) }}</td>
                    </tr>
                </tbody>
            </table>

            <div v-if="asking" class="changes-box">
                <label for="changes-reason">What needs changing?</label>
                <textarea
                    id="changes-reason"
                    v-model="changes"
                    rows="2"
                    placeholder="Name the payslip and what is wrong with it."
                ></textarea>
                <div class="changes-actions">
                    <button type="button" class="btn-outline-sm" @click="asking = false"><span>Cancel</span></button>
                    <button
                        type="button"
                        class="btn-outline-sm"
                        :disabled="changes.trim() === ''"
                        title="A run sent back with no reason leaves whoever prepared it guessing which of four payslips you disagreed with."
                        @click="requestChanges"
                    >
                        <span>Send back</span>
                    </button>
                </div>
            </div>

            <label v-if="run.status !== 'paid' && acknowledgement.required && canApprove" class="reconcile-ack">
                <input v-model="reconciled" type="checkbox" />
                <span>{{ acknowledgement.statement }}</span>
            </label>

            <div v-if="run.status !== 'paid'" class="approval-btns">
                <button type="button" class="btn-outline-sm" @click="asking = !asking">
                    <span>Request changes</span>
                </button>

                <button
                    type="button"
                    class="btn-amber-sm"
                    :disabled="!canApprove || (acknowledgement.required && !reconciled)"
                    :title="approveTitle"
                    @click="approve"
                >
                    <svg viewBox="0 0 24 24" fill="none">
                        <polyline points="20 6 9 17 4 12" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <span>Approve &amp; submit for payment</span>
                </button>
            </div>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws its export action and its two footer
 * controls as <div>s. `.btn-outline-sm`'s border IS its variant so it keeps it;
 * `.btn-amber-sm` declares a fill and no border, so only that one is reset —
 * see D-045, where a blanket rule rubbed out an outline the board did draw.
 */
button.btn-outline-sm {
    font: inherit;
    cursor: pointer;
}

button.btn-amber-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.btn-outline-sm[disabled],
button.btn-amber-sm[disabled] {
    cursor: not-allowed;
    opacity: 0.55;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws "Request changes" as a control with
 * nowhere to type, because a mock-up does not need somewhere to type. A reason
 * is required — the service refuses without one — so the field has to exist.
 * The export panel is authored for the same reason: two documents, chosen.
 */
.exp-panel {
    margin-bottom: 16px;
    padding: 15px 16px;
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 14px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.exp-head {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--navy-800);
    line-height: 1.55;
}

.exp-option {
    display: block;
    background: var(--navy-100);
    border-radius: 11px;
    padding: 11px 14px;
    text-decoration: none;
}

.exp-label {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-900);
    line-height: 1.5;
}

.exp-detail {
    font-size: 11px;
    color: var(--slate-600);
    line-height: 1.55;
}

.changes-box {
    margin-top: 16px;
    padding: 14px 16px;
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 14px;
}

.changes-box label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    color: var(--slate-500);
    margin-bottom: 7px;
}

.changes-box textarea {
    width: 100%;
    border: 1px solid var(--navy-100);
    border-radius: 10px;
    padding: 9px 11px;
    font-family: 'Inter', sans-serif;
    font-size: 12.5px;
    color: var(--navy-900);
    resize: vertical;
}

.changes-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 10px;
}

/*
 * Part F of the Q-002 ruling — the first-live-run acknowledgement. Authored,
 * because the board predates the ruling; drawn quietly above the two footer
 * controls, and only on the one run that needs it.
 */
.reconcile-ack {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    margin-top: 16px;
    font-size: 11.5px;
    line-height: 1.45;
    color: var(--slate-600);
}

.reconcile-ack input {
    margin-top: 2px;
}
</style>
