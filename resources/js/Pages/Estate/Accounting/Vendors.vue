<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * Vendors — board screen community-admin-26.
 *
 * The supplier register: who the estate buys from, what each has been paid this
 * year, and how to reach them. It is the screen a bill is recorded against, so
 * everything a bill needs about a supplier is settled here.
 *
 * PAID YTD IS NOT A COLUMN ON THE VENDOR. It is the debit side of 2000 Accounts
 * Payable on payment entries only, summed by vendor from the year's posted
 * lines. Storing it beside the name would be a second copy of the ledger free to
 * drift from it, and a reversal of a wrongly approved bill also debits 2000 —
 * counting that as money paid would tell a committee the estate had settled an
 * invoice it had merely cancelled. `Payables::paidByVendor()` filters on the
 * entry's source for exactly that reason.
 *
 * THE CATEGORY COLUMN IS READ FROM THE JOURNAL TOO. "Maintenance" and
 * "Utilities" are not stored on the vendor: they are which expense account that
 * vendor's bills actually landed on, which is a fact the books already hold. A
 * copy kept on the row would be free to say "Maintenance" about a supplier whose
 * costs all went to 5200. A vendor that has never been billed therefore has no
 * category at all, and the cell says so with an em dash rather than guessing.
 *
 * THE TRN IS REQUIRED BEFORE A BILL IS PAID, NEVER BEFORE ONE IS RECORDED. A
 * vendor with no taxpayer registration number on file is not an invalid row and
 * is not drawn as one — the estate may still receive their invoice and must
 * still book what it owes, because refusing to record it would understate the
 * payables. What it may not do is move money to them. So the register marks that
 * vendor as one whose NEXT PAYMENT WILL BE REFUSED, beside a status that is
 * still Active, which is what `Payables::pay()` actually enforces.
 *
 * A VENDOR IS DEACTIVATED, NEVER DELETED. One with bills against it is history
 * the estate has to keep, and `bills.vendor_id` restricts at the database to say
 * so. The board draws no delete control and neither does this screen; taking a
 * supplier off the register is a status change, and it belongs on the edit
 * screen `reasons` names rather than on a row action.
 *
 * TWO CONTENT RESIDUALS, both recorded and neither a styling fault:
 *
 *   The board draws five populated Paid YTD figures. Only one of the five bills
 *   board 27 specifies has been paid, so four of these rows honestly read $0 —
 *   the payments that would fill the column are not in the seed and inventing
 *   them would put money through the ledger that no board asked for.
 *
 *   The board draws "JU" as the avatar for Jamaica Public Service Co. No
 *   initials rule over that name produces it; `Vendor::initials()` returns "JP",
 *   which is correct, and a lookup table of exceptions to a two-letter initial
 *   would outlive the wireframe it came from.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    rows: { type: Array, required: true },
    paid_ytd_total_minor: { type: Number, required: true },
    canCreate: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
})

/*
 * ADDING A VENDOR IS BUILT (12 §2, Wave 1): "TRN required before a bill may be
 * paid." The panel asks for the TRN and does not require it — a supplier goes
 * on the register when the estate starts dealing with them, paperwork or not,
 * and the payment is where the missing number refuses.
 */
const adding = ref(false)

const vendorForm = useForm({
    name: '',
    category: '',
    trn: '',
    contact_name: '',
    contact_phone: '',
    contact_email: '',
})

const openAdding = () => {
    if (!props.canCreate) {
        return
    }

    adding.value = !adding.value
    vendorForm.clearErrors()
}

const closeAdding = () => {
    adding.value = false
    vendorForm.reset()
    vendorForm.clearErrors()
}

const submitVendor = () => {
    if (!props.canCreate || vendorForm.name.trim() === '') {
        return
    }

    vendorForm.post(accounting('/vendors'), { preserveScroll: true })
}

/*
 * Which board's stylesheet this page wears. The ten Estate Console boards do
 * NOT share one sheet the way the nine Gemini boards do, so every estate page
 * has to name its own or it renders with no board CSS at all.
 */
useWireframe('community-admin-07-chart-of-accounts-vendors-bills-and-bank-rec')

const page = usePage()

/*
 * Five of the six. The board draws no search box and no filter chips — the
 * register is the whole register — so `empty-filtered` cannot occur here, and
 * rendering a "clear the filter" panel on a screen with no filter would invent a
 * control in order to explain a state that does not exist. The composable still
 * carries it, and `?_state=` still forces the other five.
 */
const state = useScreenState({
    rows: () => props.rows.length,
})

const retry = () => router.reload()

/*
 * Where this console is rooted, read off the page's own URL.
 *
 * Production gives each estate its own hostname and no prefix; local serves
 * every estate from one host with the estate key in the path, as
 * /estate/{key}/accounting/vendors. The URL that served this page already
 * carries whichever shape this environment uses, so cutting it at /accounting is
 * correct in both — a hard-coded root is correct in exactly one.
 */
const root = computed(() => page.url.slice(0, page.url.indexOf('/accounting')))

const accounting = (suffix) => `${root.value}/accounting${suffix}`

/**
 * The board's money: whole dollars, comma-grouped, no decimals.
 *
 * ONE FORMAT ON THIS SCREEN, unlike its sibling. Board 25 abbreviates in its KPI
 * tiles and prints exactly in its table, because a tile is read at a glance and
 * eight digits in it are noise; board 26 draws no tiles at all, so there is
 * nothing here to abbreviate and Paid YTD is the figure a treasurer reconciles
 * a column against.
 *
 * Every amount arrives as minor units — integer cents — because that is the only
 * form that survives arithmetic. The divide by 100 happens here, at the last
 * possible moment, and nothing is added up after it.
 */
const exact = (minor) => `$${(minor / 100).toLocaleString('en-JM', { maximumFractionDigits: 0 })}`

/**
 * The column's own total, which the board draws nowhere.
 *
 * It is carried by the server because it is the figure a treasurer checks the
 * column against, and it is put on the heading rather than in a footer row: the
 * board has no footer, and adding one would be drawing a row no board drew.
 */
const paidYtdTotal = computed(
    () => `These ${props.rows.length} vendors have been paid ${exact(props.paid_ytd_total_minor)} year to date.`
)

/**
 * The four Accounting tabs, all of which are built.
 *
 * The one the reader is on carries no href, because there is nowhere for it to
 * lead; it is text rather than a control. The other three are real links, so the
 * module can be navigated with the keyboard and any of its screens opened in its
 * own tab.
 *
 * `reasons` carries only `add` on this screen, and that is right. A tab's reason
 * is what an unbuilt screen owes the reader, and none of the four is unbuilt any
 * more.
 */
const tabs = computed(() => [
    { key: 'chart', label: 'Chart of accounts', href: accounting('/chart-of-accounts') },
    { key: 'vendors', label: 'Vendors', href: null },
    { key: 'bills', label: 'Bills & payments', href: accounting('/bills') },
    { key: 'reconciliation', label: 'Bank reconciliation', href: accounting('/reconciliation') },
])

/**
 * What a missing TRN actually costs, said where a treasurer will read it.
 *
 * Not a validation message. The vendor is on the register, the vendor is Active,
 * and their invoices will be recorded exactly as everybody else's are. The one
 * thing that will not happen is a payment, and the fix is paperwork from the
 * supplier rather than anything anybody can type on this screen.
 */
const NO_TRN =
    'No taxpayer registration number on file. Bills from this vendor are recorded normally — the estate owes what it owes — but the next payment to them will be refused until the TRN is on the vendor record.'

/** The board's own em dash, for a fact that is absent rather than zero. */
const NONE = '—'
</script>

<template>
    <Head title="Vendors" />

    <EstateConsole title="Vendors" :estate-name="estate.name" active="accounting">
        <template #actions>
            <button
                type="button"
                class="btn-primary-sm"
                :disabled="!canCreate"
                :title="canCreate ? 'Put a supplier on the register. The TRN can follow — bills can be recorded against them before it does, and paid only after.' : blockedReason"
                @click="openAdding"
            >
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
                <span>Add vendor</span>
            </button>
        </template>

        <!--
          The module's tabs. The one the reader is on is text and not a control;
          the other three are real links.
        -->
        <div class="subnav">
            <template v-for="tab in tabs" :key="tab.key">
                <Link v-if="tab.href" :href="tab.href" class="subnav-item">{{ tab.label }}</Link>
                <div v-else class="subnav-item active" aria-current="page">{{ tab.label }}</div>
            </template>
        </div>

        <p v-if="page.props.flash?.success" class="vnd-flash">{{ page.props.flash.success }}</p>

        <!--
          The add-vendor panel — authored, closed on a fresh GET. The TRN field
          says in its own label what leaving it blank costs.
        -->
        <form v-if="adding" class="vnd-panel" @submit.prevent="submitVendor">
            <div class="vnd-head">Put a supplier on the register</div>

            <div class="vnd-fields">
                <div class="vnd-field vnd-field--wide">
                    <label for="vnd-name">Name</label>
                    <input id="vnd-name" v-model="vendorForm.name" type="text" required maxlength="160" placeholder="Island Electric Services" />
                </div>

                <div class="vnd-field">
                    <label for="vnd-category">Trade</label>
                    <input id="vnd-category" v-model="vendorForm.category" type="text" maxlength="64" placeholder="Electrical contractor" />
                </div>

                <div class="vnd-field">
                    <label for="vnd-trn">TRN — nine digits; blank until it arrives, and no payment until it does</label>
                    <input id="vnd-trn" v-model="vendorForm.trn" type="text" inputmode="numeric" maxlength="24" placeholder="100-482-517" />
                </div>

                <div class="vnd-field">
                    <label for="vnd-contact">Contact</label>
                    <input id="vnd-contact" v-model="vendorForm.contact_name" type="text" maxlength="160" />
                </div>

                <div class="vnd-field">
                    <label for="vnd-phone">Phone</label>
                    <input id="vnd-phone" v-model="vendorForm.contact_phone" type="tel" maxlength="40" placeholder="(876) 555 0110" />
                </div>

                <div class="vnd-field">
                    <label for="vnd-email">Email</label>
                    <input id="vnd-email" v-model="vendorForm.contact_email" type="email" maxlength="190" />
                </div>
            </div>

            <div v-if="vendorForm.errors.name" class="vnd-error">{{ vendorForm.errors.name }}</div>
            <div v-if="vendorForm.errors.trn" class="vnd-error">{{ vendorForm.errors.trn }}</div>
            <div v-if="vendorForm.errors.contact_email" class="vnd-error">{{ vendorForm.errors.contact_email }}</div>

            <div class="vnd-actions">
                <button
                    type="submit"
                    class="btn-primary-sm"
                    :disabled="vendorForm.processing || vendorForm.name.trim() === ''"
                    :title="vendorForm.name.trim() === '' ? 'Enter the supplier’s name.' : 'Add the supplier to the register and open their record.'"
                >
                    <span>{{ vendorForm.processing ? 'Adding…' : 'Add to register' }}</span>
                </button>
                <button type="button" class="text-link-sm" @click="closeAdding">Cancel</button>
            </div>
        </form>

        <SkeletonRows v-if="state.isLoading.value" :rows="5" :columns="6" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="The supplier register is not part of your role’s access"
            body="Vendors sit inside Accounting, which is a separate module from Dues & ledger by platform rule — a Property Manager commissions the work and does not see the estate’s books. A committee officer or the estate administrator can grant it from the role access matrix."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The vendor register could not be read"
            body="The estate database did not answer. No vendor has been changed and no bill has moved — this is a read that failed, and re-running it is safe."
            action-label="Try again"
            @action="retry"
        />

        <!--
          A first-use empty, not a "no results". An estate with no vendors has
          never recorded a bill, because a bill is recorded AGAINST a vendor —
          so this panel is the start of the payables ledger rather than the end
          of a search.
        -->
        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="No vendors on the register yet"
            body="Every bill the estate records names the supplier it came from, so nothing can be booked into accounts payable until at least one vendor exists. Adding one captures the trade, the contact and the taxpayer registration number together."
        />

        <table v-else class="data-table">
            <thead>
                <tr>
                    <th>Vendor</th>
                    <th>Category</th>
                    <th>Contact</th>
                    <th :title="paidYtdTotal">Paid YTD</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in rows" :key="row.id">
                    <td>
                        <div class="res-cell">
                            <div class="res-avatar">{{ row.initials }}</div>
                            <div>
                                <div class="res-name">{{ row.name }}</div>
                                <div class="res-sub">{{ row.trade }}</div>
                            </div>
                        </div>
                    </td>

                    <!-- Null until this vendor's first bill posts, because the
                         column is which expense account their costs landed on
                         and a vendor with no costs has landed on none. -->
                    <td>{{ row.category ?? NONE }}</td>

                    <td>{{ row.contact }}</td>
                    <td class="num-cell">{{ exact(row.paid_ytd_minor) }}</td>

                    <td>
                        <!--
                          The board's sheet defines .status-badge.active and no
                          twin for a vendor that has been taken off the register,
                          because it draws five active vendors. Deactivated is
                          neutral rather than red: it is the ONLY way a supplier
                          ever leaves this list — one with bills against them is
                          history the estate has to keep — so it is a fact about
                          the relationship, not a fault to flag.
                        -->
                        <div
                            class="status-badge"
                            :class="row.status"
                            :style="row.status === 'active' ? undefined : 'background:var(--navy-100);color:var(--slate-600);'"
                        >
                            {{ row.status_label }}
                        </div>

                        <!--
                          The payment rule, on the row it applies to, in the
                          board's own amber. It sits BESIDE the status rather
                          than replacing it: the vendor is genuinely still
                          Active, and drawing them as an error would say the
                          register was wrong when what is missing is the
                          supplier's paperwork.

                          Amber declared inline rather than borrowed from
                          .status-badge.unpaid — that variant is exactly this
                          colour, and "unpaid" in a vendor table would read as a
                          statement about a bill. Board 39 overrides the same
                          badge inline for the same reason.
                        -->
                        <div
                            v-if="!row.has_trn"
                            class="status-badge"
                            style="background: var(--amber-100); color: var(--amber-700)"
                            :title="NO_TRN"
                        >
                            No TRN — cannot be paid
                        </div>
                    </td>

                    <td>
                        <Link :href="accounting(`/vendors/${row.id}`)" class="text-link-sm">View</Link>
                    </td>
                </tr>
            </tbody>
        </table>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws its topbar action, its four tabs and its
 * row links as <div>s; here they are one button and several links. A real
 * button arrives wearing a border, buttonface grey and the browser's own font, and
 * .btn-primary-sm supplies everything visible. app.css has already taken the
 * UA's underline and blue off every anchor on a board page, so the links need
 * nothing at all.
 *
 * Not one declaration below introduces a colour, a size or a spacing, and none
 * of them touches a class the board gives a border to — .btn-primary-sm
 * declares a background and no border, which is why that one is safe to reset.
 */
button.btn-primary-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.btn-primary-sm[disabled] {
    cursor: not-allowed;
}

button.text-link-sm {
    border: 0;
    background: none;
    font: inherit;
    padding: 0;
    cursor: pointer;
}

/*
 * AUTHORED BELOW THIS LINE. The board is a still image of a register nobody is
 * adding to, so it draws no panel and no flash. Kept to the tokens the boards
 * define and the shapes they already use.
 */
.vnd-flash {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 14px;
    background: var(--green-100);
    color: var(--green-700);
}

.vnd-panel {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 18px;
    margin-bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 11px;
}

.vnd-head {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-800);
}

.vnd-fields {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 11px;
}

.vnd-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.vnd-field--wide {
    grid-column: span 2;
}

.vnd-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.vnd-field input {
    height: 34px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

.vnd-field input::placeholder {
    color: var(--slate-300);
    opacity: 1;
}

.vnd-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--red-700);
    line-height: 1.5;
}

.vnd-actions {
    display: flex;
    align-items: center;
    gap: 14px;
}
</style>
