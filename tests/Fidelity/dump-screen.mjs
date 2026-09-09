/**
 * Prints one screen's markup, straight out of its board.
 *
 *     node tests/Fidelity/dump-screen.mjs super-admin-04
 *     node tests/Fidelity/dump-screen.mjs super-admin-04 --selector .sidebar
 *
 * The boards are posters holding several screens each, so reading one screen's
 * DOM by eye out of a 130 KB HTML file is slow and error-prone. This cuts the
 * exact region out and pretty-prints it, which is the markup a Vue page has to
 * reproduce — structure first, then the same class names, then real data
 * bound into the same slots.
 *
 * --selector narrows further, for when only the sidebar or one panel is
 * wanted rather than the whole 1440x900.
 */

import { chromium } from 'playwright'
import { pathToFileURL } from 'node:url'
import { readFileSync } from 'node:fs'
import path from 'node:path'

const [, , screenId, ...rest] = process.argv

if (!screenId) {
    console.error('usage: node tests/Fidelity/dump-screen.mjs <screen-id> [--selector <css>]')
    process.exit(1)
}

const narrowIndex = rest.indexOf('--selector')
const narrow = narrowIndex === -1 ? null : rest[narrowIndex + 1]

const regions = JSON.parse(readFileSync('_design/SCREEN_REGIONS.json', 'utf8'))
const screen = regions.screens.find((s) => s.id === screenId)

if (!screen) {
    console.error(`No screen '${screenId}'. Ids look like super-admin-04, guard-app-12.`)
    process.exit(1)
}

const browser = await chromium.launch()
const page = await browser.newPage({ viewport: { width: 1920, height: 1200 } })
await page.goto(pathToFileURL(path.resolve('_design/wireframes', screen.source)).href, { waitUntil: 'load' })

const html = await page.evaluate(
    ({ selector, index, narrow }) => {
        const region = document.querySelectorAll(selector)[index]

        if (!region) {
            return null
        }

        const target = narrow ? region.querySelector(narrow) : region

        if (!target) {
            return null
        }

        /* Indent by depth. The boards are minified onto few lines, and a wall
         * of unbroken markup is no easier to reproduce than the raw file. */
        const print = (node, depth = 0) => {
            const pad = '  '.repeat(depth)

            if (node.nodeType === Node.TEXT_NODE) {
                const text = node.textContent.trim()
                return text ? pad + text + '\n' : ''
            }

            if (node.nodeType !== Node.ELEMENT_NODE) {
                return ''
            }

            const tag = node.tagName.toLowerCase()
            const attrs = [...node.attributes]
                .map((a) => ` ${a.name}="${a.value}"`)
                .join('')

            // SVG icons are copied wholesale rather than read, so collapse
            // them to one line instead of 30.
            if (tag === 'svg') {
                return `${pad}${node.outerHTML.replace(/\s+/g, ' ')}\n`
            }

            if (node.children.length === 0) {
                const text = node.textContent.trim()
                return `${pad}<${tag}${attrs}>${text}</${tag}>\n`
            }

            let out = `${pad}<${tag}${attrs}>\n`
            for (const child of node.childNodes) {
                out += print(child, depth + 1)
            }

            return out + `${pad}</${tag}>\n`
        }

        return print(target)
    },
    { selector: screen.selector, index: screen.index, narrow }
)

await browser.close()

if (html === null) {
    console.error(`Region found but '${narrow}' matched nothing inside it.`)
    process.exit(1)
}

console.error(`# ${screen.id} — ${screen.title}`)
console.error(`# ${screen.source}`)
console.error(`# ${screen.viewport.width}x${screen.viewport.height}${narrow ? `  (narrowed to ${narrow})` : ''}`)
console.error('')

console.log(html)
