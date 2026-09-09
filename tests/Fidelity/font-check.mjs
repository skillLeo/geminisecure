/**
 * Font proof — §1.2 of the remediation brief.
 *
 * A missing font falls back silently to a system face and changes every
 * measurement on the page. Nothing downstream can be trusted until this
 * passes, so it runs first and on its own.
 *
 * Checks the running application, not the wireframe: the wireframe pulls
 * Poppins and Inter from Google Fonts over the network, while the application
 * self-hosts them through @fontsource. Those are two different delivery paths
 * and only one of them is ours to get wrong.
 */

import { chromium } from 'playwright'

const APP = process.env.APP_URL ?? 'http://127.0.0.1:8000'

/* Exactly the assertions the brief names, plus the weights the boards use. */
const CHECKS = [
    '600 18px Poppins',
    '500 13px Poppins',
    '700 32px Poppins',
    '400 12.5px Inter',
    '500 13px Inter',
    '600 13px Inter',
    '700 15px Inter',
    '400 13px "IBM Plex Mono"',
    '500 13px "IBM Plex Mono"',
]

const browser = await chromium.launch()
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } })

await page.goto(`${APP}/login`, { waitUntil: 'networkidle' })
await page.evaluate(() => document.fonts.ready)

const result = await page.evaluate(async (checks) => {
    const out = { checks: {}, families: {}, loaded: [] }

    /*
     * load() before check().
     *
     * document.fonts.check() reports whether a face is ALREADY fetched, not
     * whether it is available — so a perfectly good @fontsource weight that
     * this particular page happens not to use returns false. Forcing the load
     * first is the assertion that actually matters: if the @font-face URL is
     * missing or 404s, load() cannot satisfy it and check() stays false.
     */
    for (const spec of checks) {
        try {
            await document.fonts.load(spec)
        } catch {
            // Swallowed on purpose: the check below is the verdict, and a
            // throw here would hide which specific weight is the broken one.
        }

        out.checks[spec] = document.fonts.check(spec)
    }

    for (const face of document.fonts) {
        out.loaded.push(`${face.family} ${face.weight} ${face.status}`)
    }

    const h1 = document.querySelector('h1')
    const body = document.body

    out.families.h1 = h1 ? getComputedStyle(h1).fontFamily : '(no h1 on this page)'
    out.families.body = getComputedStyle(body).fontFamily

    return out
}, CHECKS)

await browser.close()

let failed = 0

console.log('document.fonts.check')
console.log('-'.repeat(52))
for (const [spec, ok] of Object.entries(result.checks)) {
    if (!ok) {
        failed++
    }
    console.log(`${ok ? 'true ' : 'FALSE'}  ${spec}`)
}

console.log('')
console.log('getComputedStyle')
console.log('-'.repeat(52))
console.log(`h1     ${result.families.h1}`)
console.log(`body   ${result.families.body}`)

console.log('')
console.log(`faces registered: ${result.loaded.length}`)

if (failed > 0) {
    console.error(`\nFAILED: ${failed} font check(s) returned false.`)
    process.exit(1)
}

console.log('\nPASS: every font check returned true.')
