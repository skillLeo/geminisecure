<script setup>
import { Head, Link } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'

/**
 * Client Detail — board screen super-admin-05.
 *
 * DOM and class names are the board's, including the two inline `style`
 * attributes the board itself carries: the tier pill and the two-column grid.
 * Neither has a class in the board's stylesheet, and inventing one would be
 * authoring CSS the design does not have.
 *
 * Two things the board draws are not reproduced literally, and both for the
 * same reason — the figure does not exist in the central database and this
 * screen may not open an estate one:
 *
 *   "Units by phase"   phases are estate structure, held in the estate's own
 *                      database. The panel shows this client's platform
 *                      invoices instead, which is the same shape and is the
 *                      thing the primary action leads to.
 *   "Security add-on"  there is no per-guard add-on rate in the central
 *                      schema. The row shows the contracted unit count.
 */
defineProps({
    estate: { type: Object, required: true },
    canViewBilling: { type: Boolean, required: true },
})
</script>

<template>
    <Head :title="estate.name" />

    <GeminiConsole :title="estate.name" board="super-admin-01-login-dashboard-and-activity">
        <div class="detail-head">
            <div class="hero-card">
                <div class="hero-top">
                    <div>
                        <div class="hero-name">{{ estate.name }}</div>
                        <div class="hero-sub">{{ estate.subtitle }}</div>
                    </div>
                    <div
                        style="
                            font-size: 10.5px;
                            font-weight: 700;
                            color: var(--amber-700);
                            background: var(--amber-100);
                            padding: 5px 11px;
                            border-radius: 20px;
                        "
                    >{{ estate.tierLabel }}</div>
                </div>
                <div class="hero-stats">
                    <div v-for="stat in estate.stats" :key="stat.label" class="hero-stat">
                        <div class="hs-v">{{ stat.value }}</div>
                        <div class="hs-l">{{ stat.label }}</div>
                    </div>
                </div>
            </div>

            <div class="action-stack">
                <Link v-if="canViewBilling" href="/billing" class="stack-btn primary">
                    <BoardIcon name="billing" :stroke="1.7" />
                    <span>View billing history</span>
                </Link>
                <button
                    v-else
                    type="button"
                    class="stack-btn primary"
                    disabled
                    title="Billing &amp; subscriptions is not part of your role's access"
                >
                    <BoardIcon name="billing" :stroke="1.7" />
                    <span>View billing history</span>
                </button>

                <button
                    type="button"
                    class="stack-btn outline"
                    disabled
                    title="Available when the Manage Guard Assignment screen ships"
                >
                    <BoardIcon name="guards" :stroke="1.7" />
                    <span>Manage guard assignment</span>
                </button>

                <button
                    type="button"
                    class="stack-btn outline"
                    disabled
                    title="Available when the Message Estate Admin screen ships"
                >
                    <BoardIcon name="broadcast" :stroke="1.7" />
                    <span>Message estate admin</span>
                </button>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px">
            <div>
                <div class="info-panel">
                    <div class="info-panel-head">Subscription</div>

                    <EmptyState
                        v-if="!estate.subscription.length"
                        variant="first-use"
                        title="Not on a plan yet"
                        body="This estate is provisioned but has no subscription, so it has no units, no tier and no recurring revenue."
                    />

                    <div v-for="row in estate.subscription" :key="row.label" class="info-row2">
                        <span>{{ row.label }}</span>
                        <span>{{ row.value }}</span>
                    </div>
                </div>

                <div class="info-panel">
                    <div class="info-panel-head">Platform invoices</div>

                    <EmptyState
                        v-if="!estate.invoices.length"
                        variant="first-use"
                        title="Nothing invoiced yet"
                        body="Invoices appear here from the first billing period after the subscription starts."
                    />

                    <div v-for="row in estate.invoices" :key="row.label" class="info-row2">
                        <span>{{ row.label }}</span>
                        <span>{{ row.value }}</span>
                    </div>
                </div>
            </div>

            <div>
                <div class="info-panel">
                    <div class="info-panel-head">Estate contacts</div>

                    <EmptyState
                        v-if="!estate.contacts.length"
                        variant="first-use"
                        title="No committee accounts yet"
                        body="A contact appears here once an estate account is issued and assigned a committee role."
                    />

                    <div v-for="contact in estate.contacts" :key="contact.name" class="contact-row">
                        <div class="contact-avatar">{{ contact.initials }}</div>
                        <div>
                            <div class="cn">{{ contact.name }}</div>
                            <div class="cr">{{ contact.detail }}</div>
                        </div>
                    </div>
                </div>

                <div class="info-panel">
                    <div class="info-panel-head">Guards deployed</div>

                    <EmptyState
                        v-if="!estate.guards.length"
                        variant="first-use"
                        title="No guards posted here"
                        body="Either this estate runs its own security and buys the software alone, or it has not been staffed yet."
                    />

                    <div v-for="guard in estate.guards" :key="guard.name" class="contact-row">
                        <div class="contact-avatar">{{ guard.initials }}</div>
                        <div>
                            <div class="cn">{{ guard.name }}</div>
                            <div class="cr">{{ guard.detail }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/*
 * The only authored CSS on this screen, and both rules remove a browser default
 * rather than adding a style.
 *
 * The board draws the action stack as three <div>s. Here the first is a real
 * link and the other two are real buttons, so the anchor's underline and the
 * button's border would show through and change the pixels.
 *
 * Note what is deliberately absent. There is no padding, background or colour
 * reset: .stack-btn and .stack-btn.outline already set all three, and a rule
 * here would out-specify the board and undo it. The border reset is written
 * against the bare element for the same reason — .stack-btn.outline's own
 * border is the more specific rule and still wins, which is exactly what should
 * happen, while the primary button, which the board gives no border, loses the
 * browser's.
 */
button {
    border: 0;
    font: inherit;
}

.action-stack a {
    text-decoration: none;
}
</style>
