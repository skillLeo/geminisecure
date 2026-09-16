<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'

/**
 * The Guard workforce sub-navigation, drawn on boards 18 to 27.
 *
 * One list in one place. The six items appear on every screen in the module,
 * and a copy of them per page is six copies to keep in step — which is how a
 * console ends up with a tab that is a live link on one screen and a dead
 * <div> on the next.
 *
 * All six screens are built, so all six are links (12 §2, item 43).
 */
const props = defineProps({
    /** Which item is the current screen. */
    active: { type: String, required: true },
})

const items = computed(() => [
    { key: 'directory', label: 'Directory', href: '/guards' },
    { key: 'roster', label: 'Roster', href: '/guards/roster' },
    { key: 'standing-orders', label: 'Standing orders', href: '/guards/standing-orders' },
    { key: 'gate-activity', label: 'Gate activity', href: '/guards/activity' },
    { key: 'compliance', label: 'Compliance', href: '/guards/compliance' },
    { key: 'incidents', label: 'Incidents', href: '/guards/incidents' },
])
</script>

<template>
    <div class="subnav">
        <Link
            v-for="item in items"
            :key="item.key"
            :href="item.href"
            class="subnav-item"
            :class="{ active: item.key === props.active }"
            :aria-current="item.key === props.active ? 'page' : undefined"
        >
            {{ item.label }}
        </Link>
    </div>
</template>

<style scoped>
/*
 * Nothing here adds a style. The board draws each tab as a <div>; here they are
 * anchors, and a browser gives an anchor an underline. That is all this takes
 * back off, so every visible property still comes from the board's own
 * .subnav-item rule.
 */
a.subnav-item {
    text-decoration: none;
}
</style>
