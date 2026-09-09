<script setup>
import { computed } from 'vue'
import { Head } from '@inertiajs/vue3'
import EstateConsole from '../../Layouts/EstateConsole.vue'
import ModuleIcon from '../../Components/ModuleIcon.vue'

const props = defineProps({
    estate: { type: Object, required: true },
    counts: { type: Object, required: true },
    modules: { type: Array, required: true },
})

const kpis = computed(() => [
    { key: 'units', value: props.counts.units, label: 'Units' },
    { key: 'households', value: props.counts.households, label: 'Households' },
    { key: 'residents', value: props.counts.residents, label: 'Residents' },
    { key: 'charges', value: props.counts.outstanding_charges, label: 'Outstanding charges' },
])
</script>

<template>
    <Head :title="estate.name" />

    <EstateConsole :title="estate.name" :estate-name="estate.name" :modules="modules">
        <!--
          States plainly what is and is not built. A placeholder that looks
          broken is indistinguishable from a broken page, and costs the reader
          time working out which one they are looking at.
        -->
        <div class="phase-note">
            <strong>The Estate Console is Phase 5.</strong>
            This estate's data is live and isolated in its own database, and the sidebar shows
            exactly the modules your role will hold &mdash; generated from the role access matrix,
            not hand-written. The screens behind them are not built yet.
        </div>

        <div class="kpi-row">
            <div v-for="kpi in kpis" :key="kpi.key" class="kpi-card">
                <div class="k-top">
                    <div class="kpi-icon"><ModuleIcon module="clients" /></div>
                </div>
                <div class="k-val">{{ kpi.value }}</div>
                <div class="k-lbl">{{ kpi.label }}</div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">
                <h2>What your role can reach</h2>
                <span class="module-count">{{ modules.length }} modules</span>
            </div>

            <table class="data-table">
                <thead>
                    <tr>
                        <th>Module</th>
                        <th>Section</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="module in modules" :key="module.key">
                        <td class="cell-strong">{{ module.label }}</td>
                        <td>{{ module.section ?? '—' }}</td>
                        <td><span class="status-badge pending">Phase 5</span></td>
                    </tr>
                </tbody>
            </table>

            <p class="matrix-note">
                A module your role cannot use is absent from this list entirely, never shown and
                disabled. If a module you expect is missing, that is the matrix, not a fault.
            </p>
        </div>
    </EstateConsole>
</template>

<style scoped>
.phase-note {
    background: var(--amber-100);
    color: var(--amber-700);
    border-radius: 12px;
    padding: 13px 16px;
    font-size: 12.5px;
    line-height: 1.6;
    margin-bottom: 16px;
}

.module-count {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--slate-500);
}

.matrix-note {
    font-size: 11.5px;
    color: var(--slate-500);
    line-height: 1.55;
    margin-top: 12px;
}
</style>
