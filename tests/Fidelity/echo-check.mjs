/**
 * Proves the alert socket end to end, in a real browser.
 *
 * A WebSocket cannot be asserted from PHP. A server-side test can show that an
 * event WOULD broadcast on the right channel; it cannot show that a browser
 * connects, that channel authorization admits it, and that the payload arrives.
 * Those are three separate failures and each one presents identically on
 * screen: a queue that never updates.
 *
 * So this opens Chromium, signs in, waits for the connection to reach
 * 'connected', subscribes to the private per-estate channel, and then waits
 * for a broadcast that is fired separately with:
 *
 *     php artisan alerts:broadcast-test {tenant}
 *
 * Run it as:  node tests/Fidelity/echo-check.mjs [tenantId]
 */

import { chromium } from 'playwright'
import { spawn } from 'node:child_process'

const APP = process.env.APP_URL ?? 'http://127.0.0.1:8000'
const TENANT = process.argv[2] ?? 'phoenixpark'
const CHANNEL = `estate.${TENANT}.alerts`

const log = (...args) => console.log(...args)

const browser = await chromium.launch()
const context = await browser.newContext()
const page = await context.newPage()

/* Everything the page logs, including Pusher's own protocol chatter. */
page.on('console', (msg) => log(`  [browser:${msg.type()}] ${msg.text()}`))
page.on('pageerror', (err) => log(`  [browser:error] ${err.message}`))

log(`app      ${APP}`)
log(`channel  private-${CHANNEL}`)
log('')

await page.goto(`${APP}/dev/login/gemini.dispatcher`, { waitUntil: 'networkidle' })
log(`signed in as gemini.dispatcher, landed on ${new URL(page.url()).pathname}`)

const hasEcho = await page.evaluate(() => typeof window.Echo !== 'undefined')
log(`window.Echo present: ${hasEcho}`)

if (!hasEcho) {
    log('\nFAIL: Echo is not on the page. Is it imported in resources/js/app.js, and was the bundle rebuilt?')
    await browser.close()
    process.exit(1)
}

/* --- 1. does the socket connect at all? ------------------------------- */

const connection = await page.evaluate(
    () =>
        new Promise((resolve) => {
            const conn = window.Echo.connector?.pusher?.connection

            if (!conn) {
                resolve({ ok: false, state: '(no connection object)' })

                return
            }

            if (conn.state === 'connected') {
                resolve({ ok: true, state: 'connected', socketId: conn.socket_id })

                return
            }

            const timer = setTimeout(() => resolve({ ok: false, state: conn.state }), 10000)

            conn.bind('connected', () => {
                clearTimeout(timer)
                resolve({ ok: true, state: 'connected', socketId: conn.socket_id })
            })

            conn.bind('error', (e) => {
                clearTimeout(timer)
                resolve({ ok: false, state: conn.state, error: JSON.stringify(e) })
            })
        })
)

log(`connection state: ${connection.state}${connection.socketId ? `  socket_id=${connection.socketId}` : ''}`)

if (!connection.ok) {
    log(`\nFAIL: socket did not connect. ${connection.error ?? ''}`)
    log('Is Reverb running?  php artisan reverb:start --host=127.0.0.1 --port=8080')
    await browser.close()
    process.exit(1)
}

/* --- 2. does channel authorization admit us? -------------------------- */

const subscription = await page.evaluate(
    (channel) =>
        new Promise((resolve) => {
            const ch = window.Echo.private(channel)

            const timer = setTimeout(() => resolve({ ok: false, why: 'timed out waiting for subscription_succeeded' }), 10000)

            ch.subscribed(() => {
                clearTimeout(timer)
                resolve({ ok: true })
            })

            ch.error((e) => {
                clearTimeout(timer)
                resolve({ ok: false, why: `authorization refused: ${JSON.stringify(e)}` })
            })

            /* Park the arriving event where step 3 can read it. */
            window.__alertReceived = null
            ch.listen('.alert.raised', (payload) => {
                window.__alertReceived = payload
            })
        }),
    CHANNEL
)

log(`subscribed to private-${CHANNEL}: ${subscription.ok}${subscription.why ? `  (${subscription.why})` : ''}`)

if (!subscription.ok) {
    log('\nFAIL: subscription refused. Check the callback in routes/channels.php and that /broadcasting/auth is reachable.')
    await browser.close()
    process.exit(1)
}

/* --- 3. does a real broadcast actually land? -------------------------- */

log('')
log(`firing: php artisan alerts:broadcast-test ${TENANT}`)

await new Promise((resolve) => {
    const proc = spawn('php', ['artisan', 'alerts:broadcast-test', TENANT], { shell: true })
    proc.stdout.on('data', (d) => log(`  [artisan] ${String(d).trim()}`))
    proc.stderr.on('data', (d) => log(`  [artisan] ${String(d).trim()}`))
    proc.on('close', resolve)
})

const received = await page.evaluate(
    () =>
        new Promise((resolve) => {
            if (window.__alertReceived) {
                resolve(window.__alertReceived)

                return
            }

            const started = Date.now()
            const poll = setInterval(() => {
                if (window.__alertReceived) {
                    clearInterval(poll)
                    resolve(window.__alertReceived)
                } else if (Date.now() - started > 10000) {
                    clearInterval(poll)
                    resolve(null)
                }
            }, 100)
        })
)

log('')

if (received === null) {
    log('FAIL: subscribed and connected, but no broadcast arrived within 10s.')
    await browser.close()
    process.exit(1)
}

log('RECEIVED on private-' + CHANNEL + ':')
log(JSON.stringify(received, null, 2))
log('')
log('PASS: connected, authorised, and a real broadcast was received in a browser.')

await browser.close()
