# Pre-Phase-1 Report — required by 06_OVERRIDE §10

> Authority: `06_OVERRIDE` is the controlling document. Where it contradicts the
> master prompt, `00_SETUP.md`, `03_PHASE_PLAN.md` or `GeminiSecure Build Spec.dc.html`,
> the override wins. This report follows it.

**All six §10 items are now satisfied.** The §5 stop condition was tripped by XAMPP's
MariaDB 10.4.32 and has since been cleared by installing MySQL 8.4.9 alongside it.

---

## 1 · `SELECT VERSION()` — ✅ RESOLVED

**Initially blocking.** XAMPP ships MariaDB 10.4.32, below the §5 floor — exactly the case
§5 predicted. Per instruction, nothing was created and the blocker was reported rather
than worked around.

Resolved by installing **MySQL 8.4.9** (client choice) on **port 3307**, leaving XAMPP's
MariaDB untouched on 3306 per §9. Nothing in this project uses the MariaDB instance.

```
version  flavor                            port
8.4.9    MySQL Community Server - GPL      3307
```

Choosing MySQL rather than MariaDB also resolves the collation contradiction in §6 below:
`utf8mb4_0900_ai_ci` is valid here, so §5's DDL runs exactly as written.

### ⚠ One environment limitation

`mysqld --install` returned **"Install/Remove of the Service Denied!"** — registering a
Windows service requires elevation, which this session does not have. MySQL is therefore
running as a **background process, not a service**, and will not survive a reboot.

To make it permanent, run once in an **elevated** PowerShell:

```powershell
& "C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqld.exe" --install MySQL84 `
    --defaults-file="C:\ProgramData\MySQL\MySQL Server 8.4\my.ini"
Start-Service MySQL84
Set-Service MySQL84 -StartupType Automatic
```

Also note **root has a blank password** (`--initialize-insecure`), matching XAMPP's own
posture. Fine for local development; must be set before this box is exposed to anything.

## 2 · `SHOW GRANTS FOR 'gs_app'@'localhost';` — ✅

```
Grants for gs_app@localhost
GRANT USAGE ON *.* TO `gs_app`@`localhost`
GRANT SELECT, INSERT, UPDATE, DELETE ON `gs_platform`.* TO `gs_app`@`localhost`
```

`USAGE ON *.*` is MySQL's implicit "may connect" grant and carries no privileges.
**`gs_app` holds no grant on any `gs_estate_*` database and no wildcard** — §7 step 5
satisfied.

For contrast, `gs_owner`, which provisions estates and is never used to serve a request:

```
Grants for gs_owner@localhost
GRANT CREATE USER ON *.* TO `gs_owner`@`localhost` WITH GRANT OPTION
GRANT ALL PRIVILEGES ON `gs\_estate\_%`.* TO `gs_owner`@`localhost` WITH GRANT OPTION
GRANT ALL PRIVILEGES ON `gs_platform`.* TO `gs_owner`@`localhost`
```

Laravel connectivity verified on both connections:

```
app   : gs_app@127.0.0.1   -> gs_platform on 3307 (8.4.9)
owner : gs_owner@127.0.0.1 -> gs_platform
```

## 3 · `php -m | findstr mysql` — ✅

```
mysqli
mysqlnd
pdo_mysql
```

Both required extensions enabled in `C:\tools\php\php.ini`. Backup at
`php.ini.bak-pre-geminisecure`. The `pgsql`/`pdo_pgsql` lines added under the previous
PostgreSQL instruction are now inert; harmless, removable on request.

## 4 · `stancl/tenancy` — ✅ installed and configured for multi-database

`v3.10.1`, installed cleanly against Laravel 13. `php artisan tenancy:install` published
`config/tenancy.php`, `routes/tenant.php`, `TenancyServiceProvider`, and
`database/migrations/tenant/`.

Verified at runtime:

```
central    : mysql
prefix     : gs_estate_
mysql mgr  : Stancl\Tenancy\TenantDatabaseManagers\PermissionControlledMySQLDatabaseManager
default db : mysql
db name    : gs_platform
db user    : gs_app
owner user : gs_owner
```

`QueueTenancyBootstrapper` is enabled by default, satisfying §3's requirement that jobs
restore tenant context.

### ⚠ A design decision §5 left ambiguous — flagging, not resolving

§5 says estate grants are *"issued for that estate only"* and gs_app must hold no
wildcard. That admits two readings:

- **(a)** `gs_app` accumulates an individual grant per estate database.
- **(b)** each estate database gets its **own dedicated MySQL user**; `gs_app` holds
  grants on `gs_platform` only.

**Only (b) can pass §7 step 4.** Under (a), `gs_app` would hold a valid grant on
`gs_estate_oceanview`, so a raw query from the Phoenix Park context would *succeed* —
the connection is authorised and only application context differs. §7 step 4 demands it
**fail on a GRANT error**. Reading (b) delivers that; reading (a) cannot.

I have therefore configured `PermissionControlledMySQLDatabaseManager` (per-estate user)
rather than the default `MySQLDatabaseManager`. Rationale is documented inline in
`config/tenancy.php`. **Please confirm** — if you intended (a), §7 step 4 needs rewording.

## 5 · `spatie/laravel-permission` pinned to central — ✅

§3's warning is accurate and the failure mode is real. Investigated and confirmed:
**v8 exposes no configuration key for the connection.** Its stock models resolve against
Laravel's default connection, which `DatabaseTenancyBootstrapper` swaps to the tenant
database during a tenant request — producing one private role table per estate.

Fixed with central-pinned subclasses using the package's own
`Stancl\Tenancy\Database\Concerns\CentralConnection` trait:

- `app/Models/Role.php`
- `app/Models/Permission.php`
- `config/permission.php` now points `models.role` / `models.permission` at them

Verified at runtime:

```
role       : App\Models\Role      Role conn : mysql
permission : App\Models\Permission  Perm conn : mysql
```

This matters beyond tidiness: the role-permission matrix drives runtime navigation
generation, so per-estate role tables would let two estates drift into different
definitions of the same role.

## 6 · Every PostgreSQL / RLS instruction found — ✅ complete

### In `GeminiSecure Build Spec.dc.html`

| Line | Text | Disposition |
| --- | --- | --- |
| 163 | "database layer with row-level security keyed on `tenant_id` — never in application code" | Superseded → §3. *"never in application code"* still holds |
| 187 | "1. Auth, tenancy, RLS policies, role matrix" | Superseded → §6 |
| 270 | "every query … must be constrained by a row-level security policy — not by a WHERE clause an engineer might forget" | Superseded → §3 |
| 1279 | "Every tenant-scoped table carries `tenant_id` and a row-level security policy…" | Superseded → §3. The *"a bug must return nothing, not another estate's records"* guarantee is strengthened, not weakened |
| 1309 | "Auth, tenancy and RLS … row-level security policies, audit log" | Superseded → §6/§7 |
| 469 | `user` entity carries `tenant_id` | **Not superseded** — central table, §3 keeps `tenant_id` |
| 499 | `audit_log` carries `tenant_id` | **Not superseded** — §3 requires central `audit_log` tagged with `tenant_id` |
| 500 | `subscription`/`invoice` carry `tenant_id` | **Not superseded** — central tables |

### In the master prompt

Invariant #1 in full, and the STACK line "PostgreSQL 16+ with Row-Level Security", plus
the review-failure bullets "Tenant filtering done only in application code" and "Laravel
connecting to PostgreSQL as the table owner or a superuser". All superseded per §2.

**Confirmed: following `06_OVERRIDE` in all cases above.**

### ⚠ A contradiction §2 does not list — reporting, not resolving, per §10.6

§5's DDL specifies `COLLATE utf8mb4_0900_ai_ci`. **That collation is MySQL 8.0+ only; it
does not exist in any MariaDB release.** Yet §5's own version floor explicitly permits
"MariaDB 10.11+". The two halves of §5 cannot both be satisfied:

- Choosing **MySQL 8.x** → §5's DDL runs as written.
- Choosing **MariaDB 12.x** → the collation must become `utf8mb4_uca1400_ai_ci`
  (10.10+) or `utf8mb4_unicode_ci`.

Pending your decision I have set `utf8mb4_unicode_ci` in `config/database.php`, as it is
valid on both engines. Flagging so the choice is yours, not mine by default.

### A further note on line 1309

The Build Spec contains a **phase table** (`['1','Auth, tenancy and RLS','—', …]`),
so it may partially cover the missing `03_PHASE_PLAN.md`. A design-extraction workflow is
running to determine exactly what it does and does not cover; findings will follow.

---

## Also done since the last report

- **Phase 0 gate PASSED.** All 43 design files imported to `_design/wireframes/`,
  verified 43/43 with zero `uploads/` leakage, SHA-256 manifest at `_design/MANIFEST.md`.
  Tagged `gate-0-passed`.
- **Git discipline** per §9: branch `phase-1-foundation` cut from the tagged baseline.
- **Superseded artifacts removed:** the four PostgreSQL RLS scripts and
  `tools/db-bootstrap.ps1` (recoverable from commit `4fa62e5`).

## Known limitations

- **Redis / Memurai not installed.** §9 permits `CACHE_STORE=file` +
  `QUEUE_CONNECTION=database` as a fallback, recorded here as a known limitation. **Reverb
  still needs a running server** or the Phase 5 dispatch screens have no live channel.
- **PostgreSQL 17 is installed and now orphaned.** Installed under the previous
  instruction before the override arrived. It is idle on port 5432 and conflicts with
  nothing. Say the word and I will uninstall it.
- **Five companion documents still missing** (`00A_DESIGN_IMPORT`, `02_WEB_MOBILE_SPLIT`,
  `03_PHASE_PLAN`, `04_RBAC`, `05_QUALITY_AND_REPORT`), plus `00_SETUP.md`, which §5 and
  §7 both reference. The override supersedes parts of each, but not all of them.

## What unblocks Phase 1

1. A database server meeting the floor, and the collation decision in §6 above.
2. Confirmation of the per-estate-user reading in §4 above.

Once both land, Phase 1 opens with the §7 gate: provision `phoenixpark` and `oceanview`,
seed both, and prove isolation at steps 4 and 5 with raw output pasted into the report.
