<script setup>
import { computed } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * One household in full — board screen community-admin-38.
 *
 * THE HERO'S "BALANCE OWED" TILE IS ONE CELL, NOT TWO. `stats` carries the
 * whole `standing` object's `label` and `tone` for that tile and nothing else
 * of it — `value_minor` arrives on the payload and this page never reads it,
 * because the cell has to print whatever `standingOf()` decided a screen may
 * say, and reaching past `label` for a number would be exactly the second route
 * back to the figure the hard invariant exists to close. `EstateResidentsTest`
 * scans this whole payload for the vocabulary of money and for any digit at
 * all, against Lot 47's real balance.
 *
 * THE LEDGER CROSS-LINK IS ABSENT, NOT BLANKED, WHEN IT MAY NOT BE SHOWN.
 * `Residents::linkedActivity()` only ever appends the arrears row when the
 * standing it was built from actually carries a positive `balance_minor` —
 * which cannot happen for a restricted household or a viewer without
 * `estate.dues_ledger.view`, both of which stop `standingOf()` before a figure
 * exists to compare. So this template never decides whether to draw that row;
 * it draws exactly the rows `linked` contains.
 *
 * "VIEW LEDGER" IS DRAWN ONLY FOR A ROLE THAT HOLDS THE LEDGER — absent rather
 * than disabled, the same rule the sidebar itself follows for a module a role
 * cannot reach (D-055). Board 38's own persona is the Property Manager, whom
 * D-010 locks out of `dues_ledger` entirely; measured as a role that holds it,
 * this page draws the same three actions the board does, and the discrepancy
 * for the Property Manager specifically is D-044's general ruling rather than
 * a new one.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    resident: { type: Object, required: true },
    standing: { type: Object, required: true },
    stats: { type: Array, required: true },
    members: { type: Array, required: true },
    contact: { type: Array, required: true },
    linked: { type: Array, required: true },
    canViewLedger: { type: Boolean, required: true },

    /*
     * Declared and not rendered. `reasons.edit` says editing a resident is not
     * built yet FOR ANYBODY — the same shape as board 3's "Add phase" — so
     * there is no access-denied sentence this flag would ever choose between;
     * an undeclared prop on this fragment's root would fall through as a stray
     * HTML attribute rather than be ignored.
     */
    canEdit: { type: Boolean, required: true },
    reasons: { type: Object, required: true },
})

/*
 * Which board's stylesheet this page wears. Boards 3, 4 and 38 all live in the
 * first Community Admin sheet, and naming the wrong one — or none — renders
 * this screen with no board CSS at all.
 */
useWireframe('community-admin-01-login-dashboard-structure-and-residents')

const page = usePage()

/**
 * Five of the six. `empty` is a unit with no household on it —
 * `Residents::vacantStanding()` is what the server built this payload from —
 * and it is the one state real data can actually put this screen in: a lot the
 * boards address by reference rather than by resident can always be somebody
 * nobody has moved into. There is no filter on a detail screen, so
 * `empty-filtered` cannot occur from real data.
 */
const state = useScreenState({
    rows: () => props.members.length,
})

const retry = () => router.reload()

/*
 * Where this console is rooted, read off the page's own URL.
 *
 * Production gives each estate its own hostname and no prefix; local serves
 * every estate from one host with the estate key in the path, as
 * /estate/{key}/residents/lot-47. Cutting the current URL at /residents is
 * correct in both.
 */
const root = computed(() => page.url.slice(0, page.url.indexOf('/residents')))
const residentsPath = computed(() => `${root.value}/residents`)
const ledgerPath = computed(() => `${root.value}/finance/units/${props.resident.unit_id}`)

/** The hero's verification pill — green when verified, amber while pending. */
const verificationPillStyle = computed(() =>
    props.resident.verification === 'verified'
        ? 'font-size:10.5px;font-weight:700;color:var(--green-700);background:#DCFCE7;padding:5px 11px;border-radius:20px;'
        : 'font-size:10.5px;font-weight:700;color:var(--amber-700);background:var(--amber-100);padding:5px 11px;border-radius:20px;'
)

/** Which glyph a Linked activity row draws, by its own `type`. */
const linkIcon = (type) => (type === 'ticket' ? 'ticket' : type === 'booking' ? 'booking' : 'ledger')
</script>

<template>
    <Head :title="resident.name" />

    <EstateConsole :title="resident.name" :estate-name="estate.name" active="residents">
        <template #lead>
            <Link
                :href="residentsPath"
                style="width: 34px; height: 34px; border-radius: 50%; background: var(--navy-100); display: flex; align-items: center; justify-content: center; flex: 0 0 auto"
                title="Back to Residents"
                aria-label="Back to Residents"
            >
                <svg viewBox="0 0 24 24" fill="none" style="width: 16px; height: 16px; color: var(--navy-700)">
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

        <!-- The whole screen is one payload, so nothing on it arrives before the rest. -->
        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="2" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="This household is not part of your role’s access"
            body="A resident's detail names who holds a household, what it owes where a role may see that, and what is linked to it, so it opens only to roles that hold Residents. A committee officer or the estate administrator can grant it from the role access matrix."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="This household could not be read"
            body="The estate database did not answer. Nothing has been edited and no message has been sent — this is a read that failed, and re-running it is safe."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="No resident is on record at this unit"
            body="This lot has nobody on the register — a household is filed here the same way any other unit's is, through Add resident. What the unit itself owes is on its own ledger, addressed to the address rather than to a family that is not there."
            action-label="Back to Residents"
            @action="router.get(residentsPath)"
        />

        <template v-else>
            <div class="detail-head">
                <div class="hero-card">
                    <div class="hero-top">
                        <div>
                            <div class="hero-name">{{ resident.name }}</div>
                            <div class="hero-sub">{{ resident.sub }}</div>
                        </div>
                        <div :style="verificationPillStyle">{{ resident.verification_label }}</div>
                    </div>
                    <div class="hero-stats">
                        <div v-for="stat in stats" :key="stat.key" class="hero-stat">
                            <div class="hs-v" :class="{ 'hs-v-amber': stat.key === 'balance' && stat.tone === 'amber' }">
                                {{ stat.value ?? '—' }}
                            </div>
                            <div class="hs-l">{{ stat.label }}</div>
                        </div>
                    </div>
                </div>

                <div class="action-stack">
                    <Link v-if="canViewLedger" :href="ledgerPath" class="stack-btn primary">
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="2" y="6" width="20" height="14" rx="2" stroke="currentColor" stroke-width="1.7" />
                            <path d="M2 10h20" stroke="currentColor" stroke-width="1.7" />
                        </svg>
                        <span>View ledger</span>
                    </Link>

                    <button type="button" class="stack-btn outline" disabled :title="reasons.message">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path
                                d="M4 11v2a1 1 0 0 0 1 1h2l4 4V6L7 10H5a1 1 0 0 0-1 1z"
                                stroke="currentColor"
                                stroke-width="1.7"
                                stroke-linejoin="round"
                            />
                            <path d="M17 8a5 5 0 0 1 0 8" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                        </svg>
                        <span>Message household</span>
                    </button>

                    <button type="button" class="stack-btn outline" disabled :title="reasons.edit">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path
                                d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"
                                stroke="currentColor"
                                stroke-width="1.6"
                                stroke-linejoin="round"
                            />
                            <path
                                d="M18.5 2.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4z"
                                stroke="currentColor"
                                stroke-width="1.6"
                                stroke-linejoin="round"
                            />
                        </svg>
                        <span>Edit details</span>
                    </button>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px">
                <div>
                    <div class="info-panel">
                        <div class="info-panel-head">Household members</div>
                        <div v-for="row in members" :key="row.label" class="info-row2">
                            <span>{{ row.label }}</span><span>{{ row.value }}</span>
                        </div>
                    </div>
                    <div class="info-panel">
                        <div class="info-panel-head">Contact</div>
                        <div v-for="row in contact" :key="row.label" class="info-row2">
                            <span>{{ row.label }}</span><span>{{ row.value }}</span>
                        </div>
                    </div>
                </div>

                <div>
                    <div v-if="linked.length > 0" class="info-panel">
                        <div class="info-panel-head">Linked activity</div>
                        <div v-for="link in linked" :key="link.type + link.title" class="cross-link">
                            <svg v-if="linkIcon(link.type) === 'ledger'" viewBox="0 0 24 24" fill="none">
                                <rect x="2" y="6" width="20" height="14" rx="2" stroke="currentColor" stroke-width="1.6" />
                                <path d="M2 10h20" stroke="currentColor" stroke-width="1.6" />
                            </svg>
                            <svg v-else-if="linkIcon(link.type) === 'ticket'" viewBox="0 0 24 24" fill="none">
                                <path
                                    d="M14.7 6.3a3 3 0 1 0-4.2 4.2l-7 7 2.3 2.3 7-7a3 3 0 0 0 4.2-4.2l-2.1 2.1-2-2z"
                                    stroke="currentColor"
                                    stroke-width="1.6"
                                    stroke-linejoin="round"
                                />
                            </svg>
                            <svg v-else viewBox="0 0 24 24" fill="none">
                                <path
                                    d="M12 3v18M4 9c0-3.3 3.6-6 8-6s8 2.7 8 6"
                                    stroke="currentColor"
                                    stroke-width="1.6"
                                    stroke-linecap="round"
                                />
                                <path d="M4 9h16" stroke="currentColor" stroke-width="1.6" />
                            </svg>
                            <div class="cl-txt">
                                <div class="cl1">{{ link.title }}</div>
                                <div class="cl2">{{ link.sub }}</div>
                            </div>
                        </div>
                    </div>

                    <!--
                      Absent rather than blanked follows through to the panel
                      itself: a household with nothing linked (no arrears, no
                      open ticket, no booking) draws no empty "Linked activity"
                      card either, which is the board's own logic taken one
                      step further rather than a state the board had to draw.
                    -->
                    <div v-else class="info-panel">
                        <div class="info-panel-head">Linked activity</div>
                        <p class="no-linked-activity">Nothing is currently linked to this household.</p>
                    </div>
                </div>
            </div>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only, and each removal names the element that needs it.
 *
 * The board draws its back affordance and its three action-stack buttons as
 * <div>s; here they are a link and buttons, each arriving with the browser's
 * own font, border and buttonface grey. .stack-btn.primary declares a
 * background and no border; .stack-btn.outline declares both, and its border
 * IS the variant, so only the primary style is reset — see D-045.
 */
a.stack-btn.primary {
    text-decoration: none;
    border: 0;
}

button.stack-btn.outline {
    font: inherit;
    cursor: pointer;
}

button.stack-btn.outline[disabled] {
    cursor: not-allowed;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws no amber balance and no empty
 * "Linked activity" panel — Andrea Fletcher's is neither restricted nor
 * unlinked. Kept to the tokens the board does define: .hs-v is what the amber
 * variant overrides, and the empty note borrows .info-row2's own colour.
 */
.hs-v-amber {
    color: var(--amber-300);
}

.no-linked-activity {
    font-size: 11.5px;
    color: var(--slate-500);
    line-height: 1.6;
    padding: 4px 0;
}
</style>
