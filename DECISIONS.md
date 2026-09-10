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

### D-033 · Dispatch models the shift, the bound device and the request — and no position at all
Phase: 2 · Class: modelling · Screens super-admin-12, 13, 15, 16
Sources: the four dispatch boards ask questions the schema could not answer. Each gap below was checked against the Build Spec entity list before a column was added.
Added, because the DOMAIN needs them:
- `shifts` — the spec's `shift` entity, unmodelled. The coverage board asks whether a post is staffed 7 AM–7 PM and again 7 PM–7 AM. `guards.post_id` is a standing assignment with NO time dimension, so answering two windows from it would print one fact under two headings and let a dispatcher read it as knowledge of tonight. On the board whose purpose is showing which posts are unmanned, that is the one thing it must not do. Rostered and actual are kept apart: a shift nobody started is a gap, not coverage.
- `guards.device_id`, `guards.device_label` — the spec's `guard` entity lists `device_id`, and its rule ("one device per guard, so a single phone cannot start shifts for several people") is a fraud control. Unique at the database, not in application code. The label is separate because a binding identifier is not a name — a dispatcher is told "Company Pixel 7a", not a hash.
- `guard_requests`, `dispatch_messages` — the requests inbox has no entity in the spec's table, yet the spec describes the workflow from both ends: the Guard App raises leave and equipment requests, "a leave request routes to the dispatcher requests inbox", and "every decision records the deciding user". A screen that approves something must have something to approve.
- `guards.leave_entitlement_days` — the inbox states the balance a decision would leave. Only the entitlement is stored; days taken are derived from approved requests, so the two can never disagree.
NOT added, deliberately: **any guard position column, anywhere.** The live map draws a parish, a site and a post — all fixed, all already known — and its pins are the board's own hand-drawn inline positions with real estates placed into them. A pin says which post a guard is standing, which the label states outright. A projected coordinate would be read as where the guard is, and a guard's live position is the most sensitive thing a security company could hold on its own staff. The safest place for it is nowhere, and the consequence is that nothing on this screen can be broadcast as a position because none exists.
Also not added: a map library. leaflet is installed; the board draws the map by hand in CSS, so the markup is reproduced rather than a tile layer substituted for the design.
Guarded by: `tests/Feature/DispatchLiveMapTest.php` asserts the serialised map payload contains no latitude, longitude or geofence, over the whole structure rather than one remembered field.
Reversible: yes for the tables; the absent position column is not a gap to fill later without a fresh ruling
Needs client confirmation: no — all four are spec entities or spec-described workflow

### D-035 · The activity feed reads three tables and never a fourth copy
Phase: 2 · Class: modelling · Screen super-admin-26
Sources: board 26 is a cross-client feed of admits, denials, checkpoint scans and shift starts.
Chose: `gate_events` holds gate DECISIONS only — admit, deny, override. The feed merges it with `checkpoint_scans` and `shifts`, each read from the table that owns the fact.
Why: a scan and a shift start are already recorded centrally by the screens that own them. Copying them into `gate_events` so the feed could be one query would create two records of one event that are free to disagree, and the one that disagrees is the one an insurer reads after a claim. The geofence line under a clock-in ("geofence verified") is the clearest case: it comes from `shifts.geofence_distance_m` and `shifts.mock_location_flag`, and a denormalised copy would have to be kept in step by hand.
Why `gate_events` is central at all, and not an isolation breach: the estate's record of an arrival lives in its own database joined to the household and the pass. This is a different record of the same moment — Gemini's account of work its own employee did, on a post it staffs. A cross-client feed can only be built two ways: this, or a query that opens every estate database in turn. The second is the breach.
NOT added, deliberately: any household id, resident id, charge, balance or position. `subject` is the free-text line the guard saw, and a unit reference is not a resident.
Added, because the domain needs them: `gate_events.idempotency_key` (a gate handset queues offline and retries; one admit uploaded twice would silently inflate the day's count) and `gate_events.is_simulated` (every device-originated table here carries it, so a console can say the data came from the seed rather than a handset).
Guarded by: `tests/Feature/GateActivityFeedTest.php` — 8 tests, including an ALLOWLIST on the feed row keys so a column added later must be admitted deliberately rather than arriving by default.
Reversible: yes
Needs client confirmation: no

### D-036 · "Guards on post" counts guards standing one, not guards assigned to one
Phase: 2 · Class: semantics · Screen super-admin-26
Sources: board 26's first KPI card reads "Guards on post, platform-wide".
Chose: distinct guards with an ACTIVE shift and a recorded `actual_start`, not `guards.post_id`.
Why: Devon Palmer is posted to Phoenix Park's Service Gate and his PSRA licence has lapsed, so he is un-rosterable and that gate stands empty — which is precisely what the coverage board two screens away reports. Counting standing assignments would have this screen call him on post while that one calls his gate uncovered. Two screens disagreeing about whether a post is manned is worse than either number alone.
Same correction applied to the standing orders library: a post is "staffed" only where the guard on it is active AND licensed, which is what separates the board's red "Unassigned post" badge from an order set a guard simply has not signed yet.
Guarded by: `tests/Feature/GateActivityFeedTest.php`, `tests/Feature/StandingOrdersLibraryTest.php`
Reversible: no — reverting reintroduces the contradiction
Needs client confirmation: no

### D-037 · Client health reads a central roll-up, because this console never opens an estate database
Phase: 2 · Class: architecture · Screen super-admin-07
Sources: the cross-tenant report catalogue already carried the constraint on this report's own card — "adoption must first be rolled up into platform data, because this console never reads an estate database". Screen 07 was the last unbuilt Gemini screen because of it.
Chose: a central `client_adoption` table with TWO WRITERS. Gemini writes the capabilities it operates inside the client's estate — Guard App coverage, visitor pass take-up — which are its own facts and already central. The estate console writes the capabilities it operates for itself — dues, facilities, governance, its own payroll — during Phase B. The report reads the roll-up and joins nothing.
Why not fan out across estates on page load: that is the breach the rule exists to prevent, and it would also put a scan of every client's day behind a screen an account manager keeps open in a tab. `php artisan adoption:rollup` recomputes the Gemini-owned half.
TWO COUNTS ARE STORED, NOT A PERCENTAGE: `adopted` over `eligible`, so the hover can say "2 of 5 active posts worked in the last 7 days by a licensed guard on a bound handset". A stored percentage loses what was counted, and a client with nothing to adopt would read as 0% rather than as a question that does not apply.
THREE STATES ARE KEPT APART, and conflating any two would mislead an account manager: measured (a bar), nothing to measure (named under the bars, no bar), and never reported (no score, chip reads "Onboarding" or "Not yet reported").
KNOWN CONTENT EXCEPTION — 3.52%, and it is not to be read as a pass: the board draws four adoption bars for its first client and this screen draws two, because Dues & ledger, Facilities, Governance and Payroll are counted inside the estate and the Estate Console has not shipped. **No figure was invented to close the gap.** The screen is re-measured at the end of Phase B, when those four writers exist. Flagged in the same class as super-admin-42.
Guarded by: `tests/Feature/ClientHealthTest.php` — 9 tests.
Reversible: yes
Needs client confirmation: no

### D-038 · Boards 25, 26 and 27 name a third client the approved dataset does not have
Phase: 2 · Class: fidelity exception · Screens super-admin-07, 26, 27
Sources: board 26 tags feed rows "Emerald Heights", board 27's client column reads "Emerald Heights Estate", board 07 draws a third card for "Coral Bay Residences".
Chose: the platform keeps the two estates the rest of the boards are drawn from — Phoenix Park Village 1 and Ocean View Gardens — and the screens print those names.
Why: "Emerald Heights" appears on three boards in one batch and nowhere else in the design; "Ocean View Gardens" appears throughout, including on the client directory and both client detail screens, which are measured against it and pass. Renaming an estate to match one batch would break the batch that names it correctly, and provisioning a third estate creates a real database and MySQL user as a side effect of a cosmetic match.
Recorded as a content residual on those three screens rather than corrected.
Reversible: no longer open — see the ruling below
Needs client confirmation: **ANSWERED AND CLOSED.** Neither is a real client. "Emerald Heights" is not a rename of Ocean View Gardens and "Coral Bay Residences" does not exist: both are illustrative names the designer used to show a populated multi-client console, and **neither may be seeded**. Phoenix Park Village 1 and Ocean View Gardens are the only estates. Screens 07, 25, 26 and 27 pass against the real dataset, and that is the correct outcome rather than a residual to chase — the client name printed on those screens is a fact about the platform, not a pixel to match.

### D-039 · The lines are written before the header, so the database can refuse an unbalanced entry
Phase: 5 (Estate Console) · Class: modelling · Screens community-admin-25 and every money screen behind it
Sources: the Build Spec's `journal` entity — "lines[] (account, debit, credit) … Debits must equal credits. A posted journal is reversed by an equal and opposite journal, never edited." The table that shipped in Phase 1 held a single signed amount and no account: enough to prove append-only, not enough to be a ledger.
Chose: `accounts` + `journal_lines` + the existing `journals` as the entry header. Lines carry `entry_ref`; the header is inserted LAST, and `journals_must_balance` fires BEFORE that insert to count them.
Why the inverted write order: "debits equal credits" is a statement about a SET of rows, and MySQL cannot check a set as it is being built — a trigger on the lines fires once per line while the entry is half-written, and a trigger on the header fires before the lines it would need to count exist. Checking it in PHP would leave the guarantee one forgotten service call away from being false, and an unbalanced ledger is not a defect anybody finds by looking. Writing the lines first is the only ordering under which the database itself can enforce it.
The cost, stated plainly: `journal_lines` has NO foreign key to `journals`, because a foreign key would demand the header first — the exact ordering that makes the check impossible. `gate:ledger` re-proves the link across every entry rather than trusting it, and reports any orphan.
SIX TRIGGERS, TWO CHECK CONSTRAINTS, ONE REVOKED GRANT. `journals_must_balance` (differing sides, fewer than two lines, or a header total that disagrees with its own lines); `journal_lines_no_late_addition` (a balanced entry must not be unbalanced a second later); no-update and no-delete on BOTH tables; `journal_lines_one_side_only` (exclusive-or on the two columns) and `journal_lines_never_negative`. `journal_lines` was ADDED to the estate append-only grant list — a header nobody can edit above lines anybody can edit is not append-only, and the amount, the account and the household all live on the lines.
NO BALANCE IS STORED ON AN ACCOUNT, and none ever will be. A balance is the sum of the posted lines, computed on read. A stored balance is a second copy of the ledger free to drift from it, and the copy is the one a committee reads.
NO SIGNED AMOUNT COLUMN. A single signed column would let a credit be written as a negative debit — the same arithmetic, a different statement — and a trial balance printed from it would have no two columns to compare.
Guarded by: `php artisan gate:ledger` (audits every posted row AND attempts each forbidden write live against the real estate databases) and `tests/Feature/DoubleEntryLedgerTest.php` — 23 tests, every forbidden-write test issued in raw SQL that bypasses the service entirely.
Reversible: no. The ordering is the guarantee.
Needs client confirmation: no

### D-040 · The Phase 1 placeholder journals were removed, and it took stepping around both enforcement layers
Phase: 5 · Class: data migration · One-time
Sources: `2026_09_11_010100_retire_pre_ledger_journals`
Chose: delete the `journals` rows that have no lines, at the moment the ledger is introduced.
Why not leave them: `gate:ledger` requires at least two lines per entry and debits equal to credits. A pre-ledger row can satisfy neither and can never be made to — `journal_lines_no_late_addition` means lines cannot be added to a posted entry. Leaving them would leave the money gate permanently failing, and a gate that is expected to fail stops being read.
Why not convert them: giving them lines means choosing which accounts they hit, and nobody knows because nothing recorded it. Inventing a plausible pair would put fabricated bookkeeping into the ledger and make it indistinguishable from the real thing.
BOTH LAYERS REFUSED IT, which is the clearest evidence they work: the trigger had to be dropped and restored around the delete, and the delete itself had to run on the schema-owner connection because the estate's own MySQL user has DELETE on `journals` revoked and rejected it outright on the first attempt. Neither layer was weakened afterwards; the trigger is restored in a `finally`.
Scope is exactly rows with no lines, so it cannot reach an entry posted through `Ledger`. Running it twice removes nothing.
Reversible: no — the rows held no accounting information, so there is nothing to restore
Needs client confirmation: no

### D-041 · The estate chart of accounts carries an equity account the board does not draw
Phase: 5 · Class: fidelity exception · Screen community-admin-25
Sources: board 25 groups its chart under Assets, Liabilities, Income and Expenses.
The arithmetic: total the twelve accounts as drawn and they do not balance — J$11,640,904 of debit-nature accounts against J$3,084,185 of credit-nature accounts, out by J$8,556,719. That difference is not an error in the figures; it IS the members' accumulated fund. The Build Spec's `account` entity lists `equity` as one of the five types for exactly this reason.
Chose: 3000 Accumulated Fund is in the chart and on the screen, in code order between Liabilities and Income.
Why not hide it: a chart of accounts screen that hides an account from the accountant is a lie about the estate's books, and a trial balance that can never agree is not a trial balance. The residual is one group heading and one row.
Also added, and each is a genuine domain need rather than a number's convenience: `accounts.is_control` + `accounts.subsidiary` (bank reconciliation and the sub-ledger tie cannot be checked without knowing which account controls which sub-ledger, and hardcoding "1200 is receivables" would be wrong the first time an estate renumbers) and `accounts.is_bank_account` (screen 28 reconciles ONE account against ONE statement and cannot know which without being told).
Reversible: no
Needs client confirmation: no — the board's own figures require it

### D-042 · Two Estate Console boards give figures that cannot both be true; the detail wins
Phase: 5 · Class: fidelity exception · Screens community-admin-06 and community-admin-25
Sources: screen 6 draws Lot 47 charged J$6,200 a month in July, August and September. Screen 25 draws 4000 Maintenance Fee Income at J$2,790,000, which is exactly 450 units × J$6,200 — one month of dues across the whole estate.
The conflict: 450 units billed for three months cannot produce one month's income. No single dataset satisfies both.
Chose: the SUB-LEDGER wins. Charges and payments are seeded to match screens 5 and 6 exactly — the ageing buckets, the running balance, the receipt numbers — and the chart of accounts then shows whatever those records actually total.
Why: screens 5 and 6 are the record and screen 25 is a summary of it. A summary must follow from the detail. Bending 450 real unit ledgers to make one summary figure match is the "invent a number" failure in its purest form.
Also unresolved in the boards: "Cash on hand" J$4.12M on screen 25 reconciles to neither 1000 alone (J$3,890,214) nor 1000 + 1010 (J$4,302,214). Computed here as the total of the accounts flagged `is_bank_account`, which is the only definition that is checkable.
Recorded as a content residual on screen 25's income row rather than resolved by fabrication.
Reversible: yes
Needs client confirmation: YES — is the maintenance fee J$6,200 per unit per month, and over how many months does screen 25's income figure run?

### D-043 · The Estate Console is 40 screens, not 39
Phase: 5 · Class: scope
Sources: `_design/SCREEN_REGIONS.json` measures 40 `community-admin-*` regions; the Build Spec lists 40 rows across its ten board files.
The fortieth is community-admin-01, the estate's own login screen — a distinct board from the Gemini login, with the estate name and parish in the eyebrow and a "Powered by Gemini Security Limited" footer. It was not in the 39 figure.
Web total is therefore 45 + 40 = 85 screens, not 84.
Needs client confirmation: no — reported, not contested

### D-044 · The Estate Console sidebars on the boards are an illustration, not a role's navigation
Phase: 5 · Class: fidelity exception · Every estate screen
Sources: board 24 is the role access matrix and is the Build Spec's stated generator of navigation — "This matrix generates navigation. A role without a module permission does not see that module at all."
The test that settled it: board 05's persona is the PROPERTY MANAGER, and its sidebar draws Dues & ledger, Accounting and Payroll & HR — the three modules Ruling 1 (D-010) explicitly locks that role out of. Board 25's persona is the Treasurer, and its sidebar draws Estate structure, which board 24's own matrix gives the Treasurer as "—".
So every board draws the SAME ten items whatever persona it names. The sidebar carries no per-role information, and no role's real navigation can match it — the Property Manager's certainly must not.
Chose: the matrix generates the sidebar, as the Build Spec says. A Treasurer sees nine items, not ten; a Property Manager sees no money modules at all.
Cost, measured: roughly two percentage points of pixel diff on every estate screen, because one missing nav row shifts the six below it by 38px. Screens 05 (2.18%) and 35 (2.03%) sit just above the 2% bar for this reason alone and are recorded as such rather than passed.
Rejected: making the sidebar static to match the boards. It would publish Dues & ledger to a Property Manager, which is a locked client invariant and the one thing this console must not do.
Verified, not assumed: the ESTATE_GRID in RbacMatrixSeeder was re-read against board 24 cell by cell. Estate structure is President V, Vice President V, Secretary V, Property Manager F, Treasurer —, Admin Assistant —. The transcription is correct; the sidebars are what disagree.
Reversible: yes, if the client rules that the sidebar is not permission-driven
Needs client confirmation: no — the Build Spec already rules it, and the alternative breaks Ruling 1

### D-045 · The board's outline buttons were losing their border to a default-removal rule
Phase: 5 · Class: defect · Screens super-admin-16, 33, 36 to 40
Found while building the estate unit ledger, by an agent reading the house style rather than by measurement.
What was wrong: `.btn-outline-sm` carries `border: 1.5px solid var(--navy-200)` on every board. Three pages reset `button.btn-outline-sm { border: 0 }` as "default-removal", which out-specifies the board's own rule and strips the outline off the control entirely.
Why it survived: 1.5px on one small button is a few hundred pixels. Every affected screen measured under 2% while drawing a button the boards do not draw.
The rule that was misapplied: a browser's own border needs no removing, because an author rule already beats the user agent's. `border: 0` is only correct where the BOARD gives the element no border — `.btn-primary-sm`, `.req-btn`.
Fixed in ReportShell, Requests and Invoice. Re-measured: 16 0.51 to 0.47, 33 0.41 to 0.36, 37 1.09 to 1.07, 40 unchanged. Small, and it was a real difference from the approved design.
Reversible: no
Needs client confirmation: no

### D-046 · Board 19 draws three amenity chips and board 20 registers four amenities
Phase: 5 · Class: fidelity exception · Screen community-admin-19
Sources: board 19's filter row is "All amenities · Gazebo · Club House · Community Centre"; board 20 is the rate card and lists a fourth, the Pool Deck, on the same terms as the other three — a capacity, opening hours, and a fee and deposit that happen to be nil.
The conflict: brief-19 states the rule for the chip row itself — "the chip list is generated from bookable amenities" — and brief-20 states that Pool Deck "is bookable but has no current bookings". Generated from four bookable amenities, the row is five chips; the board draws four. The two boards cannot both be right about the same estate.
Chose: the RATE CARD wins, and the chip row is generated as its own brief says. `Amenities::bookingsBoard()` lists every active, bookable amenity, so the diary carries a Pool Deck chip that board 19 does not draw.
Why: a chip row hard-coded to the three amenities that happen to have bookings is a filter an estate cannot use on an amenity it owns, and it would go wrong the first time somebody books the pool. Dropping the Pool Deck from the rate card instead would delete an amenity the estate has, to make a filter row shorter.
Cost, measured: one 31px chip, about a third of a percentage point. Screen 19 measures 0.80% against its board with this and the fee control on it, and passes.
The related residual is already recorded in `FacilitiesSeeder`: board 19 dates three bookings "Sat, Sep 20", "Sun, Sep 21" and "Sat, Sep 27", weekdays no calendar has together, so the date is stored and the weekday derived.
Reversible: yes — it is one query in `bookingsBoard()`
Needs client confirmation: no — the boards' own briefs state the generation rule

### D-047 · Board 17 draws five tickets and the queue holds seventeen; the queue wins
Phase: 5 · Class: content residual · Screen community-admin-17
Sources: board 17 draws five rows — #1042, #1041, #1039, #1037, #1031 — above four tiles reading 12 open, 7 in progress, 2 overdue and 3.2 days average resolution. `_design/brief-17.json` concludes from that gap that "the table is paginated or filtered". `FacilitiesSeeder` seeds seventeen tickets for the same reason and numbers the twelve extra ones below 1031 so that a queue sorted descending by number still OPENS with exactly the five the board draws.
The conflict: `Maintenance::queueBoard()` returns every ticket, so the built screen draws all seventeen. The board's five are the first five and correct, but every column after the Ticket cell is then shifted — `table-layout` is auto and "#1021 — Club House air conditioning" is wider than anything the board had to fit.
Chose: draw the whole queue. Rejected a five-row limit, which is what would have made this measure near zero: board 17 draws no pager, and its four chips are All, Open, In progress and Completed — nine tickets sit under "In progress" alone, so a limit would hide four outstanding jobs with no control anywhere on the screen able to reach them. A maintenance queue that conceals work the estate still owes somebody is a worse defect than a percentage.
Also considered and rejected: authoring a pager, which board 27's payment panel shows is permitted where the board is a still image of something nobody has pressed. It is a bigger change than this phase needs and it is a decision about every estate list screen, not this one — `Dues::arrearsBoard()` draws all 450 units' worth today and would want the same answer.
Cost, measured: 1.52% against the board, roughly half of it the twelve extra rows and half the column shift they cause. Under the 2% bar, and recorded rather than passed silently.
Reversible: yes — a pager or a limit resolves it the day the console gets either
Needs client confirmation: no — the board's own transcription already anticipates a longer list

### D-048 · The role access matrix is read-only in the Estate Console, because no estate role can approve a permission change
Phase: 5 · Class: architecture · Screen community-admin-24
Sources: board 24 draws sixty permission pills and not one control that changes any of them — no button, no dropdown, no save. Its `controls` list is the seven sub-navigation items and "Invite user", and nothing else.
Chose: the matrix screen READS `role_module_access` and there is no route, no service method and no payload field that writes it. `matrixBoard()` reports `can_edit` from `estate.settings.approve`, which resolves to false for all seven estate roles.
Why that permission and why nobody holds it: changing what a role may do is the definition of an irreversible act, and D-013 reserved `approve` for exactly those — "the officer who RUNS a thing is not the officer who declares it final". The seeded Settings row gives Full to the Community Super Admin and View to the President and Vice President, and `can_approve` is false in every cell of it. D-008 is explicit that Full without the Approver tag does not grant approve, so the widest role in an estate holds `configure` and still cannot approve. The result is that a community cannot widen its own access from its own console at all, which is the correct answer for a multi-tenant platform: a permission change is Gemini Security's act against the platform matrix.
Three refusals rather than one, deliberately, because a settings screen is precisely where an escalation gets built by accident: no route, no method, and a `can_edit` flag that is false for every role.
Guarded by: `tests/Feature/EstateSettingsTest.php` — "it gives no estate role the approve permission that changing the matrix would need" walks all seven roles; "it registers no settings route that could write a permission" holds an ALLOWLIST of the two writes this module ships, so the next write added has to be admitted deliberately; and "it leaves the permission matrix untouched after the widest role has used every write the module offers" fingerprints every row of `role_module_access` — role, module, level, approver flag and scope — before and after the Community Super Admin saves the profile, toggles a feature and changes a payroll routing, and compares the two strings.
WHAT THE SCREEN DRAWS THAT THE BOARD DOES NOT, and it is more than a cosmetic difference: **thirteen modules and seven roles where board 24 drew ten and six**. The three extra modules are the Ruling 1 split (D-010, D-014) — `payments`, `vendor_costs` and `maintenance_budget` were separated out so a Property Manager could see the costs they commission without ever seeing a resident's financial position — and the seventh role is the Community Super Admin, which board 24's own audit note says it deliberately omits. Rendering them is the more honest screen: a permission screen that hides three modules and a role is not describing the permissions.
WHERE THE DRAWN CELLS AND THE MODEL DISAGREE, RECORDED AND NOT RESOLVED (D-044): board 24 gives the Property Manager **View** on Dues & ledger and on Accounting. The model gives them **none** on `dues_ledger`, `payments`, `accounting_posting` and `payroll`, and `RbacMatrixSeeder` throws rather than seeding otherwise. The model wins, the screen prints em dashes there, and `matrixBoard()` carries an `invariant_note` saying so — because a committee reading the matrix is exactly who needs to be told that this is a platform invariant and not their estate's preference. Every other row of board 24 agrees with the model cell for cell; this was re-read against the board while building the screen.
Reversible: the read-only decision is, if the client rules that an estate may administer its own roles. The Property Manager cells are not — D-010 is a client ruling.
Needs client confirmation: no for the matrix being read-only, which follows from the permission model the client already approved

### D-049 · The estate profile screen shows the client record and owns only the estate's own contact block
Phase: 5 · Class: modelling · Screen community-admin-21
Sources: board 21 draws seven inputs and a "Save changes" button — estate name, address, total units, number of phases, general enquiries email, phone, security provider.
Chose: four of the seven are READ-ONLY, each carrying the reason on the field so a page can say why rather than disabling an input silently; three are the estate's own and are written to `estate_settings`.
Why the name and address are not editable here: they are real columns on the central `tenants` record (D-033) and they are the SECURITY COMPANY'S CLIENT RECORD — the name on Gemini's invoices, and the address the dispatch board sends a supervisor to. A community renaming itself from a settings form would rename the client on Gemini's own screens without Gemini knowing. Changing one is a request to the management company, which is a different act from editing a field.
Why the unit and phase counts are not editable, and are not columns either: the unit total is `SELECT COUNT(*) FROM units` in the estate's own database — the same 450 board 2's phase table adds up to and the same 450 the subscription is priced on — and the phase count follows from the phase STRUCTURE, which D-033 settled in these words: "Two places holding one count is how they come to disagree." A stored total would be a fourth number free to disagree with three.
Added, because the domain genuinely needs them: `estate_settings.enquiries_email`, `.enquiries_phone`, `.security_provider` and `.logo_path`. The first two are the number a resident rings about a blocked drain and belong to the community, not to Gemini; the third is who stands on the gate, a fact about the estate rather than about this platform's identity, defaulted rather than hardcoded; the fourth is a PATH on the tenant disk and never the bytes, so `tenant_asset()` keeps one estate's logo out of another's URL.
NOT ADDED, and restated here because a settings screen is where somebody would think to add the switch: there is no column for the resident-own-entry or emergency-vehicle exemptions, and there never will be. Both are hardcoded in `RestrictionPolicy`; a misconfigured estate leaving an ambulance at a gate is not recoverable by any amount of UI copy. `EstateSettingsTest` asserts that no column on `estate_settings` names either.
Guarded by: `tests/Feature/EstateSettingsTest.php` — the profile board test asserts each field's `editable` flag and reason, that the unit count equals the row count, and that a save with extra keys for the held-back flags changes none of them (the mass-assignment case: `HELD_BACK` is absent from `$fillable` AND from the controller's validated set).
Reversible: yes — making a field editable is a route change and a fillable entry
Needs client confirmation: no

### D-050 · Boards 22 and 23 print content this platform does not hold, and it is recorded rather than invented
Phase: 5 · Class: content residual · Screens community-admin-22 and community-admin-23
**Board 22 draws four committee members and this estate has three.** The fourth is Delroy Samuels, Secretary. He is not seeded, and adding him would change a measured GEMINI screen: `ClientDirectory::contacts()` lists every active estate assignment, and the client-detail board draws exactly three. A fourth row there is a fidelity regression on a screen this wave does not own. Recorded; the users screen draws the three real committee members, which is honest about who can actually reach this estate.
**Board 22 badges Tracey Reid "Treasurer & Accountant" and there is no such role.** The estate catalogue holds seven roles and that is not one of them, so the badge reads "Treasurer" — the role she actually holds and the role every permission on the screen is derived from. The green note beside it ("her role has been consolidated ... she was previously listed under two separate titles") is NOT printed: no role merge ever happened in this system, there is no `role_merge` history to resolve prior audit attribution against, and printing a claim about a named person's role history on an audit-adjacent screen would be fabricating a record. Building the entity to carry one sentence would be worse.
**Board 22's module lists are summaries and the screen derives its own.** The board prints "Dues & ledger, Accounting, Payroll" against the Treasurer; the matrix gives that role eleven modules. It prints "All modules" against the President, and the matrix agrees — the President does hold view or better on all thirteen, so the sentinel is DERIVED and happens to match. Only one of a summary and a derivation can stay true as the matrix changes, so both are derived. D-044 again.
**Board 22's mailboxes are `@phoenixpark1.org` and the seeded ones are `@phoenixpark.test`.** The demo credentials are deliberately unroutable — they are what the quick-login and the Phase 1 gate authenticate as — and renaming them to a domain that looks real would put four addresses on screen that somebody might write to. The `.org` domain IS used where it belongs: `estate_settings.enquiries_email` is seeded as board 21's own `info@phoenixpark1.org`, because that is a published estate address rather than a login.
**Board 22 draws exactly one amber "Owner" badge and no such column exists.** Derived: the owner is whoever holds the seniormost estate role assigned here — the Community Super Admin where one has been designated, the President where none has. A stored flag would be a second answer to a question the role already answers, and the two would disagree the first time a committee changed hands.
**Board 23 draws eleven feature rows and the catalogue holds ten.** The eleventh is "Security guard payroll routing", which is not a catalogue feature at all: guards are Gemini Security Limited's employees, paid by Gemini, filed under Gemini's TRN. It is emitted from `estate_settings.security_payroll_routing` and LOCKED to gemini-managed — an estate that routed guard pay in-house would be claiming to employ people it does not employ and posting a payroll liability it has no obligation to settle. `Settings::setRouting()` refuses that key by name and says so.
**Board 23's feature descriptions are not board 43's.** The central `package_features.sub_label` is a column caption in the package builder — "GL, AP/AR, bank import" — and board 23 prints a sentence for a committee member deciding whether to switch something off: "General ledger, AP/AR, bank reconciliation". They describe one feature to two audiences. The settings copy lives as a constant in `App\Services\Estate\Settings` rather than as a second column on the central catalogue, on the same reasoning that put board 28's scope banner in its controller: it is copy belonging to this screen in this release.
**Board 23 gets a fourth group the board does not draw — "Held back in this release".** Biometric check-in consent (off, D-022), card payments for dues (manual, D-023) and geofenced clock-in (off, D-033). None of the three is a catalogue feature and none was added to `package_features`, because that table is the package builder's and putting a legally-blocked feature into it would offer it for sale. Each is a named flag on `estate_settings` in its safest position, shown locked with the ruling on it, and refused by the toggle endpoint. Stating a decision is the difference between a decision and an oversight.
**No `estate_features` row is seeded, for any estate.** A row means somebody in that community decided something. Board 23 draws every switch on because Phoenix Park is on the Premium plan and Premium includes everything — the PLAN's answer, not the estate's. Eleven seeded rows saying "enabled" would be eleven decisions nobody made, and they would outvote the plan the day it changed. The effective state is resolved on read: core is on, else the estate's override, else whether the plan includes it.
Guarded by: `tests/Feature/EstateSettingsTest.php` — nineteen tests, including the override-resolution order, the empty override table, the four core/scale/plan/reason refusals, and the guard-payroll refusal with its positive control.
Reversible: yes for every one of them
Needs client confirmation: **YES on two.** Is Delroy Samuels a real Secretary this estate should hold an account for, and is "Treasurer & Accountant" a role the client wants added to the seven?

### D-051 · The 2026 election is seeded at the two moments its own boards draw
Phase: 5 · Class: content residual · Screens community-admin-09, 10, 11, 12, 36
Sources: board 9 draws the 2026 election at "Nominations Open" with three days left; board 11 draws the SAME election at "Tally", voted and awaiting certification. One ballot cannot be at both.
Chose: two papers, which is what board 9's own subtitle says an election is. Ballot A (Community Executive) has run its course and sits at Tally; Ballot B (Phase Leadership) is still taking nominations. `Governance::leadBallot()` was built for exactly this — the control room speaks for the LEAST advanced paper and the results screen for the most advanced — so both boards are reachable at once, and so are both officers' controls: the Secretary gets a live "Close nominations early" and five nominations to vet, the President and Vice President get a tally only they may certify.
Five residuals recorded under this ruling rather than resolved by invention:
1. TURNOUT. Board 11's five phase bars (80/65/74/58/69) against board 2's phase sizes (92/104/88/96/70) give 74, 68, 65, 56 and 48 households — 311, not the 318 the headline states, and no other whole number renders as those percentages. The HEADLINE wins: it is the numerator of the turnout percentage and the denominator of all four vote shares on the same screen, so 311 would move five figures to save five captions (187/318 is 59%, 187/311 is 60%). The seven extra households go where one household moves a printed bar least — Phase 1 sits at 80.4% and would jump to 82%, every other phase shifts a single point — so Phase 1 keeps the board's figure exactly and the bars come out 80/67/76/60/70.
2. MICHELLE PALMER. Board 10 draws her nomination "Pending review"; board 11 gives her 201 votes and a Vice Chairman seat. A paper cannot carry a candidate nobody vetted, so hers is seeded approved. Keith Walters is the pending one the boards' own arithmetic allows: board 9 counts three Chairman nominations, board 11 draws two Chairman candidates, so exactly one of the three never made the paper.
3. PHASES ON BOARD 10. Sonia Campbell is placed at "Phase 4 · Lot 88" and Keith Walters at "Phase 2 · Lot 63"; the estate's map puts both lots in Phase 1. Board 4 itself puts two different households at Lot 12 in two different phases, so the boards' phase labels cannot all be true of one estate. The UNIT wins — `nominationsBoard()` reads the phase off the property, because one estate has one map — and D-042's rule is the same one.
4. RICARDO HALL. Board 10 rejects him for "arrears >90 days"; boards 5 and 6 put Lot 9 in the 60-day bucket owing J$18,600. The reason is stored VERBATIM, because it is the returning officer's recorded decision rather than a computed value, and the eligibility snapshot written beside it is the ageing the ledger actually reports on the day the decision was taken. The sub-ledger wins over the badge, exactly as D-042 has it.
5. WEEKDAYS. Board 36 dates the AGM "Sat, Sep 27" and the phase meeting "Sat, Oct 4"; both fall on a Sunday. The dates are seeded as offsets from today and the weekday is derived, so the screen is never internally wrong — the same treatment D-046's booking dates already get.
Invented so a stated figure is reachable, and only where a board states a figure it does not itself show: nine of board 9's fourteen positions (four executive seats plus a lead and a representative for each of five phases) and seventeen of its twenty-two candidates. The seventeen are the estate's own householders, named exactly as the residents table names them — `EstateFinanceSeeder` refused to invent 440 Jamaican names and this refuses to invent seventeen. Same argument as `FacilitiesSeeder`'s twelve undrawn tickets: seeding only the five drawn rows leaves four headline figures on a reviewed screen unreachable.
ONLY PHOENIX PARK IS SEEDED, and the seeder decides that from the estate's own map rather than from its name: the phases must be exactly the five board 11's turnout bars name. Ocean View currently carries four "Block A"–"Block D" units alongside a full five-phase estate, which would have configured twenty-two positions against board 9's fourteen and left four phase pools holding a single household each — half an election, which is worse than none. It is also the right answer on its own terms: Ocean View is mid-onboarding on every board that draws it, and an estate with a certified-in-waiting committee election behind it is not onboarding. `DemoDataSeeder` already withholds the dues history from it for the same reason.
Reversible: yes
Needs client confirmation: YES — are board 11's phase bars or its 318 headline the figure the client means? They cannot both be true of a 450-unit estate.

### D-052 · Four defects the governance seed found, in code the boards could not otherwise reach
Phase: 5 · Class: defect · Screens community-admin-10, 12, 36
Found by seeding boards 9 to 12 and 36 and reading the service's own output back. Each was invisible until a screen had rows to draw.
1. `meetings.quorum_member_total` in the migration against `quorum_required_total` in `Meeting`, `Governance` and board 36's own brief. The provisioned estates carry the model's name, so this only bit a FRESH `tenants:migrate` — which then produced a database the model could not read. The migration now says `quorum_required_total`.
2. `Meeting::quorumRequired()` returned that column unchanged for a committee, making the requirement equal to the committee's whole membership. Board 36's "Quorum met · 6/7" was unreachable: six of seven present is one short of a quorum of seven, because one column was being read as both the denominator of the badge and the bar to clear. It is now `ceil(basis × quorum_percent)` for both bases, which is what the migration's own docblock says — "`quorum_percent` is the rule in both cases, which is why there is one of it".
3. `Governance::nominationsBoard()` and `::meetingsBoard()` each passed one-argument key extractors to `Collection::sortBy([...])`. A callable there is a COMPARATOR, called as `$fn($a, $b)`, and its return value IS the verdict — Laravel only treats the argument as a key when it is a string. Both boards were therefore drawn in an order nobody chose: board 10 lost "rows grouped by position sought" and board 36 lost "upcoming soonest-first, then past newest-first", which is the only order a meeting register reads sensibly in. Both are now two-argument comparators.
4. `EstateSetting::current()` created its row without the eight governance and meeting columns. They carry database defaults, so an estate whose row already existed read them back correctly — but the row this method CREATES is the one it hands straight back, and a column absent from the INSERT is null on that model however sensible the column default is. The first meeting scheduled on a brand-new estate went in with a null quorum percentage and the database refused it. The whole point of that method is that a caller always gets a number; leaving eight columns out made it untrue for exactly one estate — a new one.
5. `GovernanceSeeder` itself keyed its nominations and its ballot options on the CANDIDATE'S NAME. Seventeen of the twenty-two candidates are the estate's own householders, so the moment `ResidentsSeeder` taught the register that Lot 97 is Natalie Wong, a name-keyed row stopped matching and a second nomination appeared for the same seat and the same household — the exact stacking a seeder must be unable to do, observed in the wild between two runs. Both are now keyed on something a rename cannot move: the seat plus the household for a nomination, the seat plus the place on the paper for an option, because Andre Thompson carries no household at all.
Guarded by: `php artisan tenants:seed --class="Database\Seeders\Estate\GovernanceSeeder"`, which now reproduces every figure boards 9, 10, 11 and 36 print, and by `EstateFinanceSeeder`'s own test estates, which build from empty and hit (4) every time. (5) is proved by renaming a seeded householder and re-running: the nomination and the paper entry both follow the new name and neither table grows.
Reversible: no — each reverts to a screen that is wrong
Needs client confirmation: no

### D-053 · Approving a unit claim is `approve`, and the Residents row gained two Approver cells board 24 never drew
Phase: 5 · Class: modelling · Screens community-admin-04 and 31
Sources: board 24's matrix gives Residents `F · V · V · F · F · V · E` — Full to the Community Super Admin, the Secretary and the Property Manager, and no Approver tag anywhere. Board 31 then puts an irreversible act inside that module: "Approve claim", drawn as the primary action on all three cards, with Patricia Morgan, Property Manager, in the sidebar footer as the reviewer.
The conflict: approving a unit claim binds a person to a household. That decides whose guest passes they may issue and whose gate they may be admitted at, and no edit afterwards unbinds the night somebody was let through. D-013 and D-008 are explicit that `approve` is a separate verb and that Full WITHOUT the tag must not grant it — so as transcribed, `estate.residents.approve` was held by nobody at all and the route behind board 31's primary button would have answered 403 to every role in the estate.
Chose: the Residents row becomes `A · V · V · F · A · V · E`. The Community Super Admin, which holds everything within its own estate (D-009), and the Property Manager, whom board 31 names as the reviewer, gain the Approver tag. Everything else is unchanged.
THE SECRETARY KEEPS PLAIN FULL, and that is the point rather than an oversight. They may refuse a claim, ask a claimant for an identity document and edit the register all day, and may not commit the one act that cannot be walked back. That is the separation D-013 exists for, stated as a role rather than as a principle.
Why a derived cell rather than a question: this is the same shape as D-014's derived rows — the wireframe drew a row before the capability existed, so there is no cell to contradict. The derivation is narrow (two columns, one verb) and the more restrictive reading was taken everywhere it could be: refusing and requesting a document stayed `update`, and no role gained `approve` that did not already hold Full.
Guarded by: `tests/Feature/EstateResidentAccessTest.php` — "it refuses the approval to a Secretary who holds update and not approve" asserts both permissions on the role and then the 403, and re-reads the claim to prove nothing was written on the way past.
Reversible: yes, via the permission matrix
Needs client confirmation: no — but the alternative reading (nobody may approve a claim, and the estate escalates to Gemini) is a one-line change to the same row if the client wants it

### D-054 · Board 4's lot numbers cannot all exist in this estate, so the lot wins and the phase gives way
Phase: 5 · Class: fidelity exception · Screens community-admin-04, 31 and 38
Sources: board 4 gives six households a phase and a lot — Andrea Fletcher at Phase 2 · Lot 47, Dwayne Robinson at Phase 1 · Lot 12, Sonia Campbell at Phase 4 · Lot 88, Keith Walters at Phase 2 · Lot 63, Rachel Bennett at Phase 1 · Lot 3 and Natalie Wong at Phase 2 · Lot 12. Its own domain notes state that lot numbers repeat across phases and that the key is therefore (phase, lot).
Three of the six cannot be reproduced:
- `units.reference` is unique across the estate, so ONE Lot 12 exists. Dwayne Robinson and Natalie Wong are drawn at two different Lot 12s.
- The arrears fit (`ArrearsPlan`) places Lot 88 and Lot 63 in Phase 1, not in Phases 4 and 2. That fit is what makes the per-phase and the per-ageing totals both land on the J$1,840,000 three boards state, and it cannot be moved without re-billing six months of dues onto a ledger that has no delete.
Chose: THE LOT WINS AND THE PHASE FOLLOWS THE ESTATE. Each named household is placed at the lot the boards give it and takes whichever phase this estate puts that lot in; Natalie Wong, whose lot is already Dwayne Robinson's, goes to the first unnamed occupied unit in the phase she is drawn in (Lot 97).
Why the lot rather than the phase: board 19 got there first. The facilities seed has already booked the Gazebo for "Andrea Fletcher — Lot 47", the Club House for "Sonia Campbell — Lot 88" and the Community Centre for "Keith Walters — Lot 63", and raised ticket #1042 against Lot 47 — which is what board 38's Linked activity panel reads. An estate cannot have two Sonia Campbells at two different addresses, and honouring the phase would have created exactly that.
AND THE BALANCES ARE THE LEDGER'S. Board 4 draws $12,400, $0, $6,200, $0, $6,200 and $0 down its Balance column. Lot 47 is exactly J$12,400 because the arrears fit put it there; the other five read whatever their unit ledgers actually say. Nothing was billed or receipted to make a column match — a residents screen showing a figure the accounts disagree with is the failure the whole module is built to avoid, and `EstateResidentsTest` re-sums every row in raw SQL against 1200.
Not modelled, deliberately: a (phase, lot) composite key. It would mean renumbering 450 units under six months of posted dues to buy a uniqueness the estate does not currently need — no two lots in Phoenix Park share a number — and the first estate that genuinely repeats them is a schema change with a migration, not a guess made now.
Reversible: yes, by re-fitting the arrears — which would restate every board that quotes them
Needs client confirmation: no — reported, not contested

### D-055 · One function decides what any screen may say about a household's standing
Phase: 5 · Class: modelling · Screens community-admin-04, 31 and 38 · implements D-024 and D-025 in the console
Sources: invariant 2 and D-025 fixed what a GUARD may be told about a restricted household — "Access restricted — contact management", amber, no amount, no wording implying money. Three Estate Console boards then draw a household's standing: board 4's Balance column, board 31's "Tanya Simms — 90+ days arrears" and board 38's "Balance owed" hero stat.
Chose: `Residents::standingOf()` — one method, one return shape, and every one of those three screens reads it. It refuses in two directions and the ORDER is the guarantee:
1. A RESTRICTED HOUSEHOLD IS ANSWERED FIRST, before the ledger is consulted at all. It returns amber, D-025's exact wording, and `balance_minor`, `bucket` and `bucket_label` all explicitly null. Never the balance, never the ageing bucket, never the number of days. Ordering it ahead of the arithmetic means no later edit to the balance code — a new column, a different bucket rule, a cache — can reach a restricted household by accident, which is the same reasoning that puts the exemption check first in `RestrictionPolicy::decide()`.
2. A VIEWER WITHOUT `estate.dues_ledger.view` GETS NO FIGURE EITHER. D-010 locks the Property Manager out of a resident's financial position, and a resident detail screen that printed a balance would be a side door into exactly that. The controller decides it once and passes it down; the service never asks the container, because a service reading the permission itself would be a second copy of the route matrix.
The cross-link is ABSENT rather than blanked. Board 38's "30-day arrears — $12,400" row is not emitted at all where the figure may not be shown: a row reading "arrears" with its number struck out still says there are arrears. Same rule as the sidebar — a module a role cannot reach is absent, not greyed.
The open-item count follows the links the viewer can see, so a count one higher than the rows beneath it cannot become an oracle for a withheld balance.
Guarded by: `tests/Feature/EstateResidentsTest.php` — "it gives a restricted household the same words a guard is given, and no figure" asserts `Residents::RESTRICTED_LABEL` is byte-identical to what `RestrictionPolicy` hands a guard, and "it withholds the figure from the register, the detail screen and the claim review alike" walks all three payloads. Both scan the standing's VALUES for the vocabulary of money and for any digit at all, so a leak arriving under a key nobody thought of still fails.
Reversible: no. The ordering is the guarantee.
Needs client confirmation: no — it implements D-024 and D-025 rather than adding to them

### D-056 · Content residuals on boards 3, 4, 31, 34 and 38
Phase: 5 · Class: fidelity exception
Recorded rather than resolved by invention. Each is a place the boards print something this estate cannot supply, and in every case the estate's own record wins.
- BOARD 3, PHASE 5: drawn as 70 units, 78 OCCUPIED and 0 VACANT. Occupied exceeds the unit count, which no estate can be. `EstateFinanceSeeder` clamps occupancy to the unit count, so the card reads 70 / 70 / 0 and the bar reads 100%. A card printing more households than houses would be a screen contradicting itself in public.
- BOARD 3, BLOCKS: the foot line reads "6 blocks · 3 phase officers assigned" and the domain plainly has a block between a phase and a unit. NO BLOCK TABLE WAS ADDED. A unit records its phase in `units.block` and nothing anywhere records which block it stands in, so introducing the entity would mean assigning 450 existing units to blocks no source names. The count is stored on the phase; the hierarchy is not faked. Phase officers are stored as a count for the same reason — an officer is a central user and a phase is an estate row, so the assignment crosses the database boundary D-012 draws and has no join table on either side of it yet.
- BOARD 4, KEITH WALTERS: board 31's On-record column reads "K. A. Walters" and boards 4 and 19 print "Keith Walters". The REGISTER holds the initials form, because that variance is what turns board 31's first card amber and is the whole subject of the screen; boards 4 and 19 therefore print the register's form rather than the claimant's.
- BOARD 4, MEMBERS: the estate seeder gives every household exactly one person, so the Members column would read 1 six times. Additional members are created to reach the drawn counts and are NOT given invented names — no board names them, board 38 rolls them up deliberately, and a made-up name on a screen a client reviews is fiction with no source behind it.
- BOARD 38, THE THIRD FLETCHER: drawn as "1 additional member". The panel names two and rolls up the rest (`Residents::MEMBERS_NAMED`), which is a privacy affordance rather than a layout one: a detail screen naming every occupant of a house is a roster of who lives where.
- BOARD 38, OPEN ITEMS: the board draws 2 and its own notes say the rule "needs an explicit rule". Defined as the cross-links that need somebody to ACT — an arrears balance and an unfinished ticket. A booking with a deposit held is not one: the money is in the right place and the date is in the diary.
- BOARD 38, "30-day arrears": the ageing bucket labels come from `Dues::BUCKET_LABELS`, which reads "30 days" — and board 31 prints "90+ days arrears" from the same set. The boards themselves use both forms; a second label map beside the first would be two places to keep a bucket name in step.
- BOARD 34, THE PREVIEW COPY: drawn as "Once she verifies with her ID, her status changes…" because it was written against one named person. A template cannot know, so it reads "they". The rest of the sentence is verbatim.
- BOARD 34, THE INVITE: `resident_invites.sent_at` is written NULL. There is no mail driver and no SMS gateway on this path yet, and a row claiming a message had gone would be a lie the moment somebody read it while chasing a resident who never got one.
Reversible: yes, each of them
Needs client confirmation: no

### D-057 · Board 24 measures 2.31% against its board, and the required invariant text is what costs it
Phase: 5 · Class: fidelity exception · Screen community-admin-24
Sources: board 24 draws six role columns, ten module rows and one sentence in its note box. D-048 already rules that this screen renders THIRTEEN modules and SEVEN roles instead of the drawn ten and six — the Ruling 1 split (`payments`, `vendor_costs`, `maintenance_budget`, D-010/D-014) and the Community Super Admin column the board's own audit note says it deliberately omits — and prints two more sentences under the board's single one: `read_only_reason`, because `can_edit` is false for every estate role, and `invariant_note`, because the Property Manager's four locked cells disagree with what the board drew there and each carries a "Locked" tag saying so.
The conflict: none of the excess is optional. Dropping the Community Super Admin column, the three split modules, the "Locked" tags or either extra sentence would be exactly the failure D-048 names — "a permission screen that hides three modules and a role is not describing the permissions" — traded away for a lower percentage on the one screen whose entire argument is that it must not misstate what a role may do.
Measured: 2.31% against the board (`_design/screenshots/diff/community-admin-24/`). The diff is the whole table shifted down by one extra column's width and three extra rows' height from where the board's ten-by-six grid sits, plus two extra paragraph lines in the note box — nothing in it is a misplaced border, a wrong font or a missed default-removal; every pixel of the excess is content the permission model requires and the board does not draw.
Chose: keep it, over the 2% bar, rather than remove any of the four things that put it there. Three diff iterations were already spent narrowing what could still be trimmed without touching the required text before this was recorded rather than resolved further — see D-048's own "WHAT THE SCREEN DRAWS THAT THE BOARD DOES NOT" section, which this residual measures the cost of.
Why: D-045's own rule, in reverse. A removal that reaches past the board's drawing and rubs out a fact the model requires is not a removal — it is a hidden permission. Staying silent about the Property Manager's four locked modules, or about there being a seventh role and three more modules than the board shows, is worse than 0.31 points over a bar.
Reversible: no — the excess is the invariant text itself; it shrinks only if D-048's read-only ruling or the Ruling 1 split (D-010) is reversed first, and neither is this screen's decision to make.
Needs client confirmation: no — this follows directly from D-048, which the client's own permission model already settles.

### D-058 · `Results.vue`'s `seatTitle` referenced an undeclared `turnout` and crashed the screen for every viewer
Phase: 5 · Class: defect · Screen community-admin-11
Found while measuring fidelity on the three governance screens the previous agent had written but never reported on — board 12 and 36's own commission notes this file report by. `community-admin-11` returned "100.00% FAIL (locator.screenshot: Timeout 30000ms exceeded.)" rather than a percentage: the page never finished mounting, so Playwright waited the full 30 seconds for an element that was never going to appear.
The cause: every other computed and function in `Results.vue` reads the turnout prop as `props.turnout`, exactly as `defineProps` requires when the returned object is not destructured. `seatTitle()` alone read a bare `turnout` — a variable nothing in the file ever declared. The board's own `.winner-row` binds `:title="seatTitle(card, row)"` unconditionally for every candidate on every card, so the very first render of a populated results screen threw a `ReferenceError` before Vue could mount anything to `#app`. The `.main-col` locator every fidelity target waits on then had nothing to find, for any viewer, in any state — this was not a styling defect measuring badly, it was the screen never rendering at all.
Fixed: the two `turnout.cast` reads inside `seatTitle()` now read `props.turnout.cast`, matching every other reference in the file. Nothing else in `Results.vue` changed.
Guarded by: `php artisan fidelity:check community-admin-11`, which went from a 30-second timeout to 0.48% on the next run with no other change to the file.
Reversible: no — it reverts to a screen that never rendered.
Needs client confirmation: no

### D-059 · Board 10 measures 3.37% against its board, and the reason is the one D-057 already named for board 24
Phase: 5 · Class: fidelity exception · Screen community-admin-10
Sources: board 10 draws five nomination rows behind two filter chips — "All positions", "Chairman", "Vice Chairman" — because that is the slice its own mock-up chose to illustrate. `Nominations.vue`'s own docblock already rules on this: "Board 10's own slice holds five rows covering two seats... The whole set is drawn instead, for D-047's reason: a position with no candidate at all is the one thing a returning officer has to act on before nominations close, and a list that hid it would conceal an empty seat behind a stat box." The estate has fourteen positions and twenty-two candidates, and the built screen draws all of them — thirteen chips where the board draws two, thirteen rows visible in the same frame where the board shows five.
The conflict: none of the excess is optional, for the same reason D-057's is not. Trimming the chip row or the table back to the board's own two chips and five rows would hide real, undecided nominations from the one officer who has to vet them before the window shuts — exactly the failure D-047 and this file's own docblock already name, traded away for a lower percentage on the one screen whose entire argument is that a hidden seat is worse than a missed measurement.
Measured: 3.37% against the board (`_design/screenshots/diff/community-admin-10/`). Checked first for a cheaper cause — both images are pixel-identical at 1204×900, so this is not a clipping or sizing defect, it is the seven extra chips and eight extra rows of real content the board never drew. The per-row content itself matches the seed data exactly rather than drifting: Sonia Campbell's phase reads off the unit and not the nomination (D-051 residual 3) and Michelle Palmer shows Approved rather than the board's "Pending review" because D-051 residual 2 already seeded her that way — a paper cannot carry a candidate nobody vetted, and Keith Walters is the pending one the boards' own arithmetic allows.
Chose: keep it, over the 2% bar, rather than cut real nomination data to chase a number — the same choice D-057 makes for board 24, for the same reason.
Reversible: no — the excess is the estate's real position and candidate count; it shrinks only if the estate itself ever configured fewer than fourteen positions, which is not this screen's decision to make.
Needs client confirmation: no — this follows directly from D-047 and D-051, which the seed data and this file's own docblock already settle.

### D-060 · Board 34 measures 5.52%, and the excess is a consent control and a pronoun
Phase: 5 · Class: fidelity exception · Screen community-admin-34
Sources: board 34 draws a form with five fields, a two-item verification segment and an "Add resident" button. The built screen draws all of that plus two things the board does not.
The first is the biometric consent checkbox and its note, authored under D-022. Biometrics ship off, `Residents::enrolBiometrics()` refuses without consent, and no estate setting grants it on anybody's behalf. The control sits above the submit button, so it also displaces that button roughly 74px down the panel — which is most of the 5.52% rather than the checkbox's own footprint.
The second is the preview paragraph. The board's copy is gendered — "Simone will get an SMS… once she verifies with her ID" — because it was drawn against one named person. A template cannot know, so it reads "they" and "their". That was already recorded as a D-056 residual; this entry measures what it costs.
Chose: keep both, over the 2% bar. The same choice D-057 makes for board 24 and D-059 for board 10: a removal that rubs out a fact an invariant requires is not a removal, and inventing a gender per resident to match a mock-up is worse than a pronoun that is merely less specific than the drawing.
Reversible: the pronoun, yes, if a client ruling ever says a resident's title is collected at intake. The consent control, only alongside Q-015 below.
Needs client confirmation: see Q-015 — not about the percentage, about whether this control belongs on a staff-facing form at all.

### D-061 · Board 15's PAYE column over-withholds by a factor of 5.8, and the application does not reproduce it
Phase: 5 · Class: content residual · Screens community-admin-13, 15, 16
Sources: board 15 draws six money columns per employee. Five are reproduced to the cent, because the rate card the board was drawn against and the rate version seeded here agree exactly — 3% NIS, 2% NHT, 2.25% Education Tax on gross less NIS. The sixth is PAYE, and it is not.

    employee            board PAYE    lawful PAYE
    Patricia Morgan          42,928          7,363
    Neil Anderson            22,044              0
    Wayne Thomas             20,420              0
    Simone Clarke                 0              0

The board's figure is exactly 25% of (gross − NIS − NHT − Education Tax) for the first three and zero for the fourth. Two errors compound. First, PAYE is charged on STATUTORY INCOME, which is gross less NIS only; NHT and Education Tax are not deductible against it. Second, it is charged on the EXCESS above the threshold, not on the whole amount. Simone Clarke's zero identifies the arithmetic precisely: her 66,829 sits just under 69,230.77, which is the annual threshold divided by 26 — a FORTNIGHTLY divisor applied to monthly pay, and applied as a cliff rather than as a band.
Chose: the lawful calculation. `PayrollCalculator` already implements it and its order is load-bearing; the seeder calls it rather than transcribing the board. Board 15's PAYE and Net columns, and board 13's Net column, therefore differ from what the screens show.
Why: reproducing the board would make this application withhold J$85,392 a month from four people where the law asks J$7,363 — about J$937,000 a year taken off staff who would then have to claim it back. That is not a fidelity success. It is the one case where matching the drawing is the defect.
Guarded by: `EstatePayrollTest` — "it computes PAYE on statutory income above the threshold, not on the whole" asserts both that the lawful figure comes out AND that the board's own formula does not, so the calculation cannot be quietly restored to win a pixel diff.
Reversible: only by a client ruling that the board's arithmetic is deliberate. See Q-002, which this makes concrete.
Needs client confirmation: YES — this is the sharpest form Q-002 has taken.

### D-062 · Nobody is paid for a month they had not started
Phase: 5 · Class: defect · Screens community-admin-13, 15
Found while seeding: the first payroll run included Wayne Thomas in May 2026, and he was employed from June. It was silent — every total on every screen still added up, because a wrong payslip balances exactly as well as a right one.
Board 13 had already said so in its own figures: May is drawn with three employees where the other four months have four, and its gross and net differ from theirs by exactly one person's. That variance is a fact about the register, not an exclusion somebody had to invent.
Fixed: `Payroll::calculate()` filters on `employed_since <= period_end`. Measured against the period END, because somebody who started on the 20th is on that month's payroll; what they are owed for a part month is a timesheet question, which is what board 14's first exception is for.
Guarded by: `EstatePayrollTest` — "it pays nobody for a month before they were employed", which asserts the two months differ by one person's gross and net rather than by a rounding.
Reversible: no.
Needs client confirmation: no

### D-063 · The Payroll row gained a derived Approver cell, because board 24 draws none and board 15 requires one
Phase: 5 · Class: permission ruling · Screens community-admin-15, 24
Sources: board 24 draws Payroll as Full for the Community Super Admin and the Treasurer, View for the President and Vice President, and nothing for the other three — with no Approver tag anywhere. Board 15's own banner then requires one: "Prepared by Tracey Reid — awaiting your approval. As a second approver, this run cannot be disbursed until you review and approve it."
The conflict: read literally, the drawn row makes that flow unreachable. Disbursing is irreversible — the money leaves the bank — so it is `approve` under D-013, and nobody holds it. The Treasurer prepares, and a preparer may not approve their own run, so promoting the Treasurer would not help either.
Chose: the Community Super Admin's cell is promoted from Full to Full · Approver. The Treasurer keeps plain Full and prepares. This is D-053's precedent applied a second time — the same reasoning that gave Residents two Approver cells board 24 never drew.
Guarded by: `EstatePayrollTest` finds the approver BY PERMISSION rather than by role name, so the assertion survives the matrix being corrected again and fails with a sentence naming this decision if no role can approve at all.
Reversible: yes — it is one cell in `RbacMatrixSeeder`, and a client ruling that a different officer approves payroll moves it.
Needs client confirmation: worth confirming alongside Q-002, but nothing is blocked on it.

### D-064 · One statutory payable, not four
Phase: 5 · Class: accounting ruling · Screens community-admin-15, 16
Sources: board 16's accounting note describes the S01 as clearing "the four statutory payable control accounts". The approved chart of accounts carries one — 2100 Statutory Deductions Payable — and board 25 draws it.
Chose: post the withholdings to 2100 as a single credit and keep the four-way split on the payslip line and on the filing. 2100 is the control; `payroll_run_lines` is its sub-ledger.
Why: the chart is what every other estate screen already posts against, and adding four accounts to satisfy a note would change board 25 as well. The tie the note is really about — that an S01 clears exactly what a run withheld — is asserted per component rather than in aggregate, which is stricter than four accounts summing correctly while two are individually wrong.
Guarded by: `EstatePayrollTest` — "it ties the statutory payable to what every paid run withheld and nothing else" and "it remits on the S01 exactly what the run it names withheld".
Reversible: yes, by adding four child accounts under 2100; nothing posted would have to move, because the split is already stored per payslip.
Needs client confirmation: no

### D-065 · Board 32 measures 5.02%, and the excess is a composer drawn mid-compose
Phase: 5 · Class: fidelity exception · Screen community-admin-32
Sources: board 32's right-hand column draws the notice composer with all four of its fields already filled in — the Urgent segment selected, "Water interruption — Phase 3" typed into Title, a full paragraph typed into Message, and "Phase 3 only" chosen as the audience. That is a mock-up showing what the control looks like in use.
The conflict: a real composer opens empty. Its placeholders carry the board's own example text, so the words are right and the colour is not — placeholder grey against the board's typed navy — and the audience reads "Everybody on the estate", which is the correct default for a screen whose own notification setting is called "Estate-wide announcements from Governance". Prefilling the four fields to match would hand a secretary a form they have to clear before they can use it, and would post the board's example notice to four hundred and fifty households if they did not.
Measured: three iterations spent, 4.94% → 5.02%. What was fixed in them was real and kept: the Title field now carries the board's amber focus ring from a real focus event and is focused on mount, the audience option dropped a count the board does not draw, and the three seeded notices now use the board's OWN posting offsets — six, nine and twelve days back — so an estate seeded on the day the board was drawn reproduces Sep 4, Sep 1 and Aug 29 exactly. None of that moved the number, which is itself the finding: the delta is the four filled fields and nothing else.
Chose: keep it, over the 2% bar. The same choice D-057 makes for board 24, D-059 for board 10 and D-060 for board 34 — the excess is a state the application is right not to be in.
Reversible: yes, and cheaply, if a client ruling ever says the composer should open with a template. Nothing about the data or the schema would change.
Needs client confirmation: no
