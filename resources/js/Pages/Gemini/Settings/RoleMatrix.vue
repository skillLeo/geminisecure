<script setup>
import { Head } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import SettingsTabs from './SettingsTabs.vue'

/**
 * Role Access Matrix — board screen super-admin-45, drawn at 1440x1140.
 *
 * DOM and class names are the board's: a tab strip and one table, nothing
 * else. Every pill is a row of role_module_access, which is the same table the
 * sidebar is generated from and the same table `can:` resolves against.
 *
 * The cells are read-only, deliberately. Changing a permission is a privileged,
 * audited write with no backend behind it yet, so this screen renders nothing
 * that looks like it could change a cell — no checkbox, no select, no click
 * target on a pill. A display screen must not grow a permission editor as a
 * side effect.
 *
 * The tab strip is SettingsTabs, shared with the other three screens in this
 * module: it is the same seven tabs at the same widths on all four, and a
 * reader moving between them must see it as a fixed thing that does not move.
 *
 * Text in the pills sits tight against its tags on purpose: the board's pill is
 * a <div> whose only child is the label, and a stray space either side would
 * widen it.
 */
defineProps({
    tabs: { type: Array, required: true },
    roles: { type: Array, required: true },
    modules: { type: Array, required: true },
})

/**
 * The board hard-breaks a multi-word role name before its last word —
 * "Operations / Manager", "Head of / Security", "Admin / Assistant" — so the
 * six columns keep the widths the design allots them. Derived from the label
 * rather than hardcoded per role, so a renamed role still breaks in the right
 * place and a one-word name stays on one line.
 */
const nameLines = (label) => {
    const words = String(label).trim().split(/\s+/)

    return words.length < 2 ? [label] : [words.slice(0, -1).join(' '), words[words.length - 1]]
}
</script>

<template>
    <Head title="Role access matrix" />

    <GeminiConsole title="Platform settings">
        <SettingsTabs :tabs="tabs" />

        <table class="matrix-table">
            <thead>
                <tr>
                    <th>Module</th>
                    <th v-for="role in roles" :key="role.id">
                        <div class="role-head-name">
                            <template v-for="(line, i) in nameLines(role.label)" :key="i"
                                ><br v-if="i" />{{ line }}</template
                            >
                        </div>
                        <div class="role-head-scope">{{ role.scope }}</div>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="module in modules" :key="module.id">
                    <td>
                        <div class="mod-name">{{ module.label }}</div>
                    </td>
                    <td v-for="(cell, i) in module.cells" :key="i">
                        <div class="perm-pill" :class="cell.variant">{{ cell.label }}</div>
                    </td>
                </tr>
            </tbody>
        </table>
    </GeminiConsole>
</template>

<style scoped>
/*
 * The only authored CSS here, and only to take defaults back off.
 *
 * The board draws all seven tabs as <div>. This renders the one with a route
 * as a Link and the six without one as disabled buttons, and the browser
 * brings its own chrome to both element types — an underline on the anchor,
 * and a border, background and Arial font on the button. These rules remove
 * exactly that, so the board's own .subnav-item rule is what is seen. The
 * board's reset already zeroes padding and margin on every element, and
 * .subnav-item sets the font size, weight and colour, so nothing else needs
 * restating.
 */
a.subnav-item {
    text-decoration: none;
}

button.subnav-item {
    appearance: none;
    border: 0;
    background: transparent;
    font-family: inherit;
}

/*
 * Inert, and it says why on hover. Not dimmed: the board draws these tabs at
 * full weight, and the disabled attribute plus the title already rule out a
 * silent click without changing a pixel.
 */
button.subnav-item[disabled] {
    cursor: not-allowed;
}
</style>
