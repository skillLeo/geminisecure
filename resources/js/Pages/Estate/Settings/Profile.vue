<script setup>
import { computed, reactive } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'
import { pendingReason } from './sections'

/**
 * Estate profile — board screen community-admin-21.
 *
 * SEVEN INPUTS, AND ONLY THREE OF THEM ARE THIS ESTATE'S TO CHANGE (D-049).
 * The board draws one form and the payload draws a line through the middle of
 * it: the estate's name, its address, its unit total and its phase count are the
 * SECURITY COMPANY'S CLIENT RECORD and this estate's own units, and each arrives
 * `editable: false` carrying the reason on the field. The general enquiries
 * mailbox, the phone number and the security provider belong to the community
 * and are written here.
 *
 * The distinction is drawn on the field rather than announced at the top,
 * because a banner reading "some of this is read-only" makes a reader hunt for
 * which. Every field says for itself, and the four that cannot be typed into
 * carry the sentence the server sent rather than one this page invented — the
 * same reason has to read the same way wherever it is shown.
 *
 * NOTHING HERE IS CALCULATED. The unit total is `SELECT COUNT(*) FROM units`
 * resolved at the boundary and arrives as a string; the address already has ", Jamaica"
 * appended; the labels, the section headings and the field order are the
 * server's. A page that counted, joined or reformatted any of it would be a
 * second answer to a question the estate already answers elsewhere, and the two
 * would disagree the first time a unit was added.
 *
 * THE LOGO IS DRAWN AND NOT UPLOADED. `logo.path` is a path on the tenant disk
 * and is null for every estate in this release, because the only thing that
 * could set it is the upload this phase has not built — a logo prints on
 * resident notices and receipts, so it needs a size and format rule, a stored
 * original and somewhere for the old one to go before it needs a file input.
 * Until then the board's own mark stands in, which is what the board draws.
 *
 * WHAT THE BOARD DRAWS THAT THIS PAGE DOES NOT REPRODUCE, recorded rather than
 * copied: the estate name's input is drawn with the `.focused` amber ring, which
 * is a picture of somebody's cursor sitting in it. Nobody's cursor is in it on a
 * screen that has just opened, so the ring is drawn by `:focus-within` here —
 * real focus, on whichever field actually has it — and the first paint carries
 * none. Reproducing the ring on a field nobody is typing in would draw a state
 * that is not true, and on a READ-ONLY field it would be worse than untrue.
 */
const props = defineProps({
    /** The client record: this estate's name and its single-line address. */
    estate: { type: Object, required: true },
    /** The seven-item settings column, with this screen marked current. */
    sections: { type: Array, required: true },
    logo: { type: Object, required: true },
    /** Three headed groups, each carrying its fields in the board's order. */
    groups: { type: Array, required: true },
    canEdit: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
    reasons: { type: Object, required: true },
})

/*
 * Which board's stylesheet this page wears. The ten Estate Console boards do
 * NOT share one sheet the way the nine Gemini boards do, so every estate page
 * has to name its own or it renders with no board CSS at all. All four settings
 * screens live on this one.
 */
useWireframe('community-admin-06-settings-estate-profile-users-and-roles')

const page = usePage()

/*
 * Where this console is rooted, read off the page's own URL.
 *
 * Production gives each estate its own hostname and no prefix; local serves
 * every estate from one host with the estate key in the path, as
 * /estate/{key}/settings/profile. The URL that served this page already carries
 * whichever shape this environment uses, so cutting it at /settings is correct
 * in both — and unlike a tenant key read off a prop it cannot address an estate
 * other than the one already open.
 */
const root = computed(() => page.url.slice(0, page.url.indexOf('/settings')))

/* ------------------------------------------------------------------ */
/* the form */
/* ------------------------------------------------------------------ */

const fields = computed(() => props.groups.flatMap((group) => group.fields))

/**
 * What has been typed, seeded from what is stored.
 *
 * ONLY THE EDITABLE FIELDS ARE IN HERE, and that is the second of the three
 * places the read-only four are refused. The route refuses them (`saveProfile`
 * validates three keys and no more), the model refuses them (`$fillable` names
 * three columns), and this object never holds them — so a request that carried
 * the estate's name could only be one somebody built by hand, and it would be
 * ignored when it arrived.
 */
const draft = reactive({})

for (const field of props.groups.flatMap((group) => group.fields)) {
    if (field.editable) {
        draft[field.key] = field.value
    }
}

/** Whether anything on the form differs from what is stored. */
const dirty = computed(() => fields.value.some((f) => f.editable && draft[f.key] !== f.value))

/**
 * Why Save cannot be pressed, or null when it can.
 *
 * THE PERMISSION COMES FIRST because it is true of the whole form and it is a
 * different thing to be told: the President holds Settings as View, opens this
 * screen, reads every field and can save none of them, which is exactly what
 * "View — read-only" means. Telling them nothing had changed would send them
 * looking for a change to make.
 */
const saveBlockedBy = computed(() => {
    if (!props.canEdit) {
        return props.blockedReason
    }

    if (!dirty.value) {
        return 'Nothing on this form has been changed yet. Saving an unchanged profile would write an audit entry recording that somebody re-typed what was already there.'
    }

    return null
})

/**
 * What a live Save promises, in the terms it will actually post in.
 *
 * Named rather than left implied, because three of the seven fields go and four
 * do not, and the person pressing it is entitled to know which before they find
 * out afterwards.
 */
const SAVE_TITLE =
    'Save the estate’s own contact details — the enquiries mailbox, the phone number and the security provider. The name, address, unit total and phase count are not sent: they are the client record and this estate’s own units.'

const save = () => {
    if (saveBlockedBy.value !== null) {
        return
    }

    router.post(`${root.value}/settings/profile`, { ...draft }, { preserveScroll: true })
}

/* ------------------------------------------------------------------ */
/* the board's shape */
/* ------------------------------------------------------------------ */

/**
 * The board's two-column pairs, worked out from the `span` the server sends.
 *
 * Board 21 puts "Total units" beside "Number of phases" and the enquiries
 * mailbox beside the phone, in a `.m-two-col` flex row; the other three fields
 * are full width. The payload says which is which, so the pairing is derived
 * from `span` rather than from a position in the array — a field inserted
 * between two halves would otherwise silently pair the wrong two.
 */
const layout = computed(() =>
    props.groups.map((group) => {
        const rows = []

        for (const field of group.fields) {
            const open = rows[rows.length - 1]

            if (field.span === 'half' && open?.span === 'half' && open.fields.length === 1) {
                open.fields.push(field)
            } else {
                rows.push({ span: field.span, fields: [field] })
            }
        }

        return { title: group.title, rows }
    })
)

/**
 * The value a field is showing: what has been typed where it can be typed, and
 * what is stored where it cannot.
 */
const shown = (field) => (field.editable ? draft[field.key] : field.value)

/**
 * A keystroke, kept off the four fields that are shown and not changed.
 *
 * `readonly` already refuses them at the browser, and this refuses them again
 * for the same reason the service keeps its allowlist away from the request: an
 * attribute is one line, and one line is exactly the kind of thing that gets
 * dropped during a rework.
 */
const typed = (field, event) => {
    if (field.editable) {
        draft[field.key] = event.target.value
    }
}

/**
 * Why a read-only field cannot be typed into — the server's sentence, never one
 * written here.
 *
 * The permission clause is added ON TOP of it rather than replacing it, because
 * they are different refusals and both can be true at once: a President is
 * refused the whole form, AND the estate name is refused to everybody including
 * the administrator who can save the rest of it.
 */
const fieldReason = (field) => {
    if (!field.editable) {
        return field.reason
    }

    return props.canEdit ? null : props.blockedReason
}

/* ------------------------------------------------------------------ */
/* the six states */
/* ------------------------------------------------------------------ */

/*
 * Sources are functions, not values: useScreenState runs once during setup, and
 * a value read there would freeze on the first render.
 *
 * `rows` counts FIELDS and not groups, which is what makes the sixth state
 * honest here. A payload with no groups at all is an estate with no profile on
 * record; a payload carrying the three headed groups and no field inside any of
 * them is a form that has been emptied rather than one that was never filled,
 * and the two need different copy. Neither can be produced by anything on this
 * screen — there is no filter to apply — so both are reachable by forcing, and
 * each says something true when it is forced.
 */
const state = useScreenState({
    rows: () => fields.value.length,
    filtered: () => props.groups.length > 0,
})

const retry = () => router.reload()
</script>

<template>
    <Head title="Settings" />

    <EstateConsole title="Settings" :estate-name="estate.name" active="settings">
        <template #actions>
            <!--
              Live for the estate's administrator and inert for everybody else,
              and the title says which of the two refusals applies. The board
              draws it blue and enabled because the board is drawn as somebody
              who can press it.
            -->
            <button
                type="button"
                class="btn-primary-sm"
                :disabled="saveBlockedBy !== null"
                :title="saveBlockedBy ?? SAVE_TITLE"
                @click="save"
            >
                <span>Save changes</span>
            </button>
        </template>

        <!--
          Neither is on the board and neither is drawn on a fresh GET — a board
          is a still image and nothing has ever been pressed on it. They are
          here because a save that lands in silence and one refused in silence
          are the same screen to whoever pressed the button. `security_provider`
          is the key the service refuses an empty provider against.
        -->
        <p v-if="page.props.flash.success" class="profile-flash">{{ page.props.flash.success }}</p>
        <p v-if="page.props.errors.security_provider" class="profile-refusal">
            {{ page.props.errors.security_provider }}
        </p>
        <p v-if="page.props.errors.enquiries_email" class="profile-refusal">
            {{ page.props.errors.enquiries_email }}
        </p>
        <p v-if="page.props.errors.enquiries_phone" class="profile-refusal">
            {{ page.props.errors.enquiries_phone }}
        </p>

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

            <SkeletonRows v-if="state.isLoading.value" :rows="7" :columns="1" />

            <EmptyState
                v-else-if="state.isDenied.value"
                variant="denied"
                title="Settings is not part of your role’s access"
                body="The estate profile sits inside Settings, and the matrix gives that module to the Community Super Admin, the President and the Vice President only. A committee officer or the estate administrator can grant it — from the platform matrix, not from this console."
            />

            <EmptyState
                v-else-if="state.isError.value"
                variant="error"
                title="The estate profile could not be read"
                body="The estate database did not answer. Nothing has been changed — this is a read that failed, and re-running it is safe. The contact details on record still stand exactly as they did."
                action-label="Try again"
                @action="retry"
            />

            <!--
              Groups with no fields in them. Not producible from this screen —
              nothing here filters a form — and true when it is forced: the
              headings arrived and the fields did not.
            -->
            <EmptyState
                v-else-if="state.isEmptyFiltered.value"
                variant="filtered"
                title="This profile has headings and no fields"
                body="The estate details, contact and security provider sections all arrived and not one of them carried a field. A form drawn from three empty headings would look like a profile somebody had deleted rather than one that failed to load."
            />

            <EmptyState
                v-else-if="state.isEmpty.value"
                variant="first-use"
                title="This estate has no profile on record"
                body="An estate’s profile is its name and address on the security company’s client record, the units and phases it actually holds, and the mailbox and number residents ring. None of it has been established for this estate yet."
            />

            <!--
              The board's own inline max-width, kept verbatim. A settings form
              that ran the full 924px of the right column would put a 900px box
              around a phone number.
            -->
            <div v-else class="form-panel" style="max-width:640px;">
                <!--
                  The mark, not an uploaded file. `logo.path` is a path on the
                  tenant disk rather than a URL and it is null for every estate
                  in this release, because the only thing that could set it is
                  the upload beside it — which is inert and says why. Drawing a
                  raw disk path as an image source would put a broken image on
                  450 households' notices the day one was stored.
                -->
                <div class="logo-upload">
                    <div class="logo-box">
                        <svg viewBox="0 0 40 40">
                            <circle cx="15" cy="20" r="9" fill="#FFFFFF" opacity="0.92" />
                            <circle
                                cx="25"
                                cy="20"
                                r="9"
                                fill="#FFB627"
                                opacity="0.92"
                                style="mix-blend-mode: multiply"
                            />
                        </svg>
                    </div>
                    <div>
                        <div class="lu-txt">{{ logo.caption }}</div>
                        <button type="button" class="lu-btn" disabled :title="reasons.logo">
                            {{ logo.action }}
                        </button>
                    </div>
                </div>

                <template v-for="group in layout" :key="group.title">
                    <div class="form-sec-head">{{ group.title }}</div>

                    <!--
                      A pair of halves is the board's .m-two-col flex row; a
                      full-width field is a bare .m-field. Both draw the same
                      field, so the pairing is the only thing this branch
                      decides — which is why the field itself is written once,
                      below, and reached from both.
                    -->
                    <template v-for="(row, i) in group.rows" :key="`${group.title}-${i}`">
                        <div v-if="row.span === 'half'" class="m-two-col">
                            <div v-for="field in row.fields" :key="field.key" class="m-field">
                                <label :for="`f-${field.key}`">{{ field.label }}</label>

                                <div class="m-input">
                                    <!--
                                      THE HIDDEN COPY IS WHAT SIZES THE FIELD.
                                      Board 21's half-width fields are as wide as
                                      what is in them — "Total units" comes out
                                      59px and the enquiries mailbox 172px —
                                      because the board draws the value in a
                                      <span>, and a span is as wide as its text.
                                      An <input> has no such width: it is as wide
                                      as its `size`, which is twenty characters
                                      of nothing in particular, so all four of
                                      these would come out one width and none of
                                      them the board's. The span is therefore
                                      still here, invisible, holding the same
                                      string: it sizes the cell and the input
                                      lies on top of it and stretches to fit.
                                    -->
                                    <span class="m-sizer">
                                        <span class="m-ghost" aria-hidden="true">{{ shown(field) }}</span>
                                        <input
                                            :id="`f-${field.key}`"
                                            :type="field.type"
                                            :value="shown(field)"
                                            :readonly="!field.editable || !canEdit"
                                            :aria-readonly="!field.editable || !canEdit"
                                            :title="fieldReason(field) ?? undefined"
                                            @input="typed(field, $event)"
                                        />
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!--
                          A full-width row carries exactly one field, because
                          `span` is what puts a field in a pair and a full field
                          is by definition not in one.
                        -->
                        <div v-else class="m-field">
                            <label :for="`f-${row.fields[0].key}`">{{ row.fields[0].label }}</label>
                            <div class="m-input">
                                <input
                                    :id="`f-${row.fields[0].key}`"
                                    class="m-fill"
                                    :type="row.fields[0].type"
                                    :value="shown(row.fields[0])"
                                    :readonly="!row.fields[0].editable || !canEdit"
                                    :aria-readonly="!row.fields[0].editable || !canEdit"
                                    :title="fieldReason(row.fields[0]) ?? undefined"
                                    @input="typed(row.fields[0], $event)"
                                />
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
 * The board draws its topbar action and its logo action as <div>s and its seven
 * field values as <span>s; here they are two buttons, several links and seven
 * inputs. A <button> and an <input> arrive wearing a border, a face and the
 * browser's own font — and unlike every other element on the page they do NOT
 * inherit the board's font-family, because form controls never do.
 *
 * `font-family` and never `font`. Vue's scoped attribute lifts a bare `button`
 * selector to the same weight as a single class, so `font: inherit` here would
 * out-specify `.settings-nav-item`'s own 12.5px and `.lu-btn`'s own 700 and
 * render both at the body's size and weight. The board declares no font-family
 * anywhere on these controls, so that one property is the whole of what is
 * missing. See D-045: a removal that reaches past the UA's default and rubs out
 * the board's own rule is not a removal.
 *
 * Padding and margin need no removing at all — the board's own `*` reset
 * already takes both off every element on the page, buttons and inputs
 * included.
 */
button,
input {
    font-family: inherit;
}

/*
 * NONE OF THESE THREE IS GIVEN A BORDER OR A BACKGROUND BY THE BOARD.
 * .btn-primary-sm declares a background and a shadow; .settings-nav-item and
 * .lu-btn declare neither, and the amber face on .settings-nav-item.active is
 * declared by a two-class rule that no button below can ever match — the
 * current section is drawn as text, not as a control.
 */
button.btn-primary-sm {
    border: 0;
}

button.settings-nav-item,
button.lu-btn {
    border: 0;
    background: none;
    text-align: left;
}

/* The board draws the logo action as a <div>, which is block; a <button> is
 * inline-block, and the difference is a baseline gap underneath it. */
button.lu-btn {
    display: block;
}

button:not([disabled]) {
    cursor: pointer;
}

button[disabled] {
    cursor: not-allowed;
}

/*
 * The value, on the element that now carries it.
 *
 * The board styles the value with `.m-input span` — 13px, weight 500,
 * navy-900 — and here the value is the input's own text rather than a span's.
 * The same three declarations are restated on the element that took the job
 * over. Nothing new: same size, same weight, same colour, and the colour is
 * `inherit` because .m-sizer is a span and has already been given it.
 *
 * `min-width: 0` because an input is a flex item inside .m-input and a flex
 * item's automatic minimum is its own preferred width, which for an input is
 * twenty characters — it would push the field wider than the box the board
 * drew and then overflow it.
 */
.m-input input {
    border: 0;
    background: none;
    outline: 0;
    padding: 0;
    font-size: 13px;
    font-weight: 500;
    color: var(--navy-900);
    min-width: 0;
}

/* The full-width fields fill the box, exactly as the board's span sits in one
 * that is already 594px wide. */
.m-input input.m-fill {
    width: 100%;
}

/*
 * A read-only field is still a field: its text is selectable and copyable, and
 * the browser greys neither. Nothing is removed here because nothing is added —
 * `readonly` carries no UA appearance of its own, which is exactly why it is
 * used in preference to `disabled` for the four fields that are shown and not
 * changed. `disabled` would grey the client record out and make it unselectable
 * to somebody who wanted to quote it in a message to Gemini Security.
 */
.m-input input[readonly] {
    cursor: default;
}

/* =====================================================================
 * AUTHORED BELOW THIS LINE.
 * ===================================================================== */

/*
 * The focus ring, which is the board's own and is drawn by real focus.
 *
 * Board 21 draws the estate name's input wearing `.m-input.focused` — an amber
 * border, a white face and a soft amber glow. That is a picture of somebody's
 * cursor in the field, and nobody's cursor is in it on a screen that has just
 * opened. So the three declarations are the board's exactly, moved onto
 * `:focus-within`, and the ring appears on whichever field is actually being
 * typed in. An input with no visible focus is unusable with a keyboard, and the
 * board has already decided what focus on this form looks like.
 */
.m-input:focus-within {
    border-color: var(--amber-500);
    background: var(--white);
    box-shadow: 0 0 0 3px rgba(255, 182, 39, 0.15);
}

/*
 * The hidden copy that gives an input the width a span would have had.
 *
 * One grid cell with two things in it: the ghost, which is invisible and sets
 * the width, and the input, which stretches to fill it. `white-space: pre` so a
 * trailing space counts for as much as it does in the span the board drew, and
 * a minimum so that a field somebody has emptied is still wide enough to click
 * back into.
 */
.m-sizer {
    display: inline-grid;
    min-width: 4ch;
}

.m-sizer > * {
    grid-area: 1 / 1;
}

.m-ghost {
    visibility: hidden;
    white-space: pre;
}

/*
 * The flash and the refusal, neither of which the board draws.
 *
 * A board is a still image of a screen nobody has pressed anything on, so it
 * has no room for the answer to a press. Both are absent on a fresh GET, which
 * is the state the board is in. Kept to the boards' own tokens.
 */
.profile-flash,
.profile-refusal {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 14px;
}

.profile-flash {
    background: var(--green-100);
    color: var(--green-700);
}

.profile-refusal {
    background: var(--red-100);
    color: var(--red-700);
}
</style>
