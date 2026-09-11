import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import { echo } from './echo'

const appName = import.meta.env.VITE_APP_NAME || 'GeminiSecure'

/*
 * Echo, imported here so it actually exists.
 *
 * echo.js was written and then imported by nothing, so the socket had never
 * opened once — which is why the alert queue was still relying entirely on a
 * 3-second poll. That was a one-line defect, not a design decision.
 *
 * Exposed on window as well because a WebSocket is not something you can
 * assert from PHP: proving it works means opening a browser, watching the
 * connection reach 'connected', subscribing to the private channel and seeing
 * a real broadcast land. tests/Fidelity/echo-check.mjs does exactly that, and
 * needs a handle to do it.
 */
window.Echo = echo

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
        // The token, not its value. This used to be --navy-600's hex, written out,
        // on the belief that the colour is read before any stylesheet is parsed — but
        // Inertia only writes it into a <style> rule for the bar, and a custom
        // property resolves when that rule is applied to the element, against
        // :root in tokens.css. So the bar follows the token like everything else.
        color: 'var(--navy-600)',
    },
})
