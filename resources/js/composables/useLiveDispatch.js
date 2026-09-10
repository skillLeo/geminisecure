import { computed, watch } from 'vue'
import { useAlertStream } from './useAlertStream.js'
import { useLifeSafetyPoll } from './useLifeSafetyPoll.js'

/**
 * The one way a dispatch screen stays current: socket for speed, poll for the
 * guarantee, and the poll backing off while the socket is up.
 *
 * WHY THIS EXISTS RATHER THAN FOUR COPIES OF THE RULE. Four screens carry a
 * panic alert — the queue, one alert's response, the live map and the alertness
 * board — and each was wiring its own combination of the two composables. Two
 * of them had no socket at all and all four polled at a fixed rate whether one
 * was connected or not. A life-safety refresh policy that is written down four
 * times is a policy that will shortly be written down four different ways.
 *
 * THE POLL IS NEVER TURNED OFF. It would be easy to read "Reverb is installed
 * now" as "delete the polling", and it is the one conclusion that must not be
 * drawn: a socket that has silently stopped delivering looks exactly like a calm
 * night, and the screen where that matters is the screen where somebody has
 * pressed a panic button. So the socket only ever changes the RATE.
 *
 *   connected      → HEARTBEAT_MS, a safety net behind the push
 *   not connected  → the screen's own full rate, because nothing else is working
 *
 * THIRTY SECONDS IS NOT AN ARBITRARY NUMBER. It is the longest a dispatcher
 * should ever be unaware that the socket died — because that is the worst case:
 * the socket drops silently right after a heartbeat, and the next poll is what
 * discovers both the disconnection and whatever arrived during it. Anything
 * longer trades a real safety margin for load this platform does not have.
 *
 * @param {object} options
 * @param {string[]} options.only        the props to re-fetch
 * @param {number} options.intervalMs    full rate, used whenever the socket is down
 * @param {string[]} [options.estateIds] estates to listen to; omit for none
 */
export function useLiveDispatch({ only, intervalMs, estateIds = [] }) {
    /** The slowest this may run, and only while a socket is proven up. */
    const HEARTBEAT_MS = 30000

    const poll = useLifeSafetyPoll(only, intervalMs)

    /*
     * One subscription per estate. A Director sees every client at once, so the
     * map and the alertness board listen to all of them; a single alert's screen
     * listens only to the estate it belongs to.
     */
    const streams = estateIds.map((id) => useAlertStream(id, { onAlert: poll.refresh }))

    /*
     * EVERY stream, not any. A console watching four estates with three sockets
     * up is not covered — the fourth estate's panic would arrive only on the
     * poll — so the screen must keep polling at full rate until all of them are
     * connected. `every` on an empty list is true, which is why the guard on
     * length comes first: a screen with no subscriptions is not "fully
     * connected", it is not connected at all.
     */
    const streaming = computed(
        () => streams.length > 0 && streams.every((stream) => stream.connected.value),
    )

    watch(
        streaming,
        (isStreaming) => {
            poll.setIntervalMs(isStreaming ? HEARTBEAT_MS : intervalMs)
        },
        { immediate: true },
    )

    /** What the screen tells the dispatcher, in the two states it can be in. */
    const liveLabel = computed(() => (streaming.value ? 'Live' : 'Polling'))

    const liveReason = computed(() =>
        streaming.value
            ? `Live over the alert channel. A ${HEARTBEAT_MS / 1000}-second refresh runs behind it so a `
              + 'socket that stops delivering cannot look like a quiet night. Click to refresh now.'
            : `Refreshing every ${intervalMs / 1000} seconds. The live alert channel is not connected, `
              + 'so this poll is the only notifier. Click to refresh now.',
    )

    return { poll, streaming, liveLabel, liveReason, heartbeatMs: HEARTBEAT_MS }
}
