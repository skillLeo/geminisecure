/**
 * The fidelity harness — §1.3 of the remediation brief.
 *
 * Opens the wireframe board and the running application side by side at the
 * same size, crops the board to the one screen under test, and reports the
 * percentage of pixels that differ. A screen is not done until that number is
 * under the threshold.
 *
 * Run through `php artisan fidelity:check`, which resolves the record ids the
 * detail routes need and writes _design/SCREEN_TARGETS.json for this script.
 *
 * On failure it writes original.png, actual.png and diff.png so the difference
 * can be looked at rather than guessed at.
 */

import { chromium } from 'playwright'
import { PNG } from 'pngjs'
import pixelmatch from 'pixelmatch'
import { readFileSync, writeFileSync, mkdirSync, rmSync, existsSync } from 'node:fs'
import { pathToFileURL } from 'node:url'
import path from 'node:path'

const targets = JSON.parse(readFileSync('_design/SCREEN_TARGETS.json', 'utf8'))
const APP = targets.app_url
const THRESHOLD = targets.threshold_percent
const OUT = path.resolve('_design/screenshots/diff')

/* Per-pixel colour tolerance. 0.1 is strict: antialiasing passes, a changed
 * shade or a shifted baseline does not. */
const PIXEL_THRESHOLD = 0.1

if (existsSync(OUT)) {
    rmSync(OUT, { recursive: true })
}
mkdirSync(OUT, { recursive: true })

/*
 * Freeze every animation, on both sides.
 *
 * A board is a still image; the application is not. A loading skeleton's
 * gradient sweep, a progress bar easing to its width, a spinner — any of them
 * leaves Playwright waiting for the element to be "stable" until it times out,
 * and a screen that never settles reports as a failure indistinguishable from
 * a broken one. It also makes any diff that does complete non-deterministic,
 * because the frame it caught is a matter of milliseconds.
 *
 * Applied to the board too, so neither side is measured under different rules.
 */
const FREEZE_ANIMATIONS = `
*, *::before, *::after {
    animation-duration: 0s !important;
    animation-delay: 0s !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0s !important;
    transition-delay: 0s !important;
    caret-color: transparent !important;
}
`

const browser = await chromium.launch()

/*
 * One signed-in context for every authenticated screen.
 *
 * The quick-login route is local-only and exists so the 13 roles can be
 * compared without 13 sets of credentials; here it saves 14 sign-ins. The
 * Director holds every Gemini module, so no screen fails the diff merely
 * because the role could not reach it.
 */
const contexts = new Map()

/**
 * A browser context signed in as one role, created once and reused.
 *
 * ONE PER ROLE, NOT ONE FOR ALL. The Gemini boards are all drawn as the
 * Director, who holds every Gemini module — but the Estate boards are drawn as
 * a committee member, and the Director cannot reach an estate console at all.
 * Sharing a single session across both would diff every estate screen against
 * a 403 and report a believable-looking percentage, which sends the reader
 * hunting for a styling fault that is not there.
 */
async function contextFor(role) {
    if (contexts.has(role)) {
        return contexts.get(role)
    }

    const context = await browser.newContext({ deviceScaleFactor: 1 })
    const p = await context.newPage()

    await p.goto(`${APP}/dev/login/${role}`, { waitUntil: 'networkidle' })

    // Verified, not assumed. A lapsed session makes every screen diff the
    // sign-in page against its board.
    const landed = new URL(p.url()).pathname

    if (landed === '/login') {
        console.error(`Quick login as ${role} did not take: still on /login.`)
        console.error('Is APP_ENV=local, and is the dev.login route registered?')
        await browser.close()
        process.exit(1)
    }

    console.log(`signed in as ${role}, landed on ${landed}`)
    await p.close()

    contexts.set(role, context)

    return context
}

const guest = await browser.newContext({ deviceScaleFactor: 1 })

const results = []

for (const target of targets.targets) {
    const { width, height } = target.viewport

    /* --- the wireframe side ------------------------------------------- */
    const boardPage = await browser.newPage({
        viewport: { width: 1920, height: 1200 },
        deviceScaleFactor: 1,
    })

    const boardUrl = pathToFileURL(path.resolve('_design/wireframes', target.source)).href
    await boardPage.goto(boardUrl, { waitUntil: 'load' })
    await boardPage.evaluate(() => document.fonts.ready)
    await boardPage.addStyleTag({ content: FREEZE_ANIMATIONS })

    const element = boardPage.locator(target.selector).nth(target.index)
    const expectedBuf = await element.screenshot()
    await boardPage.close()

    /* --- the application side ------------------------------------------ */
    const context = target.guest ? guest : await contextFor(target.role ?? 'gemini.director')

    if (process.env.FIDELITY_DEBUG) {
        const jar = await context.cookies()
        console.log(
            `[debug] ${target.id} guest=${target.guest} cookies=${jar.map((c) => c.name).join(',') || '(none)'} url=${target.url}`
        )
    }

    const appPage = await context.newPage()
    await appPage.setViewportSize({ width, height })

    let actualBuf = null
    let loadError = null

    try {
        const response = await appPage.goto(target.url, { waitUntil: 'networkidle', timeout: 30000 })

        if (response && response.status() >= 400) {
            loadError = `HTTP ${response.status()}`
        } else if (new URL(appPage.url()).pathname !== new URL(target.url).pathname) {
            /*
             * Redirected somewhere else — almost always to /login because the
             * harness session lapsed. Without this the run diffs the sign-in
             * page against a dashboard board and reports a plausible-looking
             * 62%, which reads as a styling problem rather than an auth one.
             */
            loadError = `redirected to ${new URL(appPage.url()).pathname}`
        } else {
            await appPage.evaluate(() => document.fonts.ready)
            // Inertia mounts after the module script runs; without this the
            // screenshot can catch an empty #app and report 100% differing
            // for a page that is merely a frame from being ready.
            await appPage.waitForSelector('#app > *', { timeout: 15000 }).catch(() => {})
            await appPage.addStyleTag({ content: FREEZE_ANIMATIONS })
            actualBuf = await appPage.screenshot()
        }
    } catch (e) {
        loadError = e.message.split('\n')[0]
    }

    await appPage.close()

    if (loadError !== null) {
        results.push({ ...target, percent: 100, pass: false, note: loadError })
        continue
    }

    /* --- compare -------------------------------------------------------- */
    const expected = PNG.sync.read(expectedBuf)
    const actual = PNG.sync.read(actualBuf)

    // A size mismatch is a real failure, not something to scale away — but it
    // must not crash the run, so compare the overlapping region and say so.
    const w = Math.min(expected.width, actual.width)
    const h = Math.min(expected.height, actual.height)
    const sizeNote =
        expected.width !== actual.width || expected.height !== actual.height
            ? `size ${actual.width}x${actual.height} vs board ${expected.width}x${expected.height}`
            : null

    const crop = (png) => {
        if (png.width === w && png.height === h) {
            return png
        }
        const out = new PNG({ width: w, height: h })
        PNG.bitblt(png, out, 0, 0, w, h, 0, 0)

        return out
    }

    const a = crop(expected)
    const b = crop(actual)
    const diff = new PNG({ width: w, height: h })

    const differing = pixelmatch(a.data, b.data, diff.data, w, h, {
        threshold: PIXEL_THRESHOLD,
        includeAA: false,
    })

    const percent = (differing / (w * h)) * 100

    /*
     * A row can be marked unverified when the screen is knowingly measured
     * against the wrong route — a board whose own route does not exist yet and
     * currently resolves to a sibling sharing the same shell. Such a row
     * produces a plausible percentage that means nothing, and a plausible
     * percentage is worse than no number at all: it reads as a pass.
     */
    const pass = percent < THRESHOLD && sizeNote === null && !target.unverified

    if (!pass && !target.unverified) {
        const dir = path.join(OUT, target.id)
        mkdirSync(dir, { recursive: true })
        writeFileSync(path.join(dir, 'original.png'), PNG.sync.write(expected))
        writeFileSync(path.join(dir, 'actual.png'), PNG.sync.write(actual))
        writeFileSync(path.join(dir, 'diff.png'), PNG.sync.write(diff))
    }

    results.push({ ...target, percent, pass, note: sizeNote })
}

await browser.close()

/* --- the table ---------------------------------------------------------- */
const pad = (s, n) => String(s).padEnd(n)
const padL = (s, n) => String(s).padStart(n)

console.log(pad('SCREEN', 18) + pad('TITLE', 34) + padL('DIFF', 8) + '  ' + 'RESULT')
console.log('-'.repeat(78))

for (const r of results) {
    const title = r.title.length > 32 ? r.title.slice(0, 31) + '…' : r.title

    // An unverified row shows no percentage at all. Printing one invites
    // someone to read it, and it is not a measurement of this screen.
    const figure = r.unverified ? '—' : r.percent.toFixed(2) + '%'
    const verdict = r.unverified
        ? `UNVERIFIED (${r.unverified})`
        : r.pass
          ? 'PASS'
          : 'FAIL' + (r.note ? ` (${r.note})` : '')

    console.log(pad(r.id, 18) + pad(title, 34) + padL(figure, 8) + '  ' + verdict)
}

const unverified = results.filter((r) => r.unverified)
const measured = results.filter((r) => !r.unverified)
const failed = results.filter((r) => !r.pass)

console.log('-'.repeat(78))
console.log(`${measured.length - failed.filter((r) => !r.unverified).length}/${measured.length} under ${THRESHOLD}%`)

if (unverified.length > 0) {
    console.log(`${unverified.length} screen(s) UNVERIFIED — not counted either way.`)
}

if (failed.length > 0) {
    console.log(`\nDiff images: _design/screenshots/diff/<screen>/`)
    process.exit(1)
}
