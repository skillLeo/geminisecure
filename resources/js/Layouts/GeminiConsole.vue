<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import BrandMark from '../Components/BrandMark.vue'
import ModuleIcon from '../Components/ModuleIcon.vue'

/**
 * The Gemini Console shell.
 *
 * DOM structure and class names are the wireframe's, unchanged. Only the
 * hardcoded strings became props. Where a component would read better with
 * different markup, the markup wins.
 *
 * Navigation is NOT declared here. It arrives from the server, generated from
 * the role access matrix at runtime, so a module a role cannot use is absent
 * from this list entirely rather than rendered and hidden. There is
 * deliberately no `disabled` state to render.
 */
defineProps({
    title: { type: String, required: true },
})

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
                    <span>Search clients, guards, invoices&hellip;</span>
                </div>

                <div class="top-right">
                    <div class="top-icon-btn">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path
                                d="M4 11v2a1 1 0 0 0 1 1h2l4 4V6L7 10H5a1 1 0 0 0-1 1z"
                                stroke="currentColor"
                                stroke-width="1.8"
                                stroke-linejoin="round"
                            />
                            <path d="M17 8a5 5 0 0 1 0 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                        </svg>
                    </div>

                    <div class="top-profile">
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
                    </div>
                </div>
            </div>

            <div class="content">
                <slot />
            </div>
        </div>
    </div>
</template>
