<script setup>
import { Head, Link } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'

/**
 * Statutory rate table — board screen super-admin-31.
 *
 * D-021 IS THE SUBJECT OF THIS SCREEN, not a caveat on it. The rates the
 * engine calculates with have never been checked against a worked example by
 * anyone qualified, so they are a DRAFT and no run may be approved against
 * them. The screen has to do two things at once: show the arithmetic honestly
 * enough that an accountant can check it, and say plainly that nobody has.
 *
 * What is deliberately absent is any means of approving them. There is no
 * approve control on this page and no route behind one — a guarded route is one
 * refactor away from being reachable.
 *
 * THE WORKED EXAMPLE IS A REAL PAYSLIP. A rate table whose example is invented
 * proves nothing: it demonstrates the arithmetic somebody typed rather than the
 * arithmetic the engine performed. Its six lines add up to that payslip's net
 * pay to the cent, and if they ever stop, the screen is reporting a real defect.
 */
const props = defineProps({
    table: { type: Object, default: null },
})

/*
 * The tab that has no screen of its own yet. Disabled and says why rather than
 * swallowing the click.
 */
const unbuilt = {
    employees: 'Available when the employee register ships — board screen super-admin-32',
}

const state = useScreenState({
    rows: () => (props.table === null ? 0 : 1),
})
</script>

<template>
    <Head title="Statutory rate table" />

    <GeminiConsole title="Statutory rate table">
        <!-- The four tabs in the board's own order. -->
        <div class="subnav">
            <Link href="/payroll" class="subnav-item">Pay runs</Link>
            <button type="button" class="subnav-item" disabled :title="unbuilt.employees">Employees</button>
            <Link href="/payroll/filings" class="subnav-item">Statutory filings</Link>
            <Link href="/payroll/rates" class="subnav-item active">Rate table</Link>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="6" :columns="3" />

        <!--
          No rate version has ever been recorded. A genuine first-use state, not
          an error: the engine cannot calculate until someone enters a rate set,
          and saying so beats a screen of em dashes.
        -->
        <EmptyState
            v-else-if="table === null"
            variant="first-use"
            title="No statutory rates on file"
            body="Pay runs are calculated against a dated set of NIS, NHT, Education Tax and PAYE rates. Until one is recorded there is nothing to apply and no run can be calculated."
        />

        <div v-else style="display: grid; grid-template-columns: 1.3fr 1fr; gap: 18px">
            <div class="rate-panel">
                <div class="rate-panel-head">Jamaica statutory deductions</div>
                <div class="rate-panel-sub">
                    Applied automatically to every pay run — {{ table.version.label }}, effective
                    {{ table.version.effective_from }}
                </div>

                <!--
                  Order is load-bearing and is the engine's own: NIS first,
                  because Education Tax and PAYE are both computed on income
                  after it. Listing them differently would describe a different
                  calculation from the one that produced the figures below.
                -->
                <div v-for="line in table.deductions" :key="line.key" class="rate-item">
                    <div class="rate-item-icon">
                        <BoardIcon :name="line.icon" :stroke="1.7" />
                    </div>
                    <div class="rate-item-txt">
                        <div class="rn">{{ line.name }}</div>
                        <div class="rd">{{ line.basis }}</div>
                    </div>
                    <div class="rate-item-val">{{ line.rate }}</div>
                </div>

                <div v-if="table.worked_example" class="example-box">
                    <div class="ex-head">{{ table.worked_example.head }}</div>
                    <div
                        v-for="(row, i) in table.worked_example.rows"
                        :key="i"
                        class="example-row"
                        :class="{ total: row.total }"
                    >
                        <span>{{ row.label }}</span>
                        <span>{{ row.value }}</span>
                    </div>
                </div>
            </div>

            <div class="rate-panel">
                <div class="rate-panel-head">PAYE threshold</div>
                <div class="rate-panel-sub">Determines when income tax applies</div>

                <div class="example-box">
                    <div
                        v-for="(row, i) in table.threshold.rows"
                        :key="i"
                        class="example-row"
                        :class="{ total: row.total }"
                    >
                        <span>{{ row.label }}</span>
                        <span>{{ row.value }}</span>
                    </div>
                </div>
            </div>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws every tab as a <div>; here three are
 * links and one is a disabled button, so the UA's underline and the button's
 * own border, face and font would show through. .subnav-item states everything
 * else, and the board's reset already zeroes padding and margin.
 */
a.subnav-item {
    text-decoration: none;
}

button.subnav-item {
    appearance: none;
    border: 0;
    background: transparent;
    font-family: inherit;
}

/* Inert, and it says why on hover. Not dimmed — the board draws every tab at
 * full weight, and disabled plus a title already rule out a silent click. */
button.subnav-item[disabled] {
    cursor: not-allowed;
}
</style>
