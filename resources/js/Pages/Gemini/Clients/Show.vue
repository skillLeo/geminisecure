<script setup>
import { Head, Link } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'

defineProps({
    estate: { type: Object, required: true },
})
</script>

<template>
    <Head :title="estate.name" />

    <GeminiConsole :title="estate.name">
        <div class="panel">
            <div class="panel-head">
                <h2>Estate profile</h2>
                <Link href="/clients">Back to clients</Link>
            </div>

            <table class="data-table">
                <tbody>
                    <tr>
                        <td class="cell-strong">Name</td>
                        <td>{{ estate.name }}</td>
                    </tr>
                    <tr>
                        <td class="cell-strong">Subdomain</td>
                        <td class="cell-mono">{{ estate.id }}</td>
                    </tr>
                    <tr>
                        <td class="cell-strong">Status</td>
                        <td><span class="status-badge" :class="estate.status">{{ estate.status }}</span></td>
                    </tr>
                    <tr>
                        <td class="cell-strong">Provisioned</td>
                        <td class="cell-mono">{{ estate.provisioned_at ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="cell-strong">Database</td>
                        <td class="cell-mono">{{ estate.database }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </GeminiConsole>
</template>
