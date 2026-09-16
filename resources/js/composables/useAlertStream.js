import { onBeforeUnmount, ref } from 'vue'
import { router } from '@inertiajs/vue3'

/**
 * Live alert feed for one estate, over the private broadcast channel.
 *
 * THIS IS A LIFE-SAFETY SURFACE. The design follows from one rule: quiet
 * because nothing happened and quiet because the connection dropped must never
 * look the same. A dispatcher staring at an empty queue has to be able to tell
 * which one they are looking at.
 *
 * `connected` IS TRUE ONLY WHEN THE SOCKET IS CONNECTED AND THIS ESTATE'S
 * CHANNEL IS SUBSCRIBED (13 C2). A connected socket whose subscription is
 * pending, or was refused, delivers nothing — and since the caller now stops
 * polling while this is true, a premature `true` would be a screen that has
 * gone quiet for no reason. So the channel's own subscription-succeeded event
 * is what sets it, and any state change away from `connected` clears it.
 *
 * `reconnectedAt` moves each time the channel comes back after being down, so
 * the caller can refresh once and pick up whatever arrived during the outage.
 *
 * Echo is imported lazily. The sign-in page and every non-dispatch screen have
 * nothing to listen for, and an idle socket per open tab is a cost with no
 * benefit.
 *
 * @param {string} tenantId  the estate whose alerts to listen for
 * @param {object} [options]
 * @param {() => void} [options.onAlert]  called when an alert arrives
 */
export function useAlertStream(tenantId, options = {}) {
    const connected = ref(false)
    const lastEventAt = ref(null)
    const reconnectedAt = ref(null)

    let socketUp = false
    let subscribed = false
    let everSubscribed = false

    const recompute = () => {
        const next = socketUp && subscribed

        if (next && !connected.value && everSubscribed) {
            reconnectedAt.value = new Date()
        }

        connected.value = next
    }

    let channel = null
    let echoInstance = null
    let unbindState = () => {}
    let disposed = false

    const onAlert =
        options.onAlert ??
        (() => {
            // Reload the queue rather than splice the broadcast payload into
            // it. The payload is deliberately thin — no location, no money —
            // so it is enough to know something happened, never enough to
            // render a row from. Asking the server keeps one source of truth.
            router.reload({ only: ['alerts', 'counts'], preserveScroll: true })
        })

    import('../echo')
        .then(({ echo, onConnectionState }) => {
            if (disposed) {
                return
            }

            echoInstance = echo

            unbindState = onConnectionState((state) => {
                socketUp = state === 'connected'

                // Pusher resubscribes after a reconnect and says so again; until
                // it does, this estate's channel is not delivering.
                if (!socketUp) {
                    subscribed = false
                }

                recompute()
            })

            channel = echo.private(`estate.${tenantId}.alerts`)

            channel.subscribed(() => {
                subscribed = true
                recompute()
                everSubscribed = true
            })

            channel.listen('.alert.raised', () => {
                lastEventAt.value = new Date()
                onAlert()
            })

            /*
             * A guard clocking on or off changes coverage and alertness (13 C1).
             * The same thin notice: something moved, ask the server.
             */
            channel.listen('.shift.clocked', () => {
                lastEventAt.value = new Date()
                onAlert()
            })

            /*
             * A refused subscription is not the same as a quiet one.
             *
             * If authorization fails the socket stays connected and simply
             * never delivers, which is indistinguishable from a calm night.
             * Treat it as disconnected so the caller keeps polling at full
             * rate and the dispatcher is told.
             */
            channel.error(() => {
                subscribed = false
                recompute()
            })
        })
        .catch(() => {
            // Echo could not load at all. Not fatal: the caller polls whenever
            // this is false, which is exactly the right signal.
            connected.value = false
        })

    onBeforeUnmount(() => {
        disposed = true
        unbindState()

        if (echoInstance && channel) {
            echoInstance.leave(`estate.${tenantId}.alerts`)
        }
    })

    return { connected, lastEventAt, reconnectedAt }
}
