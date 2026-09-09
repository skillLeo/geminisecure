<script setup>
import { Head, Link } from '@inertiajs/vue3'
import GeminiConsole from '../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../Components/BoardIcon.vue'
import EmptyState from '../../Components/EmptyState.vue'

/**
 * Platform Dashboard — board screen super-admin-02.
 *
 * DOM and class names are the board's. The inline grid style on the two-panel
 * row is the board's too, kept inline rather than promoted to a class,
 * because the board's stylesheet defines no class for it and inventing one
 * would be authoring CSS the design does not have.
 */
defineProps({
    kpis: { type: Array, required: true },
    tiers: { type: Array, required: true },
    activity: { type: Array, required: true },
})
</script>

<template>
    <Head title="Dashboard" />

    <GeminiConsole title="Dashboard" board="super-admin-01-login-dashboard-and-activity">
        <div class="kpi-row">
            <div v-for="kpi in kpis" :key="kpi.key" class="kpi-card">
                <div class="k-top">
                    <div class="kpi-icon">
                        <BoardIcon :name="kpi.icon" :stroke="1.7" />
                    </div>
                    <div v-if="kpi.trend" class="kpi-trend up">{{ kpi.trend }}</div>
                </div>
                <div class="k-val">{{ kpi.value }}</div>
                <div class="k-lbl">{{ kpi.label }}</div>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1.4fr 1fr; gap: 18px">
            <div class="panel">
                <div class="panel-head">
                    <h3>MRR by subscription tier</h3>
                    <Link href="/billing">View billing</Link>
                </div>

                <EmptyState
                    v-if="tiers.length === 0"
                    variant="first-use"
                    title="No active subscriptions"
                    body="Tier revenue appears once a client is on a plan."
                />

                <div v-for="tier in tiers" :key="tier.key" class="tier-bar-row">
                    <div class="tier-dot" :class="tier.key"></div>
                    <div class="tier-lbl">{{ tier.label }}</div>
                    <div class="tier-track">
                        <i :class="tier.key" :style="{ width: tier.percent + '%' }"></i>
                    </div>
                    <div class="tier-val">{{ tier.amount }}</div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <h3>Recent activity</h3>
                    <Link href="/audit">See all</Link>
                </div>

                <EmptyState
                    v-if="activity.length === 0"
                    variant="first-use"
                    title="Nothing yet"
                    body="Onboarding, billing and deployment events appear here as they happen."
                />

                <div v-for="(event, i) in activity" :key="i" class="activity-row">
                    <div class="a-icon">
                        <BoardIcon :name="event.icon" :stroke="1.6" />
                    </div>
                    <div class="a-txt">
                        <div class="at1">{{ event.title }}</div>
                        <div class="at2">{{ event.meta }}</div>
                    </div>
                </div>
            </div>
        </div>
    </GeminiConsole>
</template>
