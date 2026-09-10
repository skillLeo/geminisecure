<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { pendingReason } from './sections'

/**
 * Feature toggles — board screen community-admin-23.
 *
 * WHAT THIS ESTATE'S PLAN OFFERS IT AND WHAT THIS ESTATE DECIDED, and the two
 * are not the same question. Every row's effective state is resolved at the
 * boundary in a fixed order — core is on, else this community's own override,
 * else whether the plan includes it — so nothing here reads a stored "enabled"
 * flag, because `estate_features` holds overrides ONLY. A row per feature per
 * estate would look decided when nothing had been decided, and the day the plan
 * changed, eleven stale copies of its old answer would outvote it.
 *
 * A CHANGE HERE NEEDS A STATED REASON, AND THE REASON IS TYPED. Board 23's own
 * audit note is the requirement: "every toggle change writes an audit record
 * with actor, timestamp, and reason", and the service refuses a blank one. So a
 * switch does not post when it is pressed — it ARMS, and a field opens beneath
 * the row asking why. A dialog would have been the other way to do it; this is
 * the one that leaves the board untouched until somebody presses something,
 * which is the state the board is a picture of.
 *
 * ELEVEN ROWS, AND ONLY TEN OF THEM ARE FEATURES (D-050). The eleventh is
 * "Security guard payroll routing", which is not in the catalogue at all: guards
 * are Gemini Security Limited's employees, paid by Gemini, filed under Gemini's
 * TRN. It is emitted from `estate_settings` and drawn locked, and
 * `Settings::setRouting()` refuses the key by name — an estate that routed guard
 * pay in-house would be claiming to employ people it does not employ and posting
 * a payroll liability it has no obligation to settle.
 *
 * A FOURTH GROUP THE BOARD DOES NOT DRAW: "Held back in this release". The
 * biometric consent flag (D-022), card payments for dues (D-023) and geofenced
 * clock-in (D-033) are each a ruling rather than a feature, each is a named flag
 * on `estate_settings` in its safest position, and each is drawn locked with the
 * ruling on it. None of the three was added to the central catalogue, because
 * that table is the package builder's and putting a legally-blocked feature into
 * it would offer it for sale. Stating a decision is the difference between a
 * decision and an oversight.
 *
 * THE AUDIT NOTE IS DRAWN ABOVE THE HELD-BACK GROUP AND NOT BELOW IT, which is
 * one place away from the order the payload lists the groups in. The note is
 * about what happens WHEN a toggle is changed, and not one row in the group
 * below it can be changed by anybody — so it reads as a footer to the ten live
 * rows and a preamble to the three that are shut. It also keeps the board's own
 * closing sentence on screen: `.content` clips at the fold, and three more rows
 * above the note would push the note past it.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    /** The seven-item settings column, with this screen marked current. */
    sections: { type: Array, required: true },
    /** The plan banner: the estate's plan, its sentence and its badge. */
    plan: { type: Object, required: true },
    /** Tiers in ascending order, then the held-back group. */
    groups: { type: Array, required: true },
    audit_note: { type: String, required: true },
    canToggle: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
})

/*
 * Which board's stylesheet this page wears. The ten Estate Console boards do
 * NOT share one sheet the way the nine Gemini boards do, so every estate page
 * has to name its own or it renders with no board CSS at all.
 */
useWireframe('community-admin-06-settings-estate-profile-users-and-roles')

const page = usePage()

/*
 * Where this console is rooted, read off the page's own URL. Production gives
 * each estate its own hostname and no prefix; local serves every estate from one
 * host with the estate key in the path. The URL that served this page already
 * carries whichever shape this environment uses.
 */
const root = computed(() => page.url.slice(0, page.url.indexOf('/settings')))

/**
 * The three tier badge variants the board defines, keyed by the tier the server
 * sends.
 *
 * `essential` is the plan key and `core` is what the board calls the badge, and
 * they are deliberately not renamed into each other: the plan is a commercial
 * record and the badge is a word on a pill.
 */
const TIER_CLASS = { essential: 'core', standard: 'standard', premium: 'premium' }

/**
 * The badge on a row with no tier at all.
 *
 * The three held-back rows belong to no plan — they are rulings, not products —
 * so the board defines no variant for them and one is not invented. The neutral
 * pair is the board's own, lifted off `.tier-req.core`, because "Held" is a
 * statement of fact rather than a fault to flag. Declared inline for the same
 * reason board 26 declares its amber inline: borrowing a variant named for
 * something else puts the wrong word's colour on the right fact.
 */
const HELD_BADGE = 'background:var(--navy-100);color:var(--slate-600);'

/* ------------------------------------------------------------------ */
/* why a control is inert */
/* ------------------------------------------------------------------ */

/**
 * Why a core row's switch cannot be moved.
 *
 * The service's own refusal, said before the press rather than after it. A core
 * feature is not an estate setting at any price: the panic button, visitor
 * passes and amenity booking are what this platform IS.
 */
const coreLocked = (row) =>
    `“${row.label}” is a core feature and is on at every tier. The panic button, visitor passes and amenity booking are not behind a price tier and are not an estate setting.`

/**
 * Why the branding scale is a readout and not a control.
 *
 * Board 23 lists the three dots among its controls and the model overrules it
 * (D-044): every tier has custom branding and the tiers differ in HOW MUCH, so
 * there is no state here to switch — the dots report what this estate's plan
 * grants. Drawn as the board draws them, which is not as buttons.
 */
const SCALE_LOCKED =
    'Not a switch. Every tier includes custom branding and the tiers differ in how much of it, so what this estate gets is what its plan grants. Changing it is a commercial change made by Gemini Security against the subscription.'

/**
 * Why this row cannot be changed, or null when it can.
 *
 * THE ROW'S OWN REFUSAL COMES FIRST and the viewer's permission second, which is
 * the opposite of the order most screens use — and deliberately so. A locked row
 * is locked for everybody including the estate's own administrator, so telling a
 * President they lack the permission to switch the panic button off would send
 * them to ask for access that would not help them.
 */
const blockedBy = (row) => {
    if (row.control === 'scale') {
        return SCALE_LOCKED
    }

    if (row.blocked_reason) {
        return row.blocked_reason
    }

    if (row.locked) {
        return coreLocked(row)
    }

    if (!props.canToggle) {
        return props.blockedReason
    }

    return null
}

/** Whether the option a segmented row is already set to can be pressed. */
const alreadySet = (row, option) =>
    `${row.label} is already routed to ${option.label}. Choosing it again would write an audit entry recording that nothing changed.`

/* ------------------------------------------------------------------ */
/* arming a change */
/* ------------------------------------------------------------------ */

/**
 * The change somebody has started, and has not yet said why they are making.
 *
 * Null until a live control is pressed, which is why a screen that has just
 * opened draws exactly what the board draws. It holds the whole intent — which
 * feature, and whether it is a switch or a routing — so the reason field knows
 * what sentence to put above itself and the post knows which shape to send.
 */
const armed = ref(null)
const reason = ref('')

const arm = (row, intent) => {
    if (blockedBy(row) !== null) {
        return
    }

    armed.value = { key: row.key, label: row.label, note: row.note ?? null, ...intent }
    reason.value = ''
}

const disarm = () => {
    armed.value = null
    reason.value = ''
}

/** The sentence above the reason field, in the terms the change will be recorded in. */
const armedSentence = computed(() => {
    if (armed.value === null) {
        return ''
    }

    if ('option' in armed.value) {
        return `Routing “${armed.value.label}” to ${armed.value.optionLabel}. Say why — the change is recorded with your name, the time and this sentence.`
    }

    return `Switching “${armed.value.label}” ${armed.value.enabled ? 'on' : 'off'}. Say why — the change is recorded with your name, the time and this sentence.`
})

/**
 * Why the change cannot be committed yet.
 *
 * FOUR CHARACTERS IS THE SERVICE'S OWN FLOOR, restated here so the refusal
 * arrives before the round trip rather than after it. It is not a formality: an
 * unexplained change to what several hundred households can do is exactly what
 * an audit has to be able to question a year from now, and "x" is not an
 * explanation.
 */
const commitBlockedBy = computed(() => {
    if (armed.value === null) {
        return 'Nothing has been changed yet.'
    }

    if (reason.value.trim().length < 4) {
        return 'A feature change needs a stated reason. Turning a module off changes what several hundred households can do, and an unexplained change to that is exactly what an audit has to be able to question.'
    }

    return null
})

const commit = () => {
    if (commitBlockedBy.value !== null) {
        return
    }

    const change = armed.value

    router.post(
        `${root.value}/settings/features/${change.key}`,
        'option' in change
            ? { option: change.option, reason: reason.value }
            : { enabled: change.enabled, reason: reason.value },
        { preserveScroll: true, onSuccess: disarm }
    )
}

/* ------------------------------------------------------------------ */
/* the six states */
/* ------------------------------------------------------------------ */

/**
 * How many rows the catalogue actually produced, held back ones excluded.
 *
 * The held-back three are this release's own and exist whatever the catalogue
 * holds, so counting them would make an estate with no features at all look
 * populated — three rulings and nothing to rule on.
 */
const catalogueRows = computed(() =>
    props.groups
        .filter((group) => group.key !== 'held_back')
        .reduce((n, group) => n + group.rows.length, 0)
)

/*
 * Sources are functions, not values: useScreenState runs once during setup, and
 * a value read there would freeze on the first render.
 *
 * THE SIXTH IS REAL AND IS REACHED FROM DATA. `featuresBoard()` drops any
 * catalogue feature that no plan includes — a row somebody is still drafting,
 * and drawing it would offer a community something nobody sells — so a
 * catalogue that is full of drafts produces a screen with no groups and no rows,
 * which is emptiness caused by the filter rather than by an empty catalogue.
 * That is a different sentence from "nothing is on offer at all", and both are
 * below.
 */
const state = useScreenState({
    rows: () => catalogueRows.value,
    filtered: () => props.plan.key !== null,
})

const retry = () => router.reload()
</script>

<template>
    <Head title="Settings" />

    <!--
      No topbar action at all. Board 23 draws an h1 and no .top-right block:
      nothing on this screen is created, so there is nothing for a primary
      button to do.
    -->
    <EstateConsole title="Settings" :estate-name="estate.name" active="settings">
        <div class="settings-layout">
            <!--
              The settings column, on every one of the four screens. The item
              the reader is on is text rather than a control — there is nowhere
              for it to lead — the three built siblings are real links, and the
              three unbuilt ones are visibly inert and each says what it is
              waiting on rather than linking into a 404.
            -->
            <div class="settings-nav">
                <template v-for="section in sections" :key="section.key">
                    <div v-if="section.active" class="settings-nav-item active" aria-current="page">
                        {{ section.label }}
                    </div>
                    <Link v-else-if="section.href" :href="section.href" class="settings-nav-item">
                        {{ section.label }}
                    </Link>
                    <button
                        v-else
                        type="button"
                        class="settings-nav-item"
                        disabled
                        :title="pendingReason(section.key)"
                    >
                        {{ section.label }}
                    </button>
                </template>
            </div>

            <div>
                <!--
                  Neither is on the board and neither is drawn on a fresh GET.
                  `enabled` is the key the service refuses a toggle against,
                  whatever the refusal is actually about — the plan, the
                  catalogue or the ruling — because that is the control the
                  person moved.
                -->
                <p v-if="page.props.flash.success" class="feat-flash">{{ page.props.flash.success }}</p>
                <p v-if="page.props.errors.enabled" class="feat-refusal">{{ page.props.errors.enabled }}</p>
                <p v-if="page.props.errors.reason" class="feat-refusal">{{ page.props.errors.reason }}</p>
                <p v-if="page.props.errors.option" class="feat-refusal">{{ page.props.errors.option }}</p>

                <SkeletonRows v-if="state.isLoading.value" :rows="6" :columns="3" />

                <EmptyState
                    v-else-if="state.isDenied.value"
                    variant="denied"
                    title="Settings is not part of your role’s access"
                    body="Switching a module off changes what several hundred households can do tomorrow, so the feature panel sits inside Settings and opens to the Community Super Admin, the President and the Vice President only. Reading it and changing it are separate permissions again: a President opens this screen and can move nothing on it."
                />

                <EmptyState
                    v-else-if="state.isError.value"
                    variant="error"
                    title="The feature panel could not be read"
                    body="The plan, its features and this estate’s own decisions could not be resolved. Nothing has been switched and no routing has moved — every module is running exactly as it was."
                    action-label="Try again"
                    @action="retry"
                />

                <!--
                  A catalogue with rows in it and no plan including any of them.
                  See the note on `state`: this is emptiness caused by the
                  filter, and it is the estate's subscription that decides it.
                -->
                <EmptyState
                    v-else-if="state.isEmptyFiltered.value"
                    variant="filtered"
                    title="Nothing in the catalogue belongs to a plan yet"
                    body="The platform holds features and not one of them has been put into a plan, so there is nothing this estate can be offered and nothing it can turn off. A feature no plan includes is a draft in the package builder, and drawing it here would offer a community something nobody sells."
                />

                <EmptyState
                    v-else-if="state.isEmpty.value"
                    variant="first-use"
                    title="This estate has no subscription on record"
                    body="What an estate may switch on is what its plan includes, and this one has no plan. Until a subscription is activated by Gemini Security there is nothing to resolve a feature against — not even a default, because a default nobody is paying for is a promise this console cannot keep."
                />

                <template v-else>
                    <!--
                      The plan banner. The sentence is assembled at the boundary
                      from the estate's name and the plan's, so this page never
                      joins the two — and the badge is the plan's own name
                      rather than a word chosen to match it.
                    -->
                    <div class="plan-banner">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path
                                d="M12 2 2 7v6c0 5.2 3.8 9 10 11 6.2-2 10-5.8 10-11V7l-10-5z"
                                stroke="currentColor"
                                stroke-width="1.6"
                                stroke-linejoin="round"
                            />
                        </svg>
                        <div>
                            <div class="pb1">{{ plan.banner }}</div>
                            <div class="pb2">{{ plan.note }}</div>
                        </div>
                        <div v-if="plan.name" class="pb-badge">{{ plan.name }}</div>
                    </div>

                    <template v-for="group in groups" :key="group.key">
                        <!--
                          The audit note sits between the last tier group and
                          the held-back group. See the file's own note: it is a
                          footer to the rows that can change and a preamble to
                          the three that cannot.
                        -->
                        <div v-if="group.key === 'held_back'" class="audit-note">
                            <svg viewBox="0 0 24 24" fill="none">
                                <path
                                    d="M12 2 2 7v6c0 5.2 3.8 9 10 11 6.2-2 10-5.8 10-11V7l-10-5z"
                                    stroke="currentColor"
                                    stroke-width="1.5"
                                    stroke-linejoin="round"
                                />
                            </svg>
                            <span>{{ audit_note }}</span>
                        </div>

                        <div class="feat-group">
                            <div class="feat-group-head">{{ group.heading }}</div>

                            <div class="feat-panel">
                                <template v-for="row in group.rows" :key="row.key">
                                    <div class="feat-row">
                                        <!--
                                          THE GLYPH IS THE FEATURE'S OWN KEY and
                                          not a match on its name: the board
                                          draws eleven distinct marks, and an
                                          estate on a plan that adds a twelfth
                                          feature should get a shape somebody
                                          chose rather than whichever one a
                                          string match landed on.
                                        -->
                                        <div class="feat-icon" :class="{ locked: row.locked }">
                                            <svg v-if="row.key === 'resident_core'" viewBox="0 0 24 24" fill="none">
                                                <path
                                                    d="M12 9v4M12 17h.01"
                                                    stroke="currentColor"
                                                    stroke-width="1.9"
                                                    stroke-linecap="round"
                                                />
                                                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" />
                                            </svg>
                                            <svg
                                                v-else-if="row.key === 'visitor_passes'"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                            >
                                                <rect
                                                    x="3"
                                                    y="6"
                                                    width="18"
                                                    height="14"
                                                    rx="2"
                                                    stroke="currentColor"
                                                    stroke-width="1.6"
                                                />
                                                <path
                                                    d="M3 8l9 6 9-6"
                                                    stroke="currentColor"
                                                    stroke-width="1.6"
                                                    stroke-linejoin="round"
                                                />
                                            </svg>
                                            <svg
                                                v-else-if="row.key === 'amenity_booking'"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                            >
                                                <path
                                                    d="M3 10l9-6 9 6"
                                                    stroke="currentColor"
                                                    stroke-width="1.6"
                                                    stroke-linejoin="round"
                                                />
                                                <path
                                                    d="M5 10v9M11 10v9M13 10v9M19 10v9M3 19h18"
                                                    stroke="currentColor"
                                                    stroke-width="1.6"
                                                />
                                            </svg>
                                            <svg v-else-if="row.key === 'guard_app'" viewBox="0 0 24 24" fill="none">
                                                <path
                                                    d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"
                                                    stroke="currentColor"
                                                    stroke-width="1.7"
                                                />
                                                <circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="1.7" />
                                            </svg>
                                            <svg v-else-if="row.key === 'evoting'" viewBox="0 0 24 24" fill="none">
                                                <path
                                                    d="M9 12l2 2 4-4"
                                                    stroke="currentColor"
                                                    stroke-width="2"
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                />
                                                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" />
                                            </svg>
                                            <svg
                                                v-else-if="row.key === 'gated_meetings'"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                            >
                                                <path
                                                    d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"
                                                    stroke="currentColor"
                                                    stroke-width="1.7"
                                                />
                                                <circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="1.7" />
                                                <path
                                                    d="M23 21v-2a4 4 0 0 0-3-3.9"
                                                    stroke="currentColor"
                                                    stroke-width="1.7"
                                                    stroke-linecap="round"
                                                />
                                            </svg>
                                            <svg
                                                v-else-if="row.key === 'accounting_core'"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                            >
                                                <path
                                                    d="M3 3v18h18"
                                                    stroke="currentColor"
                                                    stroke-width="1.7"
                                                    stroke-linecap="round"
                                                />
                                                <path
                                                    d="M7 15l4-5 4 3 5-7"
                                                    stroke="currentColor"
                                                    stroke-width="1.7"
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                />
                                            </svg>
                                            <svg
                                                v-else-if="row.key === 'estate_payroll'"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                            >
                                                <path
                                                    d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H7"
                                                    stroke="currentColor"
                                                    stroke-width="1.7"
                                                    stroke-linecap="round"
                                                />
                                            </svg>
                                            <svg
                                                v-else-if="row.key === 'security_payroll' || row.key === 'payment_gateway'"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                            >
                                                <rect
                                                    x="2"
                                                    y="4"
                                                    width="20"
                                                    height="16"
                                                    rx="2"
                                                    stroke="currentColor"
                                                    stroke-width="1.7"
                                                />
                                                <path d="M2 9h20M8 4v5" stroke="currentColor" stroke-width="1.7" />
                                            </svg>
                                            <svg v-else-if="row.key === 'ai_drafting'" viewBox="0 0 24 24" fill="none">
                                                <path
                                                    d="M12 3v3m0 12v3m9-9h-3M6 12H3m14.5-6.5l-2.1 2.1M8.6 15.4l-2.1 2.1m0-11l2.1 2.1m8.8 8.8l-2.1-2.1"
                                                    stroke="currentColor"
                                                    stroke-width="1.7"
                                                    stroke-linecap="round"
                                                />
                                                <circle cx="12" cy="12" r="3.5" stroke="currentColor" stroke-width="1.7" />
                                            </svg>
                                            <svg
                                                v-else-if="row.key === 'custom_branding'"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                            >
                                                <path
                                                    d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"
                                                    stroke="currentColor"
                                                    stroke-width="1.6"
                                                    stroke-linejoin="round"
                                                />
                                                <path
                                                    d="M4 22V15"
                                                    stroke="currentColor"
                                                    stroke-width="1.6"
                                                    stroke-linecap="round"
                                                />
                                            </svg>
                                            <svg
                                                v-else-if="row.key === 'biometric_consent'"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                            >
                                                <path
                                                    d="M12 4c-3.3 0-6 2.7-6 6v3M18 10a6 6 0 0 0-3-5.2M9 20c1-1.7 1.5-3.6 1.5-5.5V10a1.5 1.5 0 0 1 3 0v4.5M16.5 19v-4.5"
                                                    stroke="currentColor"
                                                    stroke-width="1.6"
                                                    stroke-linecap="round"
                                                />
                                            </svg>
                                            <svg v-else-if="row.key === 'geofencing'" viewBox="0 0 24 24" fill="none">
                                                <path
                                                    d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 0 1 16 0z"
                                                    stroke="currentColor"
                                                    stroke-width="1.6"
                                                    stroke-linejoin="round"
                                                />
                                                <circle cx="12" cy="10" r="3" stroke="currentColor" stroke-width="1.6" />
                                            </svg>
                                            <svg v-else viewBox="0 0 24 24" fill="none">
                                                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" />
                                            </svg>
                                        </div>

                                        <div class="feat-txt">
                                            <div class="ft1">
                                                {{ row.label }}
                                                <span
                                                    class="tier-req"
                                                    :class="TIER_CLASS[row.tier]"
                                                    :style="row.tier ? undefined : HELD_BADGE"
                                                >
                                                    {{ row.tier_label }}
                                                </span>
                                            </div>
                                            <div class="ft2">{{ row.description }}</div>

                                            <!--
                                              Who decided and why, drawn only
                                              where somebody actually decided
                                              something. No estate has an
                                              override in this release — the
                                              board draws every switch on
                                              because Phoenix Park is on
                                              Premium, which is the PLAN's
                                              answer and not the estate's — so
                                              this is absent on every row today.
                                            -->
                                            <div v-if="row.provenance" class="ft2 feat-prov">
                                                {{ row.provenance }}<template v-if="row.reason"> — “{{ row.reason }}”</template>
                                            </div>
                                        </div>

                                        <!--
                                          THE CONTROL, IN WHICHEVER OF THE THREE
                                          SHAPES THE SERVER SAYS. `control` is
                                          the payload's own word — switch,
                                          segmented or scale — so this page never
                                          has to know that payroll is routed and
                                          branding is not.
                                        -->
                                        <template v-if="row.control === 'segmented'">
                                            <div
                                                style="display:flex;flex-direction:column;gap:6px;align-items:flex-end;"
                                                :title="row.note ?? undefined"
                                            >
                                                <div class="seg-toggle">
                                                    <button
                                                        v-for="option in row.options"
                                                        :key="option.value"
                                                        type="button"
                                                        class="seg-toggle-item"
                                                        :class="{ active: option.active }"
                                                        :disabled="option.active || blockedBy(row) !== null"
                                                        :title="
                                                            option.active
                                                                ? alreadySet(row, option)
                                                                : (blockedBy(row) ??
                                                                  `Route ${row.label} to ${option.label}. You will be asked why before it is recorded.`)
                                                        "
                                                        @click="
                                                            arm(row, { option: option.value, optionLabel: option.label })
                                                        "
                                                    >
                                                        {{ option.label }}
                                                    </button>
                                                </div>
                                                <div style="font-size:9.5px;color:var(--slate-500);">
                                                    {{ row.caption }}
                                                </div>
                                            </div>
                                        </template>

                                        <!--
                                          The scale is a READOUT, drawn as the
                                          board draws it. Board 23 lists the
                                          three dots among its controls and the
                                          model overrules it (D-044): every tier
                                          has custom branding and the tiers
                                          differ in how much, so there is no
                                          state here to switch. The title says
                                          what each dot is, because "L" is not a
                                          word.
                                        -->
                                        <div
                                            v-else-if="row.control === 'scale'"
                                            class="branding-scale"
                                            :title="SCALE_LOCKED"
                                        >
                                            <div
                                                v-for="dot in row.scale"
                                                :key="dot.code"
                                                class="branding-dot"
                                                :class="dot.filled ? 'filled' : 'outline'"
                                                :title="`${dot.label} — ${dot.filled ? 'included in this plan' : 'not included in this plan'}`"
                                            >
                                                {{ dot.code }}
                                            </div>
                                        </div>

                                        <!--
                                          A switch that is locked ON is the
                                          board's grey `locked-on`; one that is
                                          locked OFF is the plain grey track
                                          with the knob at rest, because the
                                          board defines no third state and a
                                          held-back feature is genuinely off.
                                        -->
                                        <button
                                            v-else
                                            type="button"
                                            class="switch"
                                            :class="{ 'locked-on': row.locked && row.enabled, on: !row.locked && row.enabled }"
                                            role="switch"
                                            :aria-checked="row.enabled"
                                            :aria-label="row.label"
                                            :disabled="blockedBy(row) !== null"
                                            :title="
                                                blockedBy(row) ??
                                                `Switch ${row.label} ${row.enabled ? 'off' : 'on'}. You will be asked why before it is recorded.`
                                            "
                                            @click="arm(row, { enabled: !row.enabled })"
                                        >
                                            <span class="knob"></span>
                                        </button>
                                    </div>

                                    <!--
                                      THE REASON, ASKED BEFORE THE CHANGE IS
                                      MADE. Absent until something is armed,
                                      which is why the board's own picture is
                                      untouched on a screen nobody has pressed
                                      anything on. Board 23's audit note is the
                                      requirement it exists to satisfy: actor,
                                      timestamp AND reason, and a reason nobody
                                      typed is not one.
                                    -->
                                    <div v-if="armed && armed.key === row.key" class="feat-reason">
                                        <label :for="`why-${row.key}`">{{ armedSentence }}</label>

                                        <p v-if="armed.note" class="feat-reason-note">{{ armed.note }}</p>

                                        <div class="feat-reason-row">
                                            <input
                                                :id="`why-${row.key}`"
                                                v-model="reason"
                                                type="text"
                                                maxlength="300"
                                                placeholder="Why is this changing?"
                                                @keyup.enter="commit"
                                                @keyup.escape="disarm"
                                            />
                                            <button
                                                type="button"
                                                class="btn-primary-sm"
                                                :disabled="commitBlockedBy !== null"
                                                :title="commitBlockedBy ?? 'Record this change with your name, the time and the reason above.'"
                                                @click="commit"
                                            >
                                                <span>Save</span>
                                            </button>
                                            <button
                                                type="button"
                                                class="feat-reason-cancel"
                                                title="Leave this feature exactly as it is."
                                                @click="disarm"
                                            >
                                                Cancel
                                            </button>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                </template>
            </div>
        </div>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only above the authored line, and each removal names the
 * element that needs it.
 *
 * The board draws its settings items, its eleven switches and its four
 * segmented options as <div>s; here they are links, buttons and one input.
 *
 * `font-family` and never `font`. Vue's scoped attribute lifts a bare `button`
 * selector to the same weight as a single class, so `font: inherit` would
 * out-specify `.settings-nav-item`'s own 12.5px and `.seg-toggle-item`'s own
 * 11px and render both at the body's size. The board declares no font-family on
 * any of them, so that one property is the whole of what is missing. See D-045.
 *
 * Padding and margin need no removing — the board's own `*` reset already takes
 * both off every element on the page.
 */
button,
input {
    font-family: inherit;
}

/*
 * .switch declares its own background and .switch.on and .switch.locked-on
 * override it; NONE of the three declares a border. Only the border comes off
 * here — a `background: none` on this selector would out-specify both state
 * rules and leave every switch on the screen the same grey.
 */
button.switch {
    border: 0;
}

/*
 * .seg-toggle-item declares a colour and a padding and no face; the ACTIVE one
 * declares the navy face. So the removal is written to leave the active rule
 * alone rather than tying with it — the same mistake D-045 records, in the other
 * direction.
 */
button.seg-toggle-item {
    border: 0;
}

button.seg-toggle-item:not(.active) {
    background: none;
}

/* .settings-nav-item declares neither a border nor a face, and the amber face
 * on .active is a two-class rule no button here can match — the current section
 * is drawn as text. */
button.settings-nav-item {
    border: 0;
    background: none;
    text-align: left;
}

/* .btn-primary-sm declares a background and a shadow and no border. */
button.btn-primary-sm {
    border: 0;
}

button:not([disabled]) {
    cursor: pointer;
}

button[disabled] {
    cursor: not-allowed;
}

/* =====================================================================
 * AUTHORED BELOW THIS LINE.
 *
 * None of it is drawn on a screen that has just opened, which is the state
 * board 23 is a picture of: the flash and the refusal need a press to exist,
 * the reason field needs a control to have been armed, and the provenance line
 * needs an estate to have overridden something. Kept to the boards' own tokens
 * and to shapes they already use.
 * ===================================================================== */

.feat-flash,
.feat-refusal {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 14px;
}

.feat-flash {
    background: var(--green-100);
    color: var(--green-700);
}

.feat-refusal {
    background: var(--red-100);
    color: var(--red-700);
}

/* Who decided, and why. The board's own second-line size and colour, set apart
 * from the description above it by the amber the boards use for a fact somebody
 * is responsible for. */
.feat-prov {
    color: var(--amber-700);
}

/*
 * The reason field, drawn inside the panel and beneath the row it belongs to,
 * on the board's own navy-100 so it reads as part of that row rather than as a
 * dialog over it.
 */
.feat-reason {
    background: var(--navy-100);
    border-bottom: 1px solid var(--navy-200);
    padding: 13px 18px 15px;
}

.feat-reason label {
    display: block;
    font-size: 11.5px;
    font-weight: 700;
    color: var(--navy-900);
    margin-bottom: 8px;
}

.feat-reason-note {
    font-size: 11px;
    color: var(--slate-600);
    line-height: 1.5;
    margin: 0 0 8px;
}

.feat-reason-row {
    display: flex;
    align-items: center;
    gap: 10px;
}

.feat-reason input {
    flex: 1;
    min-width: 0;
    height: 38px;
    border: 1.5px solid var(--navy-200);
    border-radius: 10px;
    background: var(--white);
    padding: 0 13px;
    font-size: 12.5px;
    color: var(--navy-900);
    outline: 0;
}

.feat-reason input:focus {
    border-color: var(--amber-500);
    box-shadow: 0 0 0 3px rgba(255, 182, 39, 0.15);
}

.feat-reason input::placeholder {
    color: var(--slate-500);
    opacity: 1;
}

.feat-reason-cancel {
    border: 0;
    background: none;
    font-size: 11.5px;
    font-weight: 700;
    color: var(--slate-600);
    white-space: nowrap;
}
</style>
