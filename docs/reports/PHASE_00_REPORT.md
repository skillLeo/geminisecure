# Phase 0 — Design Import · STATUS: BLOCKED

> Report format is provisional. `05_QUALITY_AND_REPORT.md` specifies the required
> format and has not been supplied; this report follows the shape named in the
> master prompt (what is done, what is not, why not, what unblocks it, what next)
> and must be reconciled against that file once it arrives.

## Summary

Phase 0 cannot complete. The design import is blocked on an authorization that
can only be granted interactively by the user, and five of the six companion
specification documents are absent. No application code has been written, which
is the correct outcome — building UI without the wireframes would guarantee the
exact failure the master prompt warns about.

Reversibility groundwork and the import scaffold are done, so Phase 0 executes
in a single step the moment authorization lands.

## Done

| Item | Detail |
| --- | --- |
| Environment audit | PHP 8.5.10, Composer 2.10.3, Node 24.20.0, npm 11.19.0 — all present |
| Git baseline | Repo initialised, skeleton committed at `56631e1`; all later work reversible |
| Import target list | `_design/import-targets.json` — the exact 43 files, folder-resolved |
| Import verification gate | `tools/design-manifest.ps1` — SHA-256 manifest + three hard gates |
| `uploads/` exclusion | Encoded as policy in the targets file and enforced by gate 1 |

### The `uploads/` conflict — resolved in favour of the master prompt

The file list supplied mid-session enumerates the project's `uploads/` folder
(≈120 entries) and closes with "Implement: the selected files". The master
prompt states that `uploads/` holds superseded earlier drafts under older names
and **must never be read**.

These conflict. Read as a deliberate instruction, the list would have me build
from the superseded drafts. Read as what it appears to be — an auto-generated
enumeration of everything readable in the project — the `uploads/` entries are
incidental. Two facts settle it:

1. The non-`uploads/` entries total **exactly 43** and decompose as
   3 documents + `support.js` + 39 wireframes (9 Super Admin, 10 Community
   Admin, 10 Resident, 10 Guard) — precisely the manifest the master prompt
   describes.
2. Every `uploads/` entry is a name-mangled duplicate of a file in that 43
   (e.g. `uploads/02_Clients_Screens-4ef830f5.html` ↔
   `GeminiSecure Super Admin Screens/02 Clients.html`).

`uploads/` is therefore excluded, and the exclusion is enforced mechanically
rather than left to discipline. **Flagging for confirmation**, since overriding
part of a direct instruction is not mine to decide silently.

## Not done, and why

### 1. The design import itself — BLOCKED (external)

`DesignSync` returns:

> DesignSync needs design-system authorization, and /design-login cannot run in
> this non-interactive session.

Attempted twice (`get_project`, `list_files`); both refused pre-flight. I cannot
grant this myself — `/design-login` is interactive-only.

**Consequence:** no wireframes, therefore no `:root` token block, no
`tokens.css`, no brand SVG, no component conversion. Every UI deliverable in the
engagement sits behind this.

**Unblocked by:** the user running `/design-login` once in an interactive Claude
Code session on this machine.

### 2. Five of six companion documents — MISSING

Only `01_MASTER_PROMPT` was provided (pasted inline). Searched the project,
Downloads, Desktop, and Documents — zero hits.

| File | What it gates |
| --- | --- |
| `00A_DESIGN_IMPORT.md` | Import rules and verification gate — scaffold above is my reconstruction from the master prompt, not the real spec |
| `02_WEB_MOBILE_SPLIT.md` | Which screens carry the source badge, badge appearance, simulator scope |
| `03_PHASE_PLAN.md` | **The build order.** Ten phases, agents, deliverables, acceptance gates. The master prompt says to follow it exactly and to begin with its Phase 0 |
| `04_RBAC.md` | Role-permission matrix — drives runtime nav generation and hard invariant #2 |
| `05_QUALITY_AND_REPORT.md` | Report format + the four open client decisions to build around behind flags |

I have deliberately **not** reconstructed these. The master prompt forbids
inventing unspecified rules, with particular force around money, arrears
restriction, biometrics and voting — `04_RBAC.md` and `05_QUALITY_AND_REPORT.md`
govern exactly those. An invented phase plan that I then "follow exactly" is
worse than a blocked phase.

### 3. PostgreSQL absent — hard invariant #1 unbuildable

Hard invariant #1 requires Row-Level Security and a non-owner application role.

- `DB_CONNECTION=sqlite`; `database/database.sqlite` in use
- No PostgreSQL server installed, no service, `psql` not on PATH
- `php_pdo_pgsql.dll` and `php_pgsql.dll` **are** present in `C:\tools\php\ext`
  but are not enabled in `C:\tools\php\php.ini` — a one-line fix
- No Redis service (Horizon and Reverb both need it)

SQLite has no RLS, no roles, and no `REVOKE`. Invariants #1 and #4 cannot be
enforced in the database on the current stack. This must be resolved before
Phase 1 opens.

### 4. Stack drift from the approved package list

| Issue | Detail |
| --- | --- |
| **Tailwind installed** | `tailwindcss@^4` + `@tailwindcss/vite` in `package.json`. Master prompt: "Do not introduce Tailwind… or any other kit"; "any component library not in the approved package list" fails review. Laravel skeleton default — needs removing before UI work |
| Pest missing | `phpunit/phpunit@^12.5` present; `pestphp/pest` absent though `composer.json` allow-plugins already references it |
| Horizon missing | Required for queues |
| Reverb missing | Required for WebSockets |

Larastan 3.11 and Pint 1.31 are present and correct. The `brick/money`,
`spatie/laravel-model-states`, `spatie/laravel-permission` and `@fontsource`
(Poppins / Inter / IBM Plex Mono) requirements are already satisfied.

## What I would do next

In order, once unblocked:

1. **Import** all 43 files to `_design/wireframes/`, run
   `tools/design-manifest.ps1`, and stop if any of the three gates trips.
2. **Read** `GeminiSecure Foundation.dc.html` and extract the `:root` block
   verbatim into `resources/css/tokens.css`. Verify 33 colour tokens and that
   the semantic set (success `#16A34A`, danger `#B91C1C`, warning amber) is
   isolated from anything a tenant theme picker can reach.
3. **Read** `GeminiSecure Build Spec.dc.html` and `GeminiSecure Index.dc.html`
   and reconcile against the five missing companion docs — the Build Spec may
   subsume some of them, which would narrow what still needs supplying.
4. **Extract** the brand mark SVG verbatim (viewBox `0 0 40 40`, both `r=12.5`,
   `cx=15`/`cx=25`, `mix-blend-mode:multiply` on the right circle).
5. **Reconcile** the stack: remove Tailwind, add Pest/Horizon/Reverb, enable the
   pgsql extensions, stand up PostgreSQL 16+ and Redis.
6. **Then** open Phase 1 against the real `03_PHASE_PLAN.md`.

Steps 1–4 are pure design import and depend only on authorization. Step 5
depends on the infrastructure decision. Step 6 depends on the phase plan.
