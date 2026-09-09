<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import BrandMark from '../Components/BrandMark.vue'

/**
 * The Estate Console shell.
 *
 * Same structure and class names as GeminiConsole — the wireframes draw both
 * consoles with identical chrome — but the estate colourway on the brand mark
 * and the estate's own name in the sidebar.
 *
 * Navigation is passed in rather than read from shared props, because on this
 * console the modules a committee role sees come from the estate matrix, and
 * the placeholder screen wants to show them before the routes behind them
 * exist.
 */
const props = defineProps({
    title: { type: String, required: true },
    estateName: { type: String, required: true },
    modules: { type: Array, default: () => [] },
})

const page = usePage()
const user = computed(() => page.props.auth?.user ?? null)

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

const ungrouped = computed(() => props.modules.filter((m) => !m.section))

const sections = computed(() => {
    const groups = new Map()

    for (const module of props.modules.filter((m) => m.section)) {
        if (!groups.has(module.section)) {
            groups.set(module.section, [])
        }
        groups.get(module.section).push(module)
    }

    return [...groups].map(([name, mods]) => ({ name, modules: mods }))
})
</script>

<template>
    <div class="app-shell">
        <div class="sidebar sidebar--estate">
            <div class="side-logo">
                <BrandMark colourway="estate" />
                <div>
                    <div class="lt">{{ estateName }}</div>
                    <div class="ls">ESTATE CONSOLE</div>
                </div>
            </div>

            <div v-for="module in ungrouped" :key="module.key" class="nav-item nav-item--pending">
                <span>{{ module.label }}</span>
            </div>

            <template v-for="section in sections" :key="section.name">
                <div class="nav-section">{{ section.name }}</div>
                <div v-for="module in section.modules" :key="module.key" class="nav-item nav-item--pending">
                    <span>{{ module.label }}</span>
                </div>
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
                <div class="top-right">
                    <div class="top-profile">
                        <div class="tp-avatar">{{ initials }}</div>
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
 * Modules whose screens are not built yet. Shown rather than hidden, because
 * the point of this screen is to make the role's future navigation checkable —
 * but visibly inert, so nobody mistakes them for broken links.
 */
.nav-item--pending {
    opacity: 0.45;
    cursor: default;
}
</style>
