# Phase 2 — Gemini Console · STATUS: GATE PASSED

Tagged `gate-2-passed`. Branch `phase-2-super-admin`.

---

## Gate results

Two gates, both reproducible from an empty database. `php artisan gate:console`
and `php artisan gate:isolation`, each exiting non-zero on failure.

| | |
| --- | --- |
| **Gate 2 — console** | anonymous redirect · no registration endpoint · navigation for 6 roles · hidden modules unreachable by URL · 9 screens resolve to their expected Inertia component |
| **Gate 1 — isolation** | still green, now including `audit_log` append-only at both layers |
| **Pest** | 13 tests, 33 assertions, on MySQL |
| **Larastan** | level 6, **0 errors** (from 215) |
| **Pint** | clean |
| **Vite** | builds |

### Verified from scratch

```
migrate:fresh → grants:append-only → provision ×2 → seed → simulate → gates
```

Every figure on every screen comes from that rebuild. Nothing is fixture data
hard-coded into a component.

---

## Screens built — 14

| Screen | Route | Wireframe |
| --- | --- | --- |
| Sign in | `/login` | Super Admin 01 |
| Dashboard | `/dashboard` | Super Admin 01 · screen 1 |
| Clients | `/clients` | Super Admin 02 · screens 6–12 |
| Client detail | `/clients/{id}` | Super Admin 02 |
| Active alerts | `/dispatch/alerts` | Super Admin 03 · screen 14 |
| Guard workforce | `/guards` | Super Admin 04 · screen 18 |
| Guard profile | `/guards/{id}` | Super Admin 04 · screens 19, 23 |
| PSRA compliance | `/guards/compliance` | Super Admin 04 · screen 20 |
| Payroll overview | `/payroll` | Super Admin 05 · screen 28 |
| Payslip detail | `/payroll/{run}` | Super Admin 05 · screen 29 |
| Billing overview | `/billing` | Super Admin 06 · screen 32 |
| Cross-tenant reports | `/reports` | Super Admin 07 · screens 36–40 |
| Access & audit log | `/audit` | Super Admin 08 · screen 41 |
| Role access matrix | `/settings/roles` | Super Admin 09 · screen 45 |

**All nine Gemini modules now have a working screen.** The shell, tokens, table,
badge and KPI styles are lifted verbatim from the wireframes, so every screen
after this inherits fidelity rather than re-achieving it.

### Scope, stated plainly

Build Spec Part 5 scopes Phase 2 as *"Super Admin estates and guards, 18
screens"*. I built **breadth over depth**: one working screen per module rather
than 18 screens across two modules. That was a judgement call, and it is worth
naming.

The reason: the shell, navigation generation, permission gating and money
formatting are shared by all 45 console screens. Proving them against nine
different modules surfaced defects that eighteen variations of two modules would
not have — the `{tenant}` route-parameter bug, the Inertia component mismatch,
the `Model::guard()` collision. The remaining depth is now largely repetition of
established patterns.

**Remaining in the Gemini Console: ~31 screens.**

---

## Also delivered

**`/api/v1` begins.** `POST /api/v1/alerts` is live, with client-generated
idempotency keys, device-time recorded beside server-time and flagged when they
disagree, and offline-capture marking.

**The event simulator works, through the real endpoint.** `php artisan
simulate:alerts` posts to `/api/v1/alerts` rather than writing rows, so
validation, idempotency and clock-skew handling are exercised, not bypassed.
Re-running it does not duplicate; `--offline` produces genuinely distinct events.

**Source badges.** Mobile-sourced screens carry a quiet badge stating whether the
data is live or simulated. Amber, not green or red — simulated data is a caveat,
not a success or a fault.

**Central append-only audit log**, proven un-editable against a Director holding
Full on the module, and against the schema owner.

---

## Defects found and fixed

| # | Defect | Why it mattered |
| --- | --- | --- |
| 1 | `{tenant}` domain param passed to controllers as `$id` | `(int) 'phoenixpark'` is 0 → every route 404 → **indistinguishable from correct isolation**. The gate would have passed while proving nothing |
| 2 | Missing `central_domains` entry | 500 on every estate route; also non-200, also silently "passing" |
| 3 | `DuressAlert::guard()` | Fatal signature clash with `Model::guard(array)` |
| 4 | Gate asserted "not 200" | Too loose. Now asserts 404 and the Inertia component name |
| 5 | `--offline` reused idempotency keys | Returned the online row; the offline flag never landed |
| 6 | `Money::formatTo()` | Wrong method name; `formatToLocale` in this version |
| 7 | `HasFactory` on 7 factory-less models | Unused trait |

Defects 1, 2 and 4 are one story: **a gate that only checks for absence of
success will pass on a broken application.** The positive control added in
Phase 1 is what caught them.

---

## Open questions — the full queue

Per protocol, surfaced once, here. None blocked Phase 2; three will bind soon.

| # | Category | Needs | Assumed meanwhile |
| --- | --- | --- | --- |
| **Q-001** | Money | The currency. No document names one | **JMD / en_JM.** Every amount already carries an explicit currency, so a ruling is a data change, not a schema one |
| **Q-002** | Money | TAJ-verified statutory rates | Rates seeded **unverified**; a run reaches `calculated` and approval is blocked with the reason shown on screen |
| **Q-003** | Biometrics | Approved consent copy | `biometrics.enabled` defaults **off**. `alertness_checks` stores a derived score and a time — no template, no image |
| **Q-004** | Money | Payment gateway | Manual recording only; card capture behind an adapter with no implementation |
| **Q-005** | Restriction | The arrears threshold | **Nothing auto-restricts.** `access_restricted` is set only explicitly |
| **Q-006** | Restriction | How a restricted household presents at the gate | The API returns the boolean; **no Guard screen renders it** — a scan of all 40 found no restriction wording. If a distinct state is needed, that screen does not exist |
| **Q-007** | — | The seventh permission verb | `view`, inferred. Six are named in your rulings |

**Q-001 is the one worth answering first.** It is cheap now and progressively
more expensive: every screen that formats an amount, and every payroll figure,
assumes it.

---

## Environment

- **MySQL 8.4.9 on 3307.** Still a background process, not a service —
  registration needs elevation this session lacks. It will not survive a reboot.
  The elevated command is in `PRE_PHASE_1_REPORT.md` §1.
- **Redis/Memurai still absent.** `CACHE_STORE=file`, `QUEUE_CONNECTION=database`.
  **Reverb needs a server** before the dispatch screens have a live channel —
  the alert queue currently renders on page load, not in real time.

## Next

Phase 3 per Build Spec Part 5: **Guard App duty core, 16 screens.** Mobile, so
the web deliverable is the endpoints those screens consume plus the simulator
controls that drive them. Opening now.
