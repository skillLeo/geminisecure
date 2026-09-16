<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'

/**
 * Statutory filings — board screen super-admin-30.
 *
 * The board's body is the tab strip and one card of rows, and that is all this
 * draws. The card's wrapper carries an inline style because the board's
 * stylesheet defines no class for it; copied verbatim rather than given a class
 * of my own.
 *
 * NOTHING IN THIS LIST IS CLICKABLE, AND THAT IS THE DESIGN.
 *
 * A filed return is a posted record: the authority holds a copy, and correcting
 * it here would put the two out of step. So there is no edit and no delete —
 * not even a disabled one. A greyed-out delete says "this system deletes
 * returns and you may not", which is false; the truth is that it does not
 * delete them at all. A correction is an amended return, filed like any other.
 *
 * The one control on the screen is the topbar's "Start new filing". It prepares
 * the S01 for the oldest approved run no return covers yet (12 §2, Wave 4), and
 * when it cannot, it is inert with the reason: the register's own — nothing
 * approved left to prepare — or the viewer's role.
 */
const props = defineProps({
    filings: { type: Array, required: true },
    /** Why no new return can be prepared, from the register. Null if one could. */
    blockedReason: { type: String, default: null },
    filters: { type: Object, required: true },
    /** The years the register actually holds returns for, newest first. */
    years: { type: Array, required: true },
    canPrepare: { type: Boolean, required: true },
    prepareDeniedReason: { type: String, required: true },
})

const state = useScreenState({
    rows: () => props.filings.length,
    filtered: () => props.filters.year !== null,
})

/*
 * Why the one control on this screen does not work, when it does not. The
 * register's reason first: a business fact outranks a permission, because a
 * role that could press it would still be refused for it.
 */
const startFilingReason = computed(() => props.blockedReason ?? (props.canPrepare ? null : props.prepareDeniedReason))

const page = usePage()

const preparing = ref(false)

const prepare = () => {
    preparing.value = true
    router.post('/payroll/filings', {}, { preserveScroll: true, onFinish: () => (preparing.value = false) })
}

const clearYear = () => router.get('/payroll/filings', {}, { preserveScroll: true })

const retry = () => router.reload()
</script>

<template>
    <Head title="Statutory filings" />

    <!--
      No search field. The board's topbar draws a title and one button, so that
      is what the layout is asked for.
    -->
    <GeminiConsole title="Statutory filings">
        <template #actions>
            <button
                type="button"
                class="btn-primary-sm"
                :disabled="startFilingReason !== null || preparing"
                :title="startFilingReason ?? 'Prepare the S01 for the oldest approved pay run that no return covers yet.'"
                @click="prepare"
            >
                <BoardIcon name="plus" :stroke="2" />
                <span>Start new filing</span>
            </button>
        </template>

        <!-- The four tabs in the board's own order. -->
        <div class="subnav">
            <Link href="/payroll" class="subnav-item">Pay runs</Link>
            <Link href="/guards" class="subnav-item" title="The people paid here are the guards — this opens Guard workforce.">Employees</Link>
            <Link href="/payroll/filings" class="subnav-item active">Statutory filings</Link>
            <Link href="/payroll/rates" class="subnav-item">Rate table</Link>
        </div>

        <!-- Authored: what preparing a return did, or why it was refused. -->
        <p v-if="page.props.flash?.success" class="pf-flash">{{ page.props.flash.success }}</p>
        <p v-if="page.props.errors.filing" class="pf-refusal">{{ page.props.errors.filing }}</p>

        <EmptyState
            v-if="state.isDenied.value"
            variant="denied"
            title="Your role does not reach payroll and accounting"
            body="Statutory returns carry every guard's earnings and deductions. Ask a platform administrator to grant the Payroll & Accounting module if you need them."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The statutory register could not be loaded"
            body="Nothing has been filed or changed. The returns are still recorded; only this view failed to read them."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isEmptyFiltered.value"
            variant="filtered"
            :title="
                filters.year
                    ? `No statutory return covers ${filters.year}`
                    : 'No statutory return matches this view'
            "
            :body="
                years.length
                    ? `The register holds returns for ${years.join(', ')}. Clear the year to see all of them.`
                    : 'The register holds no returns at all yet, for any year.'
            "
            action-label="Show every year"
            @action="clearYear"
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="No statutory returns yet"
            body="A return appears here as soon as a pay period closes, and is prepared from that period's approved run with Start new filing."
        />

        <div v-else style="background:var(--white);border:1px solid var(--navy-100);border-radius:16px;overflow:hidden;">
            <div v-if="state.isLoading.value" style="padding: 8px 16px">
                <SkeletonRows :rows="4" :columns="4" />
            </div>

            <template v-else>
                <div v-for="filing in filings" :key="filing.id" class="filing-row" :title="filing.note || undefined">
                    <div class="filing-icon" :class="{ warn: filing.warn }">
                        <BoardIcon :name="filing.icon" :stroke="1.6" />
                    </div>
                    <div class="filing-txt">
                        <div class="ft1">{{ filing.title }}</div>
                        <div class="ft2">{{ filing.detail }}</div>
                    </div>
                    <div class="filing-due">{{ filing.when }}</div>
                    <div class="status-badge" :class="filing.badge_class">{{ filing.badge_label }}</div>
                </div>
            </template>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/*
 * The only authored CSS here, and every rule takes a browser default back off
 * rather than adding a style of its own.
 *
 * The board draws the tabs and the topbar action as <div>s, which is free for a
 * still image. Here the tabs are links and the action a real button, so the
 * UA's link underline and the button's own face, border and font would show
 * through and change the pixels. .subnav-item and .btn-primary-sm already state
 * everything else — size, weight, colour, padding, radius — so nothing below
 * restates any of it.
 */
a.subnav-item {
    text-decoration: none;
}

button.btn-primary-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

/* Inert, and it says so on hover. No opacity change: the board draws the button
 * at one weight, and dimming it would be a pixel the design does not have. */
button.btn-primary-sm[disabled] {
    cursor: not-allowed;
}

/* Authored, and only shown after a press. */
.pf-flash,
.pf-refusal {
    font-size: 12px;
    font-weight: 600;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 12px;
}

.pf-flash {
    background: var(--success-100);
    color: var(--success-700);
}

.pf-refusal {
    background: var(--red-100);
    color: var(--red-700);
}
</style>
