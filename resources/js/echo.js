import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

window.Pusher = Pusher

/**
 * WebSocket client, pointed at Reverb.
 *
 * Reverb speaks the Pusher protocol, which is why pusher-js is the transport
 * here despite there being no Pusher account involved. Swapping Reverb for
 * Soketi or hosted Pusher later changes these four values and nothing else.
 */
export const echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 8080,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 8080,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
    enabledTransports: ['ws', 'wss'],

    /*
     * Do not connect on import. The sign-in screen and every non-dispatch
     * page have nothing to listen for, and an idle socket per open tab is a
     * cost with no benefit.
     */
    activityTimeout: 30000,

    /*
     * HOW FAST A DEAD SOCKET IS NOTICED, now that it decides whether a dispatch
     * screen polls at all (13 C2). After `activityTimeout` of silence the
     * client pings; with no pong inside `pongTimeout` it declares the connection
     * lost, the state leaves `connected`, and every dispatch screen falls back
     * to polling. Worst case, a socket that died silently is noticed in forty
     * seconds — and the screen says "fallback" from that moment.
     */
    pongTimeout: 10000,
})

/**
 * Whether the socket is currently usable.
 *
 * Exposed so a life-safety surface can tell the difference between "quiet
 * because nothing happened" and "quiet because the connection dropped" — the
 * second must never look like the first.
 */
export function isConnected() {
    return echo.connector?.pusher?.connection?.state === 'connected'
}

/**
 * Subscribe to connection state changes.
 *
 * @param {(state: string) => void} handler
 * @returns {() => void} unsubscribe
 */
export function onConnectionState(handler) {
    const connection = echo.connector?.pusher?.connection

    if (!connection) {
        return () => {}
    }

    const listener = ({ current }) => handler(current)

    connection.bind('state_change', listener)
    handler(connection.state)

    return () => connection.unbind('state_change', listener)
}
