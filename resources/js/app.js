import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'

const appName = import.meta.env.VITE_APP_NAME || 'GeminiSecure'

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),

    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true })
        const page = pages[`./Pages/${name}.vue`]

        if (!page) {
            // Fail loudly. A silent miss renders a blank screen, which during
            // a 165-screen build reads as "not written yet" rather than
            // "wired to the wrong name".
            throw new Error(`Inertia page not found: ./Pages/${name}.vue`)
        }

        return page
    },

    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el)
    },

    progress: {
        // --navy-600, the wireframes' primary. Kept in sync by hand because
        // this runs before any stylesheet is parsed.
        color: '#1974D2',
    },
})
