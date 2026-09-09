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
