<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import EstateConsole from '../../../Layouts/EstateConsole.vue'
import EmptyState from '../../../Components/EmptyState.vue'
import SkeletonRows from '../../../Components/SkeletonRows.vue'
import { useScreenState } from '../../../composables/useScreenState'
import { useWireframe } from '../../../composables/useWireframe'

/**
 * Manage amenities — board screen community-admin-20.
 *
 * THE RATE CARD. It posts nothing and it SOURCES everything: every figure on the
 * booking diary and every amenity charge on a unit's ledger derives from the two
 * amounts on these cards, which is why they are transcribed exactly and why they
 * cannot be one column. A BOOKING FEE is revenue — earned, non-refundable, Cr
 * 4100 when the booking is charged. A DEPOSIT is a refundable liability the
 * estate is holding and never recognises as income. Conflating them credits the
 * income account with money the estate has to give back.
 *
 * EDITING A CARD MUST NOT MOVE A DEPOSIT ALREADY HELD, and that is the whole
 * reason a booking snapshots these figures instead of joining to them. Board
 * 19's accounting note states the consequence: "bookings copy
 * amenity.deposit_amount verbatim, so a change to this rate card must not
 * retro-alter deposits already held or the deposit control account will no
 * longer agree to the sum of open bookings." A rate card edited on Tuesday would
 * otherwise restate every deposit taken since March, and the estate would owe
 * residents an amount its own books no longer showed. It is the rule the edit
 * screen has to state on itself, and it is why `reasons.edit` says the control
 * needs a screen rather than a dialog.
 *
 * NULL IS NOT ZERO ON EITHER AMOUNT. The Pool Deck draws "Free for residents"
 * and "None" — statements about the amenity rather than amounts — and the board's
 * own note requires that a nil amenity "produce a booking with zero journal lines
 * rather than a zero-amount entry", because a zero-value entry is a row in the
 * ledger claiming something happened. Both labels arrive from the server beside
 * the minor units, so this page never has to invent what a null looks like.
 *
 * MONEY IS FORMATTED AT THE MODEL BOUNDARY AND NOT HERE. `Amenity::money()`
 * writes the bare "$3,000" these two boards use — no decimals, no J$, unlike the
 * ledger screens' "J$12,400.00" — from minor units, which is how every amount in
 * this system is stored. Nothing on this page divides by a hundred; a template
 * that did is how a rounding artefact reaches a rate card that other screens
 * copy from.
 *
 * TWO FIELDS THE BUILD SPEC NAMES AND THIS BOARD DRAWS NOWHERE. Every amenity
 * carries a cancellation rule and a booking window; board 20 draws four meta
 * items per card and neither is among them. They arrive in the payload, they are
 * unset on this estate, and they stay that way — recorded rather than invented,
 * and set by an estate on the edit screen. The columns exist because a booking
 * snapshots the cancellation rule it was made under.
 */
const props = defineProps({
    estate: { type: Object, required: true },
    /** One card per active amenity, in the estate's own sort order. */
    rows: { type: Array, required: true },
    /** The four glyphs the boards draw, which an amenity picks from. */
    icons: { type: Array, required: true },
    canUpdate: { type: Boolean, required: true },
    canCreate: { type: Boolean, required: true },
    blockedReason: { type: String, required: true },
    createReason: { type: String, required: true },
})

/*
 * ADD AND EDIT ARE BUILT (12 §2, Wave 2). One panel, two uses: blank for a new
 * amenity, filled from the card for an edit. Capacity, hours, fee and deposit
 * are captured together; a blank fee or deposit is "Free for residents" and
 * "None", never zero. An edit changes what the NEXT booking copies and never a
 * confirmed one — every booking carries its own copy of the terms it was made
 * under, and the panel says so in its own head.
 */
const BLANK_TERMS = {
    name: '',
    icon_key: 'pavilion',
    capacity: 20,
    fee: '',
    deposit: '',
    opens_at: '08:00',
    closes_at: '22:00',
    booking_window_days: 30,
    cancellation_hours: 24,
    is_bookable: true,
    retire: false,
}

/** Which amenity's panel is open: null for none, 'new' for the add panel, or a card id. */
const editing = ref(null)

const form = useForm({ ...BLANK_TERMS })

const openAdd = () => {
    if (!props.canCreate) {
        return
    }

    form.clearErrors()
    Object.assign(form, BLANK_TERMS)
    editing.value = editing.value === 'new' ? null : 'new'
}

const openEdit = (row) => {
    if (!props.canUpdate) {
        return
    }

    if (editing.value === row.id) {
        editing.value = null

        return
    }

    form.clearErrors()
    Object.assign(form, { ...BLANK_TERMS, ...row.terms, retire: false })
    editing.value = row.id
}

const closePanel = () => {
    editing.value = null
    form.clearErrors()
}

const submit = () => {
    if (form.name.trim() === '') {
        return
    }

    const target = editing.value === 'new' ? facilities('/amenities') : facilities(`/amenities/${editing.value}`)

    form.post(target, {
        preserveScroll: true,
        onSuccess: () => closePanel(),
    })
}

/** The terms read back before the press, in the words the cards use. */
const readBack = computed(() => {
    const fee = form.fee === '' || Number(form.fee) === 0 ? 'free for residents' : `fee $${Number(form.fee).toLocaleString('en-US')}`
    const deposit = form.deposit === '' || Number(form.deposit) === 0 ? 'no deposit' : `deposit $${Number(form.deposit).toLocaleString('en-US')}`

    return `${form.name.trim() || 'This amenity'} — ${form.capacity} guests, ${form.opens_at}–${form.closes_at}, ${fee}, ${deposit}, cancel ${form.cancellation_hours}h ahead, book up to ${form.booking_window_days} days out${form.is_bookable ? '' : ', closed to new bookings'}${form.retire ? ', RETIRED' : ''}.`
})

/*
 * Which board's stylesheet this page wears. The ten Estate Console boards do
 * NOT share one sheet the way the nine Gemini boards do, so every estate page
 * has to name its own or it renders with no board CSS at all.
 */
useWireframe('community-admin-05-maintenance-amenity-bookings-and-settings')

const page = usePage()

/**
 * All six, and the sixth is reachable only by forcing.
 *
 * `empty` is an estate that has no amenities at all — nothing to book and
 * nothing to charge for. `empty-filtered` is the same list emptied by the view
 * rather than by the estate: this screen is the ACTIVE rate card, and an amenity
 * is retired rather than deleted so that what was booked while it was open still
 * reads correctly. An estate whose amenities are all retired has a register that
 * is not empty and a card list that is. No control on this page applies that
 * filter, so nothing here can produce the state from data — it is forceable with
 * `?_state=empty-filtered` in local, and it says something true when it is.
 */
const state = useScreenState({
    rows: () => props.rows.length,
})

const retry = () => router.reload()

/*
 * Where this console is rooted, read off the page's own URL.
 *
 * Production gives each estate its own hostname and no prefix; local serves
 * every estate from one host with the estate key in the path, as
 * /estate/{key}/facilities/amenities/settings. The URL that served this page
 * already carries whichever shape this environment uses, so cutting it at
 * /facilities is correct in both — and unlike a tenant key read off a prop it
 * cannot address an estate other than the one already open.
 */
const facilities = (suffix) => `${page.url.slice(0, page.url.indexOf('/facilities'))}/facilities${suffix}`

const backHref = computed(() => facilities('/amenities/bookings'))

/**
 * What an amenity that is listed here and missing from the diary's chips means.
 *
 * Not drawn on the board — all four of its amenities are open for booking — and
 * absent from this screen whenever that is true. It is here because it is the
 * one thing about a card a reader cannot work out from what is drawn: an amenity
 * is retired rather than deleted, and one closed to new bookings still has a
 * rate card, still holds the deposits taken under it, and still appears in the
 * history of every booking it ever took.
 */
const CLOSED_LABEL = 'Closed to new bookings'
</script>

<template>
    <Head title="Manage amenities" />

    <EstateConsole title="Manage amenities" :estate-name="estate.name" active="facilities">
        <template #lead>
            <!--
              The board draws this as a bare <div> with its geometry inline —
              its stylesheet defines no class for it — so the declarations are
              copied verbatim onto the link that actually goes back. `flex:0 0
              auto` is added because the topbar is a flex row and a 34px circle
              that shrinks is an oval.
            -->
            <Link
                :href="backHref"
                style="width:34px;height:34px;border-radius:50%;background:var(--navy-100);display:flex;align-items:center;justify-content:center;flex:0 0 auto;"
                title="Back to the booking diary"
                aria-label="Back to the booking diary"
            >
                <svg viewBox="0 0 24 24" fill="none" style="width:16px;height:16px;color:var(--navy-700);">
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

        <template #actions>
            <!--
              The primary blue here where the diary's own topbar carries an
              outline button: on this screen adding an amenity IS the next thing
              to do. Live for a viewer holding Facilities create; the inert
              twin says which access the rest lack.
            -->
            <button
                type="button"
                class="btn-primary-sm"
                :disabled="!canCreate"
                :title="canCreate ? 'Add an amenity — capacity, hours, fee and deposit together. Every booking from then on copies its terms.' : createReason"
                @click="openAdd"
            >
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
                <span>Add amenity</span>
            </button>
        </template>

        <p v-if="page.props.flash?.success" class="am-flash">{{ page.props.flash.success }}</p>

        <!-- The add panel, at the top; an edit panel opens under its own card. -->
        <form v-if="editing === 'new'" class="am-panel" @submit.prevent="submit">
            <div class="am-head">Add an amenity to the rate card</div>
            <div class="am-fields">
                <div class="am-field am-field--wide">
                    <label for="am-name">Name</label>
                    <input id="am-name" v-model="form.name" type="text" required maxlength="80" placeholder="Tennis Court" />
                </div>
                <div class="am-field">
                    <label for="am-icon">Glyph</label>
                    <select id="am-icon" v-model="form.icon_key">
                        <option v-for="icon in icons" :key="icon" :value="icon">{{ icon }}</option>
                    </select>
                </div>
                <div class="am-field">
                    <label for="am-capacity">Capacity, guests</label>
                    <input id="am-capacity" v-model.number="form.capacity" type="number" min="1" max="5000" required />
                </div>
                <div class="am-field">
                    <label for="am-fee">Booking fee, J$ — blank for free</label>
                    <input id="am-fee" v-model="form.fee" type="text" inputmode="decimal" placeholder="3000.00" />
                </div>
                <div class="am-field">
                    <label for="am-deposit">Deposit, J$ — blank for none</label>
                    <input id="am-deposit" v-model="form.deposit" type="text" inputmode="decimal" placeholder="5000.00" />
                </div>
                <div class="am-field">
                    <label for="am-opens">Opens</label>
                    <input id="am-opens" v-model="form.opens_at" type="text" required pattern="^(?:[01]\d|2[0-4]):[0-5]\d$" placeholder="08:00" />
                </div>
                <div class="am-field">
                    <label for="am-closes">Closes — 24:00 for midnight</label>
                    <input id="am-closes" v-model="form.closes_at" type="text" required pattern="^(?:[01]\d|2[0-4]):[0-5]\d$" placeholder="22:00" />
                </div>
                <div class="am-field">
                    <label for="am-window">Book up to, days ahead</label>
                    <input id="am-window" v-model.number="form.booking_window_days" type="number" min="1" max="365" />
                </div>
                <div class="am-field">
                    <label for="am-cancel">Cancel at least, hours ahead</label>
                    <input id="am-cancel" v-model.number="form.cancellation_hours" type="number" min="0" max="720" />
                </div>
            </div>
            <p class="am-read">{{ readBack }}</p>
            <div v-if="form.errors.name" class="am-error">{{ form.errors.name }}</div>
            <div v-if="form.errors.opens_at || form.errors.closes_at" class="am-error">{{ form.errors.opens_at ?? form.errors.closes_at }}</div>
            <div v-if="form.errors.fee || form.errors.deposit" class="am-error">{{ form.errors.fee ?? form.errors.deposit }}</div>
            <div class="am-actions">
                <button type="submit" class="btn-primary-sm" :disabled="form.processing || form.name.trim() === ''" :title="form.name.trim() === '' ? 'Name the amenity.' : 'Put it on the rate card.'">
                    <span>{{ form.processing ? 'Adding…' : 'Add amenity' }}</span>
                </button>
                <button type="button" class="text-link-sm" @click="closePanel">Cancel</button>
            </div>
        </form>

        <!--
          The whole screen is one payload, so nothing on it arrives before the
          rest. Four skeleton rows for the four cards, four bars for the four
          facts each of them carries.
        -->
        <SkeletonRows v-if="state.isLoading.value" :rows="4" :columns="4" />

        <EmptyState
            v-else-if="state.isDenied.value"
            variant="denied"
            title="Facilities is not part of your role’s access"
            body="The amenity rate card sits inside Facilities, and your role does not hold it. A committee officer or the estate administrator can grant it from the role access matrix."
        />

        <EmptyState
            v-else-if="state.isError.value"
            variant="error"
            title="The rate card could not be read"
            body="The estate database did not answer. No amenity has been changed and no booking has moved — a booking carries its own copy of the fee and deposit it was made under, so nothing depended on this read."
            action-label="Try again"
            @action="retry"
        />

        <EmptyState
            v-else-if="state.isEmptyFiltered.value"
            variant="filtered"
            title="Every amenity here has been retired"
            body="This estate has amenities on record, and none of them is open. An amenity is retired rather than deleted, so what was booked while it was open still reads correctly and the deposits taken under it still reconcile — a retired one keeps its rate card and leaves this list."
        />

        <EmptyState
            v-else-if="state.isEmpty.value"
            variant="first-use"
            title="This estate has no amenities yet"
            body="Nothing is offered for booking, so the diary has nothing to fill with. An amenity carries a capacity, its opening hours, a booking fee and a refundable deposit — the fee is income and the deposit never is, and every booking made copies both as they stood on the day."
        />

        <!--
          Four sibling cards and nothing else — no subnav, no filter row, no KPI
          row and no table. The board is a list of rate cards, and each one is
          the icon, the four figures and the way in to change them.
        -->
        <template v-else>
            <template v-for="row in rows" :key="row.id">
            <div class="amenity-card">
                <!--
                  The glyph is the amenity's OWN stored key, not a match on its
                  name: the board draws four distinct SVGs and the same key has
                  to resolve to the smaller 16px glyph in the booking diary, so
                  an estate adding a tennis court picks an icon rather than
                  inheriting whichever one a string match landed on. Drawn at
                  this board's 1.7 — the same geometry the diary draws at 1.6,
                  and the difference is the boards' own.
                -->
                <div class="ac-icon">
                    <svg v-if="row.icon === 'gazebo'" viewBox="0 0 24 24" fill="none">
                        <path
                            d="M12 3v18M4 9c0-3.3 3.6-6 8-6s8 2.7 8 6"
                            stroke="currentColor"
                            stroke-width="1.7"
                            stroke-linecap="round"
                        />
                        <path d="M4 9h16" stroke="currentColor" stroke-width="1.7" />
                    </svg>
                    <svg v-else-if="row.icon === 'clubhouse'" viewBox="0 0 24 24" fill="none">
                        <path
                            d="M4 21V9l8-6 8 6v12"
                            stroke="currentColor"
                            stroke-width="1.7"
                            stroke-linejoin="round"
                        />
                        <path d="M9 21v-7h6v7" stroke="currentColor" stroke-width="1.7" />
                    </svg>
                    <svg v-else-if="row.icon === 'pavilion'" viewBox="0 0 24 24" fill="none">
                        <path d="M3 10l9-6 9 6" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" />
                        <path
                            d="M5 10v9M11 10v9M13 10v9M19 10v9M3 19h18"
                            stroke="currentColor"
                            stroke-width="1.7"
                        />
                    </svg>
                    <svg v-else viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7" />
                        <path
                            d="M12 3v2M12 19v2M3 12h2M19 12h2"
                            stroke="currentColor"
                            stroke-width="1.7"
                            stroke-linecap="round"
                        />
                    </svg>
                </div>

                <div class="ac-info">
                    <div class="ac-name">{{ row.name }}</div>

                    <!--
                      Four facts, in the board's order, and the two amounts are
                      side by side because they are the pair a committee
                      confuses: the fee is earned and the deposit is owed back.
                      Both labels come from the server — "Free for residents"
                      and "None" are statements about the amenity rather than
                      numbers, and a page formatting a null would have to invent
                      them.

                      The hours are an en-dash WITH spaces here, where a
                      booking's time range on the diary uses one with none. Both
                      forms are the boards' own and they are deliberately
                      different; `hoursLabel()` keeps them apart, and it is also
                      what prints "Midnight" where the twenty-fourth hour would
                      otherwise read as 12:00 AM and make an amenity closed all
                      day.
                    -->
                    <div class="ac-meta">
                        <div class="ac-meta-item">Capacity <b>{{ row.capacity_label }}</b></div>
                        <div class="ac-meta-item">Booking fee <b>{{ row.fee_label }}</b></div>
                        <div class="ac-meta-item">Deposit <b>{{ row.deposit_label }}</b></div>
                        <div class="ac-meta-item">Hours <b>{{ row.hours_label }}</b></div>

                        <!-- Absent while every amenity is open, which is what the
                             board draws. See CLOSED_LABEL. -->
                        <div v-if="!row.is_bookable" class="ac-meta-item">
                            Bookings <b>{{ CLOSED_LABEL }}</b>
                        </div>
                    </div>
                </div>

                <button
                    type="button"
                    class="icon-btn"
                    :disabled="!canUpdate"
                    :title="canUpdate ? `Edit the ${row.name}'s terms. Bookings already confirmed keep the terms they were made under.` : blockedReason"
                    :aria-label="`Edit ${row.name}`"
                    @click="openEdit(row)"
                >
                    <svg viewBox="0 0 24 24" fill="none">
                        <path
                            d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"
                            stroke="currentColor"
                            stroke-width="1.6"
                            stroke-linejoin="round"
                        />
                        <path
                            d="M18.5 2.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4z"
                            stroke="currentColor"
                            stroke-width="1.6"
                            stroke-linejoin="round"
                        />
                    </svg>
                </button>
            </div>

            <!--
              The edit panel, under its own card. The head says the one rule
              that matters: a confirmed booking keeps what it was made under.
            -->
            <form v-if="editing === row.id" class="am-panel" @submit.prevent="submit">
                <div class="am-head">
                    Edit the {{ row.name }} — changes what the next booking copies. Every booking already confirmed keeps
                    the fee, deposit and cancellation rule it was made under.
                </div>
                <div class="am-fields">
                    <div class="am-field am-field--wide">
                        <label :for="`ame-name-${row.id}`">Name</label>
                        <input :id="`ame-name-${row.id}`" v-model="form.name" type="text" required maxlength="80" />
                    </div>
                    <div class="am-field">
                        <label :for="`ame-icon-${row.id}`">Glyph</label>
                        <select :id="`ame-icon-${row.id}`" v-model="form.icon_key">
                            <option v-for="icon in icons" :key="icon" :value="icon">{{ icon }}</option>
                        </select>
                    </div>
                    <div class="am-field">
                        <label :for="`ame-capacity-${row.id}`">Capacity, guests</label>
                        <input :id="`ame-capacity-${row.id}`" v-model.number="form.capacity" type="number" min="1" max="5000" required />
                    </div>
                    <div class="am-field">
                        <label :for="`ame-fee-${row.id}`">Booking fee, J$ — blank for free</label>
                        <input :id="`ame-fee-${row.id}`" v-model="form.fee" type="text" inputmode="decimal" />
                    </div>
                    <div class="am-field">
                        <label :for="`ame-deposit-${row.id}`">Deposit, J$ — blank for none</label>
                        <input :id="`ame-deposit-${row.id}`" v-model="form.deposit" type="text" inputmode="decimal" />
                    </div>
                    <div class="am-field">
                        <label :for="`ame-opens-${row.id}`">Opens</label>
                        <input :id="`ame-opens-${row.id}`" v-model="form.opens_at" type="text" required pattern="^(?:[01]\d|2[0-4]):[0-5]\d$" />
                    </div>
                    <div class="am-field">
                        <label :for="`ame-closes-${row.id}`">Closes — 24:00 for midnight</label>
                        <input :id="`ame-closes-${row.id}`" v-model="form.closes_at" type="text" required pattern="^(?:[01]\d|2[0-4]):[0-5]\d$" />
                    </div>
                    <div class="am-field">
                        <label :for="`ame-window-${row.id}`">Book up to, days ahead</label>
                        <input :id="`ame-window-${row.id}`" v-model.number="form.booking_window_days" type="number" min="1" max="365" />
                    </div>
                    <div class="am-field">
                        <label :for="`ame-cancel-${row.id}`">Cancel at least, hours ahead</label>
                        <input :id="`ame-cancel-${row.id}`" v-model.number="form.cancellation_hours" type="number" min="0" max="720" />
                    </div>
                </div>
                <label class="am-check">
                    <input v-model="form.is_bookable" type="checkbox" />
                    <span>Open for booking. Untick to close it to new bookings while keeping the ones it has.</span>
                </label>
                <label class="am-check">
                    <input v-model="form.retire" type="checkbox" />
                    <span>Retire it. It leaves this card, keeps its history and the deposits taken under it, and is never deleted.</span>
                </label>
                <p class="am-read">{{ readBack }}</p>
                <div v-if="form.errors.name" class="am-error">{{ form.errors.name }}</div>
                <div v-if="form.errors.opens_at || form.errors.closes_at" class="am-error">{{ form.errors.opens_at ?? form.errors.closes_at }}</div>
                <div v-if="form.errors.fee || form.errors.deposit" class="am-error">{{ form.errors.fee ?? form.errors.deposit }}</div>
                <div class="am-actions">
                    <button type="submit" class="btn-primary-sm" :disabled="form.processing || form.name.trim() === ''" title="Save the terms. Confirmed bookings do not move.">
                        <span>{{ form.processing ? 'Saving…' : form.retire ? 'Retire amenity' : 'Save terms' }}</span>
                    </button>
                    <button type="button" class="text-link-sm" @click="closePanel">Cancel</button>
                </div>
            </form>
            </template>
        </template>
    </EstateConsole>
</template>

<style scoped>
/*
 * Default-removal only, and each removal names the element that needs it.
 *
 * The board draws its back affordance, its topbar action and its four edit
 * chips as <div>s; here they are a link and five buttons, and a <button> arrives
 * wearing the browser's own font, a border and buttonface grey. .btn-primary-sm
 * and .icon-btn supply everything visible, and app.css has already taken the
 * UA's underline and blue off every anchor on a board page.
 *
 * NEITHER CLASS BELOW IS GIVEN A BORDER BY THE BOARD — .btn-primary-sm declares
 * a background and a shadow, .icon-btn a background and a radius. That is what
 * makes `border: 0` a removal rather than an override: a scoped element selector
 * outranks a single class, and the same line against .btn-outline-sm would rub
 * out the 1.5px navy edge the board draws on it. See D-045.
 */
button {
    font-family: inherit;
}

button.btn-primary-sm,
button.icon-btn {
    border: 0;
}

button.btn-primary-sm,
button.icon-btn {
    cursor: pointer;
}

button.text-link-sm {
    border: 0;
    background: none;
    padding: 0;
    cursor: pointer;
}

button[disabled] {
    cursor: not-allowed;
}

/*
 * AUTHORED BELOW THIS LINE. The board draws a rate card nobody is editing, so
 * it has no panel and no flash. Kept to the tokens the boards define and the
 * shapes they already use.
 */
.am-flash {
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.5;
    border-radius: 10px;
    padding: 9px 13px;
    margin: 0 0 14px;
    background: var(--green-100);
    color: var(--green-700);
}

.am-panel {
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 16px;
    padding: 18px;
    margin-bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 11px;
}

.am-head {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--navy-800);
    line-height: 1.5;
}

.am-fields {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 11px;
}

.am-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.am-field--wide {
    grid-column: span 2;
}

.am-field label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--slate-500);
    line-height: 1.5;
}

.am-field input,
.am-field select {
    height: 34px;
    border: 1px solid var(--navy-200);
    border-radius: 9px;
    background: var(--white);
    padding: 0 10px;
    font: inherit;
    font-size: 12.5px;
    color: var(--navy-900);
}

.am-check {
    display: flex;
    align-items: flex-start;
    gap: 9px;
    font-size: 11.5px;
    color: var(--slate-600);
    line-height: 1.5;
    cursor: pointer;
}

.am-check input {
    margin: 3px 0 0;
    flex: 0 0 auto;
}

.am-read {
    font-size: 11.5px;
    color: var(--navy-900);
    font-weight: 600;
    line-height: 1.5;
    margin: 0;
    background: var(--navy-100);
    border-radius: 10px;
    padding: 10px 14px;
}

.am-error {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--red-700);
    line-height: 1.5;
}

.am-actions {
    display: flex;
    align-items: center;
    gap: 14px;
}
</style>
