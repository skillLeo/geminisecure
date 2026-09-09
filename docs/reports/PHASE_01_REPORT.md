# Phase 1 — Auth, Tenancy, Role Matrix · STATUS: GATE PASSED

Tagged `gate-1-isolation-passed`. All nine steps of the `06_OVERRIDE` §7 gate hold.

---

## Gate results

Reproduce with `php artisan gate:isolation` (exits non-zero on failure, CI-usable).

| Step | Assertion | Result |
| --- | --- | --- |
| 3 | Cross-estate route requests denied | ✅ 5/5 routes → 404 |
| 3 | **Control:** same user reads own estate | ✅ 200 |
| 4 | Cross-estate raw query fails on GRANT | ✅ MySQL 1142 |
| 5 | `gs_app` holds no estate grant | ✅ both hosts |
| 6 | Queued job without tenant context throws | ✅ |
| 7 | Journals append-only, layer 1 (grant) | ✅ MySQL 1142 |
| 7 | Journals append-only, layer 2 (trigger) | ✅ SQLSTATE 45000 |
| 8 | Navigation matches matrix, all 13 roles | ✅ |
| 9 | Identity central, not per estate | ✅ |

### The step-4 distinction that matters

```
SQLSTATE[42000]: 1142 SELECT command denied to user 'o1LZLZjmogArunmV'@'localhost'
                      for table 'residents'
```

A **denial**, not an empty result. An empty set would have meant the grant was too
wide and only application logic separated the estates.

### The positive control earned its place

It failed twice before passing, and both failures would otherwise have shipped as a
green gate:

1. **`NotASubdomainException` → 500.** `geminisecure.test` was missing from
   `central_domains`, so stancl could not identify `oceanview` as a subdomain at all.
   Every route 500'd — non-200, so the denial assertions all passed.
2. **The `{tenant}` domain parameter reached the controller as `$id`.** Laravel passes
   domain parameters before route parameters, and stancl's subdomain middleware does
   not remove it. `(int) 'phoenixpark'` is `0`, `findOrFail(0)` raised ModelNotFound,
   and every route answered **404** — indistinguishable from a correct denial.

Both are now fixed (`ForgetTenantRouteParameter`, `central_domains`). A gate asserting
only "not 200" would have passed throughout while proving nothing.

### Navigation generated from the matrix

| Role | Modules | Role | Modules |
| --- | --- | --- | --- |
| Community Super Admin | 13 | Director | 8 |
| President / Vice President | 13 | Operations Manager | 7 |
| Treasurer | 11 | Accountant | 6 |
| Admin Assistant (estate) | 8 | Head of Security | 4 |
| **Property Manager** | **7** | Dispatcher | 3 |
| Secretary | 6 | Admin Assistant (Gemini) | 3 |

Property Manager holds **zero** locked financial modules, per Ruling 1.

---

## Delivered

**Tenancy** — `stancl/tenancy` multi-database. Estate = separate database + dedicated
MySQL user. `EstateProvisioner` creates tenant, domain, database, user, migrations and
grants in one synchronous pipeline. Tenant id *is* the subdomain.

**RBAC** — Both matrices parsed from wireframe DOM. 13 roles, 21 modules, 3-field cells
(`level` + `can_approve` + `scope`), projected onto `console.module.verb` permissions.
`php artisan rbac:show` renders it back for diffing against the wireframe.

**Identity** — Users, roles, permissions, assignments and invitations, all central.
`EnsureEstateAccess` denies a user of estate A at estate B's subdomain with 404 rather
than 403, since 403 confirms both estate and record id exist.

**Append-only** — `journals` enforced by withheld grant + trigger, verified independently.

**Money** — `MoneyCast` over (`amount_minor` bigint, `currency` char). No float anywhere.

---

## Defects found and fixed

| # | Defect | Consequence had it shipped |
| --- | --- | --- |
| 1 | `modules.key` globally unique | `dashboard` exists in both consoles; seeder crashed |
| 2 | Permissions not console-scoped | Granting a Gemini role its dashboard would grant every estate role theirs |
| 3 | `Tenant::getIncrementing()` | Every estate collided on `gs_estate_0` |
| 4 | `domains` stored full hostname | Tenant unidentifiable by subdomain |
| 5 | Missing `central_domains` entry | 500 on every estate route |
| 6 | `{tenant}` param reached controller | **Silent 404 masquerading as isolation** |
| 7 | `int` typehint on route param | 500 under `strict_types` |
| 8 | §6's `REVOKE` SQL | MySQL 1147 — cannot execute at all |

---

## Environment notes

- **MySQL 8.4.9 on port 3307.** XAMPP's MariaDB 10.4.32 is below the 10.11 floor and
  remains untouched on 3306. Nothing in this project uses it.
- **MySQL runs as a process, not a service** — `mysqld --install` needs elevation this
  session lacks. Survives until reboot only. The elevated command is in
  `PRE_PHASE_1_REPORT.md` §1.
- **Redis/Memurai not installed.** Falling back to `CACHE_STORE=file` and
  `QUEUE_CONNECTION=database`. **Reverb still needs a server** before Phase 5's dispatch
  screens have a live channel.
- `log_bin_trust_function_creators=1` set and persisted, so a non-SUPER owner can create
  the append-only triggers.

## Open questions

Seven parked in `QUESTIONS.md`, none blocking Phase 2. The load-bearing ones:

- **Q-001 currency** — proceeding on JMD. No document names one.
- **Q-005 arrears restriction threshold** — nothing auto-restricts; the flag is only
  ever set explicitly.
- **Q-006 no restriction UI exists** in any of the 40 Guard screens.

## Next

Phase 2 per Build Spec Part 5: **Super Admin estates and guards, 18 screens.** Opening
immediately — a passed gate is permission to proceed.
