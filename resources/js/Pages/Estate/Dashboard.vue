<script setup>
import { computed } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import EstateConsole from '../../Layouts/EstateConsole.vue'
import EmptyState from '../../Components/EmptyState.vue'
import SkeletonRows from '../../Components/SkeletonRows.vue'
import { useScreenState } from '../../composables/useScreenState'
import { useWireframe } from '../../composables/useWireframe'

/**
 * Dashboard — board screen community-admin-02.
 *
 * EVERY FIGURE HERE ALREADY HAD A HOME. The five KPI tiles, the phase bars and
 * the activity feed are all composed server-side from `Residents`, `Dues`,
 * `Maintenance` and the `Meeting` register — see `DashboardController`. This
 * page formats what it is given; it does not add anything up.
 *
 * TWO CONTENT RESIDUALS, drawn as the data says rather than as the board says:
 * the board's "441 Occupied units, 98%" cannot be reproduced — this estate is
 * seeded 450 units and 433 occupied households (D-056's class of residual) —
 * and the board's "↑ 4%" trend chip on Outstanding dues needs a prior-period
 * snapshot nothing in this schema stores, so it is simply absent. Both are
 * recorded in DECISIONS.md rather than invented here.
 *
 * MONEY ARRIVES AS MINOR UNITS AND IS FORMATTED HERE, exactly as Arrears.vue's
 * ageing cards format theirs — $1.84M, $412k — because it is the same kind of
 * figure read at the same kind of glance, not a resident's own invoice.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    kpis: { type: Array, required: true },
    arrears: { type: Object, required: true },
    activity: { type: Object, required: true },
    quickActions: { type: Object, required: true },
    notifications: { type: Object, required: true },
})

useWireframe('community-admin-01-login-dashboard-structure-and-residents')

/*
 * The one collection on this screen that can genuinely be empty — a brand
 * new estate with no payment, incident, verification, closed ticket or
 * booking to report yet. The KPI tiles and the arrears bars stay meaningful
 * either way, so only the activity panel's own emptiness drives `rows`; the
 * other five states are still forceable with `?_state=` for review, as every
 * screen's have to be.
 */
const state = useScreenState({
    rows: () => props.activity.rows.length,
})

const retry = () => router.reload()

/** The board's own abbreviation: $1.84M, $412k, $72 — two decimals on
 * millions, none on thousands, from integer minor units. */
const abbreviated = (minor) => {
    const value = Math.round(minor / 100)

    if (Math.abs(value) >= 1_000_000) {
        return `$${(value / 1_000_000).toFixed(2)}M`
    }

    if (Math.abs(value) >= 1_000) {
        return `$${Math.round(value / 1_000).toLocaleString('en-US')}k`
    }

    return `$${value.toLocaleString('en-US')}`
}

/** The KPI row, with the one figure that needs formatting formatted. */
const kpis = computed(() =>
    props.kpis.map((kpi) => ({
        ...kpi,
        display: kpi.key === 'dues' ? abbreviated(kpi.value_minor) : kpi.value,
    }))
)

const ICONS = {
    payment: '<path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/>',
    incident:
        '<path d="M12 9v4M12 17h.01" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/><path d="M10.3 3.9L2.5 18a1.8 1.8 0 0 0 1.6 2.7h15.8a1.8 1.8 0 0 0 1.6-2.7L13.7 3.9a1.8 1.8 0 0 0-3.4 0z" stroke="currentColor" stroke-width="1.7"/>',
    residents:
        '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="1.7"/><circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="1.7"/>',
    maintenance:
        '<path d="M14.7 6.3a3 3 0 1 0-4.2 4.2l-7 7 2.3 2.3 7-7a3 3 0 0 0 4.2-4.2l-2.1 2.1-2-2z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>',
    facility:
        '<rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M3 10h18M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>',
}
</script>

<template>
    <Head :title="estate.name" />

    <EstateConsole
        title="Dashboard"
        :estate-name="estate.name"
        active="dashboard"
        search-placeholder="Search residents, units, tickets…"
        profile
    >
        <template #actions>
            <!--
              Board draws a megaphone with a badge reading 3. There is no
              notification centre yet — this counts the same pending unit
              claims board 4's banner shows, which is a real figure the badge
              can carry honestly while the panel behind it is not built.
            -->
            <button type="button" class="top-icon-btn" disabled :title="notifications.reason">
                <svg viewBox="0 0 24 24" fill="none">
                    <path
                        d="M4 11v2a1 1 0 0 0 1 1h2l4 4V6L7 10H5a1 1 0 0 0-1 1z"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linejoin="round"
                    />
                    <path d="M17 8a5 5 0 0 1 0 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                </svg>
                <div class="top-badge">{{ notifications.count }}</div>
            </button>
        </template>

        <SkeletonRows v-if="state.isLoading.value" :rows="5" :columns="3" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="This dashboard is not part of your role's access"
            body="Every estate role reaches the dashboard; a role landing here with nothing to show is a permission fault worth reporting."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The dashboard could not be read"
            body="The estate database did not answer. Nothing has changed — this is a read that failed, and re-running it is safe."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="Nothing has happened here yet"
            body="No payment, incident, verification, closed ticket or booking is on record for this estate yet. The figures above are still real — they read as zero rather than as missing."
        />

        <template v-else>
            <div class="kpi-row">
                <div v-for="kpi in kpis" :key="kpi.key" class="kpi-card">
                    <div class="k-top">
                        <div class="kpi-icon">
                            <svg v-if="kpi.key === 'units'" viewBox="0 0 24 24" fill="none">
                                <path
                                    d="M3 21h18M5 21V7l7-4 7 4v14"
                                    stroke="currentColor"
                                    stroke-width="1.7"
                                    stroke-linejoin="round"
                                />
                            </svg>
                            <svg v-else-if="kpi.key === 'occupied'" viewBox="0 0 24 24" fill="none">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="1.7" />
                                <circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="1.7" />
                            </svg>
                            <svg v-else-if="kpi.key === 'dues'" viewBox="0 0 24 24" fill="none">
                                <rect x="2" y="6" width="20" height="14" rx="2" stroke="currentColor" stroke-width="1.7" />
                                <path d="M2 10h20" stroke="currentColor" stroke-width="1.7" />
                            </svg>
                            <svg v-else-if="kpi.key === 'maintenance'" viewBox="0 0 24 24" fill="none">
                                <path
                                    d="M14.7 6.3a3 3 0 1 0-4.2 4.2l-7 7 2.3 2.3 7-7a3 3 0 0 0 4.2-4.2l-2.1 2.1-2-2z"
                                    stroke="currentColor"
                                    stroke-width="1.6"
                                    stroke-linejoin="round"
                                />
                            </svg>
                            <svg v-else viewBox="0 0 24 24" fill="none">
                                <rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.7" />
                                <path d="M3 10h18M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                            </svg>
                        </div>

                        <!-- Only "Occupied units" carries a trend, and it is
                             a real derived percentage — the board's own "↑
                             4%" on Outstanding dues would need a prior-period
                             snapshot nothing stores, so that one is absent
                             rather than invented. -->
                        <div v-if="kpi.trend !== undefined && kpi.trend !== null" class="kpi-trend up">
                            {{ kpi.trend }}%
                        </div>
                    </div>
                    <div class="k-val">{{ kpi.display }}</div>
                    <div class="k-lbl">{{ kpi.label }}</div>
                </div>
            </div>

            <div class="main-grid">
                <div class="panel">
                    <div class="panel-head">
                        <h3>Arrears by phase</h3>
                        <Link v-if="arrears.ledgerHref" :href="arrears.ledgerHref" class="panel-link">View ledger</Link>
                        <button v-else type="button" class="panel-link" disabled :title="arrears.ledgerReason">
                            View ledger
                        </button>
                    </div>

                    <div class="bar-chart">
                        <div v-for="bar in arrears.phases" :key="bar.key" class="bar-item">
                            <div
                                class="bar"
                                :class="{ over: bar.over }"
                                :style="{ height: bar.height_pct + '%', position: 'relative' }"
                            >
                                <span class="bar-val">{{ abbreviated(bar.value_minor) }}</span>
                            </div>
                            <div class="bar-lbl">{{ bar.label }}</div>
                        </div>
                    </div>

                    <div class="qa-row">
                        <button type="button" class="qa-btn" disabled :title="quickActions.notice.reason">
                            <svg viewBox="0 0 24 24" fill="none">
                                <path
                                    d="M4 11v2a1 1 0 0 0 1 1h2l4 4V6L7 10H5a1 1 0 0 0-1 1z"
                                    stroke="currentColor"
                                    stroke-width="1.7"
                                    stroke-linejoin="round"
                                />
                                <path d="M17 8a5 5 0 0 1 0 8" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                            </svg>
                            <span>Post a notice</span>
                        </button>

                        <Link v-if="quickActions.addResident.href" :href="quickActions.addResident.href" class="qa-btn">
                            <svg viewBox="0 0 24 24" fill="none">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="1.7" />
                                <circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="1.7" />
                                <path d="M19 8v6M22 11h-6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                            </svg>
                            <span>Add resident</span>
                        </Link>
                        <button v-else type="button" class="qa-btn" disabled :title="quickActions.addResident.reason">
                            <svg viewBox="0 0 24 24" fill="none">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="1.7" />
                                <circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="1.7" />
                                <path d="M19 8v6M22 11h-6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                            </svg>
                            <span>Add resident</span>
                        </button>

                        <Link v-if="quickActions.newCharge.href" :href="quickActions.newCharge.href" class="qa-btn">
                            <svg viewBox="0 0 24 24" fill="none">
                                <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" />
                            </svg>
                            <span>New charge</span>
                        </Link>
                        <button v-else type="button" class="qa-btn" disabled :title="quickActions.newCharge.reason">
                            <svg viewBox="0 0 24 24" fill="none">
                                <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" />
                            </svg>
                            <span>New charge</span>
                        </button>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-head">
                        <h3>Recent activity</h3>
                        <button type="button" class="panel-link" disabled :title="activity.seeAllReason">See all</button>
                    </div>

                    <!--
                      No fixed row count: five real events or fewer, per the
                      controller's own rule against inventing a row to fill
                      the gap. `EmptyState` above already covers zero.
                    -->
                    <div v-for="(row, index) in activity.rows" :key="index" class="activity-row">
                        <div class="a-icon" :class="{ amber: row.amber }">
                            <svg viewBox="0 0 24 24" fill="none" v-html="ICONS[row.icon]"></svg>
                        </div>
                        <div class="a-txt">
                            <div class="at1">{{ row.title }}</div>
                            <div class="at2">{{ row.subtitle }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws the topbar bell, the two panel-head
 * links and every quick action as <div>/<a>; here they are real buttons and
 * links, and a <button> arrives wearing the browser's own font, a border and
 * buttonface grey. Nothing below introduces a colour, a size or a spacing the
 * board does not already declare — each rule either subtracts a UA default or
 * restates a property `.panel-head a` already carries, because that selector
 * cannot match a <button> and the board draws no other rule for this link.
 */
button {
    font-family: inherit;
    cursor: pointer;
}

button[disabled] {
    cursor: not-allowed;
}

button.top-icon-btn {
    border: 0;
    padding: 0;
}

button.qa-btn {
    border: 0;
    width: 100%;
}

/* `.panel-head a` is the board's only rule for this link; a <button> needs it
 * restated because the selector cannot reach a different tag. */
button.panel-link {
    border: 0;
    background: none;
    padding: 0;
    font-size: 12px;
    color: var(--amber-600);
    font-weight: 700;
}

a.panel-link {
    font-size: 12px;
    color: var(--amber-600);
    font-weight: 700;
    text-decoration: none;
}
</style>
