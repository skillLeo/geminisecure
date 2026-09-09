/**
 * Lifts every wireframe's stylesheet verbatim — §1.1 of the remediation brief.
 *
 * The wireframes are standalone HTML files carrying their COMPLETE stylesheet
 * inline. Re-authoring that CSS by hand is what produced the colour, type and
 * spacing drift. So it is not re-authored: the <style> blocks are copied out
 * byte for byte, in order, and become the application's stylesheet.
 *
 * NOTHING IS EDITED HERE. Not a selector, not a value, not a shorthand, not
 * the order, not the whitespace. The extracted file is a byte-exact
 * concatenation of the source's <style> contents and nothing else — no header
 * comment, no formatting, no merging of duplicates across boards.
 *
 * Provenance therefore lives beside the CSS rather than inside it, in
 * SOURCES.json, which records for each board the source path, the source's
 * SHA-256, the extracted CSS's SHA-256 and how many blocks were joined. Any
 * later drift between a board and its stylesheet is a hash mismatch, not a
 * judgement call.
 *
 * Scoping is NOT done here either. Two boards that define .btn differently are
 * both right, and reconciling them would be exactly the editorialising that
 * caused the problem. The Vite plugin scopes each file under its own body
 * class at build time, mechanically, leaving these files verbatim on disk.
 */

import { readFileSync, writeFileSync, mkdirSync, readdirSync, rmSync, existsSync } from 'node:fs'
import { createHash } from 'node:crypto'
import postcss from 'postcss'
import path from 'node:path'

const ROOT = path.resolve('_design/wireframes')
const OUT = path.resolve('resources/css/wireframe')
const SCOPED = path.resolve('resources/css/scoped')

const SURFACES = [
    { dir: 'GeminiSecure Super Admin Screens', slug: 'super-admin' },
    { dir: 'GeminiSecure Community Admin Screens', slug: 'community-admin' },
    { dir: 'GeminiSecure Guard App Screens', slug: 'guard-app' },
    { dir: 'GeminiSecure Resident App Screens', slug: 'resident-app' },
]

const sha = (s) => createHash('sha256').update(s, 'utf8').digest('hex')

/** "02 Clients.html" under super-admin -> "super-admin-02-clients" */
function slugFor(surfaceSlug, filename) {
    const base = filename
        .replace(/\.html$/i, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')

    return `${surfaceSlug}-${base}`
}

/**
 * Every <style> block's contents, in document order.
 *
 * Deliberately a regex and not a DOM parse: a parser would normalise entities
 * and whitespace on its way through, and "verbatim" is the whole point.
 */
function styleBlocks(html) {
    const blocks = []
    const re = /<style\b[^>]*>([\s\S]*?)<\/style>/gi
    let m

    while ((m = re.exec(html)) !== null) {
        blocks.push(m[1])
    }

    return blocks
}

if (existsSync(OUT)) {
    rmSync(OUT, { recursive: true })
}
mkdirSync(OUT, { recursive: true })

const sources = []

for (const surface of SURFACES) {
    const files = readdirSync(path.join(ROOT, surface.dir))
        .filter((f) => f.endsWith('.html'))
        .sort()

    for (const file of files) {
        const abs = path.join(ROOT, surface.dir, file)
        const html = readFileSync(abs, 'utf8')
        const blocks = styleBlocks(html)

        if (blocks.length === 0) {
            console.error(`NO <style> BLOCK: ${surface.dir}/${file}`)
            process.exit(1)
        }

        const slug = slugFor(surface.slug, file)
        const css = blocks.join('\n')

        writeFileSync(path.join(OUT, `${slug}.css`), css, 'utf8')

        sources.push({
            slug,
            body_class: `wf-${slug}`,
            source: `${surface.dir}/${file}`,
            source_sha256: sha(html),
            css_sha256: sha(css),
            style_blocks: blocks.length,
            css_bytes: Buffer.byteLength(css, 'utf8'),
        })
    }
}

/*
 * Boards that are byte-identical share one sheet.
 *
 * All nine Super Admin boards carry the SAME 61,450 bytes of CSS — the
 * designer wrote one stylesheet for the console and reused it. Importing it
 * nine times and scoping it nine ways produces nine identical rule sets under
 * nine different body classes: half a megabyte of CSS for zero difference in
 * computed style, provably, by hash.
 *
 * So identical sheets are imported once and share a body class, named for the
 * first board that carries them. This is NOT the "do not merge duplicates
 * across files" the brief forbids — that rule protects boards which genuinely
 * DISAGREE, and those are still kept apart. Community Admin's ten sheets all
 * differ from one another and each keeps its own.
 *
 * Every one of the 39 files stays on disk regardless, so each board's CSS can
 * still be diffed against its own source.
 */
const bySheet = new Map()

for (const s of sources) {
    if (!bySheet.has(s.css_sha256)) {
        bySheet.set(s.css_sha256, { slug: s.slug, boards: [] })
    }
    bySheet.get(s.css_sha256).boards.push(s.slug)
}

for (const s of sources) {
    s.sheet = bySheet.get(s.css_sha256).slug
    s.body_class = `wf-${s.sheet}`
}

const distinct = [...bySheet.values()]

/*
 * Scoping — generated, never hand-edited.
 *
 * The verbatim files above are the source of truth and stay byte-exact. These
 * are their machine-scoped twins, written to a separate directory so it is
 * obvious which is which.
 *
 * Scoping is necessary because the 39 boards are not one design system. All
 * nine Super Admin boards agree, but Community Admin's ten sheets disagree
 * with each other and with Super Admin about .app-shell, .card and .btn. Two
 * boards disagreeing is the designer's decision, not a conflict to resolve, so
 * both definitions are kept and each is confined to its own pages.
 *
 * It matters after navigation, not only at first paint: Vite injects a chunk's
 * CSS and leaves it in the document, so a Gemini page visited after an Estate
 * page would otherwise inherit the Estate board's .app-shell.
 *
 * The rewrites:
 *   :root      -> body.wf-x       custom properties cascade from body exactly
 *                                 as from :root; a scoped :root matches nothing
 *   html, body -> body.wf-x
 *   *          -> body.wf-x, body.wf-x *
 *   .anything  -> body.wf-x .anything
 *
 * Rules inside @keyframes are left alone. Their selectors are 0% and 100%, and
 * prefixing those yields a stylesheet that parses but animates nothing.
 */
function scope(css, bodyClass, from) {
    const root = postcss.parse(css, { from })

    root.walkRules((rule) => {
        for (let p = rule.parent; p; p = p.parent) {
            if (p.type === 'atrule' && /keyframes$/i.test(p.name)) {
                return
            }
        }

        rule.selectors = rule.selectors.flatMap((selector) => {
            const s = selector.trim()

            if (s === ':root' || s === 'html' || s === 'body') {
                return [`body.${bodyClass}`]
            }

            if (s === '*') {
                return [`body.${bodyClass}`, `body.${bodyClass} *`]
            }

            // "body .x" / "html .x": the ancestor is already the body being
            // scoped to, so replace it rather than nest a second one that can
            // never match.
            const rooted = /^(?:html|body)\s+(.*)$/.exec(s)
            if (rooted) {
                return [`body.${bodyClass} ${rooted[1]}`]
            }

            return [`body.${bodyClass} ${s}`]
        })
    })

    return root.toString()
}

if (existsSync(SCOPED)) {
    rmSync(SCOPED, { recursive: true })
}
mkdirSync(SCOPED, { recursive: true })

for (const d of distinct) {
    const verbatim = readFileSync(path.join(OUT, `${d.slug}.css`), 'utf8')
    const header =
        '/* GENERATED by tests/Fidelity/lift-stylesheets.mjs - do not edit by hand.\n' +
        ` * Verbatim source: resources/css/wireframe/${d.slug}.css\n` +
        ` * Boards using it: ${d.boards.join(', ')}\n` +
        ' */\n'

    writeFileSync(
        path.join(SCOPED, `${d.slug}.css`),
        header + scope(verbatim, `wf-${d.slug}`, path.join(OUT, `${d.slug}.css`)),
        'utf8'
    )
}

/*
 * The index app.css imports.
 *
 * Generated rather than hand-maintained so a board added later cannot be
 * silently missing from the build. Lives outside resources/css/wireframe/ so
 * the scoping plugin — which owns that directory — never sees it.
 */
const index =
    [
        '/* GENERATED by tests/Fidelity/lift-stylesheets.mjs - do not edit by hand. */',
        '/* Each DISTINCT board stylesheet, scoped to its own body class. */',
        `/* ${sources.length} boards carry ${distinct.length} distinct stylesheets. */`,
        '',
        ...distinct.map((d) => `@import './scoped/${d.slug}.css';`),
        '',
    ].join('\n')

writeFileSync(path.resolve('resources/css/wireframe-index.css'), index, 'utf8')

/*
 * The board -> body class map the Vue pages use.
 *
 * Generated so a page cannot name a board that does not exist, and so the
 * identical-sheet collapsing above stays invisible to the pages: a page names
 * its own board and gets whichever class actually carries that board's CSS.
 */
const map =
    [
        '/* GENERATED by tests/Fidelity/lift-stylesheets.mjs - do not edit by hand. */',
        '',
        '/** Board slug -> the body class whose scoped CSS reproduces that board. */',
        'export const BOARD_BODY_CLASS = Object.freeze({',
        ...sources.map((s) => `    '${s.slug}': '${s.body_class}',`),
        '})',
        '',
    ].join('\n')

writeFileSync(path.resolve('resources/js/wireframe-map.js'), map, 'utf8')

writeFileSync(
    path.join(OUT, 'SOURCES.json'),
    JSON.stringify(
        {
            note: "Each .css here is a byte-exact copy of its board's <style> contents. Never hand-edit. Regenerate with: node tests/Fidelity/lift-stylesheets.mjs",
            generated_by: 'node tests/Fidelity/lift-stylesheets.mjs',
            count: sources.length,
            distinct_sheets: distinct.length,
            sheets: distinct.map((d) => ({ sheet: d.slug, body_class: `wf-${d.slug}`, boards: d.boards })),
            files: sources,
        },
        null,
        2
    ) + '\n',
    'utf8'
)

const totalBytes = sources.reduce((n, s) => n + s.css_bytes, 0)

for (const surface of SURFACES) {
    const mine = sources.filter((s) => s.slug.startsWith(surface.slug))
    const bytes = mine.reduce((n, s) => n + s.css_bytes, 0)
    console.log(`${surface.slug.padEnd(18)} ${String(mine.length).padStart(2)} files  ${String(Math.round(bytes / 1024)).padStart(4)} KB`)
}

console.log('-'.repeat(44))
console.log(`${'TOTAL'.padEnd(18)} ${String(sources.length).padStart(2)} files  ${String(Math.round(totalBytes / 1024)).padStart(4)} KB`)
