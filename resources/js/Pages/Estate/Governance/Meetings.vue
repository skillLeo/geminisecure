<script setup>
import { computed } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * The meeting register — board screen community-admin-36.
 *
 * THIS IS THE MODULE'S LANDING SCREEN. `EstateNavigation` points Governance's
 * sidebar item at `/governance/meetings` rather than at an election — an
 * election is addressed by year, so a constant would still be pointing at
 * 2026 in 2028, and the register is the one governance screen with a static
 * address that is never empty for an estate that has ever met.
 *
 * EVERY MEETING THE ESTATE HAS EVER HELD OR DRAFTED, in `Governance::meetingsBoard()`'s
 * own order — upcoming soonest-first, then past newest-first — which is the
 * only order a register reads sensibly in: the next thing a resident has to
 * turn up to, then the history behind it (D-052 fix #3, a `sortBy([...])`
 * comparator bug that had silently reordered this exact list).
 *
 * THIS IS WHERE A DRAFT BECOMES PUBLIC, AND NOWHERE ELSE. Board 12 only ever
 * saves a draft — `Governance::scheduleMeeting()`'s own docblock says so in
 * as many words — and `GovernanceController::publishMeeting()`'s says
 * publishing "is done from the register and nowhere else": the act belongs
 * on the one screen where the draft can be seen alongside the meetings
 * already announced. The redirect off board 12's own save says exactly that
 * — "saved as a draft. Publish it to notify households." — which is why the
 * Publish control below exists though board 36 itself never draws one: all
 * five of this estate's seeded meetings are already published, so that
 * branch renders on no row the fidelity check measures. It is `approve`, not
 * `update`, because a published meeting has already told every household in
 * its audience a date they will arrange their day around, and D-013 keeps
 * that a second pair of hands from the Secretary who drafted it.
 *
 * THE WEEKDAYS ARE DERIVED, NEVER STORED. Board 36 dates the AGM "Sat, Sep
 * 27" and the phase meeting "Sat, Oct 4" — both fall on a Sunday in this
 * estate's actual calendar (D-051 residual #5). `Meeting::dateLabel()` reads
 * the weekday off the stored `starts_at` rather than printing one this screen
 * chose, so nothing here can ever print a weekday the calendar contradicts.
 *
 * NO ROW ON THIS SCREEN TOUCHES A BALLOT. A meeting's quorum is counted from
 * `meeting_attendance` — who turned up to a sitting — which shares no table
 * and no column with `ballot_receipts` or `ballot_marks`. There is nothing
 * here that could be read, sorted or hovered to learn how a named household
 * voted, because nothing here is that vote at all.
 */
const props = defineProps({
    estate: { type: Object, required: true },

    /** Every meeting this estate has, already ordered and formatted. */
    rows: { type: Array, required: true },

    /** Which election the "Elections" tab leads to — the latest year, never today's. */
    electionYear: { type: Number, required: true },

    /*
     * Declared and deliberately not rendered. The topbar's "Schedule meeting"
     * link only ever opens board 12's own read, which needs no more than
     * `estate.governance.view` — the act this flag actually gates, saving a
     * draft, is refused there, with its own reason, rather than by hiding
     * the door to a screen that is safe for anyone with Governance access to
     * look at.
     */
    canSchedule: { type: Boolean, required: true },

    canPublish: { type: Boolean, required: true },
    publishReason: { type: String, required: true },
    reasons: { type: Object, required: true },
})

/*
 * Board 36 lives on the ninth Community Admin sheet, alongside Add Resident
 * and New Charge — not the sheet boards 9 to 12 share. Naming the wrong one
 * renders this screen with no table, no subnav and no status badges at all.
 */
useWireframe('community-admin-09-data-privacy-add-resident-and-meetings')

const page = usePage()

/*
 * Where this console is rooted, read off the page's own URL — production
 * gives each estate its own hostname and local puts the estate key in the
 * path, so cutting the URL that served this page is right in both and cannot
 * address an estate other than the one already open.
 */
const root = computed(() => {
    const cut = page.url.indexOf('/governance')

    return cut === -1 ? '' : page.url.slice(0, cut)
})

const governance = (suffix) => `${root.value}/governance${suffix}`

const newMeetingHref = computed(() => governance('/meetings/new'))
const electionsHref = computed(() => governance(`/elections/${props.electionYear}`))

/*
 * Five of the six. The board draws no search box and no filter chips — the
 * register is the whole register — so `empty-filtered` cannot occur here,
 * and `?_state=` forces the other five.
 */
const state = useScreenState({ rows: () => props.rows.length })

const retry = () => router.reload()

/* ------------------------------------------------------------------ */
/* publishing — the one act this screen performs */
/* ------------------------------------------------------------------ */

/** Why "Publish" cannot be pressed on an unpublished row, or null when it can. */
const publishBlockedBy = computed(() => (props.canPublish ? null : props.publishReason))

const publish = (id) => {
    if (publishBlockedBy.value !== null) {
        return
    }

    router.post(governance(`/meetings/${id}/publish`), {}, { preserveScroll: true })
}
</script>

<template>
    <Head title="Meetings" />

    <EstateConsole title="Meetings" :estate-name="estate.name" active="governance">
        <template #actions>
            <Link :href="newMeetingHref" class="btn-primary-sm">
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
                <span>Schedule meeting</span>
            </Link>
        </template>

        <SkeletonRows v-if="state.isLoading.value" :rows="5" :columns="6" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Meetings are not part of your role’s access"
            body="Governance covers the estate’s elections, its meetings and the record of both, so it opens only to roles that hold it. A committee officer or the estate administrator can grant it from the role access matrix."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The meeting register could not be read"
            body="The estate database did not answer. No meeting has been drafted, published or held since this screen last loaded — this is a read that failed, and re-running it is safe."
            action-label="Try again"
            @action="retry"
        />

        <template v-else>
            <!--
              What the last act did, and what it was refused for. Neither is
              on the board — a board is a still image and nothing has ever
              been pressed on it — and both are absent on a fresh GET, so
              neither touches this screen's geometry.
            -->
            <p v-if="page.props.flash?.success" class="gov-flash">{{ page.props.flash.success }}</p>
            <p v-if="page.props.errors?.starts_at" class="gov-refusal">{{ page.props.errors.starts_at }}</p>

            <!--
              Three tabs, one module. The tab for the screen the reader is
              already on is text rather than a control, because there is
              nowhere for it to lead. Notices is board 32 — built since this
              tab was drawn inert — and behind the same governance gate.
            -->
            <div class="subnav">
                <Link :href="governance('/notices')" class="subnav-item">Notices</Link>
                <div class="subnav-item active" aria-current="page">Meetings</div>
                <Link :href="electionsHref" class="subnav-item">Elections</Link>
            </div>

            <EmptyState
                v-if="state.isEmpty.value"
                variant="first-use"
                title="No meeting has ever been scheduled"
                body="Governance meetings — the AGM, an EGM, a committee sitting, a phase meeting — start on the scheduler and land here once saved. This estate has not held or drafted one yet."
                action-label="Schedule the first meeting"
                @action="router.get(newMeetingHref)"
            />

            <table v-else class="data-table">
                <thead>
                    <tr>
                        <th>Meeting</th>
                        <th>Type</th>
                        <th>Date</th>
                        <th>Audience</th>
                        <th>Quorum</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id">
                        <td>{{ row.title }}</td>
                        <td>{{ row.type_label }}</td>
                        <td>{{ row.date }}</td>
                        <td>{{ row.audience }}</td>
                        <td>
                            <div class="status-badge" :class="row.quorum_state">{{ row.quorum_label }}</div>
                        </td>
                        <td>
                            <!--
                              Publishing, drawn only for the draft board 36
                              never shows: all five seeded meetings are
                              already published, so this branch renders on no
                              row the fidelity check measures. It exists for
                              the secretary board 12's own flash message sends
                              here to find it.
                            -->
                            <button
                                v-if="!row.published"
                                type="button"
                                class="text-link-sm"
                                :disabled="publishBlockedBy !== null"
                                :title="
                                    publishBlockedBy ??
                                    `Publish ${row.title} to ${row.audience.toLowerCase()}. It tells every household in the audience a date they will arrange their day around, and it cannot be taken back.`
                                "
                                @click="publish(row.id)"
                            >
                                Publish
                            </button>

                            <!--
                              Status-driven, which is the board's own rule: an
                              upcoming meeting offers its agenda, a held one
                              its minutes. Both are inert for the same reason
                              — neither screen is built yet — and each carries
                              the real reason rather than a permission.
                            -->
                            <button
                                v-else
                                type="button"
                                class="text-link-sm"
                                disabled
                                :title="row.has_minutes ? reasons.minutes : reasons.agenda"
                            >
                                {{ row.action }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only, and each removal names the element that needs it.
 *
 * The board draws its topbar action, its subnav tabs and its row actions as
 * <div>s; here the topbar action and the "Notices" and "Elections" tabs are
 * real Links — app.css has already taken the UA's underline and blue off every
 * anchor on a board page, so none of them needs anything below — and the two
 * row actions are real buttons, which arrive wearing a border, buttonface grey
 * and the browser's own font. `.subnav-item` and `.text-link-sm` declare a
 * face and no border on this sheet, which is why both are safe to reset.
 */
button.subnav-item,
button.text-link-sm {
    border: 0;
    background: none;
    font: inherit;
    cursor: pointer;
}

/* The tab keeps the board's own padding; only its face is the browser's. */
button.subnav-item {
    padding: 9px 16px;
}

button[disabled] {
    cursor: not-allowed;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws no flash and no refusal, because
 * nothing has ever been pressed on it. Kept to the tokens the boards define
 * and to shapes they already use, exactly as the sibling governance screens'
 * `.gov-flash` / `.gov-refusal`.
 */
.gov-flash,
.gov-refusal {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 14px;
}

.gov-flash {
    background: var(--green-100);
    color: var(--green-700);
}

.gov-refusal {
    background: var(--red-100);
    color: var(--red-700);
}

/*
 * A quorum state the board never draws: a HELD meeting that did not reach
 * its quorum. `.upcoming` and `.done` are the board's own two classes on
 * this sheet; `.failed` is additive rather than a rule this overrides, kept
 * to the same red tokens `.gov-refusal` already uses.
 */
.status-badge.failed {
    background: var(--red-100);
    color: var(--red-700);
}
</style>
