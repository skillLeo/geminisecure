<script setup>
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * Compliance calendar — board 16's "Compliance calendar" (12 §2, Wave 4).
 *
 * NO BOARD DRAWS THIS SCREEN, and it is not a fidelity target. It is built in
 * board 16's own sheet, under the payroll tabs, so it reads as the register's
 * second view.
 *
 * EVERY ROW SAYS WHERE IT CAME FROM. A return on the register carries the due
 * date it was given; a projected S01 or S02 is dated by the rule the register's
 * own dates follow and is a reminder, not a return. Nothing on this screen
 * writes anything — filing is done from the register, by a role that holds
 * Payroll approval.
 *
 * NO DATE IS GUESSED. The GCT return and the annual P24 appear when the register
 * holds them and are never projected, and the page says so.
 */
defineProps({
    estate: { type: Object, required: true },
    months: { type: Array, required: true },
    projects: { type: Boolean, required: true },
    overdue: { type: Number, required: true },
    tabs: { type: Array, required: true },
})

useWireframe('community-admin-04-payroll-runs-exceptions-and-filings')

const page = usePage()

const base = computed(() => page.url.split('/payroll')[0])
</script>

<template>
    <Head title="Compliance calendar" />

    <EstateConsole title="Compliance calendar" :estate-name="estate.name" active="payroll">
        <template #lead>
            <Link
                :href="`${base}/payroll/filings`"
                style="width:34px;height:34px;border-radius:50%;background:var(--navy-100);display:flex;align-items:center;justify-content:center;flex:0 0 auto;"
                title="Back to statutory filings"
                aria-label="Back to statutory filings"
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

        <div class="subnav">
            <template v-for="tab in tabs" :key="tab.key">
                <Link
                    :href="tab.key === 'runs' ? `${base}/payroll` : `${base}/payroll/${tab.key}`"
                    class="subnav-item"
                    :class="{ active: tab.active }"
                >
                    {{ tab.label }}
                </Link>
            </template>
        </div>

        <p class="cc-note">
            Returns on the register keep the date they were given. The monthly S01 and S02 are projected for the
            periods the register does not hold yet, due on the 14th of the month after the period — a reminder, not a
            return. The GCT return and the annual P24 appear once they are on the register; no date is projected for
            either.
        </p>

        <div v-if="overdue > 0" class="cc-alert">
            {{ overdue }} {{ overdue === 1 ? 'return is' : 'returns are' }} past due. File from the statutory filings
            register.
        </div>

        <EmptyState
            v-if="months.length === 0"
            variant="first-use"
            title="Nothing falls due"
            :body="projects
                ? 'No return is outstanding and none is due in the year ahead.'
                : 'This estate has run no payroll and has no active employee, so it owes no monthly returns. Returns appear here once it does.'"
        />

        <div v-for="month in months" v-else :key="month.label" class="cc-month">
            <div class="cc-month-head">{{ month.label }}</div>

            <div class="filing-card">
                <div v-for="row in month.rows" :key="row.key" class="filing-row">
                    <div class="filing-txt">
                        <div class="ft1">{{ row.title }}</div>
                        <div class="ft2">{{ row.period }}</div>
                    </div>

                    <div class="filing-due">{{ row.due }}</div>

                    <div
                        class="status-badge"
                        :class="{
                            approved: row.state === 'filed',
                            pending: row.state === 'outstanding',
                            exceptions: row.state === 'overdue',
                            draft: row.state === 'projected',
                        }"
                    >
                        {{ row.state_label }}
                    </div>
                </div>
            </div>
        </div>
    </EstateConsole>
</template>

<style scoped>
/*
 * AUTHORED. No board draws this screen. The rows, badges and tabs are board 16's
 * own classes; the month heading, the note and the alert are kept to the tokens
 * the boards define.
 */
a.subnav-item {
    text-decoration: none;
}

.filing-card {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    overflow: hidden;
}

.cc-note {
    font-size: 11px;
    color: var(--slate-600);
    line-height: 1.6;
    margin: 0 0 14px;
    max-width: 840px;
}

.cc-alert {
    background: var(--red-100);
    color: var(--red-700);
    font-size: 12px;
    font-weight: 700;
    border-radius: 10px;
    padding: 9px 13px;
    margin-bottom: 14px;
}

.cc-month {
    margin-bottom: 16px;
}

.cc-month-head {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-900);
    margin-bottom: 8px;
}
</style>
