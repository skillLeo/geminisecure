<script setup>
/**
 * Icons the boards use OUTSIDE the sidebar.
 *
 * Separate from ModuleIcon on purpose. The boards draw the same subjects with
 * different geometry and different stroke weights depending on where they sit,
 * and those differences are deliberate:
 *
 *   sidebar navigation   1.8   ModuleIcon
 *   KPI cards            1.7   here
 *   activity rows        1.6   here
 *
 * The people icon also differs: the sidebar's has a second figure behind the
 * first, the KPI and activity ones do not. Folding these together behind one
 * component and normalising the stroke is exactly the kind of tidying that
 * produced the drift these boards are being re-lifted to fix.
 *
 * Stroke is a prop rather than baked in, because the same path is drawn at 1.7
 * in a KPI card and 1.6 in an activity row on the same screen.
 *
 * An unknown name renders nothing rather than a fallback glyph. A missing icon
 * is a visible bug worth fixing; a generic placeholder silently ships
 * something nobody drew.
 */
defineProps({
    name: { type: String, required: true },
    stroke: { type: [String, Number], default: 1.7 },
})
</script>

<template>
    <!-- Clients / estates: a building -->
    <svg v-if="name === 'clients'" viewBox="0 0 24 24" fill="none">
        <path d="M3 21V8l9-5 9 5v13M9 21v-6h6v6" :stroke-width="stroke" stroke="currentColor" stroke-linejoin="round" />
    </svg>

    <!-- Units under management: a taller block, standing on a ground line -->
    <svg v-else-if="name === 'units'" viewBox="0 0 24 24" fill="none">
        <path d="M3 21h18M5 21V7l7-4 7 4v14" :stroke-width="stroke" stroke="currentColor" stroke-linejoin="round" />
    </svg>

    <!-- Money: a card -->
    <svg v-else-if="name === 'billing'" viewBox="0 0 24 24" fill="none">
        <rect x="2" y="6" width="20" height="14" rx="2" :stroke-width="stroke" stroke="currentColor" />
        <path d="M2 10h20" :stroke-width="stroke" stroke="currentColor" />
    </svg>

    <!-- Guards: one figure, unlike the sidebar's two -->
    <svg v-else-if="name === 'guards'" viewBox="0 0 24 24" fill="none">
        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" :stroke-width="stroke" stroke="currentColor" />
        <circle cx="9" cy="7" r="4" :stroke-width="stroke" stroke="currentColor" />
    </svg>
</template>
