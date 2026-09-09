<script setup>
import { Head, Link } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'

defineProps({
    run: { type: Object, required: true },
    payslips: { type: Array, required: true },
})
</script>

<template>
    <Head :title="run.period" />

    <GeminiConsole :title="`Payroll — ${run.period}`">
        <div v-if="run.blocked_reason" class="rates-alert">
            <strong>Approval blocked.</strong> {{ run.blocked_reason }}
        </div>

        <div class="panel rates-panel">
            <div class="panel-head">
                <h2>Statutory rates applied</h2>
                <Link href="/payroll">Back to payroll</Link>
            </div>
            <dl class="rates-grid">
                <div>
                    <dt>Rate version</dt>
                    <dd class="cell-mono">{{ run.rates.label }}</dd>
                </div>
                <div>
                    <dt>Verified</dt>
                    <dd>
                        <span class="status-badge" :class="run.rates.verified ? 'approved' : 'pending'">
                            {{ run.rates.verified ? 'Signed off' : 'Unverified' }}
                        </span>
                    </dd>
                </div>
                <div>
                    <dt>PAYE threshold (annual)</dt>
                    <dd class="cell-mono">{{ run.rates.paye_threshold_annual }}</dd>
                </div>
                <div>
                    <dt>PAYE threshold (per period)</dt>
                    <dd class="cell-mono">
                        {{ run.rates.paye_threshold_period }}
                        <span class="dd-note">annual ÷ {{ run.periods_per_year }} periods</span>
                    </dd>
                </div>
            </dl>
        </div>

        <table class="data-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Client</th>
                    <th>Gross</th>
                    <th>NIS</th>
                    <th>NHT</th>
                    <th>Ed. Tax</th>
                    <th>PAYE</th>
                    <th>Net</th>
                </tr>
            </thead>
            <tbody>
                <template v-for="slip in payslips" :key="slip.id">
                    <tr>
                        <td class="cell-strong">{{ slip.employee }}</td>
                        <td>{{ slip.estate }}</td>
                        <td class="cell-mono">{{ slip.gross }}</td>
                        <td class="cell-mono">{{ slip.nis }}</td>
                        <td class="cell-mono">{{ slip.nht }}</td>
                        <td class="cell-mono">{{ slip.education_tax }}</td>
                        <td class="cell-mono">{{ slip.paye }}</td>
                        <td class="cell-mono cell-strong">{{ slip.net }}</td>
                    </tr>
                    <!--
                      Most guards fall under the threshold, so a bare 0.00
                      reads as a bug. Naming the threshold turns it into an
                      explanation the person holding the payslip can act on.
                    -->
                    <tr v-if="slip.paye_note" class="note-row">
                        <td colspan="8">{{ slip.paye_note }}</td>
                    </tr>
                </template>
            </tbody>
        </table>
    </GeminiConsole>
</template>

<style scoped>
.rates-alert {
    background: var(--amber-100);
    color: var(--amber-700);
    border-radius: 12px;
    padding: 13px 16px;
    font-size: 12.5px;
    line-height: 1.55;
    margin-bottom: 16px;
}

.rates-panel {
    margin-bottom: 16px;
}

.rates-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
}

.rates-grid dt {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    text-transform: uppercase;
    letter-spacing: 0.3px;
    margin-bottom: 4px;
}

.rates-grid dd {
    font-size: 12.5px;
    color: var(--navy-900);
}

.dd-note {
    display: block;
    font-family: 'Inter', sans-serif;
    font-size: 10.5px;
    color: var(--slate-500);
    margin-top: 2px;
}

.note-row td {
    background: var(--navy-100);
    color: var(--slate-600);
    font-size: 11px;
    line-height: 1.5;
}
</style>
