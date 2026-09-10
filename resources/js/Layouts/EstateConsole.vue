<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import BrandMark from '../Components/BrandMark.vue'
import EstateIcon from '../Components/EstateIcon.vue'

/**
 * The Estate Console shell.
 *
 * Same class names as GeminiConsole — the wireframes draw both consoles with
 * identical chrome — with the estate colourway on the brand mark and the
 * estate's own name where Gemini's wordmark sits.
 *
 * NAVIGATION COMES FROM THE VIEWER'S PERMISSIONS, resolved server-side by
 * `EstateNavigation`. A role that holds no module behind an item does not see
 * the item: the Build Spec is explicit that "a role without a module permission
 * does not see that module at all", which is why a Property Manager opening
 * this console finds no Dues & ledger and no Accounting. That is the separation
 * working, not a fault to explain.
 *
 * An item whose screens are not built yet is drawn and visibly inert with a
 * reason, never a link to a route that answers 404.
 */
const props = defineProps({
    title: { type: String, required: true },
    estateName: { type: String, required: true },
    /** Search box in the topbar — drawn on the index screens, absent on details. */
    searchPlaceholder: { type: String, default: null },
    /** Which sidebar item this screen sits under. */
    active: { type: String, default: '' },
})

const page = usePage()
const user = computed(() => page.props.auth?.user ?? null)
const nav = computed(() => page.props.estateNav ?? [])

const initials = (name) =>
    (name ?? '')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase()

const mine = computed(() => initials(user.value?.name))

/** The ungrouped items, then each section in the order the boards draw them. */
const ungrouped = computed(() => nav.value.filter((item) => !item.section))

const sections = computed(() => {
    const groups = new Map()

    for (const item of nav.value.filter((i) => i.section)) {
        if (!groups.has(item.section)) {
            groups.set(item.section, [])
        }
        groups.get(item.section).push(item)
    }

    return [...groups].map(([name, items]) => ({ name, items }))
})

const NOT_BUILT = 'Not built yet — this module is in your role and its screens are still being delivered.'
</script>

<template>
    <div class="app-shell">
        <div class="sidebar sidebar--estate">
            <div class="side-logo">
                <BrandMark colourway="estate" />
                <div>
                    <div class="lt">GeminiSecure</div>
                    <div class="ls">ESTATE CONSOLE</div>
                </div>
            </div>

            <template v-for="item in ungrouped" :key="item.key">
                <Link v-if="item.href" :href="item.href" class="nav-item" :class="{ active: item.key === props.active }">
                    <EstateIcon :name="item.icon" />
                    <span>{{ item.label }}</span>
                </Link>
                <button v-else type="button" class="nav-item" disabled :title="NOT_BUILT">
                    <EstateIcon :name="item.icon" />
                    <span>{{ item.label }}</span>
                </button>
            </template>

            <template v-for="section in sections" :key="section.name">
                <div class="nav-section">{{ section.name }}</div>

                <template v-for="item in section.items" :key="item.key">
                    <Link v-if="item.href" :href="item.href" class="nav-item" :class="{ active: item.key === props.active }">
                        <EstateIcon :name="item.icon" />
                        <span>{{ item.label }}</span>
                    </Link>
                    <button v-else type="button" class="nav-item" disabled :title="NOT_BUILT">
                        <EstateIcon :name="item.icon" />
                        <span>{{ item.label }}</span>
                    </button>
                </template>
            </template>

            <div class="side-foot">
                <div class="sf-avatar">{{ mine }}</div>
                <div>
                    <div class="sf-name">{{ user?.name }}</div>
                    <div class="sf-role">{{ user?.role_label }}</div>
                </div>
            </div>
        </div>

        <div class="main-col">
            <div class="topbar">
                <slot name="lead" />

                <h1>{{ title }}</h1>

                <!--
                  The search field. A real form, because a field that takes a
                  query and does nothing with it is worse than one that says it
                  is not ready — and the boards draw it on the index screens
                  only, so it is absent rather than empty on a detail screen.
                -->
                <form v-if="searchPlaceholder" class="top-search" @submit.prevent>
                    <svg viewBox="0 0 24 24" fill="none">
                        <circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.8" />
                        <path d="M21 21l-4.3-4.3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                    </svg>
                    <input
                        type="search"
                        :placeholder="searchPlaceholder"
                        disabled
                        title="Not built yet — search across units and residents needs an index before it needs a box that returns nothing."
                    />
                </form>

                <div class="top-right">
                    <slot name="actions" />

                    <div class="top-profile">
                        <div class="tp-avatar">{{ mine }}</div>
                        <div>
                            <div class="tp-name">{{ user?.name }}</div>
                            <div class="tp-role">{{ user?.role_label }}</div>
                        </div>
                    </div>
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
 * The Community Admin sidebar ends its gradient in #0A2D52 on all 40 approved
 * screens, where the Gemini sidebar ends in --purple-900. Recorded as an open
 * question in DESIGN_SYSTEM_FINDINGS; reproduced faithfully here rather than
 * quietly normalised to the Gemini value.
 */
.sidebar--estate {
    background: linear-gradient(180deg, var(--navy-800), #0a2d52);
}

/*
 * Default-removal only. The boards draw every nav item as a <div>; here they
 * are anchors and buttons, which arrive with an underline, a border, a face and
 * the browser's own font. The board's .nav-item supplies everything visible.
 */
a.nav-item {
    text-decoration: none;
}

button.nav-item {
    border: 0;
    background: none;
    font: inherit;
    width: 100%;
    text-align: left;
    cursor: not-allowed;
}

/* The board draws the search as a <div> with a span inside. */
form.top-search {
    margin: 0;
}

.top-search input {
    border: 0;
    background: none;
    font: inherit;
    color: inherit;
    outline: 0;
    width: 100%;
    padding: 0;
}

.top-search input:disabled {
    cursor: not-allowed;
}

.top-search input::placeholder {
    color: var(--slate-500);
    opacity: 1;
}
</style>
