# Decision Log

Append-only. One entry per decision. Never edit a past entry — supersede it with a
new one that references it.

Classes: `contradiction` · `modelling` · `environment` · `client-ruling`

---

### D-001 · Exclude `uploads/` from the design import
Phase: 0 · Class: contradiction
Sources: Master prompt ("must never be read") vs the supplied file list, which enumerated ~120 `uploads/` entries
Chose: exclude `uploads/` entirely; enforce it as a hard gate in `tools/design-manifest.ps1`
Why: The non-`uploads/` entries total exactly 43 and decompose as 3 documents + `support.js` + 39 wireframes, matching the stated manifest. Later confirmed conclusively — every manifest SHA-256 matches the hash suffix on the corresponding `uploads/` filename, so they are byte-identical duplicates under superseded names.
Reversible: yes
Needs client confirmation: no — confirmed by hash evidence

### D-002 · Per-estate MySQL user rather than accumulating grants on `gs_app`
Phase: 1 · Class: modelling
Sources: `06_OVERRIDE` §5 ("each grant is issued for that estate only") vs §7 step 4 ("must fail on a GRANT error")
Chose: `PermissionControlledMySQLDatabaseManager` — a dedicated MySQL user per estate database
Why: §5 admits two readings. If `gs_app` accumulated a grant per estate, a query from the Phoenix Park context against `gs_estate_oceanview` would *succeed* — the connection is authorised and only application context differs. Only a per-estate user makes §7 step 4's GRANT failure achievable.
Reversible: yes, but would invalidate the Phase 1 gate
Needs client confirmation: no — reported and not contested

### D-003 · MySQL 8.4.9 on port 3307, XAMPP MariaDB left on 3306
Phase: 1 · Class: environment
Sources: `06_OVERRIDE` §5 version floor; §9 "keep XAMPP's MySQL on 3306"
Chose: install MySQL 8.4.9 alongside, on 3307
Why: XAMPP ships MariaDB 10.4.32, below the 10.11 floor. Installing beside rather than over it satisfies the floor and §9 simultaneously, and nothing in this project uses the MariaDB instance.
Reversible: yes
Needs client confirmation: no — client chose MySQL 8.4 explicitly

### D-004 · Collation `utf8mb4_0900_ai_ci`
Phase: 1 · Class: contradiction
Sources: `06_OVERRIDE` §5 DDL specifies it, while §5's own version floor also permits "MariaDB 10.11+", where it does not exist
Chose: `utf8mb4_0900_ai_ci`, valid because D-003 selected MySQL
Why: Choosing MySQL dissolves the contradiction — §5's DDL runs exactly as written. Had MariaDB been chosen this would have had to become `utf8mb4_uca1400_ai_ci`.
Reversible: yes, but rewrites every table
Needs client confirmation: no — resolved by the engine choice

### D-005 · Success green split into three tokens
Phase: 1 · Class: client-ruling
Sources: Foundation declares no green but uses `#16A34A` inline; Community Admin files 02–10 tokenise `#15803D`; Guard uses `#4ADE80`
Chose: `--success-600 #16A34A` (non-text UI, light) · `--success-700 #15803D` (text, light) · `--success-400 #4ADE80` (guard dark)
Why: Client ruling. The values are not drift — they are three jobs, and each is the only value clearing its contrast threshold. `#16A34A` is 3.30:1 on white, which passes the 3:1 UI-component bar but fails the 4.5:1 text bar.
Reversible: yes
Needs client confirmation: no — this *is* the client ruling

### D-006 · Wireframe badge text diverges from the pixels
Phase: 1 · Class: client-ruling
Sources: wireframes render `.status-badge.active` text in `#16A34A` at ~10px bold
Chose: render that text in `--success-700`, keeping `--success-600` for the badge icon and dot
Why: 3.30:1 fails WCAG for text. Client identified this as a real defect in the wireframes rather than a misreading. This is the only deliberate divergence from wireframe pixels.
Reversible: yes
Needs client confirmation: no

### D-007 · Permission model is `level` + `can_approve` + `scope`
Phase: 1 · Class: modelling
Sources: Gemini grid uses `Full/Scoped/View/—`; Estate grid uses `Full/View/Entry/—` plus an `Approver` tag
Chose: three orthogonal fields — `level` (none|view|entry|full), `can_approve` (bool), `scope` (all|assigned_sites|own_records)
Why: `Scoped` narrows *which records*; `Entry` narrows *which actions*. One enum cannot express both without inventing levels. Per the protocol this was mine to decide.
Reversible: yes
Needs client confirmation: no — confirmed by Ruling 2

### D-008 · `Approver` is the `approve` verb, not a workflow role
Phase: 1 · Class: client-ruling
Sources: tags exactly 4 cells — Treasurer on Dues & ledger and Accounting, President and VP on Governance
Chose: `can_approve = true`
Why: Those four gate payroll approval, period close and ballot certification — the three irreversible acts. `approve` was deliberately separated from `update` so a role may prepare an irreversible act without committing it.
Reversible: yes
Needs client confirmation: no — Ruling 3

### D-009 · Estate roles number 7, not 6
Phase: 1 · Class: client-ruling
Sources: audit note in Community Admin wireframe 06 line 689
Chose: add **Community Super Admin** — full access within its own estate including user and role management
Why: Ruling 4. Explicitly not the platform Director; no visibility into other estates or into Gemini's own payroll. Still bound by every locked invariant.
Reversible: yes
Needs client confirmation: no — Ruling 4

### D-010 · Split `accounting` and `dues_ledger` into finer modules
Phase: 1 · Class: client-ruling
Sources: Build Spec L1267/L813 ("property manager sees no financial modules") vs Estate grid giving PM `View` on Dues & ledger and Accounting
Chose: split `accounting` → `accounting_posting` + `vendor_costs`; split `maintenance_budget` away from `dues_ledger`. PM is LOCKED out of `dues_ledger`, `payments`, `accounting_posting`, `payroll`; permitted `view` on `vendor_costs` and `maintenance_budget`.
Why: Ruling 1 — both sources were right and the module was too coarse. Screen 18 shows the PM a ticket cost they commissioned; screen 2 withholds resident financial tiles. The principle: whoever commissions work must never be able to pay for it, nor see a resident's financial position.
Reversible: yes, via the permission matrix
Needs client confirmation: no — Ruling 1

### D-011 · Remove Tailwind and the Bunny-CDN font
Phase: 1 · Class: modelling
Sources: Laravel skeleton defaults vs master prompt's approved package list
Chose: remove `tailwindcss`, `@tailwindcss/vite`, and the `bunny('Instrument Sans')` Vite font helper
Why: The prompt forbids any component library or utility framework, forbids CDN fonts, and names only Poppins/Inter/IBM Plex Mono. All three were skeleton defaults, not deliberate choices. `@fontsource` packages were already installed.
Reversible: yes
Needs client confirmation: no

### D-012 · `spatie/laravel-permission` models pinned to the central connection
Phase: 1 · Class: modelling
Sources: package v8 exposes no connection config key; `DatabaseTenancyBootstrapper` swaps the default connection during a tenant request
Chose: `App\Models\Role` and `App\Models\Permission` using the package's `CentralConnection` trait
Why: Without pinning, each estate silently gets its own role table. Since navigation is generated from the matrix at runtime, two estates could drift into different definitions of the same role.
Reversible: yes
Needs client confirmation: no

### D-013 · The seven permission verbs
Phase: 1 · Class: modelling
Sources: Ruling 3 names `approve`, `update`, `create`, `delete`, `export`, `configure` and refers to "the seven verbs"
Chose: `view · create · update · delete · approve · export · configure`
Why: Six are named explicitly in the rulings; `view` is the seventh and is implied by the `View` permission level appearing in both grids. Recorded because the source document naming all seven was not supplied.
Reversible: yes
Needs client confirmation: no — but see Q-005

### D-014 · Derived matrix cells created by the Ruling 1 split
Phase: 1 · Class: modelling
Sources: Ruling 1 splits `accounting` → `accounting_posting` + `vendor_costs`, and splits `maintenance_budget` off `dues_ledger`. The wireframe drew only the unsplit rows, so the new rows have no drawn cells for the other six roles.
Chose: inherit the parent row verbatim, changing only the Property Manager cell as Ruling 1 dictates.
- `accounting_posting` ← Accounting row, PM `View` → `—` (locked)
- `vendor_costs` ← Accounting row, PM stays `View` (permitted)
- `maintenance_budget` ← Dues & ledger row, PM stays `View` (permitted)
- `payments` ← Dues & ledger row, PM `View` → `—` (locked). A new module named in Ruling 1's locked list with no row of its own.
Why: Ruling 1 says the sources were both right and only the module was too coarse — so splitting a module must not change anyone's access except where the ruling says it does. Inheriting the parent row is the only reading that holds every other cell constant.
Reversible: yes, via the permission matrix
Needs client confirmation: no — but the four derived rows are worth a glance at the next phase boundary

### D-015 · Head of Security's scope applies to every cell it holds
Phase: 1 · Class: modelling
Sources: Gemini grid draws `Scoped` on 3 of 8 modules, but the role header reads "Assigned sites only" unconditionally
Chose: apply `assigned_sites` to every non-`none` cell for that role, not only the three drawn as Scoped
Why: The header scope is a property of the role, not of individual cells. Reading it otherwise would let Head of Security see Dashboard and Guard workforce across all sites while being site-restricted on Clients — which contradicts the header and is the less restrictive reading. Per the protocol's contradiction rule, take the more restrictive one.
Reversible: yes
Needs client confirmation: no

### D-027 · Reverb not installed; alert queue stays page-load — **SUPERSEDED by D-032**
Phase: 3 · Class: environment
Sources: `composer require laravel/reverb` needs `-W`, because Reverb requires `guzzlehttp/psr7 ^2.6` while the lock file pins 3.1.0. The dependency-resolving install was declined.
Chose: proceed without Reverb. The dispatch alert queue renders on page load rather than pushing live.
Why: The client's instruction was explicit — if Reverb will not start, log it and continue rather than block Phase 3. Nothing else depends on it: alerts are already recorded through `/api/v1/alerts`, so adding broadcasting later is a listener on an event that already fires, not a change to the intake path.
Consequence, stated plainly: a dispatcher must refresh to see a new alert. For a panic queue that is a real operational limitation, not a cosmetic one.
To resolve: `composer require laravel/reverb -W` — the `-W` allows guzzlehttp/psr7 to move from 3.1.0 down to the ^2.6 Reverb needs. Worth checking nothing else in the lock file depends on psr7 3.x before accepting that downgrade.
Reversible: yes
Needs client confirmation: no — reported

### D-020 · Currency is JMD
Phase: 2 · Class: client-ruling · closes Q-001
Chose: JMD, symbol `J$`, two decimals, `en_JM` locale. USD appears **only** as a display toggle on subscription pricing and is never a stored value.
Why: Client ruling. Every amount already carries an explicit ISO currency alongside its minor units, so this is a data decision rather than a schema one. The USD toggle being display-only matters: a stored USD amount would make the platform ledger multi-currency and every aggregate ambiguous about what it denominates.
Reversible: yes for display; a stored-currency change would require migrating every historical amount
Needs client confirmation: no — this is the ruling

### D-021 · Statutory rates seeded as 2026-04-DRAFT, approval stays blocked
Phase: 2 · Class: client-ruling · closes Q-002
Chose: label the version `2026-04-DRAFT`, keep `is_verified = false`, leave payroll approval disabled with the reason shown on screen. The accountant's worked examples are being requested.
Why: Build Spec open item [A] — do not go live on unverified numbers. A run may be CALCULATED so figures can be checked; it may not be APPROVED. Worked examples will become test cases asserting the real rates, alongside the existing tests for deduction ORDER and the threshold, which are structural and do not change.
Reversible: yes — flipping `is_verified` unblocks approval
Needs client confirmation: no

### D-022 · Biometric consent copy is draft, feature flag off
Phase: 2 · Class: client-ruling · closes Q-003
Chose: `biometrics.enabled` defaults off; consent copy stored and rendered marked DRAFT; `alertness_checks` keeps a derived score and an event time only.
Why: Legal review pending. Invariant 9 is unaffected either way — no template, no landmark set, no image exists anywhere, so the flag governs whether the feature runs at all, never what is retained.
Reversible: yes
Needs client confirmation: no

### D-023 · Manual payment recording is the day-one path
Phase: 2 · Class: client-ruling · closes Q-004
Chose: manual recording only; card capture behind an adapter interface with no live implementation.
Why: No gateway provisioned. The adapter boundary means adding one later changes an implementation rather than the payment domain.
Reversible: yes
Needs client confirmation: no

### D-024 · Arrears restriction: 90 days, 14-day notice, guest passes only
Phase: 2 · Class: client-ruling · closes Q-005
Chose, as ESTATE-CONFIGURABLE DEFAULTS:
- restriction eligibility at **90 days** overdue
- **14 days' written notice** before any restriction takes effect
- applies to **guest passes only**
- **never** the resident's own entry; **never** emergency or medical vehicles
- **Property Manager may override**, with a recorded reason
Why: Client ruling. Three prior rules are unchanged and remain absolute: restriction follows arrears and never a failed payment; a declined card or gateway outage never restricts anything; the un-restrictable categories are hardcoded rather than configured, so no estate setting can make an ambulance wait at a gate.
Note on the override: it is granted to the Property Manager, who under D-010 cannot see the arrears that caused the restriction. That is deliberate and not a contradiction — they may lift a restriction on the estate's behalf without ever seeing a household's financial position.
Reversible: yes, per estate
Needs client confirmation: no

### D-025 · Restricted households show a verdict, never a figure
Phase: 2 · Class: client-ruling · closes Q-006
Sources: my Phase 1 audit found no restriction wording anywhere in the 40 Guard screens
Chose: the guard sees **"Access restricted — contact management"** as an **amber verdict state on the scan verdict screen**. No amount, no ageing bucket, no payment history, and no wording that implies money.
Why: Client confirmed the finding and the wireframes' silence was a gap rather than an intent. Amber because it is neither a clean admit nor a hard denial: the pass is valid and the household is known, and the guard's next action is to call management, not to refuse a person. Rendering it green or red would both misdescribe it.
Invariant 2 is unchanged and is what constrains the wording: the guard token carries `access_restricted` as a boolean and nothing more.
Reversible: yes
Needs client confirmation: no

### D-026 · The seventh verb is `view`
Phase: 2 · Class: client-ruling · closes Q-007
Chose: `view · create · update · delete · approve · export · configure`
Why: Confirmed. Supersedes the inference recorded in D-013.
Reversible: no reason to
Needs client confirmation: no

### D-018 · Dispatch is a module, derived from guard_workforce
Phase: 2 · Class: contradiction
Sources: the approved Gemini sidebar lists **Dispatch** between Clients and Guard workforce (Super Admin 01, line 791) and it has a whole screen of its own (Super Admin 03), but the Role Access Matrix screen has **no Dispatch row** — it lists only 8 modules.
Chose: add `dispatch` as a module and derive its row from `guard_workforce`, its nearest operational analogue, narrowing the Accountant from `View` to `—`.
Why: The two sources genuinely conflict. Taking the matrix literally would leave Dispatch absent from every role's navigation, contradicting a sidebar that draws it and a screen that exists to serve it. Taking the sidebar literally requires a row the matrix never drew. Deriving one is the only reading that satisfies both, and per the protocol the more restrictive reading wins where they are silent — hence the Accountant, who has no operational reason to watch a live dispatch board, gets none.
Result: Director `Full`, Operations Manager `Full`, Head of Security `Scoped`, Dispatcher `Full`, Admin Assistant `View`, Accountant `—`.
Reversible: yes, via the permission matrix
Needs client confirmation: no — but listed in QUESTIONS.md as Q-008 for a glance at the next boundary

### D-019 · Sidebar sections are stored, not derived
Phase: 2 · Class: modelling
Sources: the approved sidebar groups navigation under uppercase "Platform" and "System" headings, with Dashboard above both
Chose: a nullable `section` column on `modules`; null renders before the first heading
Why: the grouping is a design decision made in the wireframe and nothing about a module key implies it. Deriving it would mean hardcoding the same decision in a switch statement, where it could drift from the matrix screen that displays it.
Estate sections (`Community`, `Money`, `System`) are provisional pending the Community Admin sidebar audit in Phase 5.
Reversible: yes
Needs client confirmation: no

### D-017 · Append-only is enforced by withholding grants, not by revoking them
Phase: 1 · Class: contradiction · **CLIENT APPROVED — supersedes 06_OVERRIDE §6**
Sources: `06_OVERRIDE` §6 specifies `REVOKE UPDATE, DELETE ON db.journals FROM user`
Chose: withhold `UPDATE`/`DELETE` from the estate user's *database-level* grant, then grant them back per-table on mutable tables only. Triggers raising `SIGNAL SQLSTATE '45000'` remain as the second layer.
Why: §6's SQL cannot execute. MySQL rejects a table-scoped revoke against a database-scoped grant:
```
ERROR 1147 (42000): There is no such grant defined for user 'x' on host '%' on table 'journals'
```
Verified empirically on MySQL 8.4.9, not assumed. MySQL supports partial revokes from global to database scope, but not from database to table scope. The inversion reaches exactly the end state §6 intended — the estate user simply cannot update or delete a journal — by the only route the engine allows. Mutable tables are read from `information_schema`, so a table added by a later migration is mutable by default and append-only stays a deliberate declaration.
Reversible: yes
Needs client confirmation: **no — approved, and recorded as a correction to §6**

### D-016 · Role names are console-prefixed
Phase: 1 · Class: modelling
Sources: "Admin Assistant" exists in BOTH consoles with different permissions
Chose: dotted machine names — `gemini.admin_assistant`, `estate.admin_assistant`
Why: spatie enforces uniqueness on (name, guard_name), so two roles both named `admin_assistant` would collide. Prefixing keeps the label as drawn while making the key unambiguous.
Reversible: yes
Needs client confirmation: no

### D-028 · Compiled assets are shared across estates; only tenant files are scoped
Phase: 2 · Class: environment
Sources: stancl/tenancy ships `tenancy.filesystem.asset_helper_tenancy => true` by default
Chose: switch it off. Tenant-owned files use `tenant_asset()` explicitly.
Why: with it on, an initialized tenant rebinds the asset root so every `asset()` URL becomes `/tenancy/assets/...`, served from that estate's own storage disk. Laravel's `Vite` class builds its script and stylesheet URLs through `asset()`, so inside an estate the page requested the Vue bundle and both stylesheets from a disk that has never held them — three 404s, no JS, no CSS, a blank Estate Console. This was not a local-only fault: the production subdomain behaved identically. The Gemini Console looked fine only because tenancy is never initialized there, which disguised a shared-asset fault as an estate-routing fault and cost a round of misdirected fixes.
The compiled bundle is the product, not tenant data — byte-identical for every estate, already public, holding nothing an estate owns. Serving it per-tenant bought no isolation.
The isolation that matters is untouched: `suffix_storage_path` still gives each estate its own storage root and `tenant_asset()` still resolves per estate, so resident photos and uploaded documents stay unreachable from another estate's URL space. Requiring a tenant URL to be asked for by name is also the safer default — the previous setting applied tenancy to every `asset()` call in the framework and in every installed package, which is how it reached Vite in the first place.
Guarded by: `tests/Feature/EstateConsoleAssetsResolveTest.php` — 5 tests pinning both halves, including that the asset root is restored on exit so it cannot leak into the next request on the same worker.
Reversible: yes, but re-breaks the Estate Console
Needs client confirmation: no

### D-033 · A content residual is a schema question, not a measurement artefact
Phase: 2 · Class: client-ruling · **standing rule for the rest of the build**
Sources: client correction on screen super-admin-05. I reported the last 2.20% as text the schema does not hold and declined to add a column "to satisfy a pixel metric". That reasoning was right in general and wrong here.
Chose: when a diff residual is CONTENT, ask whether the field is one the domain genuinely needs. If yes, add it and log it. If it exists only to move the number, do not add it — record the exception instead. **Never invent a column for a number.**
Why the correction was right: the Build Spec's `estate` entity reads `id, name, parish, subdomain, unit_count, gate_count, phase structure, geofence polygon, geofence tolerance (metres), plan, status`. `parish`, `gate_count` and `phase structure` had simply never been modelled. A security company's client record must say where the site physically is — dispatch sends a supervisor there, a guard is posted there. **The board revealed the omission; it did not cause it.**
Added: `address_line`, `parish`, `gate_count`, `phases` as real columns on `tenants` (not stancl's `data` JSON — the console filters and sorts by them and a JSON extract cannot use an index). `address_line` is not in the entity list and is added anyway, on the same merit.
Phase structure is stored as a STRUCTURE, never a count: phases have names residents use, units belong to one, and bookings and ballots are scoped by them. `phase_count` is derived from it. Two places holding one count is how they come to disagree.
Result: super-admin-05 went **2.20% → 0.87%**, and the whole Gemini set to 15/15.
Still unmodelled from that entity: **geofence polygon and geofence tolerance**. Deliberately not stubbed — they are the Guard App's clock-in boundary, they need surveyed coordinates rather than a nullable column nobody populates, and nothing reads them before Phase 3.
Reversible: no reason to
Needs client confirmation: no — this IS the client's ruling

### D-034 · "Guards deployed" means posted here, not compliant
Phase: 2 · Class: modelling · **client-confirmed**
Sources: board super-admin-05 lists Devon Palmer, whose licence has expired, under Phoenix Park's "Guards deployed"
Chose: the panel and the directory count both select guards assigned to the estate excluding `on_leave` and `suspended`. `licence_expired` is included.
Why: the query required `status = 'active'`, which hid a licence-expired guard from the client whose gate that guard is standing on. That is the wrong way round — an expired licence is exactly what a client should be able to see about someone posted at their estate, and hiding it serves the security company, not the client. On leave and suspended stay excluded because those people are genuinely not at the post.
Both the count and the list use one definition, so the directory cannot contradict the record.
Reversible: yes, but re-hides a compliance fact from the client it concerns
Needs client confirmation: no — raised and confirmed

### D-029 · Wireframe stylesheets are lifted verbatim, never re-authored
Phase: 2 · Class: client-ruling · **supersedes the master prompt's "extract the `:root` block" instruction, per 08_REMEDIATION §1.1**
Sources: `08_REMEDIATION` §0 Failure 1 — "structural differences are defects" was read as DOM-only, so every other CSS rule was re-authored by hand and drifted.
Chose: `tests/Fidelity/lift-stylesheets.mjs` copies every `<style>` block out of all 39 boards byte-for-byte into `resources/css/wireframe/`. Nothing is edited — not a selector, not a value, not the whitespace. Provenance lives beside the CSS in `SOURCES.json` (source path, source SHA-256, extracted SHA-256), so drift is a hash mismatch rather than a judgement call. `shell.css`, 11 KB of hand-authored console CSS, is deleted.
Scoping is GENERATED into `resources/css/scoped/`, never hand-applied: `:root` → `body.wf-x`, `*` → `body.wf-x, body.wf-x *`, everything else prefixed; rules inside `@keyframes` untouched.
Why scoping at all: the 39 boards are not one design system. Community Admin's ten sheets disagree with each other and with Super Admin about `.app-shell`, `.card` and `.btn`, and two boards disagreeing is the designer's decision, not a conflict to resolve. It matters after navigation as much as at first paint — Vite leaves a chunk's CSS in the document, so a Gemini page visited after an Estate page would otherwise inherit the wrong `.app-shell`.
Measured: all nine Super Admin boards carry the SAME 61,450 bytes. 39 boards, **31 distinct stylesheets**. Byte-identical sheets are imported once and share a body class — provably not a merge, since the computed styles are identical by hash.
The only authored CSS: neutralising the board's own body padding and background (poster chrome, not screen), and per-component resets where a real `<button>`/`<input>` replaces a board `<div>` and browser defaults would otherwise show through.
Reversible: yes, by regenerating
Needs client confirmation: no — this IS the client's instruction

### D-030 · Fidelity is measured in pixels, not described
Phase: 2 · Class: client-ruling · per `08_REMEDIATION` §1.3–1.4 and §2.4
Sources: "the acceptance evidence is the pixel diff percentage and the interactivity count — not a description of what you built"
Chose: `php artisan fidelity:check {screen?}` diffs each built screen against its board region and fails over 2%. `_design/SCREEN_REGIONS.json` holds all **165** regions, measured from the boards rather than hand-written.
Two findings that only measurement would have produced:
- The region selector is `.browser-body`, not `.app-shell`. The login screen has no console shell, so keying on `.app-shell` silently loses it and shifts every screen number after it. The count landing on exactly 165 — the client's own figure — is what confirms the selector.
- 17 screens are drawn taller than 900px (Dispatch at 1000, Platform Settings and Estate Settings at 1140, Chart of Accounts at 930). Reproduced as drawn; the measured height is the contract. Width is the contract that never varies.
The harness forces the URL root to the host it browses: `route()` building `localhost` while the browser signs in on `127.0.0.1` is two origins to a cookie jar, which presents as a styling fault and is an auth one. That cost a full misdiagnosis before it was found.
Reversible: no reason to
Needs client confirmation: no

### D-031 · /api/v1 is behind a token AND an ability
Phase: 3 · Class: modelling · closes debt 1 of `08_REMEDIATION` Part 3
Sources: `POST /api/v1/alerts` was left unauthenticated while device enrolment was unwritten
Chose: the whole `v1` group takes `auth:sanctum`; each route additionally names an ability. `alerts` requires `alerts:raise`, `passes/verify` requires `passes:verify`. `App\Support\DeviceAbilities` holds the two device profiles.
Why an ability and not just a token: a token with SOME ability is not a token with EVERY ability, and a bare `auth:sanctum` waves that through. A resident's handset must not be able to call the verify endpoint — they could probe which of their neighbours are restricted, one household id at a time. So `forResident()` grants `alerts:raise` only.
Why any-of rather than all-of on alerts: a guard's duress button and a resident's panic button are the same event to dispatch.
Guarded by: `tests/Feature/ApiRequiresATokenTest.php` — 5 tests, including a token that holds an unrelated ability and a resident token at the gate.
Reversible: no — a public panic endpoint is a denial-of-service surface on a life-safety path
Needs client confirmation: no

### D-032 · The alert channel is private and per-estate — supersedes D-027
Phase: 3 · Class: modelling · closes debt 4 of `08_REMEDIATION` Part 3
Sources: D-027 recorded Reverb as not installed. It since was — `guzzlehttp/psr7` resolved to 2.13.1, which satisfies Reverb, the framework, Guzzle and pusher-php-server simultaneously. **No downgrade and no Soketi were needed; the original conflict was a partial-update artefact.** Reverb v1.11.1 runs on 127.0.0.1:8080.
Chose: `AlertRaised` broadcasts on `PrivateChannel("estate.{id}.alerts")`, authorised in `routes/channels.php`.
Why this was a real defect, not a rename: the event used a plain `Channel`, so the per-estate boundary its own docblock described **did not exist**. A public channel is subscribable by anyone who can guess its name, and the names are estate subdomains. Only `PrivateChannel` sends the subscription through the authorization callback, and until this change `routes/channels.php` had no callback for it at all — so the boundary was neither enforced nor even declared.
The rule: Gemini staff are scoped by PERMISSION (`gemini.dispatch.view`), because dispatch is platform-wide — one control room watches every client. Estate users are scoped by ASSIGNMENT, via the same `canAccessEstate` the request path uses, so there is one answer to the question. A suspended account is refused even for its own estate: a live socket outlives the request the suspension happened in.
Guarded by: `tests/Feature/AlertBroadcastIsScopedTest.php` — 6 tests.
**Polling STAYS for now, and this is why:** `resources/js/echo.js` is imported by nothing. The client half has never connected, so there is no browser-confirmed subscription to remove polling on the strength of. Removing a 3-second refresh from a life-safety queue on the basis of a server-side test would be trading a proven mechanism for an unproven one. Polling comes out when a browser is observed receiving `alert.raised` on the private channel, and not before.
Reversible: yes
Needs client confirmation: no
