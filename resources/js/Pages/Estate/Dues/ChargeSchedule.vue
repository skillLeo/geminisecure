<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { duesTabs } from './tabs'

/**
 * Charge schedule — board 5's "Charge schedule" tab (12 §2, item 17):
 * "recurring run with preview and reversal".
 *
 * NOT DRAWN ON ANY BOARD: board 5 names the tab. Built from board 5's sheet so
 * it reads as a tab of one module.
 *
 * A SCHEDULE IS THE STANDING DECISION, A RUN IS ONE MONTH OF IT. Each schedule
 * shows the next month it would bill — how many units, what total, due when —
 * BEFORE anything posts, and the post sends those figures back: a register that
 * changed in between is refused rather than billed on a list nobody saw.
 *
 * A WRONG MONTH IS REVERSED, NEVER DELETED. The mirror entry posts, the month's
 * charges leave the ageing, and the run keeps who reversed it and why. Every
 * household billed sees both on its statement, which is what happened.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    schedules: { type: Array, required: true },
    runs: { type: Array, required: true },
    incomeAccounts: { type: Array, required: true },
    phases: { type: Array, required: true },
    canPost: { type: Boolean, required: true },
    canApprove: { type: Boolean, required: true },
    reasons: { type: Object, required: true },
})

useWireframe('community-admin-02-arrears-ledger-payment-plan-and-dunning')

const page = usePage()

const state = useScreenState({
    rows: () => props.schedules.length + props.runs.length,
})

const root = computed(() => {
    const cut = page.url.indexOf('/finance')

    return cut === -1 ? '' : page.url.slice(0, cut)
})

const financePath = (suffix) => `${root.value}/finance${suffix}`

const subnav = computed(() => duesTabs(financePath, 'schedule'))

/* ---- defining a schedule ---- */
const defining = ref(false)

const scheduleForm = useForm({
    description: 'Maintenance fee',
    amount: '',
    scope: 'estate',
    phase: props.phases[0] ?? '',
    due_day: 1,
    account_code: props.incomeAccounts[0]?.code ?? '4000',
})

const defineSchedule = () =>
    scheduleForm.post(financePath('/charge-schedule'), {
        preserveScroll: true,
        onSuccess: () => (defining.value = false),
    })

/* ---- posting a month, against its preview ---- */
const posting = ref(null)

const postRun = (schedule) => {
    posting.value = schedule.id

    router.post(
        financePath(`/charge-schedule/${schedule.id}/runs`),
        {
            period: schedule.preview.period,
            expected_count: schedule.preview.count,
            expected_total_minor: schedule.preview.total_minor,
        },
        { preserveScroll: true, onFinish: () => (posting.value = null) }
    )
}

const stop = (schedule) => router.post(financePath(`/charge-schedule/${schedule.id}/stop`), {}, { preserveScroll: true })

/* ---- reversing a month ---- */
const reversing = ref(null)
const reason = ref('')

const reverse = (run) => {
    if (reversing.value !== run.id) {
        reversing.value = run.id
        reason.value = ''

        return
    }

    router.post(
        financePath(`/charge-runs/${run.id}/reverse`),
        { reason: reason.value },
        { preserveScroll: true, onSuccess: () => (reversing.value = null) }
    )
}
</script>

<template>
    <Head title="Charge schedule" />

    <EstateConsole title="Charge schedule" :estate-name="estate.name" active="dues_ledger">
        <template #actions>
            <button
                type="button"
                class="btn-outline-sm"
                :disabled="!canApprove"
                :title="canApprove ? 'Define a recurring charge. Nothing is billed until a month is posted.' : reasons.approve"
                @click="defining = !defining"
            >
                <span>New schedule</span>
            </button>
        </template>

        <div class="subnav">
            <template v-for="item in subnav" :key="item.label">
                <div v-if="item.active" class="subnav-item active" aria-current="page">{{ item.label }}</div>
                <Link v-else :href="item.href" class="subnav-item">{{ item.label }}</Link>
            </template>
        </div>

        <p v-if="page.props.flash?.success" class="cs-flash">{{ page.props.flash.success }}</p>
        <p v-for="key in ['schedule', 'run', 'reversal']" v-show="page.props.errors?.[key]" :key="key" class="cs-error">
            {{ page.props.errors?.[key] }}
        </p>

        <form v-if="defining" class="cs-panel" @submit.prevent="defineSchedule">
            <div class="cs-head">
                A schedule is the standing decision — what every unit in its scope is billed, and on which day. Nothing is
                billed until a month is posted from its preview.
            </div>
            <div class="cs-fields">
                <div class="cs-field">
                    <label for="cs-desc">Charge</label>
                    <input id="cs-desc" v-model="scheduleForm.description" type="text" maxlength="120" required />
                </div>
                <div class="cs-field">
                    <label for="cs-amount">Amount per unit (J$)</label>
                    <input id="cs-amount" v-model="scheduleForm.amount" type="number" min="0.01" step="0.01" required />
                </div>
                <div class="cs-field">
                    <label for="cs-scope">Who is billed</label>
                    <select id="cs-scope" v-model="scheduleForm.scope">
                        <option value="estate">Every unit in the estate</option>
                        <option value="phase">Every unit in one phase</option>
                    </select>
                </div>
                <div v-if="scheduleForm.scope === 'phase'" class="cs-field">
                    <label for="cs-phase">Phase</label>
                    <select id="cs-phase" v-model="scheduleForm.phase">
                        <option v-for="phase in phases" :key="phase" :value="phase">{{ phase }}</option>
                    </select>
                </div>
                <div class="cs-field">
                    <label for="cs-day">Due on day</label>
                    <input id="cs-day" v-model.number="scheduleForm.due_day" type="number" min="1" max="28" required />
                </div>
                <div class="cs-field">
                    <label for="cs-account">Income account</label>
                    <select id="cs-account" v-model="scheduleForm.account_code">
                        <option v-for="account in incomeAccounts" :key="account.code" :value="account.code">
                            {{ account.label }}
                        </option>
                    </select>
                </div>
            </div>
            <div v-for="(message, key) in scheduleForm.errors" :key="key" class="cs-error">{{ message }}</div>
            <div class="cs-actions">
                <button type="submit" class="btn-primary-sm" :disabled="scheduleForm.processing">
                    <span>Save schedule</span>
                </button>
                <button type="button" class="text-link-sm" @click="defining = false">Cancel</button>
            </div>
        </form>

        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="6" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="The charge schedule is not yours to see"
            body="A schedule decides what every household is billed, which is Dues & ledger's, and your role does not hold it."
        />

        <EmptyState
            v-else-if="state.isEmpty.value || state.isEmptyFiltered.value"
            variant="first-use"
            title="No recurring charge is scheduled"
            body="The maintenance fee is set up here once — amount, scope and due day — and each month is then posted from a preview of exactly what it will bill."
        />

        <template v-else>
            <div v-for="schedule in schedules" :key="schedule.id" class="cs-panel">
                <div class="cs-top">
                    <div>
                        <div class="cs-title">{{ schedule.description }}</div>
                        <div class="cs-sub">
                            {{ schedule.amount }} a unit · {{ schedule.scope }} · due on day {{ schedule.due_day }} · credits
                            {{ schedule.account }}
                        </div>
                    </div>
                    <button
                        v-if="schedule.is_active"
                        type="button"
                        class="text-link-sm"
                        :disabled="!canApprove"
                        :title="canApprove ? 'Stop billing from this schedule. Posted months stand.' : reasons.approve"
                        @click="stop(schedule)"
                    >
                        Stop schedule
                    </button>
                    <span v-else class="cs-stopped">Stopped</span>
                </div>

                <div v-if="schedule.preview && schedule.is_active" class="cs-preview">
                    <div>
                        <div class="cs-preview-head">Next: {{ schedule.preview.label }}</div>
                        <div class="cs-sub">
                            {{ schedule.preview.count }} units · {{ schedule.preview.total }} · due {{ schedule.preview.due_label }}
                        </div>
                    </div>
                    <button
                        type="button"
                        class="btn-primary-sm"
                        :disabled="!canPost || posting === schedule.id"
                        :title="canPost ? `Post ${schedule.preview.label}: bill these ${schedule.preview.count} units, as one entry.` : reasons.post"
                        @click="postRun(schedule)"
                    >
                        <span>{{ posting === schedule.id ? 'Posting…' : `Post ${schedule.preview.label}` }}</span>
                    </button>
                </div>
                <p v-else class="cs-sub">{{ schedule.blocked }}</p>
            </div>

            <div class="cs-section">Posted months</div>

            <p v-if="runs.length === 0" class="cs-sub">No month has been posted from a schedule yet.</p>

            <table v-else class="data-table">
                <thead>
                    <tr>
                        <th>Period</th>
                        <th>Charge</th>
                        <th>Due</th>
                        <th>Units</th>
                        <th>Total</th>
                        <th>Entry</th>
                        <th>Posted</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="run in runs" :key="run.id" :class="{ 'cs-reversed': run.reversed }">
                        <td>{{ run.period }}</td>
                        <td>{{ run.schedule }}</td>
                        <td>{{ run.due_on }}</td>
                        <td>{{ run.units }}</td>
                        <td class="bal-amt">{{ run.total }}</td>
                        <td>{{ run.journal_ref }}</td>
                        <td>
                            {{ run.posted }}
                            <div v-if="run.reversal" class="cs-sub">{{ run.reversal }}</div>
                        </td>
                        <td>
                            <template v-if="!run.reversed">
                                <input
                                    v-if="reversing === run.id"
                                    v-model="reason"
                                    class="cs-reason"
                                    type="text"
                                    maxlength="300"
                                    placeholder="Why is this month wrong?"
                                />
                                <button
                                    type="button"
                                    class="text-link-sm"
                                    :disabled="!canApprove"
                                    :title="canApprove ? 'Reverse the whole month: the mirror entry posts and its charges leave the ageing.' : reasons.approve"
                                    @click="reverse(run)"
                                >
                                    {{ reversing === run.id ? 'Confirm reversal' : 'Reverse' }}
                                </button>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal for the sheet's tabs and buttons, then AUTHORED: the schedule
 * panels, preview strip and notices, kept to the tokens the boards define.
 */
a {
    text-decoration: none;
}

button {
    font-family: inherit;
    cursor: pointer;
}

button.btn-primary-sm {
    border: 0;
}

button.text-link-sm {
    border: 0;
    background: none;
    padding: 0;
}

button[disabled] {
    cursor: not-allowed;
}

.cs-panel {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 14px;
    padding: 14px 16px;
    margin-bottom: 12px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.cs-head {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--navy-800);
    line-height: 1.55;
    max-width: 820px;
}

.cs-fields {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
}

.cs-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.cs-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
}

.cs-field input,
.cs-field select,
.cs-reason {
    height: 32px;
    border: 1px solid var(--navy-200);
    border-radius: 8px;
    background: var(--white);
    padding: 0 9px;
    font: inherit;
    font-size: 12px;
    color: var(--navy-900);
}

.cs-reason {
    margin-bottom: 4px;
    width: 220px;
}

.cs-actions,
.cs-top,
.cs-preview {
    display: flex;
    align-items: center;
    gap: 14px;
}

.cs-top,
.cs-preview {
    justify-content: space-between;
}

.cs-preview {
    background: var(--navy-100);
    border-radius: 10px;
    padding: 10px 12px;
}

.cs-title {
    font-size: 13.5px;
    font-weight: 700;
    color: var(--navy-900);
}

.cs-preview-head {
    font-size: 12px;
    font-weight: 700;
    color: var(--navy-900);
}

.cs-sub {
    font-size: 11px;
    color: var(--slate-600);
    line-height: 1.6;
    margin: 0;
}

.cs-stopped {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-600);
}

.cs-section {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-900);
    margin: 18px 0 8px;
}

.cs-reversed td {
    color: var(--slate-500);
}

.cs-flash,
.cs-error {
    font-size: 11.5px;
    font-weight: 600;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 12px;
}

.cs-flash {
    background: var(--success-100);
    color: var(--success-700);
}

.cs-error {
    background: var(--red-100);
    color: var(--red-700);
}
</style>
