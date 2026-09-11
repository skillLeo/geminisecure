<script setup>
import { computed } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * One unit's ledger — board screen community-admin-06.
 *
 * THIS IS A SUBSIDIARY LEDGER, not a list of bills. Charge is a debit to the
 * unit's receivable, Payment a credit, and the Balance column is the running
 * balance of that account — accumulated OLDEST FIRST and printed NEWEST FIRST,
 * which is why the top row's balance and the hero figure are the same number.
 * Every figure on it is summed from posted journal lines by `Dues`, so what a
 * resident is shown here is what the accounts say and not what the billing
 * tables remember.
 *
 * THE AGEING STRIP IS BUCKETED OFF THE DUE DATE, NOT THE POSTING DATE. The
 * board is what proves it: two open charges, one posted this month and one
 * last, and the strip puts both in 30 DAYS with nothing in CURRENT. The server
 * derives that under FIFO; this screen only prints it.
 *
 * FOUR ACTIONS, AND TWO OF THEM LEAD ELSEWHERE. Placing a household on a plan
 * and sending a dunning notice are links to boards 7 and 8 — drawn inert here
 * while those screens did not exist, and built since — so each act happens on
 * its own screen under its own write gate. Flagging hardship still has no
 * screen and says so on hover. Recording a payment is the one that is a
 * permission question, and it is treated as one below.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    unit: { type: Object, required: true },
    balance_minor: { type: Number, required: true },
    bucket: { type: String, required: true },
    bucket_label: { type: String, required: true },
    strip: { type: Array, required: true },
    statement: { type: Array, required: true },
    canRecord: { type: Boolean, required: true },
    reasons: { type: Object, required: true },
})

/*
 * The screen names its board, because the layout cannot. Ten Community Admin
 * stylesheets sit behind the Estate Console and they disagree with each other,
 * so each is scoped to its own body class and the page that reproduces a board
 * is the only thing that knows which one it is.
 */
useWireframe('community-admin-02-arrears-ledger-payment-plan-and-dunning')

const page = usePage()

/*
 * Five of the six. A ledger carries no filter — there is no query that could
 * have excluded the entries — so `empty-filtered` cannot happen here, and
 * rendering a "clear the filter" panel on a screen with no filter would invent
 * a control to explain a state that does not exist.
 */
const state = useScreenState({
    rows: () => props.statement.length,
})

const retry = () => router.reload()

/*
 * Where this module is rooted, read off the page's own URL — production serves
 * an estate bare and local under /estate/{key}, and cutting the URL that served
 * this ledger is correct in both.
 */
const root = computed(() => {
    const cut = page.url.indexOf('/finance')

    return cut === -1 ? '' : page.url.slice(0, cut)
})

const financePath = (suffix) => `${root.value}/finance${suffix}`

/*
 * Boards 7 and 8, which this ledger's plan and dunning controls were drawn
 * inert to wait for. Both are behind the same `dues_ledger.view` gate this
 * screen is, so anybody reading the ledger can open them; what each lets them
 * DO there is decided by that screen's own write gates.
 */
const planHref = computed(() => financePath(`/units/${props.unit.id}/payment-plan`))

const dunningHref = computed(() => financePath('/dunning'))

/*
 * Where the chevron goes: the arrears list, which is the screen the reader
 * clicked "View ledger" on.
 *
 * Read off the sidebar rather than written as a literal. An estate is served
 * bare in production and under /estate/{tenant} in local (routes/tenant.php),
 * and the nav already carries this environment's own shape — a hard-coded
 * /finance/arrears answers 404 on every local machine.
 */
const backHref = computed(() => page.props.estateNav?.find((item) => item.key === 'dues_ledger')?.href ?? null)

/*
 * TWO MONEY FORMATS ON ONE SCREEN, and the difference is the design's rather
 * than an inconsistency to tidy away. The hero is the figure a household is
 * asked to settle, and a figure on a demand is quoted to the cent. The ageing
 * strip and the statement columns are read DOWN as columns, where a run of
 * ".00" is noise between the eye and the digits that differ.
 *
 * Every amount arrives as minor units — integer cents — because that is the
 * only form that survives arithmetic. The divide by 100 happens here, at the
 * last possible moment, and nothing is ever added up after it.
 *
 * A column figure is therefore ROUNDED TO THE NEAREST DOLLAR for display. The
 * exact position is the hero, and the record to the cent is the journal behind
 * it; the board draws whole dollars in these columns and this reproduces that
 * rather than quietly widening them.
 */
const toTheCent = (minor) =>
    new Intl.NumberFormat('en-JM', {
        style: 'currency',
        currency: 'JMD',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(minor / 100)

const toTheDollar = (minor) =>
    new Intl.NumberFormat('en-JM', {
        style: 'currency',
        currency: 'JMD',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(minor / 100)

/**
 * Why "Record manual payment" cannot be pressed, or null when it can.
 *
 * TWO DIFFERENT REFUSALS, and the difference matters to whoever is standing
 * there holding the cheque. The first is the viewer's own access: a role may
 * read what a household owes and still not be allowed to credit it, and
 * `canRecord` carries that. The second is the platform's: no route accepts a
 * payment yet, because a receipted payment is a method, a date and a receipt
 * number before it is an amount, and the screen that asks for them — the
 * Receipts tab on the arrears console — is not built.
 *
 * The second branch is deliberate rather than an oversight of the first.
 * Enabling this control on the strength of the permission alone would answer a
 * press with silence, which is the one thing this codebase refuses of a
 * control. When the receipting screen lands, the branch goes and `canRecord`
 * gates the button by itself.
 */
const recordBlockedBy = computed(() => {
    if (!props.canRecord) {
        return 'Recording a payment credits this unit’s ledger, so it needs Payments create access. Your role can read this ledger and not add to it.'
    }

    return 'Not built yet — a manual payment is money taken at the office: it needs a method, a date and a receipt number before it can be posted, and the screen that asks for them is the Receipts tab on the arrears console.'
})
</script>

<template>
    <Head :title="unit.title" />

    <EstateConsole :title="unit.title" :estate-name="estate.name" active="dues_ledger">
        <!--
          The back chevron. The board draws it as a .btn-outline-sm with its
          border taken off inline — NOT the 34px navy circle the other estate
          detail boards use — and that inconsistency is the board's, reproduced
          rather than normalised to its neighbours. The inline declarations are
          copied verbatim onto a real link, so the control can be clicked,
          focused and opened in a new tab.

          The disabled twin is for a viewer whose sidebar has no arrears list.
          It cannot happen through this route, which is gated on the same
          permission the nav item is — but a <Link> with a null href is a
          broken control, and this screen renders no broken controls.
        -->
        <template #lead>
            <Link
                v-if="backHref"
                :href="backHref"
                class="btn-outline-sm"
                style="border: none; padding: 0 8px 0 0"
                title="Back to the arrears list"
                aria-label="Back to the arrears list"
            >
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
            <button
                v-else
                type="button"
                class="btn-outline-sm"
                style="border: none; padding: 0 8px 0 0"
                disabled
                title="The arrears list is not part of your role’s access."
                aria-label="Back to the arrears list"
            >
                <svg viewBox="0 0 24 24" fill="none">
                    <polyline
                        points="15 18 9 12 15 6"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                </svg>
            </button>
        </template>

        <template #actions>
            <button type="button" class="btn-outline-sm" disabled :title="reasons.statement">
                <svg viewBox="0 0 24 24" fill="none">
                    <path
                        d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z"
                        stroke="currentColor"
                        stroke-width="1.7"
                        stroke-linejoin="round"
                    />
                </svg>
                <span>Print statement</span>
            </button>
        </template>

        <!-- The whole screen is one payload, so nothing on it arrives before the rest. -->
        <SkeletonRows v-if="state.isLoading.value" :rows="5" :columns="5" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="This unit’s ledger is not part of your role’s access"
            body="A unit ledger names a household and what it owes, so it opens only to roles that hold the Dues & ledger module. Yours does not — a Property Manager, for one, holds neither the ledger nor the money by platform rule rather than estate preference."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="This ledger could not be loaded"
            body="The estate database did not answer. Nothing has been posted and nothing has been lost — every entry is still on the account, waiting to be read."
            action-label="Try again"
            @action="retry"
        />

        <template v-else>
            <div class="ledger-head">
                <div class="unit-summary">
                    <div class="us-top">
                        <div>
                            <div class="us-name">{{ unit.resident }}</div>
                            <div class="us-unit">{{ unit.meta }}</div>
                        </div>

                        <!--
                          The board's own inline override, kept verbatim and
                          kept to the bucket it was drawn for. .age-badge.d30 is
                          amber-700 on amber-100 — a chip designed for a white
                          table row, which glares on this navy card — so the
                          board dims the ground and lifts the text to amber-500
                          instead. The other three buckets keep their stylesheet
                          colours: tinting them the same way would be drawing
                          three variants no board has ever drawn.
                        -->
                        <div
                            class="age-badge"
                            :class="bucket"
                            :style="bucket === 'd30' ? 'background:rgba(255,182,39,.2);color:var(--amber-500);' : undefined"
                        >
                            {{ bucket_label }}
                        </div>
                    </div>

                    <div class="us-bal">{{ toTheCent(balance_minor) }}</div>
                    <div class="us-bal-lbl">TOTAL OUTSTANDING</div>

                    <div class="us-age-row">
                        <div v-for="band in strip" :key="band.key" class="us-age-item">
                            <div class="ua-v">{{ toTheDollar(band.value_minor) }}</div>
                            <div class="ua-l">{{ band.label }}</div>
                        </div>
                    </div>
                </div>

                <div class="action-stack">
                    <button
                        type="button"
                        class="stack-btn primary"
                        :disabled="recordBlockedBy !== null"
                        :title="recordBlockedBy ?? undefined"
                    >
                        <!-- This board draws the card a pixel higher than the Gemini boards do,
                             and BoardIcon carries no printer at all, so all six glyphs on this
                             screen are lifted from this board rather than four from a component
                             and two from here. Two sources on one stack is how they drift. -->
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="2" y="5" width="20" height="14" rx="2" stroke="currentColor" stroke-width="1.7" />
                            <path d="M2 9h20" stroke="currentColor" stroke-width="1.7" />
                        </svg>
                        <span>Record manual payment</span>
                    </button>

                    <!--
                      Board 7, a real link since it was built. Anybody reading
                      this ledger may open the plan; drawing one up and
                      activating it are that screen's own create and approve
                      gates, and it states them itself.
                    -->
                    <Link
                        :href="planHref"
                        class="stack-btn amber"
                        title="Open this household’s payment plan — draw one up, record that they agreed, or read the one in force. An active plan pauses automated reminders and lifts the arrears restriction."
                    >
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.7" />
                            <path
                                d="M3 10h18M8 3v4M16 3v4"
                                stroke="currentColor"
                                stroke-width="1.7"
                                stroke-linecap="round"
                            />
                        </svg>
                        <span>Place on payment plan</span>
                    </Link>

                    <!--
                      Board 8. A reminder is chosen, sent and logged verbatim
                      there, so a dispute can be settled from what the household
                      was actually told — this ledger is not the place to send one
                      blind.
                    -->
                    <Link
                        :href="dunningHref"
                        class="stack-btn outline"
                        title="Open Dunning & reminders, where a notice is chosen, sent and logged verbatim against this unit."
                    >
                        <svg viewBox="0 0 24 24" fill="none">
                            <path
                                d="M4 11v2a1 1 0 0 0 1 1h2l4 4V6L7 10H5a1 1 0 0 0-1 1z"
                                stroke="currentColor"
                                stroke-width="1.7"
                                stroke-linejoin="round"
                            />
                            <path d="M17 8a5 5 0 0 1 0 8" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                        </svg>
                        <span>Send reminder now</span>
                    </Link>

                    <button type="button" class="stack-btn outline" disabled :title="reasons.hardship">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M12 9v4M12 17h.01" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" />
                            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" />
                        </svg>
                        <span>Flag hardship / dispute</span>
                    </button>
                </div>
            </div>

            <!--
              The unit's own tabs, which are not the module's: board 5 puts
              Arrears, Charge schedule, Payment plans and Receipts in this strip
              and board 6 replaces the lot. Statement is the screen the reader
              is on, so it is text rather than a control — there is nowhere for
              it to lead. The other two are boards 8 and 7, built since this
              strip was drawn inert.
            -->
            <div class="subnav">
                <div class="subnav-item active" aria-current="page">Statement</div>
                <Link :href="dunningHref" class="subnav-item">Dunning log</Link>
                <Link :href="planHref" class="subnav-item">Payment plan</Link>
            </div>

            <!--
              An empty ledger takes the card's place rather than sitting inside
              it: the statement card is its own bordered surface, and column
              headings ruled over nothing say nothing.
            -->
            <EmptyState
                v-if="state.isEmpty.value"
                variant="first-use"
                title="Nothing has been posted to this unit"
                body="No charge has been raised against it and no payment received, so its account carries no entries and no balance. A maintenance fee, a special assessment, a fine or a receipted payment each write a line here."
            />

            <div
                v-else
                style="background: var(--white); border: 1px solid var(--navy-100); border-radius: 16px; overflow: hidden"
            >
                <div
                    style="display: flex; padding: 11px 16px; background: var(--navy-100); font-size: 10.5px; font-weight: 700; color: var(--slate-500); text-transform: uppercase; letter-spacing: 0.3px"
                >
                    <div style="width: 80px">Date</div>
                    <div style="flex: 1">Description</div>
                    <div style="width: 100px; text-align: right">Charge</div>
                    <div style="width: 100px; text-align: right">Payment</div>
                    <div style="width: 100px; text-align: right">Balance</div>
                </div>

                <!--
                  Keyed by position, because a row is a journal LINE and carries
                  no id of its own on this payload — the reference belongs to the
                  entry, which a monthly dues run shares across every unit in the
                  estate. Nothing here reorders, so position is stable.

                  A charge row leaves the payment cell empty and a payment row
                  the charge cell, exactly as the board draws them: empty cells,
                  never a dash, because the column width is the alignment and a
                  dash would read as a value.
                -->
                <div v-for="(row, i) in statement" :key="i" class="stmt-row">
                    <div class="stmt-date">{{ row.date }}</div>
                    <div class="stmt-desc">{{ row.description }}</div>
                    <div class="stmt-charge">{{ row.charge_minor === null ? '' : toTheDollar(row.charge_minor) }}</div>
                    <div class="stmt-payment">
                        {{ row.payment_minor === null ? '' : toTheDollar(row.payment_minor) }}
                    </div>
                    <div class="stmt-bal">{{ toTheDollar(row.balance_minor) }}</div>
                </div>
            </div>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws every control on this screen as a
 * <div>; here they are one link and seven buttons, which arrive wearing an
 * underline, a border, buttonface grey and the browser's own font. Everything
 * visible still comes from the board's .btn-outline-sm, .stack-btn and
 * .subnav-item.
 *
 * THE BORDER RESETS NAME THE VARIANTS THAT HAVE NO BORDER. A scoped element
 * selector outranks a single class, so `button.stack-btn { border: 0 }` would
 * beat .stack-btn.outline and rub out the 1.5px edge that is the entire
 * difference between the outline variant and the solid ones. .btn-outline-sm is
 * left alone for the same reason: its own rule draws a border, and the two
 * places this screen wants it gone say so inline, as the board does.
 */
a {
    text-decoration: none;
}

button {
    font-family: inherit;
}

button.stack-btn.primary,
button.stack-btn.amber {
    border: 0;
}

/* The tab strip's rule declares neither, so a button brings both back. */
button.subnav-item {
    border: 0;
    background: none;
}

button[disabled] {
    cursor: not-allowed;
}
</style>
