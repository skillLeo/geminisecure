<script setup>
import { computed, ref, onMounted, onBeforeUnmount } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { useWireframe } from '../composables/useWireframe'
import BrandMark from '../Components/BrandMark.vue'
import ModuleIcon from '../Components/ModuleIcon.vue'

/**
 * The Gemini Console shell.
 *
 * DOM and class names are the board's, unchanged — screen super-admin-02, and
 * identical on all nine Super Admin boards. The stylesheet is lifted verbatim
 * from those boards, so nothing here may introduce a class the board does not
 * define or a style rule of its own.
 *
 * Where the board draws a control, this renders a real one. The board is a
 * still image and can afford a <div> that looks like a button; an application
 * cannot. Every nav item is a Link, the search is an input that searches, and
 * the profile chip opens a menu that can actually sign out.
 *
 * Navigation is NOT declared here. It arrives from the server, generated from
 * the role access matrix at runtime, so a module a role cannot use is absent
 * from this list entirely rather than rendered and hidden. There is
 * deliberately no `disabled` state to render.
 */
const props = defineProps({
    title: { type: String, required: true },
    board: { type: String, default: 'super-admin-01-login-dashboard-and-activity' },
    /** Where the top-bar search sends its query. Null hides nothing — it makes
     *  the field visibly inert, because a search box that swallows a query is
     *  worse than one that says it is not ready. */
    searchRoute: { type: String, default: null },
    searchValue: { type: String, default: '' },
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
            { preserveState: true, preserveScroll: true, replace: true, only: [] }
        )
    }, 250)
}

onBeforeUnmount(() => clearTimeout(searchTimer))

/* --- profile menu ---------------------------------------------------- */

const menuOpen = ref(false)

const signOut = () => router.post('/logout')

/* Clicking anywhere else closes it. Registered on the document rather than an
 * overlay element so the board's layout gains no extra box. */
const closeOnOutside = (event) => {
    if (!event.target.closest('.top-profile')) {
        menuOpen.value = false
    }
}

onMounted(() => document.addEventListener('click', closeOnOutside))
onBeforeUnmount(() => document.removeEventListener('click', closeOnOutside))
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

            <div class="side-foot">
                <div class="sf-avatar">{{ initials }}</div>
                <div>
                    <div class="sf-name">{{ user?.name }}</div>
                    <div class="sf-role">{{ user?.role_label }}</div>
                </div>
            </div>
        </div>

        <div class="main-col">
            <div class="topbar">
                <h1>{{ title }}</h1>

                <div class="top-search">
                    <svg viewBox="0 0 24 24" fill="none">
                        <circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.8" />
                        <path d="M21 21l-4.3-4.3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                    </svg>
                    <input
                        v-model="query"
                        type="search"
                        :disabled="!searchRoute"
                        :title="searchRoute ? undefined : 'Search is available on list screens'"
                        placeholder="Search clients, guards, invoices&hellip;"
                        @input="runSearch"
                    />
                </div>

                <div class="top-right">
                    <button type="button" class="top-icon-btn" disabled title="Notifications arrive with the alert feed in Phase 3">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path
                                d="M4 11v2a1 1 0 0 0 1 1h2l4 4V6L7 10H5a1 1 0 0 0-1 1z"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linejoin="round"
                            />
                            <path d="M17 8a5 5 0 0 1 0 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                        </svg>
                    </button>

                    <button
                        type="button"
                        class="top-profile"
                        :aria-expanded="menuOpen"
                        aria-haspopup="menu"
                        @click="menuOpen = !menuOpen"
                    >
                        <div class="tp-avatar">{{ initials }}</div>
                        <div>
                            <div class="tp-name">{{ user?.name }}</div>
                            <div class="tp-role">{{ user?.role_label }}</div>
                        </div>
                        <svg viewBox="0 0 24 24" fill="none">
                            <polyline
                                points="6 9 12 15 18 9"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>

                        <div v-if="menuOpen" class="tp-menu" role="menu">
                            <button type="button" role="menuitem" @click.stop="signOut">Sign out</button>
                        </div>
                    </button>
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
 * The only authored CSS in this component, and only where the board has no
 * equivalent to copy.
 *
 * The board draws the search as a <span> of placeholder text and the profile
 * chip as a <div>. Both are real controls here, and a browser's default
 * styling for <input> and <button> — border, background, font, padding — would
 * otherwise show through and change the pixels. These rules take those
 * defaults back off so the board's own .top-search and .top-profile rules are
 * what is seen.
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

.top-icon-btn,
.top-profile {
    border: 0;
    font: inherit;
    color: inherit;
    text-align: left;
}

/* Deliberately inert, and visibly so: no silent click. */
.top-icon-btn[disabled],
.top-search input[disabled] {
    cursor: not-allowed;
    opacity: 0.55;
}

.top-profile {
    position: relative;
}

.tp-menu {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    z-index: 20;
    min-width: 150px;
    background: var(--white);
    border: 1px solid var(--navy-100);
    border-radius: 10px;
    box-shadow: 0 12px 28px rgba(11, 31, 51, 0.16);
    padding: 6px;
}

.tp-menu button {
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

.tp-menu button:hover {
    background: var(--navy-100);
}
</style>
