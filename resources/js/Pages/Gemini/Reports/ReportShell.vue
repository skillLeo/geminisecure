<script setup>
import { Link } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'

/**
 * The chrome every drill-down report shares — boards 07, 37, 38, 39 and 40.
 *
 * All five draw the same topbar: a back chevron to the catalogue, the report's
 * title, and on two of them an Export control. One component rather than five
 * copies, because the chevron is a 34px circle built from an inline style the
 * board's stylesheet has no class for, and five hand-copies of the same
 * declarations is five chances for one to drift by a pixel.
 *
 * `exportable` is false by default. Boards 39, 40 and 07 draw no Export
 * control at all, and rendering a disabled one there would put a control on a
 * screen the design does not have — the opposite of the fidelity rule, and a
 * worse offence than a missing feature.
 */
defineProps({
    title: { type: String, required: true },
    exportable: { type: Boolean, default: false },
    exportDisabledReason: { type: String, default: null },
})
</script>

<template>
    <GeminiConsole :title="title">
        <template #lead>
            <Link href="/reports" class="topbar-back" title="Back to reports" aria-label="Back to reports">
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

        <template v-if="exportable" #actions>
            <button type="button" class="btn-outline-sm" disabled :title="exportDisabledReason">
                <BoardIcon name="export" :stroke="1.8" />
                <span>Export</span>
            </button>
        </template>

        <slot />
    </GeminiConsole>
</template>

<style scoped>
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

/*
 * Default-removal: the board's Export is a <div>; as a <button> it brings a
 * system font and buttonface grey.
 *
 * `border` is deliberately NOT reset. The board gives .btn-outline-sm a 1.5px
 * navy outline, and resetting it here out-specifies the board and strips that
 * outline off the control entirely — a real fidelity defect that survived
 * several passing measurements, because 1.5px on one small button is well under
 * the threshold. The browser's own border never needed removing: an author rule
 * already beats the user agent's.
 */
button.btn-outline-sm {
    font: inherit;
    cursor: pointer;
}

button.btn-outline-sm[disabled] {
    cursor: not-allowed;
}
</style>
