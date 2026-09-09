<script setup>
import { Head } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'

/**
 * Guard profile — board screen super-admin-19.
 *
 * DOM and class names are the board's, including the inline grid style on the
 * two-column row, which is the board's own and is kept inline rather than
 * promoted to a class the design does not define.
 *
 * Two of the board's values have no column behind them and are not invented
 * here: the hero's standard hourly rate is replaced by the gross on the guard's
 * most recent payslip, and the employment panel's bank account and NIS number
 * by the employee number and email that the record actually holds.
 *
 * The status pill uses the board's own .status-badge classes rather than the
 * hard-coded green inline style the board carries. That style says Active in
 * green whatever the guard's status is, which on a suspended officer would be
 * a lie told in the most prominent place on the screen.
 */
defineProps({
    guard: { type: Object, required: true },
    history: { type: Array, required: true },
})
</script>

<template>
    <Head :title="guard.name" />

    <GeminiConsole :title="guard.name">
        <div class="detail-head">
            <div class="hero-card">
                <div class="hero-top">
                    <div>
                        <div class="hero-name">{{ guard.name }}</div>
                        <div class="hero-sub">{{ guard.subtitle }}</div>
                    </div>
                    <div class="status-badge" :class="guard.status_badge">{{ guard.status_label }}</div>
                </div>
                <div class="hero-stats">
                    <div v-for="stat in guard.stats" :key="stat.label" class="hero-stat">
                        <div class="hs-v">{{ stat.value }}</div>
                        <div class="hs-l">{{ stat.label }}</div>
                    </div>
                </div>
            </div>
            <div class="action-stack">
                <!--
                  Nothing writes a guard's posting yet: there is no reassignment
                  route and no screen behind one. Disabled and saying why, rather
                  than a button that looks live and does nothing.
                -->
                <button
                    type="button"
                    class="stack-btn primary"
                    disabled
                    title="Available when guard reassignment ships with the shift roster"
                >
                    <BoardIcon name="guards" :stroke="1.7" />
                    <span>Reassign client</span>
                </button>

                <a v-if="guard.contact_href" :href="guard.contact_href" class="stack-btn outline">
                    <BoardIcon name="broadcast" :stroke="1.7" />
                    <span>Contact guard</span>
                </a>
                <button
                    v-else
                    type="button"
                    class="stack-btn outline"
                    disabled
                    title="No phone number or email address is on this guard's record"
                >
                    <BoardIcon name="broadcast" :stroke="1.7" />
                    <span>Contact guard</span>
                </button>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px">
            <div>
                <div class="info-panel">
                    <div class="info-panel-head">Employment</div>
                    <div v-for="row in guard.employment" :key="row.label" class="info-row2">
                        <span>{{ row.label }}</span>
                        <span>{{ row.value }}</span>
                    </div>
                </div>
                <div class="info-panel">
                    <div class="info-panel-head">Current assignment</div>
                    <div v-for="row in guard.assignment" :key="row.label" class="info-row2">
                        <span>{{ row.label }}</span>
                        <span>{{ row.value }}</span>
                    </div>
                </div>
            </div>
            <div>
                <div class="tl2">
                    <div class="info-panel-head">Deployment history</div>
                    <div v-for="(event, i) in history" :key="i" class="tl2-row">
                        <div class="tl2-dot" :class="{ danger: event.danger }"></div>
                        <div class="tl2-txt">
                            <div class="tt1">{{ event.title }}</div>
                            <div class="tt2">{{ event.meta }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/*
 * The board draws both action-stack buttons as <div>s. One is a real link that
 * dials or mails the guard, the other a disabled button until reassignment
 * exists. These rules take back off only what the browser adds — the link's
 * underline, the button's font family, and the border on the one variant whose
 * board rule declares none. Everything visible still comes from .stack-btn.
 *
 * The border reset names .primary rather than .stack-btn because a scoped
 * element selector outranks a single class: `button.stack-btn { border: 0 }`
 * would beat .stack-btn.outline and strip the outline variant of the 1.5px
 * edge that is the whole difference between the two.
 */
a {
    text-decoration: none;
}

button {
    font-family: inherit;
}

button.stack-btn.primary {
    border: 0;
}
</style>
