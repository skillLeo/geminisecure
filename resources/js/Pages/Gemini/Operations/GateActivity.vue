<script setup>
import { Head } from '@inertiajs/vue3'
import GeminiConsole from '../../../Layouts/GeminiConsole.vue'
import SourceBadge from '../../../Components/SourceBadge.vue'
import Subnav from './Subnav.vue'
import BoardIcon from '../../../Components/BoardIcon.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useLifeSafetyPoll } from '../../../composables/useLifeSafetyPoll'

/**
 * Live gate activity across every client — board screen super-admin-26.
 *
 * READ FROM A CENTRAL TABLE, which is the only way this screen can exist. A
 * cross-client feed built by querying each estate's database in turn would be a
 * tenant-isolation breach wearing a report's clothes. `gate_events` is written
 * centrally at the moment of each scan — Gemini's own account of work its
 * employee did, on a post it staffs — and carries no household, no resident and
 * no money. The table's migration is the whole argument.
 *
 * LIVE, AND IT SAYS SO. The board's topbar carries an amber dot and "updates
 * every 5s", which is a promise: if the feed stopped refreshing, a dispatcher
 * would read a quiet screen as a quiet night. So the poll is real and the
 * indicator is driven by it rather than drawn.
 *
 * A denial is not a failure. It is a guard doing their job, which is why the
 * board colours it rather than flagging it.
 */
const props = defineProps({
    kpis: { type: Array, required: true },
    feed: { type: Array, required: true },
    /**
     * Whether the gate decisions and scans in this feed came from real handsets
     * or from the simulator. See App\Support\SourceBadge.
     */
    sourceBadge: { type: Object, required: true },
})

const state = useScreenState({
    rows: () => props.feed.length,
})

/*
 * The three glyphs the board draws, and the weight it draws each at. A denial
 * is heavier than an admit and a patrol lighter than both — the board's own
 * emphasis, kept rather than normalised to one stroke.
 */
const GLYPH = {
    admit: { icon: 'check', stroke: 3 },
    deny: { icon: 'close', stroke: 2.4 },
    patrol: { icon: 'map', stroke: 1.6 },
}

const glyph = (verdict) => GLYPH[verdict] ?? GLYPH.patrol

/*
 * Five seconds, because the topbar says five seconds. The indicator is a
 * promise a dispatcher reads a quiet screen against, and a poll on a different
 * cadence from the one it claims makes that promise a lie.
 */
useLifeSafetyPoll(['kpis', 'feed'], 5000)
</script>

<template>
    <Head title="Live gate activity" />

    <GeminiConsole title="Live gate activity">
            <template #byline>
                <SourceBadge v-bind="sourceBadge" />
            </template>

        <template #actions>
            <!--
              A status indicator, not a control. It is a <div> here because the
              board draws one and because there is nothing to click: it reports
              that the poll is running. A button that did nothing would be worse
              than the text.
            -->
            <div class="btn-outline-sm">
                <svg viewBox="0 0 24 24" fill="none">
                    <circle cx="6" cy="6" r="4" fill="#B87908" />
                </svg>
                <span>Live · updates every 5s</span>
            </div>
        </template>

        <Subnav active="gate-activity" />

        <div class="kpi-row">
            <div v-for="kpi in kpis" :key="kpi.key" class="kpi-card">
                <div class="k-top">
                    <div class="kpi-icon">
                        <BoardIcon
                            :name="kpi.icon"
                            :stroke="kpi.icon === 'check' ? 3 : kpi.icon === 'close' ? 2.4 : 1.7"
                        />
                    </div>
                </div>
                <div class="k-val">{{ kpi.value }}</div>
                <div class="k-lbl">{{ kpi.label }}</div>
            </div>
        </div>

        <div style="background: var(--white); border: 1px solid var(--navy-100); border-radius: 16px; overflow: hidden">
            <SkeletonRows v-if="state.isLoading.value" :rows="8" :columns="4" />

            <!--
              Nothing at the gates. On a live feed that is a fact worth stating
              rather than an empty box: a dispatcher needs to know the screen is
              working and the night is quiet, not wonder which.
            -->
            <EmptyState
                v-else-if="state.isEmpty.value"
                variant="first-use"
                title="Nothing at the gates yet today"
                body="Admits, denials and patrol check-ins appear here the moment a guard records them, across every client."
            />

            <div v-for="(row, i) in feed" v-else :key="i" class="feed-row">
                <div class="feed-icon" :class="row.verdict">
                    <BoardIcon :name="glyph(row.verdict).icon" :stroke="glyph(row.verdict).stroke" />
                </div>
                <div class="feed-txt">
                    <div class="f1">{{ row.headline }}</div>
                    <div class="f2">{{ row.detail }}</div>
                </div>
                <div class="feed-client-tag">{{ row.estate }}</div>
                <div class="feed-time">{{ row.time }}</div>
            </div>
        </div>
    </GeminiConsole>
</template>
