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
 * So the socket does not replace polling — it races it. Both run; whichever
 * notices first wins. That is deliberate redundancy on the one screen where a
 * missed event is not an inconvenience, and it is cheap: a poll every three
 * seconds against a page that is already open costs far less than a panic
 * alert sitting unseen.
 *
 * When the socket is connected the caller may slow its poll right down; when
 * it is not, the poll is the only thing working and must stay at full rate.
 * `connected` is exposed for exactly that decision, and for telling the
 * dispatcher on screen.
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
                connected.value = state === 'connected'
            })

            channel = echo.private(`estate.${tenantId}.alerts`)

            channel.listen('.alert.raised', () => {
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
                connected.value = false
            })
        })
        .catch(() => {
            // Echo could not load at all. Not fatal: polling is still running,
            // and connected staying false is exactly the right signal.
            connected.value = false
        })

    onBeforeUnmount(() => {
        disposed = true
        unbindState()

        if (echoInstance && channel) {
            echoInstance.leave(`estate.${tenantId}.alerts`)
        }
    })

    return { connected, lastEventAt }
}
