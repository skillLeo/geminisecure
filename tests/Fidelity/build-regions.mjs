/**
 * Generates _design/SCREEN_REGIONS.json — §1.4 of the remediation brief.
 *
 * Every wireframe file is a BOARD: one HTML document holding several screens
 * side by side, each inside a browser-chrome or phone-frame mock. A Vue route
 * renders one screen with no frame around it. Diffing a route against a whole
 * board compares one screen to six and fails for the wrong reason, so the
 * harness needs to know exactly which rectangle of which board is which screen.
 *
 * Measured, never assumed:
 *
 *   web boards     .app-shell   1440 x 900   — inside .browser > .browser-body
 *   mobile boards  .screen       397 x 856   — inside .device (433 x 892 with bezel)
 *
 * The bezel is deliberately outside the region. It is frame, not screen, and
 * including it would bake a mock into the acceptance criteria.
 *
 * Screen numbers are the board's own. Each screen is introduced by a .chip
 * reading "4  ·  Client Directory", and those numbers run continuously across
 * the files of one surface, so the designer's numbering survives into the
 * harness rather than being replaced by an index of my own invention.
 */

import { chromium } from 'playwright'
import { pathToFileURL } from 'node:url'
import { writeFileSync, readdirSync } from 'node:fs'
import path from 'node:path'

const ROOT = path.resolve('_design/wireframes')

const SURFACES = [
    { dir: 'GeminiSecure Super Admin Screens', slug: 'super-admin', kind: 'web' },
    { dir: 'GeminiSecure Community Admin Screens', slug: 'community-admin', kind: 'web' },
    { dir: 'GeminiSecure Guard App Screens', slug: 'guard-app', kind: 'mobile' },
    { dir: 'GeminiSecure Resident App Screens', slug: 'resident-app', kind: 'mobile' },
]

/*
 * The element that IS the screen, per board kind.
 *
 * .browser-body, not .app-shell. The console screens are drawn as .app-shell
 * inside .browser-body, but the login screen is not — it is a centred card
 * with no console chrome, so keying on .app-shell silently loses it and every
 * screen number after it shifts by one. .browser-body is the mock's inner
 * surface and is present on every web screen, which also makes it the element
 * that carries the app viewport's background.
 */
const VIEWPORT_SELECTOR = { web: '.browser-body', mobile: '.screen' }

const browser = await chromium.launch()
const screens = []
const boards = []

for (const surface of SURFACES) {
    const files = readdirSync(path.join(ROOT, surface.dir))
        .filter((f) => f.endsWith('.html'))
        .sort()

    for (const file of files) {
        const abs = path.join(ROOT, surface.dir, file)

        /*
         * A tall, wide viewport so nothing lazy-lays-out or wraps differently
         * than it would on the designer's screen. The boards are fixed-width,
         * so this only affects how much is painted at once, never the metrics.
         */
        const page = await browser.newPage({ viewport: { width: 1920, height: 1200 } })
        await page.goto(pathToFileURL(abs).href, { waitUntil: 'load' })
        await page.evaluate(() => document.fonts.ready)

        const found = await page.evaluate((selector) => {
            /*
             * Label association by document order. Each screen is preceded by
             * its own .chip, so the nearest preceding chip is its label. This
             * survives boards that nest .stage inside .stage, which an
             * ancestor-walk does not.
             */
            const all = [...document.querySelectorAll('.chip, ' + selector)]
            const out = []
            let label = null

            for (const el of all) {
                if (el.matches('.chip')) {
                    label = el.textContent.trim().replace(/\s+/g, ' ')
                    continue
                }

                const r = el.getBoundingClientRect()

                out.push({
                    label,
                    x: Math.round(r.x + window.scrollX),
                    y: Math.round(r.y + window.scrollY),
                    width: Math.round(r.width),
                    height: Math.round(r.height),
                })
            }

            return out
        }, VIEWPORT_SELECTOR[surface.kind])

        found.forEach((hit, index) => {
            /* "4 · Client Directory" -> number 4, title "Client Directory". */
            const m = /^(\d+)\s*·\s*(.+)$/.exec(hit.label ?? '')
            const number = m ? Number(m[1]) : null
            const title = m ? m[2] : (hit.label ?? `screen ${index + 1}`)

            screens.push({
                id: `${surface.slug}-${String(number ?? index + 1).padStart(2, '0')}`,
                surface: surface.slug,
                kind: surface.kind,
                number,
                title,
                source: `${surface.dir}/${file}`,
                selector: VIEWPORT_SELECTOR[surface.kind],
                index,
                region: { x: hit.x, y: hit.y, width: hit.width, height: hit.height },

                /*
                 * The viewport is the MEASURED size, not the nominal one.
                 *
                 * Width is the contract and never varies: 1440 on web, 397 on
                 * mobile. Height does — Dispatch is drawn at 1000 and Platform
                 * Settings at 1140 because those screens carry more. Forcing
                 * every screen to 900 would crop the designer's own layout and
                 * then fail the diff for content the board never put there.
                 */
                viewport: { width: hit.width, height: hit.height },
                route: null,
                component: null,
            })
        })

        boards.push({ file: `${surface.dir}/${file}`, screens: found.length })
        await page.close()
    }
}

await browser.close()

/* Width is the contract. A board that disagrees about width is a real fault. */
const NOMINAL = { web: { width: 1440, height: 900 }, mobile: { width: 397, height: 856 } }

const wrongSize = screens.filter((s) => s.region.width !== NOMINAL[s.kind].width)

/* Taller-than-nominal screens are the designer's decision, recorded not corrected. */
const tallerThanNominal = screens.filter((s) => s.region.height !== NOMINAL[s.kind].height)

const payload = {
    generated_by: 'node tests/Fidelity/build-regions.mjs',
    note: 'Measured from the wireframe boards, never hand-written. Regenerate after any board changes.',
    viewports: { web: '1440x900 (.app-shell)', mobile: '397x856 (.screen, inside the 433x892 .device bezel)' },
    total: screens.length,
    nominal: NOMINAL,
    taller_than_nominal: tallerThanNominal.map((s) => ({
        id: s.id,
        height: s.region.height,
        source: s.source,
    })),
    boards,
    screens,
}

writeFileSync('_design/SCREEN_REGIONS.json', JSON.stringify(payload, null, 2) + '\n', 'utf8')

for (const surface of SURFACES) {
    const n = screens.filter((s) => s.surface === surface.slug).length
    console.log(`${surface.slug.padEnd(18)} ${String(n).padStart(3)} screens  (${surface.kind})`)
}

console.log('-'.repeat(40))
console.log(`${'TOTAL'.padEnd(18)} ${String(screens.length).padStart(3)} screens`)

if (tallerThanNominal.length > 0) {
    console.log(`\n${tallerThanNominal.length} screen(s) drawn taller than nominal - reproduced, not corrected:`)
    const byHeight = new Map()
    for (const s of tallerThanNominal) {
        const key = `${s.region.height}px  ${s.source}`
        byHeight.set(key, (byHeight.get(key) ?? 0) + 1)
    }
    for (const [key, n] of byHeight) {
        console.log(`  ${String(n).padStart(2)} x  ${key}`)
    }
}

if (wrongSize.length > 0) {
    console.error(`\n${wrongSize.length} region(s) have the wrong WIDTH, which is the contract:`)
    for (const s of wrongSize.slice(0, 10)) {
        console.error(`  ${s.id}  ${s.region.width}  expected ${NOMINAL[s.kind].width}  ${s.source}`)
    }
    process.exit(1)
}

const unlabelled = screens.filter((s) => s.number === null)
if (unlabelled.length > 0) {
    console.error(`\n${unlabelled.length} screen(s) have no numbered chip label:`)
    for (const s of unlabelled.slice(0, 10)) {
        console.error(`  ${s.id}  "${s.title}"  ${s.source}`)
    }
    process.exit(1)
}

console.log('\nEvery region is exactly its expected size and carries a numbered label.')
