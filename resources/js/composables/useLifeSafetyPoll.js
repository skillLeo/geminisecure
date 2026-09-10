import { onMounted, onUnmounted, ref } from 'vue'
import { router } from '@inertiajs/vue3'

/**
 * Polls a life-safety surface, and keeps polling even once WebSockets are live.
 *
 * IT WAS A STOPGAP AND IT IS NOW THE GUARANTEE. Reverb could not be installed
 * when this was written (D-027), which left the alert queue refreshing only on
 * page load; Reverb is installed now (D-032), and the obvious next step is to
 * delete this. That would be wrong, for the reason `useAlertStream` states in
 * its own docblock: quiet because nothing happened and quiet because the socket
 * dropped must never look the same, and a dispatcher staring at an empty queue
 * has to be able to tell which one they are looking at.
 *
 * So the poll stays and BACKS OFF instead. `setIntervalMs()` lets a screen slow
 * it to a heartbeat while the socket is connected and return it to full rate
 * the moment that stops being true — which is the decision `useAlertStream`
 * exposes `connected` for. The socket is the speed; this is the guarantee.
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

    /*
     * The rate in force right now, which is not the same as the rate this was
     * created with. A screen whose socket is connected slows it down; one whose
     * socket drops speeds it straight back up. Exposed so the screen can say
     * which, because "refreshing every five seconds" and "refreshing every
     * thirty" are different promises to make to a dispatcher.
     */
    const currentIntervalMs = ref(intervalMs)

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
        timer = setInterval(refresh, currentIntervalMs.value)
        polling.value = true
    }

    /**
     * Change the rate, and restart the timer so it takes effect now.
     *
     * WITHOUT THE RESTART THIS WOULD DO NOTHING for up to a full interval —
     * `setInterval` fixes its period when it is created. On the way DOWN that
     * is merely slow; on the way up, when a socket has just dropped, it would
     * leave the screen on a thirty-second heartbeat during exactly the window
     * where the poll is the only thing working.
     *
     * @param {number} ms
     */
    const setIntervalMs = (ms) => {
        if (ms === currentIntervalMs.value) {
            return
        }

        currentIntervalMs.value = ms

        if (polling.value) {
            start()
        }
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

    return { polling, lastUpdated, currentIntervalMs, refresh, start, stop, setIntervalMs }
}
