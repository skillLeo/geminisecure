<script setup>
import { computed } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'

/**
 * Client Detail — board screens super-admin-05 AND super-admin-09.
 *
 * ONE COMPONENT, TWO STATES, and that is the whole point of it.
 *
 * Board 05 draws Phoenix Park, a live client: what it is billed, who is posted
 * at its gates, what it has paid. Board 09 draws Ocean View, an estate still
 * being onboarded, and its panels are different — a checklist of what is left
 * to do, the plan as prepared rather than as billed, and the single contact who
 * has been named so far. Those are not two designs of the same screen. They are
 * the same screen answering a client that cannot answer the first set of
 * questions, and a client mid-onboarding rendered through the live panels is a
 * column of em dashes: no MRR, no invoices, no guards, and nothing saying why.
 *
 * So the lifecycle arrives from the server and this branches on it. Forking the
 * component would have been faster and would have meant that every later change
 * to the hero, the action stack or the panel chrome had to be made twice, with
 * one of the two silently drifting.
 *
 * DOM and class names are the board's, including the two inline `style`
 * attributes the board itself carries: the pill and the two-column grid.
 * Neither has a class in the board's stylesheet, and inventing one would be
 * authoring CSS the design does not have.
 *
 * Two things the LIVE board draws are not reproduced literally, and both for
 * the same reason — the figure does not exist in the central database and this
 * screen may not open an estate one:
 *
 *   "Units by phase"   phases are estate structure, held in the estate's own
 *                      database. The panel shows this client's platform
 *                      invoices instead, which is the same shape and is the
 *                      thing the primary action leads to.
 *   "Security add-on"  there is no per-guard add-on rate in the central
 *                      schema. The row shows the contracted unit count.
 *
 * The onboarding board adds a third of the same kind: it draws "Contract term —
 * 24 months", and nothing central records a term. That row shows the contract's
 * renewal date, which is stored and is the fact an operator acts on.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    canViewBilling: { type: Boolean, required: true },
    /** Whether this role may change this client rather than only read it. */
    canManage: { type: Boolean, required: true },
})

const isOnboarding = computed(() => props.estate.lifecycle === 'onboarding')

const state = useScreenState({
    /*
     * A detail screen holds one record, so "how many rows" is really "is there
     * anything recorded against this client". An onboarding one always has its
     * checklist; a live one that has never been given a plan, a contact, a
     * guard or an invoice is a bare provisioned estate and says so.
     */
    rows: () =>
        isOnboarding.value
            ? 1
            : props.estate.subscription.length +
              props.estate.invoices.length +
              props.estate.contacts.length +
              props.estate.guards.length,

    /*
     * Never true of its own accord. This screen draws no search and no filter —
     * the directory is where clients are narrowed — so empty-because-filtered
     * cannot happen here and is only ever reached by forcing it locally with
     * `?_state=empty-filtered`. It still renders, because the reviewer checking
     * all six states on every screen should see a sentence rather than a blank
     * page, and what it says is exactly that: the filter lives on the directory.
     */
    filtered: () => false,

    /*
     * The service hands back an empty payload when the estate row disappeared
     * between the controller resolving it and the panels being read. Rare, and
     * a blank hero with four em dashes would read as a client with no data
     * rather than as a page that failed.
     */
    failed: () => !props.estate.id,
})

/*
 * Marking onboarding complete is a real transition: it puts the client live and
 * starts its billing. useForm rather than a bare router.post so the button can
 * show that it is mid-flight and cannot be pressed twice, which on this action
 * would mean two attempts to activate the same subscription.
 */
const completion = useForm({})

const markComplete = () => {
    completion.post(`/clients/${props.estate.id}/onboarding/complete`, { preserveScroll: true })
}

/** Why the go-live button cannot be pressed, or null when it can. */
const completionBlockedBy = computed(() => {
    if (!props.canManage) {
        return 'Changing a client is not part of your role’s access'
    }

    return props.estate.onboarding?.blockedReason ?? null
})
</script>

<template>
    <Head :title="estate.name" />

    <GeminiConsole :title="estate.name" board="super-admin-01-login-dashboard-and-activity">
        <template #lead>
            <Link href="/clients" class="topbar-back" title="Back to clients" aria-label="Back to clients">
                <svg viewBox="0 0 24 24" fill="none">
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

        <SkeletonRows v-if="state.isLoading.value" :rows="9" :columns="4" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="This client is not yours to open"
            body="Your role is scoped to the sites you are assigned to, and this estate is not one of them. An Operations Manager or the Director can widen that scope."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="This client's record could not be read"
            body="The estate exists but its panels could not be loaded from the platform database. Nothing has been changed. Try again, and if it persists the platform database is the place to look."
        />

        <EmptyState
            v-else-if="state.isEmptyFiltered.value"
            variant="filtered"
            title="This screen has no filter"
            body="A client record shows one estate and everything recorded against it. Narrowing by name, tier or status happens on the client directory."
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="Nothing recorded against this client yet"
            body="The estate has been provisioned but has no plan, no committee accounts, no guards posted and nothing invoiced. Put it on a plan to start."
        />

        <template v-else>
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

                <!--
                  The action stack is the lifecycle's, not the screen's. An
                  onboarding client has nothing to bill and nobody posted, so
                  neither "View billing history" nor "Manage guard assignment"
                  has anything to open; what it has instead is the one act that
                  moves it forward.
                -->
                <div v-if="isOnboarding" class="action-stack">
                    <button
                        type="button"
                        class="stack-btn primary"
                        :disabled="completionBlockedBy !== null || completion.processing"
                        :title="completionBlockedBy ?? 'Put this client live and start its billing'"
                        @click="markComplete"
                    >
                        <BoardIcon name="check" :stroke="3" />
                        <span>Mark onboarding complete</span>
                    </button>

                    <Link :href="`/clients/${estate.id}/message`" class="stack-btn outline">
                        <BoardIcon name="broadcast" :stroke="1.7" />
                        <span>Message primary contact</span>
                    </Link>
                </div>

                <div v-else class="action-stack">
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

                    <Link :href="`/clients/${estate.id}/guards`" class="stack-btn outline">
                        <BoardIcon name="guards" :stroke="1.7" />
                        <span>Manage guard assignment</span>
                    </Link>

                    <Link :href="`/clients/${estate.id}/message`" class="stack-btn outline">
                        <BoardIcon name="broadcast" :stroke="1.7" />
                        <span>Message estate admin</span>
                    </Link>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px">
                <div>
                    <div v-if="isOnboarding" class="info-panel">
                        <div class="info-panel-head">Onboarding checklist</div>

                        <!--
                          Every step is derived from what is actually recorded,
                          never from a stored tick. A step this system cannot
                          answer carries its reason on the row rather than
                          quietly reading Pending forever.
                        -->
                        <div
                            v-for="step in estate.onboarding.checklist"
                            :key="step.label"
                            class="info-row2"
                            :title="step.note ?? undefined"
                        >
                            <span>{{ step.label }}</span>
                            <span>{{ step.value }}</span>
                        </div>
                    </div>

                    <div class="info-panel">
                        <div class="info-panel-head">{{ estate.subscriptionHead }}</div>

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

                    <div v-if="!isOnboarding" class="info-panel">
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
                    <!--
                      One contact while onboarding, the whole committee once
                      live. Listing six accounts against an estate that has
                      named one person makes the record look further along than
                      it is; naming one against a live client hides the
                      treasurer the accounts team actually needs.
                    -->
                    <div v-if="isOnboarding" class="info-panel">
                        <div class="info-panel-head">Primary contact</div>

                        <div v-for="row in estate.onboarding.primaryContact" :key="row.label" class="info-row2">
                            <span>{{ row.label }}</span>
                            <span>{{ row.value }}</span>
                        </div>
                    </div>

                    <template v-else>
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
                    </template>
                </div>
            </div>
        </template>
    </GeminiConsole>
</template>

<style scoped>
/*
 * The only authored CSS on this screen, and every rule removes a browser
 * default or reproduces an inline style the board itself carries. Nothing here
 * introduces a colour, a size or a spacing the design does not already declare.
 *
 * The board draws the action stack as three <div>s. Here one is a real link and
 * the rest are real buttons, so the anchor's underline and the button's border
 * would show through and change the pixels.
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

.action-stack button {
    cursor: pointer;
}

.action-stack button[disabled] {
    cursor: not-allowed;
}

/*
 * The back chevron. The board draws it as an inline-styled <div> because its
 * stylesheet has no class for it; those exact declarations are reproduced here
 * on a real <a> so the control can be clicked, focused and opened in a new tab.
 */
.topbar-back {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: var(--navy-100);
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
}

.topbar-back svg {
    width: 16px;
    height: 16px;
    color: var(--navy-700);
}
</style>
