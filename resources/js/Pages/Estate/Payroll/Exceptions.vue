<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * Pre-run exceptions — board screen community-admin-14.
 *
 * FOUR BUTTONS, TWO OUTCOMES, AND THE DIFFERENCE IS SOMEBODY'S WAGES. "Enter
 * manually" and "Approve as-is" RESOLVE an exception, leaving the employee in
 * the run. "Exclude" leaves them OUT of it entirely — no payslip, no gross, no
 * net — and that is not the same act however similar the two buttons look. A
 * run that treated exclusion as resolution would pay somebody nothing and
 * report itself complete. `PayrollException` keeps them as separate states and
 * `EstatePayrollTest` asserts they are.
 *
 * "REVIEW HOURS" AND "ENTER MANUALLY" ARE INERT, and the reason is honest: both
 * need a timesheet this platform does not hold. What a manager can do today is
 * decide — accept the hours as logged, or take the person out of the run — and
 * both of those write. Drawing all four as live would be four buttons where two
 * work.
 *
 * THE BANNER COUNTS WHAT IS STILL BLOCKING, not what is on the screen. A run
 * whose last exception was just resolved says so without a reload, which is why
 * the count comes from the server on every render rather than from the row list.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    run: { type: Object, required: true },
    blocking: { type: Number, required: true },
    rows: { type: Array, required: true },
    canCalculate: { type: Boolean, required: true },
    calculateReason: { type: String, default: null },
    canResolve: { type: Boolean, required: true },
    reasons: { type: Object, required: true },
})

useWireframe('community-admin-04-payroll-runs-exceptions-and-filings')

const page = usePage()

const state = useScreenState({
    rows: () => props.rows.length,
})

const base = computed(() => page.url.split('/payroll')[0])
const busy = ref(null)

/**
 * The two acts that actually write, and which button is which.
 *
 * `accept` resolves the exception into the run — the hours stand as logged.
 * `exclude` takes the employee out of it. Both carry a note saying who decided
 * and why, because the reason a technician's 22 overtime hours went through
 * unchallenged is exactly what somebody asks about six months later.
 */
const decide = (row, outcome, note) => {
    busy.value = row.id

    router.post(
        `${base.value}/payroll/exceptions/${row.id}`,
        { outcome, note },
        { preserveScroll: true, onFinish: () => (busy.value = null) },
    )
}

/** Whether this particular button does anything, and what to say if it does not. */
const actionState = (row, action) => {
    if (row.resolved) {
        return { live: false, reason: 'Already dealt with. An exception is resolved once and the record kept.' }
    }

    if (!props.canResolve) {
        return { live: false, reason: props.reasons.resolve }
    }

    if (action.key === 'enter' || action.key === 'review') {
        return {
            live: false,
            reason: 'Not built yet — both of these need the timesheet itself, and this platform holds no '
                + 'hours. What you can decide today is whether the run proceeds with this person in it.',
        }
    }

    return { live: true, reason: null }
}

const runAction = (row, action) =>
    action.key === 'exclude'
        ? decide(row, 'excluded', 'Excluded from this run at the manager’s decision.')
        : decide(row, 'resolved', 'Hours accepted as logged.')
</script>

<template>
    <Head :title="`${run.period} — Exceptions`" />

    <EstateConsole :title="`${run.period} — Exceptions`" :estate-name="estate.name" active="payroll">
        <template #lead>
            <Link
                :href="`${base}/payroll`"
                style="width: 34px; height: 34px; border-radius: 50%; background: var(--navy-100); display: flex; align-items: center; justify-content: center; flex: 0 0 auto"
                title="Back to pay runs"
                aria-label="Back to pay runs"
            >
                <svg viewBox="0 0 24 24" fill="none" style="width: 16px; height: 16px; color: var(--navy-700)">
                    <polyline points="15 18 9 12 15 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </Link>
        </template>

        <SkeletonRows v-if="state.isLoading.value" :rows="2" :columns="3" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Payroll is not part of your role’s access"
            body="An exception names an employee and what is unresolved about their pay, so it opens only to a role that holds Payroll."
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="Nothing is holding this run up"
            body="Every timesheet is in and no figure looks unusual. The run can be calculated."
        />

        <template v-else>
            <div v-if="blocking > 0" class="exc-banner">
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 3.5 22 20H2z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" />
                    <path d="M12 10v4M12 17h.01" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" />
                </svg>
                <div>
                    <div class="eb1">
                        {{ blocking }} exception{{ blocking === 1 ? '' : 's' }} found — resolve before calculating
                    </div>
                    <div class="eb2">
                        This run can’t proceed to calculation until each item below is resolved or the employee is
                        excluded.
                    </div>
                </div>
            </div>

            <div class="exc-card">
                <div v-for="row in rows" :key="row.id" class="exc-row">
                    <div class="exc-icon">
                        <svg viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7" />
                            <path d="M12 7v5l3.5 2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                        </svg>
                    </div>

                    <div class="exc-txt">
                        <div class="ex1">{{ row.title }}</div>
                        <div class="ex2">{{ row.resolved ? row.resolution_note ?? row.detail : row.detail }}</div>
                    </div>

                    <div class="exc-actions">
                        <button
                            v-for="action in row.actions"
                            :key="action.key"
                            type="button"
                            class="exc-btn"
                            :class="action.primary ? 'resolve' : 'exclude'"
                            :disabled="!actionState(row, action).live || busy === row.id"
                            :title="actionState(row, action).reason ?? undefined"
                            @click="runAction(row, action)"
                        >
                            {{ action.label }}
                        </button>
                    </div>
                </div>
            </div>

            <div class="exc-foot">
                <button
                    type="button"
                    class="btn-outline-sm"
                    :disabled="!canCalculate"
                    :title="calculateReason ?? undefined"
                    @click="router.post(`${base}/payroll/runs/${run.slug}/calculate`, {}, { preserveScroll: true })"
                >
                    <span>{{ canCalculate ? 'Continue to calculation' : 'Continue to calculation (resolve all first)' }}</span>
                </button>
            </div>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws its back affordance, its four row pills
 * and its footer control as <div>s. `.exc-btn` and `.btn-outline-sm` declare
 * their own faces, and `.btn-outline-sm`'s border IS its variant, so it keeps
 * it — see D-045.
 */
button.exc-btn {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.btn-outline-sm {
    font: inherit;
    cursor: pointer;
}

button.exc-btn[disabled],
button.btn-outline-sm[disabled] {
    cursor: not-allowed;
    opacity: 0.5;
}

/*
 * AUTHORED BELOW THIS LINE. The board styles this card and its footer inline
 * rather than with named classes, so the same values are declared here.
 */
.exc-card {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    overflow: hidden;
}

.exc-foot {
    display: flex;
    justify-content: flex-end;
    margin-top: 18px;
}
</style>
