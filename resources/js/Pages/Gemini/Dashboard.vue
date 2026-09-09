<script setup>
import { computed, ref, onMounted, onBeforeUnmount } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
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

const page = usePage()
const user = computed(() => page.props.auth.user)

const initials = computed(() =>
    (user.value?.name ?? '')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase()
)

const menuOpen = ref(false)
const signOut = () => router.post('/logout')

const closeOnOutside = (event) => {
    if (!event.target.closest('.top-profile')) {
        menuOpen.value = false
    }
}

onMounted(() => document.addEventListener('click', closeOnOutside))
onBeforeUnmount(() => document.removeEventListener('click', closeOnOutside))
</script>

<template>
    <Head title="Dashboard" />

    <GeminiConsole
        title="Dashboard"
        board="super-admin-01-login-dashboard-and-activity"
        search-disabled-reason="Global search arrives with the client and guard directories. Search within a list from that list's own screen."
    >
        <!--
          This board is the ONLY one of the forty-five that draws a
          notification bell and a profile chip in the top bar, so they are
          passed from here rather than built into the layout.
        -->
        <template #actions>
            <button
                type="button"
                class="top-icon-btn"
                disabled
                title="Notifications arrive with the alert feed"
            >
                <svg viewBox="0 0 24 24" fill="none">
                    <path
                        d="M4 11v2a1 1 0 0 0 1 1h2l4 4V6L7 10H5a1 1 0 0 0-1 1z"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linejoin="round"
                    />
                    <path d="M17 8a5 5 0 0 1 0 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                </svg>
            </button>

            <!--
              The chevron promises a menu, so it opens one. Sign-out also
              lives on the sidebar footer, which every board draws — this is
              the second way to reach it, not the only one.
            -->
            <button type="button" class="top-profile" :aria-expanded="menuOpen" aria-haspopup="menu" @click="menuOpen = !menuOpen">
                <div class="tp-avatar">{{ initials }}</div>
                <div>
                    <div class="tp-name">{{ user?.name }}</div>
                    <div class="tp-role">{{ user?.role_label }}</div>
                </div>
                <svg viewBox="0 0 24 24" fill="none">
                    <polyline
                        points="6 9 12 15 18 9"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                </svg>

                <div v-if="menuOpen" class="tp-menu" role="menu">
                    <button type="button" role="menuitem" @click.stop="signOut">Sign out</button>
                </div>
            </button>
        </template>

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
                    <!-- The full feed, not the audit log. Those are different
                         records with different rules. -->
                    <Link href="/dashboard/activity">See all</Link>
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

<style scoped>
/*
 * Default-removal only, for the two board <div>s that had to become real
 * controls. The board's own .top-icon-btn and .top-profile rules supply
 * everything visual; these take off what the browser adds to a <button>.
 */
.top-icon-btn,
.top-profile {
    border: 0;
    font: inherit;
    color: inherit;
    text-align: left;
    cursor: pointer;
}

.top-icon-btn[disabled] {
    cursor: not-allowed;
}

.top-profile {
    position: relative;
}

.tp-menu {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    z-index: 20;
    min-width: 150px;
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 10px;
    box-shadow: 0 12px 28px rgba(11, 31, 51, 0.16);
    padding: 6px;
}

.tp-menu button {
    display: block;
    width: 100%;
    border: 0;
    background: transparent;
    font: inherit;
    color: var(--navy-900);
    text-align: left;
    padding: 8px 10px;
    border-radius: 7px;
    cursor: pointer;
}

.tp-menu button:hover {
    background: var(--navy-100);
}
</style>
