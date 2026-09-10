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
 * Every item that appears is a link. Through the build an item whose module had
 * no screens yet was drawn visibly inert with a reason rather than as a link to
 * a route that answers 404; Reports was the last of the ten, so the server
 * cannot produce one any more (D-070).
 */
const props = defineProps({
    title: { type: String, required: true },
    estateName: { type: String, required: true },
    /** Search box in the topbar — drawn on the index screens, absent on details. */
    searchPlaceholder: { type: String, default: null },
    /** Which sidebar item this screen sits under. */
    active: { type: String, default: '' },

    /**
     * Whether the topbar carries the signed-in profile chip.
     *
     * It varies board to board and is not decoration: the dashboard draws it,
     * the accounting and detail screens do not, and rendering it everywhere
     * pushes the topbar's own controls out of position on every screen that
     * omits it.
     */
    profile: { type: Boolean, default: false },
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

/*
 * EVERY ITEM IN THIS SIDEBAR IS A LINK, AND THERE IS NO LONGER AN INERT ONE.
 *
 * Through the build an item whose module had no screens yet arrived with a null
 * href and was drawn as a disabled button saying so, because a role checking
 * what it will reach is better served by a greyed row than by an absence.
 * Reports was the tenth and last, so the server cannot produce one any more and
 * the branch that drew it is gone rather than left as markup nothing reaches
 * (D-070). `EstateNavigation` types the href non-null and its test asserts it.
 */
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

            <Link
                v-for="item in ungrouped"
                :key="item.key"
                :href="item.href"
                class="nav-item"
                :class="{ active: item.key === props.active }"
            >
                <EstateIcon :name="item.icon" />
                <span>{{ item.label }}</span>
            </Link>

            <template v-for="section in sections" :key="section.name">
                <div class="nav-section">{{ section.name }}</div>

                <Link
                    v-for="item in section.items"
                    :key="item.key"
                    :href="item.href"
                    class="nav-item"
                    :class="{ active: item.key === props.active }"
                >
                    <EstateIcon :name="item.icon" />
                    <span>{{ item.label }}</span>
                </Link>
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

                    <div v-if="profile" class="top-profile">
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
 * are anchors, which arrive with an underline. The board's .nav-item supplies
 * everything else visible.
 */
a.nav-item {
    text-decoration: none;
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
