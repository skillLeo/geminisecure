import { onMounted, onUnmounted, ref } from 'vue'
import { router } from '@inertiajs/vue3'

/**
 * Polls a life-safety surface until WebSockets are live.
 *
 * THIS IS A STOPGAP, NOT AN ARCHITECTURE. Reverb could not be installed
 * (DECISIONS.md D-027), which left the alert queue refreshing only on page
 * load — meaning a dispatcher would not see a panic alert until they happened
 * to reload. That is not acceptable on a life-safety surface, so this closes
 * the gap while the dependency conflict is resolved properly.
 *
 * DELIBERATELY NARROW. Only the alert queue and the panic response screen use
 * it. Polling the whole console every three seconds would be a self-inflicted
 * load problem for screens where staleness costs nothing — a billing figure
 * three seconds out of date harms no one.
 *
 * Uses Inertia partial reloads, so only the named props are re-fetched and the
 * scroll position, open menus and focus are preserved. A full page reload
 * every three seconds would make the screen unusable.
 *
 * Pauses when the tab is hidden: a backgrounded dispatcher screen polling
 * forever is wasted load, and the visibility change triggers an immediate
 * refresh so nothing is missed on return.
 *
 * @param {string[]} only  the props to re-fetch
 * @param {number} intervalMs
 */
export function useLifeSafetyPoll(only, intervalMs = 3000) {
    const polling = ref(true)
    const lastUpdated = ref(new Date())

    let timer = null

    const refresh = () => {
        router.reload({
            only,
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                lastUpdated.value = new Date()
            },
        })
    }

    const start = () => {
        stop()
        timer = setInterval(refresh, intervalMs)
        polling.value = true
    }

    const stop = () => {
        if (timer !== null) {
            clearInterval(timer)
            timer = null
        }
        polling.value = false
    }

    const onVisibilityChange = () => {
        if (document.hidden) {
            stop()

            return
        }

        // Refresh immediately on return, then resume. Waiting a full interval
        // would show a stale queue at exactly the moment attention returns.
        refresh()
        start()
    }

    onMounted(() => {
        start()
        document.addEventListener('visibilitychange', onVisibilityChange)
    })

    onUnmounted(() => {
        stop()
        document.removeEventListener('visibilitychange', onVisibilityChange)
    })

    return { polling, lastUpdated, refresh, start, stop }
}
