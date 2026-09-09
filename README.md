<div align="center">

# GeminiSecure

**Estate security and community governance, built for Jamaican gated communities.**

[![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.4+-777BB4?logo=php&logoColor=white)](https://php.net)
[![Vue](https://img.shields.io/badge/Vue-3-4FC08D?logo=vuedotjs&logoColor=white)](https://vuejs.org)
[![MySQL](https://img.shields.io/badge/MySQL-8.4-4479A1?logo=mysql&logoColor=white)](https://mysql.com)
[![Tests](https://img.shields.io/badge/tests-passing-16A34A)](#testing)

</div>

---

## What this is

Gemini Security Limited sells GeminiSecure as a subscription to gated communities.
One platform serves four distinct surfaces and two different kinds of tenant:

| Surface | Audience | Form |
| :--- | :--- | :--- |
| **Gemini Console** | Gemini's own staff, across every estate | Web · 1440×900 |
| **Estate Console** | One community's committee, that estate only | Web · 1440×900 |
| **Resident App** | A household inside one estate | Mobile · 433×892 |
| **Guard App** | An officer on shift at a post | Mobile · 433×892, dark |

165 screens across 39 approved wireframes. This repository builds both web consoles
to completion, along with **100% of the domain model, business rules and API** —
including every endpoint the two mobile apps will later consume.

## Why the architecture looks like this

Community committees keep their money in this system, and a security company runs
its operations on it. Four rules outrank every feature, and each is enforced by the
database rather than by application discipline.

### 1 · Tenant isolation is physical

Every estate owns a **separate MySQL database** — `gs_estate_phoenixpark`,
`gs_estate_oceanview` — with its own dedicated database user. Central platform data
lives apart, in `gs_platform`.

A leak between two communities is therefore a **connection error, not a query
error**. That distinction is the whole point: a forgotten `WHERE` clause is an
ordinary mistake, while crossing a credential boundary is not something application
code can do by accident.

```
$ php artisan gate:isolation

STEP 4 · from phoenixpark context, read oceanview directly
  SQLSTATE[42000]: 1142 SELECT command denied to user 'o1LZLZ…'@'localhost'
                        for table 'residents'
  PASS  cross-estate read denied at the GRANT layer
  PASS  no rows returned across the estate boundary
```

The application user, `gs_app`, holds privileges on `gs_platform` and **nothing
else** — never a `gs_estate_%` wildcard. Tenant identity resolves from the subdomain
or the authenticated session, never from a request parameter.

### 2 · A guard never sees an amount owed

The guard token carries `access_restricted` as a boolean and nothing more. No
endpoint reachable by a guard returns a balance, an ageing bucket, or a payment
history — enforced at the API layer, not by omitting it from a screen.

### 3 · A vote can never be linked to a voter

Ballot choices and voter participation live in separate tables with no join key and
no shared surrogate that could reconstruct one. Turnout and quorum are provable; how
a household voted is not recoverable — not by the secretary, not by platform staff,
not by a DBA with full database access.

### 4 · Posted records are append-only

A posted journal, a gate event, an approved payroll run and an audit entry are never
updated or deleted. A correction is a **new record referencing the original**.

Enforced in two independent layers, because either alone is insufficient — a MySQL
grant is per-table and easily lost in a later migration, while a trigger can be
dropped by anyone holding `TRIGGER` privilege:

```
STEP 7 · journals are append-only
  PASS  LAYER 1 — estate user holds no table grant on journals
  1142  UPDATE command denied to user 'o1LZLZ…' for table 'journals'
  1644  journals is append-only; post a reversing entry
  PASS  LAYER 2 — trigger rejects UPDATE even for a privileged user
```

## Tech stack

**Backend** — Laravel 13 · PHP 8.4 · MySQL 8.4 · `stancl/tenancy` (multi-database)
· `spatie/laravel-permission` · `spatie/laravel-model-states` · `brick/money`

**Frontend** — Inertia 2 · Vue 3 Composition API · Vite · hand-written CSS

**Quality** — Pest · Larastan · Pint

Two deliberate absences:

- **No CSS framework.** The approved wireframes are hand-written CSS, reproduced
  structurally. Tailwind, Bootstrap and every component kit are excluded by design.
- **No floats for money, anywhere.** Every amount is a `bigint` of minor units plus
  an explicit ISO currency, read through `brick/money`. A float cannot represent
  `0.10`, and a community's ledger has to reconcile exactly.

Business rules live in **services**, never controllers, so the Inertia consoles and
the `/api/v1` endpoints the mobile apps will call cannot drift apart.

## The design system

The 43-file approved wireframe set is imported to `_design/` and pinned with a
SHA-256 manifest, so a re-import can be diffed rather than guessed at:

```bash
pwsh tools/design-manifest.ps1   # verifies 43/43 and regenerates _design/MANIFEST.md
```

`resources/css/tokens.css` holds **36 tokens** — 33 extracted verbatim from the
approved foundation, plus three that resolve a success colour the foundation used
inline but never declared. A hex literal written inline in a component is treated as
a defect.

The success green is split by **job**, not by surface, because one value cannot
clear every contrast threshold:

| Token | Value | Use | Contrast |
| :--- | :--- | :--- | :--- |
| `--success-600` | `#16A34A` | icons, dots, rails — light surfaces | 3.30:1 (UI, passes 3:1) |
| `--success-700` | `#15803D` | success **text** — light surfaces | 5.02:1 |
| `--success-400` | `#4ADE80` | text and icons — guard dark surfaces | 9.43:1 |

Semantic colours are locked and never exposed to a tenant theme picker. A tenant may
theme navy and amber only; no configuration may ever make *denied* look like
*verified*.

## Role access control

Navigation is **generated from the role matrix at runtime**. A module a role cannot
use is absent from the interface — never disabled, never greyed.

Each matrix cell is three orthogonal facts rather than one enum, because the two
consoles narrow access along different axes: `Scoped` narrows *which records*,
`Entry` narrows *which actions*.

```
level        none | view | entry | full
can_approve  boolean          — the approve verb, held apart from update
scope        all | assigned_sites | own_records
```

Inspect the seeded matrix at any time:

```bash
php artisan rbac:show --console=estate
php artisan rbac:show --nav
```

One rule is enforced in code rather than merely represented in data: the **Property
Manager may never hold any level on a locked financial module**. Whoever commissions
work must never be able to pay for it, nor see a resident's financial position. The
seeder throws rather than seeding a breach.

## Getting started

**Requirements** — PHP 8.4+, Composer 2, Node 20+, MySQL 8.0+ or MariaDB 10.11+

> MySQL **8.0+** is strongly preferred. `utf8mb4_0900_ai_ci` does not exist in any
> MariaDB release, and MariaDB below 10.11 lacks enforced `CHECK` constraints, which
> the accounting module depends on.

```bash
git clone https://github.com/skillLeo/geminisecure.git
cd geminisecure
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Create the database roles — `gs_owner` owns the schema, `gs_app` is what the
application connects as and is deliberately neither the owner nor `root`:

```bash
mysql -u root < database/sql/00_mysql_setup.sql
```

Set `DB_PASSWORD` and `DB_OWNER_PASSWORD` in `.env`, then:

```bash
php artisan migrate --database=mysql_owner
php artisan db:seed --class=RbacMatrixSeeder

php artisan estate:provision phoenixpark "Phoenix Park" --status=active
php artisan estate:provision oceanview  "Ocean View"   --status=active

php artisan gate:isolation      # must print GATE PASSED
npm run dev
```

## Testing

```bash
php artisan test                # Pest
./vendor/bin/pint --test        # style
./vendor/bin/phpstan analyse    # Larastan
php artisan gate:isolation      # tenant isolation + append-only
```

The isolation gate is not a unit test. It asserts real MySQL `GRANT` failures
against two live estate databases, because that is the only way to prove the
boundary actually holds.

## Repository layout

```
_design/            approved wireframes + SHA-256 manifest (build input, never edited)
app/
  Enums/            AccessLevel, AccessScope, PermissionVerb, Console
  Jobs/Tenancy/     estate provisioning pipeline
  Models/           central models pinned to gs_platform
  Services/         business rules — shared by Inertia and /api/v1
database/
  migrations/       central schema (gs_platform)
  migrations/tenant/ per-estate schema (gs_estate_*)
  sql/              role and database bootstrap
resources/css/      tokens.css — the design contract
tools/              design import verification
DECISIONS.md        append-only decision log
QUESTIONS.md        open items awaiting a client ruling
```

## Project conventions

- **`DECISIONS.md` is append-only.** Every non-obvious choice is recorded with its
  sources, reasoning and reversibility. Supersede an entry; never edit one.
- **`QUESTIONS.md` carries only hard blockers** — money, restriction, biometrics and
  voting. Anything undecided in those four areas sits behind a feature flag
  defaulting to the safest option, with affected code marked `// ASSUMPTION Q-0xx`.
- **One branch per phase**, tagged at each passed gate. A tag is a rollback point.

## Status

| Phase | Scope | State |
| :--- | :--- | :--- |
| 0 | Design import and verification | ✅ 43/43, manifest pinned |
| 1 | Auth, tenancy, role matrix, audit log | 🔨 isolation gate passing |
| 2–10 | Consoles, money, governance, payroll, reporting | ⬜ Planned |

---

<div align="center">
<sub>Built for Gemini Security Limited · Kingston, Jamaica</sub>
</div>
