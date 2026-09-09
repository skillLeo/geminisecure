<script setup>
import { computed, ref, onMounted, onBeforeUnmount } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { useWireframe } from '../composables/useWireframe'
import BrandMark from '../Components/BrandMark.vue'
import ModuleIcon from '../Components/ModuleIcon.vue'

/**
 * The Gemini Console shell.
 *
 * DOM and class names are the board's, unchanged, and the stylesheet is lifted
 * verbatim from those boards — so nothing here may introduce a class the board
 * does not define or a style rule of its own.
 *
 * THE TOPBAR IS PER SCREEN, NOT FIXED.
 *
 * This layout used to render the Platform Dashboard's topbar on every page:
 * title, search, notification bell, profile chip. The boards do not agree with
 * that. Cross-tenant reports draws a title and nothing else. Access & audit log
 * draws a title and an Export button. Guard workforce draws a title, a search
 * field with its OWN placeholder ("Search guards…"), and an Add guard button.
 * Only the dashboard draws the bell and the chip.
 *
 * So the topbar is assembled from what each screen passes: `searchRoute` opts
 * a screen into a live search field, and the `actions` slot fills .top-right.
 * A screen that passes neither gets a bare title, which is what most boards
 * draw.
 *
 * SIGN-OUT LIVES IN THE SIDEBAR FOOTER, and that is not a preference.
 *
 * The interactivity bar requires sign-out reachable from every authenticated
 * page. The topbar profile chip appears on exactly one board, so hanging
 * sign-out there would have put it on one screen out of forty-five. .side-foot
 * is drawn on every board, so that is where it goes.
 *
 * Navigation is NOT declared here. It arrives from the server, generated from
 * the role access matrix at runtime, so a module a role cannot use is absent
 * from this list entirely rather than rendered and hidden. There is
 * deliberately no `disabled` state to render.
 */
const props = defineProps({
    title: { type: String, required: true },
    board: { type: String, default: 'super-admin-01-login-dashboard-and-activity' },

    /**
     * Where the top-bar search sends its query.
     *
     * Null means the board draws no search field on this screen, and none is
     * rendered — rather than a dead field that swallows what is typed into it.
     */
    searchRoute: { type: String, default: null },
    searchValue: { type: String, default: '' },
    searchPlaceholder: { type: String, default: 'Search clients, guards, invoices…' },

    /**
     * For a board that DRAWS a search field this screen cannot yet honour.
     *
     * The Platform Dashboard is the case: it draws a global search box, and
     * there is no global search. Omitting the field would put a hole in the
     * board; rendering a live-looking one that swallows the query would be
     * worse. So it renders, visibly inert, saying why.
     */
    searchDisabledReason: { type: String, default: null },
})

useWireframe(props.board)

const page = usePage()

const user = computed(() => page.props.auth.user)
const nav = computed(() => page.props.nav ?? [])

/**
 * The sidebar groups modules under uppercase headings, with anything
 * section-less rendered first and outside any heading.
 */
const ungrouped = computed(() => nav.value.filter((m) => !m.section))

const sections = computed(() => {
    const groups = new Map()

    for (const module of nav.value.filter((m) => m.section)) {
        if (!groups.has(module.section)) {
            groups.set(module.section, [])
        }
        groups.get(module.section).push(module)
    }

    return [...groups].map(([name, modules]) => ({ name, modules }))
})

/** Initials for the avatar, as drawn: two characters, uppercase. */
const initials = computed(() => {
    const name = user.value?.name ?? ''

    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase()
})

/* --- search ---------------------------------------------------------- */

const query = ref(props.searchValue)

/*
 * Server-side, and debounced.
 *
 * `preserveState` keeps the field focused and the caret where it was, which a
 * full visit would throw away on every keystroke. `replace` keeps the browser
 * history from filling with one entry per character.
 */
let searchTimer = null

const runSearch = () => {
    if (!props.searchRoute) {
        return
    }

    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => {
        router.get(
            props.searchRoute,
            { q: query.value || undefined },
            { preserveState: true, preserveScroll: true, replace: true }
        )
    }, 250)
}

/* --- sign out -------------------------------------------------------- */

const menuOpen = ref(false)

const signOut = () => router.post('/logout')

/* Clicking anywhere else closes it. Registered on the document rather than an
 * overlay element so the board's layout gains no extra box. */
const closeOnOutside = (event) => {
    if (!event.target.closest('.side-foot')) {
        menuOpen.value = false
    }
}

const closeOnEscape = (event) => {
    if (event.key === 'Escape') {
        menuOpen.value = false
    }
}

onMounted(() => {
    document.addEventListener('click', closeOnOutside)
    document.addEventListener('keydown', closeOnEscape)
})

onBeforeUnmount(() => {
    clearTimeout(searchTimer)
    document.removeEventListener('click', closeOnOutside)
    document.removeEventListener('keydown', closeOnEscape)
})
</script>

<template>
    <div class="app-shell">
        <div class="sidebar">
            <div class="side-logo">
                <BrandMark colourway="console" />
                <div>
                    <div class="lt">GeminiSecure</div>
                    <div class="ls">GEMINI CONSOLE</div>
                </div>
            </div>

            <Link
                v-for="module in ungrouped"
                :key="module.key"
                :href="module.href"
                class="nav-item"
                :class="{ active: module.active }"
            >
                <ModuleIcon :module="module.key" />
                <span>{{ module.label }}</span>
            </Link>

            <template v-for="section in sections" :key="section.name">
                <div class="nav-section">{{ section.name }}</div>
                <Link
                    v-for="module in section.modules"
                    :key="module.key"
                    :href="module.href"
                    class="nav-item"
                    :class="{ active: module.active }"
                >
                    <ModuleIcon :module="module.key" />
                    <span>{{ module.label }}</span>
                </Link>
            </template>

            <!--
              The board draws this as a <div>. It is a <button> because it is
              the only route to signing out, and it is on every screen.
            -->
            <button
                type="button"
                class="side-foot"
                :aria-expanded="menuOpen"
                aria-haspopup="menu"
                @click="menuOpen = !menuOpen"
            >
                <div class="sf-avatar">{{ initials }}</div>
                <div>
                    <div class="sf-name">{{ user?.name }}</div>
                    <div class="sf-role">{{ user?.role_label }}</div>
                </div>

                <div v-if="menuOpen" class="sf-menu" role="menu">
                    <button type="button" role="menuitem" @click.stop="signOut">Sign out</button>
                </div>
            </button>
        </div>

        <div class="main-col">
            <div class="topbar">
                <!--
                  Detail boards put a back chevron before the title — payslip
                  detail, and every drill-down report. It is a control, so the
                  screen supplies a real one rather than the layout drawing a
                  decorative circle.
                -->
                <slot name="lead" />

                <h1>{{ title }}</h1>

                <div v-if="searchRoute || searchDisabledReason" class="top-search">
                    <svg viewBox="0 0 24 24" fill="none">
                        <circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.8" />
                        <path d="M21 21l-4.3-4.3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                    </svg>
                    <input
                        v-model="query"
                        type="search"
                        :placeholder="searchPlaceholder"
                        :disabled="!searchRoute"
                        :title="searchRoute ? undefined : searchDisabledReason"
                        @input="runSearch"
                    />
                </div>

                <div v-if="$slots.actions" class="top-right">
                    <slot name="actions" />
                </div>
            </div>

            <div class="content">
                <slot />
            </div>
        </div>
    </div>
</template>

<style scoped>
/*
 * The only authored CSS in this component, and only where a board <div> had to
 * become a real control.
 *
 * The board draws the search as a <span> of placeholder text and the sidebar
 * footer as a <div>. Both are real controls here, and a browser's default
 * styling for <input> and <button> — border, background, font, padding, width
 * — would otherwise show through and change the pixels. These rules take those
 * defaults back off so the board's own .top-search and .side-foot rules are
 * what is seen. Nothing here introduces a colour, size or spacing the board
 * does not already declare.
 */
.top-search input {
    flex: 1;
    min-width: 0;
    border: 0;
    outline: 0;
    background: transparent;
    font: inherit;
    color: inherit;
    padding: 0;
}

.top-search input::placeholder {
    color: inherit;
    opacity: 1;
}

/* Chrome paints a clear button and a magnifier on type="search". The board
 * draws neither, and its own icon is already in the box. */
.top-search input::-webkit-search-decoration,
.top-search input::-webkit-search-cancel-button {
    -webkit-appearance: none;
    appearance: none;
}

/* Inert, and it says so on hover. No opacity change: the board draws the field
 * at one weight, and dimming it would be a pixel the design does not have. */
.top-search input[disabled] {
    cursor: not-allowed;
}

/* A <button> shrinks to its content and centres its text; the board's
 * .side-foot is a full-width row. margin-top:auto is the board's own rule and
 * is not restated here — only what the UA adds is removed. */
.side-foot {
    width: 100%;
    border: 0;
    background: transparent;
    font: inherit;
    color: inherit;
    text-align: left;
    cursor: pointer;
    position: relative;
}

.sf-menu {
    position: absolute;
    bottom: calc(100% + 8px);
    left: 0;
    right: 0;
    z-index: 20;
    background: var(--white);
    border-radius: 10px;
    box-shadow: 0 12px 28px rgba(11, 31, 51, 0.28);
    padding: 6px;
}

.sf-menu button {
    display: block;
    width: 100%;
    border: 0;
    background: transparent;
    font: inherit;
    color: var(--navy-900);
    text-align: left;
    padding: 8px 10px;
    border-radius: 7px;
    cursor: pointer;
}

.sf-menu button:hover {
    background: var(--navy-100);
}
</style>
