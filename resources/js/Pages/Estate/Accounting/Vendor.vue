<script setup>
import { computed } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * Vendor detail — board screen community-admin-39.
 *
 * ONE SUPPLIER'S ACCOUNTS-PAYABLE SUB-LEDGER. The hero is what the estate has
 * paid them and what it still owes; the table beneath is the bills those figures
 * came out of.
 *
 * THE HERO IS NOT THE SUM OF THE ROWS, AND MUST NOT BE. "Currently owed" is this
 * vendor's balance on 2000 Accounts Payable and "Paid YTD" is the debits on the
 * same account raised by payments — both summed by `Payables` over every bill
 * this vendor ever had, while the table is a recent-history view. The board says
 * so itself: it draws four bills and eleven jobs completed. A page that added up
 * the rows on screen would produce a smaller, plausible, wrong number, and it
 * would be wrong by however much history the table did not show.
 *
 * THE TRN IS A PAYMENT RULE, NOT A VALIDATION. A vendor with no taxpayer
 * registration number on file may be billed all day — refusing to record their
 * invoices would understate what the estate owes, which is the one thing a
 * payables ledger exists to state. What may not happen is money moving, and
 * `Payables::pay()` checks it FIRST, before anything about the bill, because it
 * is the only refusal on that path nobody at this estate can clear. So this
 * screen states it as a consequence in front of the vendor's own controls rather
 * than as an error on a record that is perfectly valid.
 *
 * NO DELETE, AND NOT BECAUSE THE BOARD OMITTED ONE. A vendor with bills against
 * them is history the estate has to keep, and `bills.vendor_id` restricts at the
 * database to enforce it. The board draws exactly two actions — record a bill and
 * edit the vendor — and taking a supplier off the register is a status change
 * that belongs on the edit screen `reasons.edit` names, beside the TRN rule.
 *
 * CONTENT RESIDUAL, recorded and not a styling fault: the board draws four bills
 * for Island Electric — one unpaid and three settled — and the seed carries the
 * one open bill board 27 specifies. The three paid ones, the $142,300 paid to
 * date and the eleven completed jobs are history no board itemises, and posting
 * invented payments through the ledger to reach them would put money through the
 * estate's accounts that nobody asked for. The screen renders what the data says.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    vendor: { type: Object, required: true },
    stats: { type: Array, required: true },
    bills: { type: Array, required: true },
    reasons: { type: Object, required: true },
})

/*
 * Board 39 lives in the Payroll/Details/Billing sheet, not the Accounting one
 * its module sits in. The ten Estate Console boards do not share a stylesheet,
 * so a page names the board it reproduces rather than the module it belongs to —
 * naming board 26's sheet here would render this screen with no hero card, no
 * action stack and no detail head at all.
 */
useWireframe('community-admin-10-payroll-employees-details-and-billing')

const page = usePage()

/*
 * Five of the six. A vendor's own sub-ledger carries no filter and no search —
 * there is no query that could have excluded the bills — so `empty-filtered`
 * cannot occur, and drawing a "clear the filter" panel on a screen with no
 * filter would invent a control to explain a state that does not exist.
 */
const state = useScreenState({
    rows: () => props.bills.length,
})

const retry = () => router.reload()

/*
 * Where this console is rooted, read off the page's own URL — the same cut board
 * 26 makes. Production gives each estate its own hostname and no prefix; local
 * serves every estate from one host as /estate/{key}/accounting/vendors/{id}, so
 * a hard-coded root is correct in exactly one of the two.
 */
const root = computed(() => page.url.slice(0, page.url.indexOf('/accounting')))

/** The register, which is the screen the reader pressed "View" on. */
const backHref = computed(() => `${root.value}/accounting/vendors`)

/**
 * The board's money: whole dollars, comma-grouped, no decimals — board 26's
 * formatter, unchanged, because these are the same figures read from the same
 * account and a supplier quoted $142,300 on one screen and $142.3k on the next
 * has been quoted two numbers.
 *
 * Minor units in, dollars out, and nothing is added up after the divide.
 */
const exact = (minor) => `$${(minor / 100).toLocaleString('en-JM', { maximumFractionDigits: 0 })}`

/**
 * The hero strip carries four figures and only two of them are money.
 *
 * That is the board's, not an inconsistency to tidy away: "Paid YTD" and
 * "Currently owed" are amounts, "Jobs completed" is a count and "Vendor since"
 * is a month. The server sends money as `value_minor` and the other two as
 * `value` precisely so the page does not have to guess which is which — a count
 * of 11 run through a currency formatter reads "$11", which is a smaller lie
 * than it looks and a completely different fact.
 *
 * `'value_minor' in stat` rather than a truthiness test, because a vendor paid
 * nothing this year has a value_minor of 0 and still needs a dollar sign.
 */
const figure = (stat) => ('value_minor' in stat ? exact(stat.value_minor) : stat.value)

/**
 * The board's status pill, and the one state it does not draw.
 *
 * .status-badge.active is the only variant board 10's sheet defines, so the
 * others are declared inline exactly as the board declares its own Unpaid
 * override. Amber for a bill still owed, red for one whose due date has passed,
 * neutral for a voided one — a void bill is a correction rather than a failure,
 * and colouring it red would put a settled decision in the same visual bucket as
 * an unpaid debt.
 */
const badgeStyle = (status) => {
    if (status === 'paid') {
        return undefined
    }

    if (status === 'overdue') {
        return 'background:var(--red-100);color:var(--red-700);'
    }

    if (status === 'void') {
        return 'background:var(--navy-100);color:var(--slate-600);'
    }

    return 'background:var(--amber-100);color:var(--amber-700);'
}

/**
 * The consequence of a missing TRN, said in front of the controls it blocks.
 *
 * Deliberately phrased as what happens next rather than as what is wrong. There
 * is nothing wrong: the vendor is on the register, their bills post, and their
 * balance on 2000 is real money the estate owes. The refusal arrives at payment
 * and is cleared by paperwork from the supplier, which is not something anybody
 * can type into this screen.
 */
const NO_TRN =
    'No taxpayer registration number on file. Bills from this vendor are still recorded and still owed — the next payment to them is what will be refused, until the TRN is on the vendor record.'

/**
 * Why the pill is green, or is not.
 *
 * The board hard-codes the green Active pill inline, because it draws an active
 * vendor. A deactivated one is neutral rather than red: deactivation is the only
 * way a supplier ever leaves the register — one with bills against them cannot
 * be deleted — so it is a fact about the relationship, not a fault.
 */
const pillStyle = computed(() =>
    props.vendor.status === 'active'
        ? 'font-size:10.5px;font-weight:700;color:var(--green-700);background:var(--success-100);padding:5px 11px;border-radius:20px;'
        : 'font-size:10.5px;font-weight:700;color:var(--slate-600);background:var(--navy-100);padding:5px 11px;border-radius:20px;'
)
</script>

<template>
    <Head :title="vendor.name" />

    <EstateConsole :title="vendor.name" :estate-name="estate.name" active="accounting">
        <!--
          The back chevron. This board draws it as the 34px navy circle the other
          estate detail screens use — NOT the border-stripped .btn-outline-sm of
          board 6 — and its declarations live inline because the sheet has no
          class for it. They are copied verbatim onto a real link, so the control
          can be clicked, focused and opened in a new tab.

          `flex:0 0 auto` is the one addition: the board's <div> sits in a flex
          topbar with room to spare, and an anchor in the same row would shrink
          below 34px on a narrow viewport the board never had to survive.

          No disabled twin, unlike board 6. That screen reads its destination off
          the sidebar, which a role can lack; this one cuts it out of the URL that
          served the page, so it is always the register this vendor came from.
        -->
        <template #lead>
            <Link
                :href="backHref"
                style="width:34px;height:34px;border-radius:50%;background:var(--navy-100);display:flex;align-items:center;justify-content:center;flex:0 0 auto;"
                title="Back to the vendor register"
                aria-label="Back to the vendor register"
            >
                <svg viewBox="0 0 24 24" fill="none" style="width:16px;height:16px;color:var(--navy-700);">
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
        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="4" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="This vendor’s account is not part of your role’s access"
            body="A vendor’s sub-ledger names what the estate owes one supplier and what it has already paid them, so it opens only to roles that hold Accounting. That is a separate module from Dues & ledger by platform rule — a Property Manager commissions the work and does not see the books behind it."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="This vendor’s account could not be loaded"
            body="The estate database did not answer. Nothing has been posted and nothing has been lost — every bill and every payment is still on the account, waiting to be read."
            action-label="Try again"
            @action="retry"
        />

        <template v-else>
            <div class="detail-head">
                <div class="hero-card">
                    <div class="hero-top">
                        <div>
                            <div class="hero-name">{{ vendor.name }}</div>

                            <!-- "Electrical contractor · Owen Grant · (876) 555 0110" —
                                 composed on the server out of whichever of the trade,
                                 the contact and the phone this vendor actually has, so
                                 a missing one closes up rather than leaving a stray
                                 middot in the line. -->
                            <div class="hero-sub">{{ vendor.sub }}</div>
                        </div>

                        <div :style="pillStyle">{{ vendor.status_label }}</div>
                    </div>

                    <!--
                      Four figures over the whole bill set, not over the rows
                      below. Each is printed by what the server sent it as: an
                      amount, a count, a month.
                    -->
                    <div class="hero-stats">
                        <div v-for="stat in stats" :key="stat.key" class="hero-stat">
                            <div class="hs-v">{{ figure(stat) }}</div>
                            <div class="hs-l">{{ stat.label }}</div>
                        </div>
                    </div>
                </div>

                <!--
                  Two actions, which is what the board draws. Both are real acts
                  with consequences outside this screen — recording a bill posts
                  a journal, and editing a vendor changes who the estate is
                  allowed to pay — so each is visibly inert and says which form
                  it is waiting on rather than answering a press with silence.

                  There is no third. A vendor is deactivated on the edit screen
                  and never deleted, and a delete control here would promise
                  something the database refuses.
                -->
                <div class="action-stack">
                    <button type="button" class="stack-btn primary" disabled :title="reasons.bill">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                        </svg>
                        <span>Record new bill</span>
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
                        <span>Edit vendor</span>
                    </button>
                </div>
            </div>

            <!--
              Authored, and it earns its place. It is drawn only for a vendor the
              estate cannot pay, it sits under the hero because that is where the
              money figures are, and it states the consequence rather than
              marking the record invalid.
            -->
            <p v-if="!vendor.trn" class="trn-note">{{ NO_TRN }}</p>

            <!-- A bare section heading, not a panel — the board uses
                 .info-panel-head standalone here, with its own inline margin. -->
            <div class="info-panel-head" style="margin-bottom: 12px">Bill history</div>

            <!--
              Different from board 26's empty in what it means and what comes
              next. An estate with no vendors has never bought anything; a vendor
              with no bills is a supplier the estate has taken on and not yet
              used, which is an ordinary state on the day they are added.
            -->
            <EmptyState
                v-if="state.isEmpty.value"
                variant="first-use"
                title="No bill has been recorded against this vendor"
                body="They are on the register and can be billed, but nothing has been booked to them yet — so they owe the estate nothing and the estate owes them nothing. The first recorded bill posts a liability and appears here."
            />

            <table v-else class="data-table">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Amount</th>
                        <th>Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="bill in bills" :key="bill.id">
                        <!-- A ticket-linked bill prints the work order rather
                             than the invoice number, because a committee asking
                             what a job cost is asking about the ticket. Board 17
                             is where that ticket lives and it is not built, so
                             the number is text with the reason on it rather than
                             a link to a route that answers 404. -->
                        <td :title="bill.ticket_id ? reasons.ticket : undefined">{{ bill.reference }}</td>

                        <td class="num-cell">{{ exact(bill.amount_minor) }}</td>
                        <td>{{ bill.date }}</td>
                        <td>
                            <!-- "Paid Aug 22" against a bill dated Aug 20: the
                                 settlement date is its own fact and the server
                                 composes the label, because the two-day lag is
                                 the difference between the credit to 2000 and
                                 the debit that cleared it. -->
                            <div
                                class="status-badge"
                                :class="{ active: bill.status === 'paid' }"
                                :style="badgeStyle(bill.status)"
                            >
                                {{ bill.status_label }}
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws both actions as <div>s; here they are
 * real buttons, which arrive wearing a border, buttonface grey and the browser's
 * own font. .stack-btn and its two modifiers supply everything visible.
 *
 * THE BORDER RESET NAMES THE VARIANT THAT HAS NO BORDER. A scoped element
 * selector outranks a single class, so `button.stack-btn { border: 0 }` would
 * beat .stack-btn.outline and rub out the 1.5px navy edge that is the entire
 * difference between the outline variant and the solid one — a real fidelity
 * defect small enough to survive a passing measurement. Neither variant needs
 * its background touched: each modifier declares its own.
 */
button {
    font-family: inherit;
}

button.stack-btn.primary {
    border: 0;
}

button[disabled] {
    cursor: not-allowed;
}

/*
 * The TRN note. Authored, because the board carries no equivalent — it draws a
 * vendor whose paperwork is in order — and it is the one thing on this screen a
 * reader cannot work out from what is drawn. Kept to the tokens the board
 * defines, and drawn in amber rather than red: nothing here has failed yet.
 */
.trn-note {
    font-size: 11.5px;
    color: var(--amber-700);
    line-height: 1.6;
    max-width: 720px;
    margin: 0 0 16px;
}
</style>
