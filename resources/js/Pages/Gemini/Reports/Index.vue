<script setup>
import { Head } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import ModuleIcon from '../../../Components/ModuleIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'

defineProps({
    mrr: { type: Object, required: true },
    revenueByTier: { type: Array, required: true },
    guardUtilisation: { type: Array, required: true },
})
</script>

<template>
    <Head title="Cross-tenant reports" />

    <GeminiConsole title="Cross-tenant reports">
        <div class="kpi-row">
            <div class="kpi-card">
                <div class="k-top"><div class="kpi-icon"><ModuleIcon module="billing_subscriptions" /></div></div>
                <div class="k-val">{{ mrr.total }}</div>
                <div class="k-lbl">Monthly recurring revenue</div>
            </div>
            <div class="kpi-card">
                <div class="k-top"><div class="kpi-icon"><ModuleIcon module="clients" /></div></div>
                <div class="k-val">{{ mrr.subscription_count }}</div>
                <div class="k-lbl">Active subscriptions</div>
            </div>
            <div class="kpi-card">
                <div class="k-top"><div class="kpi-icon"><ModuleIcon module="clients" /></div></div>
                <div class="k-val">{{ mrr.unit_count }}</div>
                <div class="k-lbl">Units under contract</div>
            </div>
            <div class="kpi-card">
                <div class="k-top"><div class="kpi-icon"><ModuleIcon module="guard_workforce" /></div></div>
                <div class="k-val">{{ guardUtilisation.reduce((n, r) => n + r.guards, 0) }}</div>
                <div class="k-lbl">Guards deployed</div>
            </div>
        </div>

        <!--
          Stated on the screen because it constrains what may ever be added
          here, not merely what is here today.
        -->
        <p class="reports-note">
            Every figure on this page is an aggregate drawn from platform data alone. None can be
            drilled down to an individual resident, and no report here queries an estate database.
        </p>

        <div class="report-grid">
            <section class="panel">
                <div class="panel-head"><h2>Revenue by tier</h2></div>

                <EmptyState
                    v-if="revenueByTier.length === 0"
                    variant="first-use"
                    title="No active subscriptions"
                    body="Revenue appears here once an estate's subscription becomes active. Estates in onboarding or dunning are excluded, since neither is billing at its contracted rate."
                />

                <table v-else class="data-table">
                    <thead>
                        <tr>
                            <th>Client</th>
                            <th>Tier</th>
                            <th>Units</th>
                            <th>Contribution</th>
                            <th>% of MRR</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in revenueByTier" :key="row.estate">
                            <td class="cell-strong">{{ row.estate }}</td>
                            <td>{{ row.tier }}</td>
                            <td class="cell-mono">{{ row.units }}</td>
                            <td class="cell-mono">{{ row.contribution }}</td>
                            <td class="cell-mono">{{ row.share }}%</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section class="panel">
                <div class="panel-head"><h2>Guard utilisation</h2></div>

                <EmptyState
                    v-if="guardUtilisation.length === 0"
                    variant="first-use"
                    title="No guards deployed"
                    body="Utilisation appears here once guards are posted to a client."
                />

                <table v-else class="data-table">
                    <thead>
                        <tr>
                            <th>Client</th>
                            <th>Guards</th>
                            <th>Active</th>
                            <th>On leave</th>
                            <th>Unpostable</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in guardUtilisation" :key="row.estate">
                            <td class="cell-strong">{{ row.estate }}</td>
                            <td class="cell-mono">{{ row.guards }}</td>
                            <td class="cell-mono">{{ row.active }}</td>
                            <td class="cell-mono">{{ row.on_leave }}</td>
                            <td class="cell-mono">
                                <span :class="{ 'unpostable-flag': row.unpostable > 0 }">{{ row.unpostable }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p class="table-note">
                    Unpostable counts guards whose licence has lapsed or who are suspended. They
                    are excluded from cover because they cannot lawfully stand a post.
                </p>
            </section>
        </div>
    </GeminiConsole>
</template>

<style scoped>
.reports-note {
    font-size: 12.5px;
    color: var(--slate-600);
    line-height: 1.6;
    max-width: 760px;
    margin-bottom: 18px;
}

.report-grid {
    display: grid;
    gap: 16px;
}

.table-note {
    font-size: 11.5px;
    color: var(--slate-500);
    line-height: 1.55;
    margin-top: 10px;
}

.unpostable-flag {
    color: var(--red-700);
    font-weight: 700;
}
</style>
