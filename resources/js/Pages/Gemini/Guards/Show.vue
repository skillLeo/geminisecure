<script setup>
import { Head, Link } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'

defineProps({
    guard: { type: Object, required: true },
})
</script>

<template>
    <Head :title="guard.name" />

    <GeminiConsole :title="guard.name">
        <div
            v-if="guard.licence_state === 'expired'"
            class="licence-alert"
        >
            <strong>PSRA licence expired {{ guard.psra_expires_on }}.</strong>
            This guard cannot lawfully stand a post until the licence is renewed.
        </div>

        <div class="panel">
            <div class="panel-head">
                <h2>Guard record</h2>
                <Link href="/guards">Back to workforce</Link>
            </div>

            <table class="data-table">
                <tbody>
                    <tr>
                        <td class="cell-strong">Name</td>
                        <td>{{ guard.name }}</td>
                    </tr>
                    <tr>
                        <td class="cell-strong">Employee number</td>
                        <td class="cell-mono">{{ guard.employee_number }}</td>
                    </tr>
                    <tr>
                        <td class="cell-strong">PSRA number</td>
                        <td class="cell-mono">{{ guard.psra_number }}</td>
                    </tr>
                    <tr>
                        <td class="cell-strong">Licence expires</td>
                        <td class="cell-mono">{{ guard.psra_expires_on ?? 'Not recorded' }}</td>
                    </tr>
                    <tr>
                        <td class="cell-strong">Employment</td>
                        <td>{{ guard.employment_type }}</td>
                    </tr>
                    <tr>
                        <td class="cell-strong">Status</td>
                        <td>
                            <span class="status-badge" :class="guard.status_badge">{{ guard.status_label }}</span>
                        </td>
                    </tr>
                    <tr>
                        <td class="cell-strong">Client</td>
                        <td>{{ guard.estate }}</td>
                    </tr>
                    <tr>
                        <td class="cell-strong">Post</td>
                        <td>{{ guard.post }}</td>
                    </tr>
                    <tr>
                        <td class="cell-strong">Hired</td>
                        <td class="cell-mono">{{ guard.hired_on ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </GeminiConsole>
</template>

<style scoped>
.licence-alert {
    background: var(--red-100);
    color: var(--red-700);
    border-radius: 12px;
    padding: 13px 16px;
    font-size: 12.5px;
    line-height: 1.55;
    margin-bottom: 16px;
}
</style>
