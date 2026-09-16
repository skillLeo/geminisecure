import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useAlertStream } from './useAlertStream.js'
import { useLifeSafetyPoll } from './useLifeSafetyPoll.js'

/**
 * The one way a dispatch screen stays current (13 C1, C2).
 *
 * THE SOCKET IS THE NORMAL MODE. POLLING IS THE FALLBACK, AND IT SAYS SO.
 *
 * Until work order 13 the poll ran all the time — at full rate with no socket,
 * at a thirty-second heartbeat behind one — because a socket that silently
 * stopped delivering looked exactly like a calm night. The client ruled that a
 * thirty-second refresh on a panic queue is a stated degradation, not a normal
 * mode. So the two mechanisms no longer run in parallel:
 *
 *   live      every estate's channel is connected AND subscribed → no poll;
 *             each push re-fetches the screen's props
 *   fallback  any channel is down → the poll runs at the screen's full rate,
 *             starting the moment the channel is lost
 *
 * WHAT NOW CATCHES A SILENT SOCKET is the socket's own heartbeat rather than a
 * second mechanism: pusher-js pings after 30s of quiet and declares the
 * connection lost if no pong arrives within 10s (`echo.js`), so a dead socket
 * flips the screen to fallback within forty seconds. A refused subscription
 * counts as down from the start (`useAlertStream`).
 *
 * WHAT IS MISSED DURING AN OUTAGE is fetched once, on the way back: the moment
 * every channel is live again the screen refreshes, then stops polling.
 *
 * AND THE DISPATCHER IS TOLD WHICH MODE THEY ARE IN — `DispatchConnectionBar`,
 * on every dispatch screen, reads `connection` from here.
 *
 * @param {object} options
 * @param {string[]} options.only        the props to re-fetch
 * @param {number} options.intervalMs    the fallback rate
 * @param {string[]} [options.estateIds] estates to listen to; omit for none
 */
export function useLiveDispatch({ only, intervalMs, estateIds = [] }) {
    /*
     * One subscription per estate. A Director sees every client at once, so the
     * map and the alertness board listen to all of them; a single alert's screen
     * listens only to the estate it belongs to.
     */
    let poll = null
    const streams = estateIds.map((id) => useAlertStream(id, { onAlert: () => poll?.refresh() }))

    /*
     * EVERY stream, not any. A console watching four estates with three sockets
     * up is not covered — the fourth estate's panic would arrive only on the
     * poll — so the screen stays in fallback until all of them are live. `every`
     * on an empty list is true, which is why the guard on length comes first: a
     * screen with no subscriptions is not live, it has nothing to be live on.
     */
    const streaming = computed(
        () => streams.length > 0 && streams.every((stream) => stream.connected.value),
    )

    poll = useLifeSafetyPoll(only, intervalMs, { active: () => !streaming.value })

    /** When the screen last fell back to polling, for the bar to say how long. */
    const downSince = ref(streaming.value ? null : new Date())

    watch(streaming, (isStreaming, wasStreaming) => {
        if (isStreaming) {
            poll.stop()
            downSince.value = null

            // Back from an outage: fetch once what the pushes may have missed.
            if (wasStreaming === false) {
                poll.refresh()
            }

            return
        }

        downSince.value = new Date()
        poll.refresh()
        poll.start()
    })

    /*
     * A clock for the bar's "for 2 min" and the map's staleness figure, so both
     * are live figures rather than values frozen at the last render.
     */
    const now = ref(Date.now())
    let ticker = null

    onMounted(() => {
        ticker = setInterval(() => {
            now.value = Date.now()
        }, 1000)
    })

    onUnmounted(() => clearInterval(ticker))

    const connection = computed(() => ({
        mode: streaming.value ? 'live' : 'fallback',
        estates: streams.length,
        intervalMs,
        downForSeconds: downSince.value === null ? 0 : Math.max(0, Math.round((now.value - downSince.value.getTime()) / 1000)),
        lastUpdated: poll.lastUpdated.value,
    }))

    /** What the screen tells the dispatcher, in the two states it can be in. */
    const liveLabel = computed(() => (streaming.value ? 'Live' : 'Fallback · polling'))

    const liveReason = computed(() =>
        streaming.value
            ? 'Live over the alert channel: alerts and clock-ins push the moment they happen. If the channel '
              + 'drops, this screen falls back to polling within forty seconds and says so. Click to refresh now.'
            : `The live alert channel is down, so this screen is refreshing every ${intervalMs / 1000} seconds `
              + 'until it returns. Alerts can be up to that late. Click to refresh now.',
    )

    return { poll, streaming, connection, now, liveLabel, liveReason }
}