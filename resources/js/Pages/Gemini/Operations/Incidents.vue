<script setup>
import { computed, ref } from 'vue'
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import Subnav from './Subnav.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'

/**
 * Security incident log — board screen super-admin-27.
 *
 * The record a security company is judged on: by an insurer after a claim, by a
 * client deciding whether to renew, and by the PSRA. These are incidents on
 * posts Gemini staffs involving guards it employs, which is why the log is
 * central and readable across clients without opening an estate database.
 *
 * OPEN IS A STATE WITH CONSEQUENCES. An incident stays open until somebody
 * records what was done about it, and the badge says so because an incident
 * nobody closed is the one that gets asked about later.
 *
 * LOGGING IS A STRUCTURED INTAKE (12 §2, item 27): the client, when, what kind,
 * how serious, what happened, and the guard on post where there was one. It is
 * authored — the board draws a log, not a form — and closed on a fresh GET, so
 * it costs nothing against the board in the state the board draws.
 *
 * DURESS ALERTS ARE NOT INCIDENTS. The board spends its closing panel saying
 * none are on record, and that is the reassurance rather than an empty state:
 * a duress alert is a life-safety event with its own table, its own broadcast
 * channel and its own response screen. Folding them into a case log would put
 * a panic button behind case management.
 */
const props = defineProps({
    incidents: { type: Array, required: true },
    duress_count: { type: Number, required: true },
    canLog: { type: Boolean, required: true },
    clients: { type: Array, required: true },
    officers: { type: Array, required: true },
    severities: { type: Object, required: true },
    writeDisabledReason: { type: String, required: true },
})

const state = useScreenState({
    rows: () => props.incidents.length,
})

const page = usePage()

const logging = ref(false)

const form = useForm({
    tenant_id: props.clients[0]?.id ?? '',
    guard_id: '',
    occurred_at: '',
    kind: '',
    severity: 'low',
    detail: '',
})

/* The guards at the chosen client, first, then everyone else this role covers. */
const officersForClient = computed(() => props.officers.filter((o) => o.tenant_id === form.tenant_id))
const otherOfficers = computed(() => props.officers.filter((o) => o.tenant_id !== form.tenant_id))

const submit = () => {
    form.transform((data) => ({ ...data, guard_id: data.guard_id === '' ? null : data.guard_id })).post('/guards/incidents')
}
</script>

<template>
    <Head title="Security incident log" />

    <GeminiConsole title="Security incident log">
        <template #actions>
            <button
                type="button"
                class="btn-primary-sm"
                :disabled="!canLog"
                :title="canLog ? 'Record an incident on a Gemini-staffed post.' : writeDisabledReason"
                @click="logging = !logging"
            >
                <BoardIcon name="plus" :stroke="2" />
                <span>Log incident</span>
            </button>
        </template>

        <Subnav active="incidents" />

        <p v-if="page.props.errors?.incident" class="inc-refusal">{{ page.props.errors.incident }}</p>

        <form v-if="logging" class="inc-panel" @submit.prevent="submit">
            <div class="inc-head">
                An incident record is evidence: an insurer, the client and the PSRA read it. Every field but the guard is
                required, and the record stays open until what was done about it is written down.
            </div>

            <div class="inc-fields">
                <div class="inc-field">
                    <label for="inc-client">Client</label>
                    <select id="inc-client" v-model="form.tenant_id" required>
                        <option v-for="client in clients" :key="client.id" :value="client.id">{{ client.name }}</option>
                    </select>
                </div>
                <div class="inc-field">
                    <label for="inc-when">When it happened</label>
                    <input id="inc-when" v-model="form.occurred_at" type="datetime-local" required />
                </div>
                <div class="inc-field">
                    <label for="inc-kind">What kind of incident</label>
                    <input
                        id="inc-kind"
                        v-model="form.kind"
                        type="text"
                        maxlength="160"
                        placeholder="Attempted unauthorized access"
                        required
                    />
                </div>
                <div class="inc-field">
                    <label for="inc-severity">Severity</label>
                    <select id="inc-severity" v-model="form.severity" required>
                        <option v-for="(label, key) in severities" :key="key" :value="key">{{ label }}</option>
                    </select>
                </div>
                <div class="inc-field inc-field--wide">
                    <label for="inc-guard">Guard on post — optional</label>
                    <select id="inc-guard" v-model="form.guard_id">
                        <option value="">No guard involved</option>
                        <optgroup v-if="officersForClient.length" label="Posted at this client">
                            <option v-for="o in officersForClient" :key="o.id" :value="o.id">{{ o.label }}</option>
                        </optgroup>
                        <optgroup v-if="otherOfficers.length" label="Elsewhere">
                            <option v-for="o in otherOfficers" :key="o.id" :value="o.id">{{ o.label }}</option>
                        </optgroup>
                    </select>
                </div>
                <div class="inc-field inc-field--wide">
                    <label for="inc-detail">What happened</label>
                    <textarea id="inc-detail" v-model="form.detail" rows="4" maxlength="4000" required />
                </div>
            </div>

            <div v-for="(message, key) in form.errors" :key="key" class="inc-error">{{ message }}</div>

            <div class="inc-actions">
                <button type="submit" class="btn-primary-sm" :disabled="form.processing" title="Record it, open.">
                    <span>{{ form.processing ? 'Logging…' : 'Log incident' }}</span>
                </button>
                <button type="button" class="text-link-sm" @click="logging = false">Cancel</button>
            </div>
        </form>

        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="6" />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="No incidents recorded"
            body="Nothing has been logged against a Gemini-staffed post. Incidents appear here as they are recorded, across every client."
        />

        <table v-else class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Client</th>
                    <th>Type</th>
                    <th>Guard involved</th>
                    <th>Severity</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="incident in incidents" :key="incident.id">
                    <td>{{ incident.date }}</td>
                    <td>{{ incident.estate }}</td>
                    <td>{{ incident.kind }}</td>
                    <td>{{ incident.guard }}</td>
                    <td>
                        <div class="severity-badge" :class="incident.severity">{{ incident.severity_label }}</div>
                    </td>
                    <td>
                        <div class="status-badge" :class="incident.status">{{ incident.status_label }}</div>
                    </td>
                    <td>
                        <Link :href="`/guards/incidents/${incident.id}`" class="text-link-sm">View</Link>
                    </td>
                </tr>
            </tbody>
        </table>

        <!--
          The duress panel. Shown when nothing has been triggered, because
          "nothing has happened" is the single most reassuring thing this screen
          can report and a blank space does not report it.
        -->
        <div v-if="duress_count === 0" class="empty-state">
            <BoardIcon name="shield" :stroke="1.7" />
            <div class="es1">No duress alerts on record</div>
            <div class="es2">
                Every guard's duress button is monitored live through Gate Activity. Nothing has been triggered across
                any client to date.
            </div>
        </div>
    </GeminiConsole>
</template>

<style scoped>
/*
 * Default-removal first. The board draws the topbar action and the row link as
 * <div>s; as real controls the button arrives with a border, a face and Arial,
 * and the row link sits inline on a baseline where the board's is a block.
 */
button.btn-primary-sm {
    border: 0;
    font: inherit;
    cursor: pointer;
}

a.text-link-sm {
    display: block;
    text-decoration: none;
}

button.btn-primary-sm[disabled] {
    cursor: not-allowed;
}

button.text-link-sm {
    border: 0;
    background: transparent;
    font: inherit;
    cursor: pointer;
}

/*
 * AUTHORED BELOW THIS LINE: the intake, which the board does not draw. Closed on
 * a fresh GET, and kept to the tokens the Gemini boards define.
 */
.inc-refusal,
.inc-error {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    border-radius: 10px;
    padding: 9px 13px;
    background: var(--red-100);
    color: var(--red-700);
}

.inc-refusal {
    margin: 0 0 14px;
}

.inc-panel {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 16px;
    margin-bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.inc-head {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--navy-800);
    line-height: 1.55;
    max-width: 800px;
}

.inc-fields {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
}

.inc-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.inc-field--wide {
    grid-column: span 2;
}

.inc-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.inc-field input,
.inc-field select,
.inc-field textarea {
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 7px 10px;
    font: inherit;
    font-size: 12px;
    color: var(--navy-900);
}

.inc-field input,
.inc-field select {
    height: 33px;
    padding: 0 10px;
}

.inc-actions {
    display: flex;
    align-items: center;
    gap: 14px;
}
</style>
