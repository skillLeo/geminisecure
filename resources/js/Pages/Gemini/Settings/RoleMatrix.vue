<script setup>
import { Head } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'

defineProps({
    consoles: { type: Array, required: true },
})

/**
 * Pill class per level, matching the wireframe legend.
 *
 * `entry` uses the green tint because the wireframe's legend defines it that
 * way ("Data entry, no approval"), not because it signals success.
 */
const pillClass = (level) => `perm-pill ${level === 'none' ? 'none' : level}`
</script>

<template>
    <Head title="Role access matrix" />

    <GeminiConsole title="Platform settings">
        <div class="matrix-lede">
            This grid is the source of truth. Navigation is generated from it at runtime, so a
            module a role cannot use is absent from that role's sidebar and unreachable by URL
            &mdash; not merely hidden.
        </div>

        <div class="role-legend">
            <div class="role-legend-item"><span class="perm-pill full">Full</span><span>Create, edit, approve</span></div>
            <div class="role-legend-item"><span class="perm-pill view">View</span><span>Read-only</span></div>
            <div class="role-legend-item"><span class="perm-pill entry">Entry</span><span>Data entry, no approval</span></div>
            <div class="role-legend-item"><span class="perm-pill none">&mdash;</span><span>No access</span></div>
            <div class="role-legend-item"><span class="approver-tag">Approver</span><span>May commit the irreversible act</span></div>
        </div>

        <section v-for="console in consoles" :key="console.key" class="matrix-block">
            <div class="panel-head">
                <h2>{{ console.label }}</h2>
                <span class="matrix-count">{{ console.roles.length }} roles &times; {{ console.modules.length }} modules</span>
            </div>

            <div class="matrix-scroll">
                <table class="matrix-table">
                    <thead>
                        <tr>
                            <th>Module</th>
                            <th v-for="role in console.roles" :key="role.id">
                                <div class="role-head-name">{{ role.label }}</div>
                                <div v-if="role.scope_narrows" class="role-head-scope">{{ role.scope }}</div>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="module in console.modules" :key="module.id">
                            <td>
                                <div class="mod-name">
                                    <span v-if="module.locked_financial" class="lock-mark" title="Locked financial module">&#128274;</span>
                                    {{ module.label }}
                                </div>
                            </td>
                            <td v-for="(cell, i) in module.cells" :key="i">
                                <div :class="pillClass(cell.level)">
                                    {{ cell.label }}<span v-if="cell.can_approve" class="approver-tag">Approver</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="audit-note">
            Locked financial modules can never be granted to the Property Manager, by any route
            including a hand-edited matrix. Whoever commissions work must not be able to pay for
            it, nor see a resident's financial position. Every permission change writes to an
            immutable audit log.
        </div>
    </GeminiConsole>
</template>

<style scoped>
.matrix-lede,
.audit-note {
    font-size: 12.5px;
    color: var(--slate-600);
    line-height: 1.6;
    max-width: 760px;
    margin-bottom: 16px;
}

.audit-note {
    margin-top: 18px;
    background: var(--navy-100);
    border-radius: 12px;
    padding: 13px 16px;
    max-width: none;
}

.role-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 18px;
    margin-bottom: 18px;
}

.role-legend-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 11.5px;
    color: var(--slate-600);
}

.matrix-block {
    margin-bottom: 26px;
}

.matrix-count {
    font-size: 11.5px;
    color: var(--slate-500);
    font-weight: 600;
}

/* Wide tables scroll inside their own container; the page never scrolls sideways. */
.matrix-scroll {
    overflow-x: auto;
}

.matrix-table {
    width: 100%;
    border-collapse: collapse;
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    overflow: hidden;
}

.matrix-table thead th {
    text-align: center;
    font-size: 10.5px;
    font-weight: 700;
    color: var(--navy-900);
    padding: 12px 8px;
    background: var(--navy-100);
    border-bottom: 1px solid var(--navy-200);
}

.matrix-table thead th:first-child {
    text-align: left;
    padding-left: 16px;
}

.matrix-table tbody td {
    padding: 11px 8px;
    border-bottom: 1px solid var(--navy-100);
    text-align: center;
}

.matrix-table tbody tr:last-child td {
    border-bottom: none;
}

.matrix-table tbody td:first-child {
    text-align: left;
    padding-left: 16px;
}

.role-head-name {
    font-size: 10.5px;
}

.role-head-scope {
    font-size: 9px;
    font-weight: 600;
    color: var(--amber-700);
    margin-top: 2px;
}

.mod-name {
    font-size: 12px;
    font-weight: 600;
    color: var(--navy-900);
    white-space: nowrap;
}

.lock-mark {
    margin-right: 4px;
}

.perm-pill {
    display: inline-block;
    font-size: 9px;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 20px;
    white-space: nowrap;
}

.perm-pill.full {
    background: var(--amber-100);
    color: var(--amber-700);
}

.perm-pill.view {
    background: var(--navy-100);
    color: var(--navy-700);
}

.perm-pill.entry {
    background: var(--success-100);
    color: var(--success-700);
}

.perm-pill.none {
    background: transparent;
    color: var(--slate-300);
}

.approver-tag {
    display: inline-block;
    margin-left: 5px;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    color: var(--navy-700);
    background: var(--white);
    border: 1px solid var(--navy-200);
    border-radius: 20px;
    padding: 1px 5px;
}
</style>
