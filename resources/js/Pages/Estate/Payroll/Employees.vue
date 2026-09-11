<script setup>
import { computed, ref } from 'vue'
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * Payroll employees — board screen community-admin-37.
 *
 * THE BANK ACCOUNT AND NIS NUMBERS ARE MASKED BEFORE THEY LEAVE THE SERVER, not
 * here. `Payroll::maskedBank()` and `maskedNis()` build "NCB •••• 3315" and
 * "••• •••5 208" at the boundary, and the payload carries nothing fuller — a
 * page that masked in a template would be sending the whole number to every
 * browser and hiding it with CSS, which is not hiding it at all. Anybody at the
 * desk can open the network tab. `EstatePayrollTest` asserts no field on any row
 * carries a run of digits long enough to be an account.
 *
 * THE RATE IS THE ONLY MONEY ON THIS SCREEN and it is a monthly gross. The four
 * rates sum to the J$440,000 board 15 debits to payroll expense — one number
 * appearing on two screens because both read the same register, not because
 * either was typed twice.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    rows: { type: Array, required: true },
    tabs: { type: Array, required: true },
    canCreate: { type: Boolean, required: true },
    reasons: { type: Object, required: true },
})

/*
 * Board 37 is drawn in the employees-details-and-billing sheet beside boards 38
 * and 40, NOT in the payroll sheet its two siblings use. Checked against the
 * sheet that defines `.res-cell` and `.res-avatar` rather than inferred from the
 * module — board 38 lost a whole measurement to exactly that assumption.
 */
useWireframe('community-admin-10-payroll-employees-details-and-billing')

const page = usePage()

const state = useScreenState({
    rows: () => props.rows.length,
})

const base = computed(() => page.url.split('/payroll')[0])

/* ------------------------------------------------------------------ */
/* adding somebody, behind the consent box (12 §1) */
/* ------------------------------------------------------------------ */

const adding = ref(false)

const form = useForm({
    full_name: '',
    job_title: '',
    employment_type: 'full_time',
    monthly_rate: '',
    employed_since: '',
    bank_name: '',
    bank_account_number: '',
    nis_number: '',
    consent: false,
})

const submit = () => {
    // The server refuses an unticked box too. This is about not sending a
    // write that will bounce, never about being the guard.
    if (!props.canCreate || !form.consent) {
        return
    }

    form.post(`${base.value}/payroll/employees`, {
        preserveScroll: true,
        onSuccess: () => {
            adding.value = false
            form.reset()
        },
    })
}

/** "$185,000/mo", as the board writes it. */
const rate = (minor) => `$${(minor / 100).toLocaleString('en-JM', { maximumFractionDigits: 0 })}/mo`
</script>

<template>
    <Head title="Payroll & HR" />

    <EstateConsole title="Payroll & HR" :estate-name="estate.name" active="payroll">
        <template #actions>
            <button
                type="button"
                class="btn-primary-sm"
                :disabled="!canCreate"
                :title="canCreate ? 'Put somebody on the payroll. Their bank and NIS numbers are personal data the estate will hold for seven years, so the form records that they were asked first.' : reasons.create"
                @click="adding = !adding"
            >
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
                <span>Add employee</span>
            </button>
        </template>

        <p v-if="page.props.flash?.success" class="emp-flash">{{ page.props.flash.success }}</p>

        <!--
          AUTHORED. The board draws a register nobody is adding to.

          THE CONSENT BOX IS THE RULING (12 §1) AND IT IS NOT DECORATION. The
          bank details are optional — somebody can go on the register before
          their bank sends the account — and the consent is not: the server
          refuses without it, and records the officer who took it.
        -->
        <form v-if="adding" class="emp-panel" @submit.prevent="submit">
            <div class="emp-head">
                Put somebody on the payroll. Nothing posts — an employee is a register row, and the journal is raised
                when a run is approved against the rate in force that day.
            </div>

            <div class="emp-fields">
                <div class="emp-field emp-field--wide">
                    <label for="em-name">Full name</label>
                    <input id="em-name" v-model="form.full_name" type="text" required maxlength="120" />
                </div>
                <div class="emp-field">
                    <label for="em-title">Job title</label>
                    <input id="em-title" v-model="form.job_title" type="text" required maxlength="80" placeholder="Security Supervisor" />
                </div>
                <div class="emp-field">
                    <label for="em-type">Employment</label>
                    <select id="em-type" v-model="form.employment_type" required>
                        <option value="full_time">Full time</option>
                        <option value="part_time">Part time</option>
                        <option value="contract">Contract</option>
                    </select>
                </div>
                <div class="emp-field">
                    <label for="em-rate">Monthly gross, J$</label>
                    <input id="em-rate" v-model="form.monthly_rate" type="text" inputmode="decimal" required placeholder="185000.00" />
                </div>
                <div class="emp-field">
                    <label for="em-since">Employed since</label>
                    <input id="em-since" v-model="form.employed_since" type="date" />
                </div>
                <div class="emp-field">
                    <label for="em-bank">Bank — optional</label>
                    <input id="em-bank" v-model="form.bank_name" type="text" maxlength="60" placeholder="NCB" />
                </div>
                <div class="emp-field">
                    <label for="em-acct">Account number — optional</label>
                    <input id="em-acct" v-model="form.bank_account_number" type="text" maxlength="32" />
                </div>
                <div class="emp-field">
                    <label for="em-nis">NIS number — optional</label>
                    <input id="em-nis" v-model="form.nis_number" type="text" maxlength="32" />
                </div>
            </div>

            <label class="emp-consent">
                <input v-model="form.consent" type="checkbox" />
                <span>
                    This person was asked, and agreed, before the estate holds their bank and NIS numbers. The estate
                    keeps payroll records for seven years. Who ticked this and when is recorded against their record.
                </span>
            </label>

            <div v-if="form.errors.full_name" class="emp-error">{{ form.errors.full_name }}</div>
            <div v-if="form.errors.consent" class="emp-error">{{ form.errors.consent }}</div>
            <div v-if="form.errors.monthly_rate" class="emp-error">{{ form.errors.monthly_rate }}</div>

            <div class="emp-actions">
                <button
                    type="submit"
                    class="btn-primary-sm"
                    :disabled="form.processing || !form.consent || form.full_name.trim() === ''"
                    :title="!form.consent ? 'Tick the consent box. The estate does not hold somebody\'s bank details without recording that they were asked.' : 'Add them to the register.'"
                >
                    <span>{{ form.processing ? 'Adding…' : 'Add employee' }}</span>
                </button>
                <button type="button" class="text-link-sm" @click="adding = false">Cancel</button>
            </div>
        </form>

        <div class="subnav">
            <template v-for="tab in tabs" :key="tab.key">
                <div v-if="tab.active" class="subnav-item active" aria-current="page">{{ tab.label }}</div>
                <Link
                    v-else
                    :href="tab.key === 'runs' ? `${base}/payroll` : `${base}/payroll/${tab.key}`"
                    class="subnav-item"
                >
                    {{ tab.label }}
                </Link>
            </template>
        </div>

        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="7" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Payroll is not part of your role’s access"
            body="This register holds every member of staff's pay rate, bank account and NIS number, so it opens only to a role that holds Payroll."
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="Nobody is on the payroll yet"
            body="The estate employs no staff of its own on this platform. Security guards are Gemini Security Limited's employees, paid centrally, and never appear here."
        />

        <table v-else class="data-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Role</th>
                    <th>Type</th>
                    <th>Bank account</th>
                    <th>NIS number</th>
                    <th>Rate</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in rows" :key="row.id">
                    <td>
                        <div class="res-cell">
                            <div class="res-avatar">{{ row.initials }}</div>
                            <div>
                                <div class="res-name">{{ row.name }}</div>
                                <div class="res-sub">{{ row.since }}</div>
                            </div>
                        </div>
                    </td>
                    <td>{{ row.role }}</td>
                    <td>{{ row.type }}</td>
                    <td>{{ row.bank }}</td>
                    <td>{{ row.nis }}</td>
                    <td class="num-cell">{{ rate(row.rate_minor) }}</td>
                    <td>
                        <div class="status-badge active">{{ row.status === 'active' ? 'Active' : 'Inactive' }}</div>
                    </td>
                </tr>
            </tbody>
        </table>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only. The board draws its topbar action and its three tabs as
 * <div>s; here one is a button and two are anchors.
 */
button.btn-primary-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

button.btn-primary-sm[disabled] {
    cursor: not-allowed;
}

a.subnav-item {
    text-decoration: none;
}

button.text-link-sm {
    border: 0;
    background: none;
    padding: 0;
    font: inherit;
    cursor: pointer;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws a register nobody is adding to, so
 * it has no panel and no flash. Kept to the tokens the boards define.
 */
.emp-flash {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 14px;
    background: var(--green-100);
    color: var(--green-700);
}

.emp-panel {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 18px;
    margin-bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 11px;
}

.emp-head {
    font-size: 12px;
    font-weight: 700;
    color: var(--navy-800);
    line-height: 1.55;
}

.emp-fields {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 11px;
}

.emp-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.emp-field--wide {
    grid-column: span 2;
}

.emp-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.emp-field input,
.emp-field select {
    height: 34px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

/* The consent box carries the amber ground the boards give a condition. */
.emp-consent {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    font-size: 11.5px;
    font-weight: 600;
    color: var(--navy-900);
    line-height: 1.55;
    background: var(--amber-100);
    border-radius: 10px;
    padding: 11px 14px;
    cursor: pointer;
}

.emp-consent input {
    margin: 3px 0 0;
    flex: 0 0 auto;
}

.emp-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--red-700);
    line-height: 1.5;
}

.emp-actions {
    display: flex;
    align-items: center;
    gap: 14px;
}
</style>
