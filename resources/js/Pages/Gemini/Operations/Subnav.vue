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
 * An item whose screen is not built is a real <button>, disabled, saying so on
 * hover. Never a link to a route that answers 404, and never a silent click.
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
        <template v-for="item in items" :key="item.key">
            <Link
                v-if="item.href"
                :href="item.href"
                class="subnav-item"
                :class="{ active: item.key === props.active }"
                :aria-current="item.key === props.active ? 'page' : undefined"
            >
                {{ item.label }}
            </Link>
            <button v-else type="button" class="subnav-item" disabled :title="item.reason">
                {{ item.label }}
            </button>
        </template>
    </div>
</template>

<style scoped>
/*
 * Nothing here adds a style. The board draws each tab as a <div>; two of them
 * are anchors and the rest buttons, and a browser gives an anchor an underline
 * and a button a border, a face and its own font. These rules take exactly
 * those defaults back off, so every visible property still comes from the
 * board's own .subnav-item rule.
 */
a.subnav-item {
    text-decoration: none;
}

/*
 * The font family only, and on the bare element rather than on the class. The
 * board's .subnav-item already declares the size, the weight and the colour,
 * and an author rule always beats the browser's own — so those need nothing
 * here. Writing `font: inherit` on `button.subnav-item` would out-specify the
 * board's own rule and quietly resize every tab.
 */
button {
    font-family: inherit;
}

button.subnav-item {
    border: 0;
    background: none;
}
</style>
