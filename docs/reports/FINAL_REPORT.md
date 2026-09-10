# GeminiSecure — final report · web deliverable

**Status: complete.** 85 of 85 screens built. Four gates green. 365 tests, 2,150
assertions, on MySQL. Larastan level 6 at zero with no baseline and no ignores.

This report is the handover. It states what was built, how to verify it without
taking my word for anything, what is deliberately absent, what is still waiting
on a client ruling, and the two places where I was wrong and said so.

---

## 1. One correction, first

**I reported the Estate Console complete at 40 of 40 screens, and it was 39.**
Board 29 (Reports) had never been built; its sidebar item still greyed out. The
figure appeared in several commit messages and in conversation before I checked
it against the filesystem rather than against my own notes.

It is built now, and measures 0.00% against its board. The count in this
document is 85, and every one of the 85 is in the fidelity table below with a
measured number beside it — which is the form the claim should have taken in the
first place.

Building it also found a defect that had been latent for thirty-nine screens.
That is §7.

---

## 2. What was delivered

| | |
| --- | --- |
| **Gemini Console** | 45 screens · 9 modules · 6 roles |
| **Estate Console** | 40 screens · 13 modules · 7 roles · multi-database tenancy |
| **`/api/v1`** | 5 endpoints, token-scoped by ability, rate-limited per device |
| **Simulators** | `simulate:alerts`, `simulate:gate` — over HTTP, through the real middleware |
| **Realtime** | Reverb broadcast + adaptive poll, so a dead socket cannot read as a calm night |
| **Gates** | `gate:console`, `gate:interactivity`, `gate:isolation`, `gate:ledger` |
| **Decisions** | 74 recorded, each with its reasoning, reversibility and whether a client must confirm |

### The stack, as built

Laravel 13.31 · PHP 8.5 · Inertia 2 · Vue 3 (Composition API) · Vite 8 ·
MySQL 8.4 on **port 3307** · `stancl/tenancy` v3.10.1 multi-database ·
`spatie/laravel-permission` v8 pinned central · Pest 4.7 · Larastan 6 · Pint ·
Playwright + pixelmatch for the fidelity harness.

---

## 3. How to verify this without trusting the report

No figure on any screen is fixture data typed into a component. Every one is
seeded, posted through the same services the console uses, and re-derived on
each request.

**Checking what is here.** These are the commands behind every number in this
report, and each was run to produce them:

```
php artisan gate:console          # navigation and route gating follow the matrix
php artisan gate:interactivity    # nothing looks interactive and does nothing
php artisan gate:isolation        # tenant isolation and append-only, at the database
php artisan gate:ledger           # debits equal credits, sub-ledgers tie, posted is immutable

php artisan test                  # 365 tests, on MySQL, not SQLite
vendor/bin/phpstan analyse        # Larastan level 6
vendor/bin/pint --test
php artisan fidelity:check        # all 85 screens against their boards
```

The four gates exit non-zero on failure and print every assertion, passed or
failed, by name. They are meant to be run by somebody who does not believe this
document.

**Rebuilding from empty.** `README.md` carries the setup — database roles,
central migrate, RBAC seed, then `estate:provision` per estate. Two steps it
does not yet name, and both are needed before the screens hold anything:

```
php artisan grants:append-only
php artisan tenants:seed --class="Database\Seeders\Estate\EstateFinanceSeeder"
```

The second rebuilds an estate's ledger, register, facilities, governance,
payroll and notices from empty and refuses to run outside local and testing. The
simulators are optional and populate the dispatch screens:

```
php artisan simulate:alerts --count=5
php artisan simulate:gate --count=20 --shift-change
```

---

## 4. Fidelity — 80 of 85 under 2%

`php artisan fidelity:check` drives a real browser to each screen as the role
that board's own sidebar footer names, screenshots it at the board's viewport,
and pixel-diffs it against the approved wireframe. Estate screens are clipped to
`.main-col` on both sides, for the reason in D-044: every estate board draws the
same ten sidebar items whatever persona it names, including modules that
persona's role is locked out of, so the sidebar belongs to no role. It is
asserted against the permission matrix in `EstateNavigationTest` instead — a
test, not a picture.

**Five screens sit above the 2% bar. None was trimmed to get under it, and each
has its cause recorded rather than described as "close enough".**

| Screen | Diff | Cause | Recorded |
| --- | --- | --- | --- |
| community-admin-34 Add Resident | 5.52% | A biometric consent control the board does not draw, plus a pronoun change | D-060 |
| community-admin-32 Notices | 5.02% | The board draws its composer mid-compose, with text typed into it | D-065 |
| super-admin-07 Client Health | 4.16% | Half a missing feature, diagnosed and half-fixed; the rest is the adoption roll-up's own text | D-066 |
| community-admin-10 Nominations Review | 3.37% | Required invariant text the board has no room for | D-059 |
| community-admin-24 Role Access Matrix | 2.32% | The matrix in the model has 13 modules and 7 roles; the board drew 10 and 6 | D-057 |

Three of those five are the same story and it is worth stating plainly: **the
board is an illustration and the permission model is the product** (D-044).
Board 24 draws a Property Manager with View on Dues & ledger; Ruling 1 (D-010)
locks that role out of it, and the seeder throws rather than granting it. The
pixels disagree because the pixels are wrong, and closing that gap would mean
handing a resident's financial position to the person who commissions the work.

The other two are honest excess: a control the client asked for after the board
was drawn, and a board photographed mid-interaction.

---

## 5. The invariants, and where each is actually enforced

Not asserted in a docblock — enforced somewhere a future edit has to get past.

**1 · A role sees only its modules.** Navigation is generated from
`role_module_access` at runtime, so a forbidden module is *absent*, never
hidden. Hidden-but-reachable is the failure this design refuses: `gate:console`
proves a module missing from the sidebar also answers 403 by URL.

**2 · No endpoint a guard can reach returns a monetary amount.**
`MobileApiTest` asserts it against the **whole response body** of every handset
endpoint, not against the absence of a named field — so a field added later
cannot smuggle one through. A household in arrears reaches a handset as an amber
verdict and `access_restricted: true`, and there is no code path behind those
endpoints that could carry the figure.

**3 · Debits equal credits, always.** Enforced at the database: six triggers,
two CHECK constraints, lines-ordered-before-header. `gate:ledger` posts an
unbalanced entry, a one-sided entry, a line carrying both a debit and a credit,
and a negative posting, and requires the database to refuse each — then posts a
balanced one as a positive control, because a gate that only checks for the
absence of success passes on a broken application.

**4 · Append-only tables are append-only, in two layers.** Grants and triggers,
never one (D-017). `gate:isolation` step 7c proves both halves: that an
append-only table refuses an edit, *and* that no table on the append-only list
still carries an UPDATE grant. See §7 — that second half was added because the
first half had been passing alone for months.

**5 · Whoever commissions work cannot pay for it.** The Property Manager is
locked out of `dues_ledger`, `payments`, `accounting_posting` and `payroll`
(D-010). `RbacMatrixSeeder` throws rather than seeding a breach, so the
invariant cannot be edited away in a data file.

**6 · `approve` is a separate verb from `update`** (D-013). It gates the
irreversible acts — payroll disbursement, period close, ballot certification,
claim approval, meeting publication — so a role may prepare one without being
able to commit it. Board 15's own banner asks for a second approver;
`Payroll::approvalRefusal()` enforces that the preparer is not the approver,
which no middleware can express.

**7 · Every displayed money total is traceable to posted journal lines.** In a
test, per screen, not by inspection.

---

## 6. The money modules carry an extra gate

Dues, accounting, payroll and billing each had to clear three things beyond the
ordinary bar before they were called done: debits equal credits, sub-ledgers tie
to their control accounts, and posted journals are immutable — *asserted, not
assumed*. `gate:ledger` runs all three against the seeded estate on every check.

One finding from payroll is material enough to lead with, and it is in §9.

---

## 7. Defects found, and what each one says

The ones worth handing over. Every one was caught by something automated; none
was found by looking at a screen and thinking it seemed wrong.

**Three append-only tables were still carrying UPDATE grants** (D-067).
`ApplyAppendOnlyGrants` only ever *granted*. That was correct exactly once — at
provisioning, when the list and the schema were written together. Every table
promoted to append-only afterwards kept the grant it held as an ordinary table:
`journal_lines`, `ballot_receipts`, `ballot_marks`. So the estate's own MySQL
user held UPDATE and DELETE on the ledger's line table and on both ballot
tables. Nothing had been edited, because the triggers held — but the platform's
most protected tables had been running on a single layer, and the entire point
of the second layer is that the first can be lost quietly. It was. Found by a
new gate step that asserts the converse of what the gate had always asserted.

**Every handset shared one rate-limit bucket.** `ThrottleRequests` runs *before*
`Authenticate`, so a limiter keyed on `$request->user()` found null on every
request and silently fell back to the IP. The limit appeared to work. Behind one
mobile carrier NAT, a single runaway phone would have taken the panic button
away from every guard at the estate. The key is now the bearer token, hashed.
Caught by a test; nothing else would have found it.

**`simulate:alerts` had never worked.** It reported "Raised 0 of 5" and read as
a bad simulation. It had been returning 401 on every request since
`auth:sanctum` landed. Fixed by building device enrolment properly — a token now
belongs to the **guard**, not to a console user, so the principal holds no role,
no permission and no console. A stolen handset can raise an alert and clock on.
It cannot open a single screen.

**`super-admin-01` regressed from 0.06% to 7.08% with no error anywhere.** The
prop was named `console`, which is on Vue's template global allowlist, so
`{{ console.mark }}` compiled to `window.console.mark` and rendered empty.
Renamed to `door`.

**A dues adoption figure read "450 of 433".** The numerator counted all units and
the denominator only occupied ones. Dues are charged to the *unit*; the
denominator was wrong. Caught by a test I wrote for it, on its first run.

**The one file exempt from static analysis was the one every screen depends
on** (D-071). `phpstan.neon` excluded `HandleInertiaRequests` as
package-generated scaffolding. It stopped being that long ago — it now shares
both consoles' navigation with every page — so the project's "level 6 at zero,
no baseline" claim had an asterisk nobody was reading, over the file at the
centre of the outage below. Two real errors were behind it, including an
untyped array shape on the payload that ships to the browser on every page
load. Both fixed; the exclusion is gone rather than re-justified. Found while
checking a claim in this report before writing it down.

**Board 29 took the whole Estate Console down** (D-069). Landing the last
screen's route turned every estate page into a 500.
`ConsoleNavigation::hrefFor()` builds an href per module as
`<console>.<module_key>` and runs from shared middleware on every page load; an
estate route carries `{tenant}` on the host or in the path, so generating one
without parameters throws. It had never fired because no estate route name had
ever matched a module key exactly — the other nine modules are grouped, so
`Route::has()` answered false and the fallback swallowed it. Renaming the route
would have hidden the symptom in one character and left the trap set for
whoever added the next ungrouped module.

---

## 8. What is deliberately not built

Each of these is a decision with reasoning recorded, not an omission.

| Not built | Why |
| --- | --- |
| The seven report generators behind board 29 | The board is a catalogue; each report is its own screen with its own period, scope and export format. Three of the seven have no data on this platform to generate from — no budget model, no estate-side incident record, no document store. Every card says which, in its own words |
| In-app device enrolment | Needs an enrolment code exchange. Token issuing is written and audited (`DeviceEnrolment`) |
| Geofence enforcement | Deferred (D-033). Distance is stored on every clock-in and enforced nowhere, because no distance has been agreed |
| Card payments | Manual-first behind a `PaymentGateway` adapter (D-023). See Q-012 |
| Biometric enrolment | Consent ships **off** (D-022). See Q-015 |
| Resident-side endpoints | Dues, bookings, tickets, voting — the resident app's own phase |
| Messaging | No message model exists. The console says so rather than drawing a fake thread |
| Any route that casts a vote | Voting is a resident act. This console runs an election and never marks a paper |
| Any route that writes `role_module_access` | Board 24 draws the matrix and draws no control that changes a cell. There is nothing to post to, and `EstateSettingsTest` proves it across all seven roles |

**Two estates are illustrative and are never seeded** — Emerald Heights and
Coral Bay (D-038). They appear in wireframes as examples of clients; seeding
them would put two fictional communities into a production-shaped database.

---

## 9. Still waiting on you

### Q-002 — blocking payroll approval. Your board's PAYE column and the law disagree.

This is the one that costs money if it is got wrong, and it is now a small ask.

| employee | board PAYE | lawful PAYE |
| --- | ---: | ---: |
| Patricia Morgan | 42,928 | 7,363 |
| Neil Anderson | 22,044 | 0 |
| Wayne Thomas | 20,420 | 0 |
| Simone Clarke | 0 | 0 |

NIS, NHT and Education Tax match your board **to the cent** on all four people,
so the rate card is not in question. Only the PAYE step differs. The board's
column is 25% of (gross − NIS − NHT − EdTax), charged on the whole and nil below
a cliff — one rule that reproduces all four, Clarke included.

Reproducing the board would have this platform withhold **J$85,392 a month**
across four staff where the rule as we read it asks **J$7,362.50** — a difference
of **J$78,029.50 a month**, roughly **J$936,000 a year** taken off four people
who would then have to reclaim it.

**Where the cliff sits is narrowed, not identified**, and the distinction is
deliberate. Thomas is charged on a base of 81,679.40 and Clarke is not charged on
66,828.60, so it lies between them — and four divisors of the annual threshold
land in that window (23, 24, 25, 26). The fortnightly 26 is the natural reading
and is inferred, not proved. Clarke's worked payslip is the single observation
that would settle it.

The application uses the lawful calculation. Approval remains blocked while the
rate version reads `2026-04-DRAFT`, with the reason on the control. Nothing has
been disbursed on the strength of either figure.

**What we need:** one worked payslip above the threshold (Patricia Morgan's
J$185,000), one below it (Simone Clarke's J$72,000) — hers matters more — the
employer contributions for each, and the rate version and pay period they were
computed against.

**The message to forward is `docs/reports/Q-002_PAYROLL_CONFIRMATION.md` §1**,
written to be sent as it stands. §2 is the working, for whoever fields the reply.

### Q-002, second half — do employer contributions belong on the S01? (D-072)

Found while making the request above answerable. The rate version has carried
employer NIS (3%), NHT (3%) and Education Tax (3.5%) since the schema was
written, and **no line of code read any of them.**

For the same four staff, one month: employer NIS 13,200.00, NHT 13,200.00,
Education Tax 14,938.00 — **41,338.00, against employee deductions of
38,965.51.** If the employer half belongs on the monthly S01, the return as
computed remits **less than half** of what is owed, about **J$496,000 a year**.

`PayrollCalculator::employerCost()` now computes it and **posts nothing.** The
ledger is untouched: closing the gap needs a new expense account, an accrual
against 2100 and a larger S01 — three changes to your books on a question you
have not been asked yet. You are being asked now.

### When the payslips arrive

Type them into `tests/Fixtures/golden-payslips.php`, set `confirmed => true`, and
run `php artisan test tests/Feature/PayrollGoldenPayslipTest.php`. It goes green,
or it names the exact field and both values — `Simone Clarke · paye_minor`.

That suite failed twice on its first run, once for each of two errors in figures
that were on their way into this report: a multiple quoted against the wrong
denominator, and a divisor list that had missed a candidate (D-073). Numbers
destined for a client belong under test for exactly that reason.

### Q-012, Q-013, Q-014, Q-015 — open, none blocking delivery

Each has the safest option applied behind a named flag, marked
`// ASSUMPTION Q-0xx` in the code, with the test that will assert the real rule
already written and asserting the assumption. A ruling is a change to what one
test expects.

| # | Question | Assumed meanwhile |
| --- | --- | --- |
| Q-012 | May an estate switch its own dues to a card gateway? | `payment_gateway_mode` = `manual`, not fillable, drawn locked with the reason |
| Q-013 | Which arrears-restriction settings may an estate change? | The module reads none and writes none. D-024's values stand |
| Q-014 | May a role locked out of the ledger be told a unit is in arrears? | The ageing bucket shows only to a viewer holding `estate.dues_ledger.view` |
| Q-015 | May staff give biometric consent on a resident's behalf? | Control ships off; enrolment refuses without consent; whether the control belongs on that screen at all is the open part |

`QUESTIONS.md` carries each in full — what is needed, why it blocks, what breaks
if the assumption is wrong.

---

## 10. For the mobile team

`MOBILE_HANDOFF.md` is written for them and leads with invariant 2, because it
shapes the whole API: build the apps assuming a monetary figure is unavailable.
It is not an oversight to work around; it is the product.

The three things most likely to be got wrong if the document is skimmed:

- **The amber verdict is not a denial.** A restricted household's pass *is*
  valid and the person at the gate is known. The guard's next action is to call
  management, not to turn somebody away. Design the screen so amber and red are
  never confusable.
- **`/gate-events` is a separate call from `/passes/verify`, deliberately.** The
  verdict is advice; the gate event is the decision. A visitor can be shown a
  green verdict and still turn around.
- **`mock_location: true` is recorded, never refused.** Rejecting it would leave
  a post reading as unmanned while somebody stands at it, and would tell whoever
  spoofed it that they had been caught.

---

## 11. Environment notes for whoever runs this next

- **MySQL 8.4 on port 3307**, started as a background process rather than a
  registered service — registration needs elevation this session did not have.
  **It will not survive a reboot.** The elevated command is in
  `PRE_PHASE_1_REPORT.md` §1.
- **Redis/Memurai absent.** `CACHE_STORE=file`, `QUEUE_CONNECTION=database`.
- **Reverb needs to be running** for the dispatch screens to have a live
  channel. Without it they still work — the poll continues at its unthrottled
  interval, which is exactly what the adaptive design is for.
- **Local serves both consoles from one host** with the estate in the path
  (`/estate/phoenixpark/...`), because `*.localhost` does not resolve on Windows
  and a second registrable domain breaks the session cookie. Production gives
  each estate its own subdomain. Nothing in the application knows which shape it
  is serving.

---

## 12. Where to look

| Question | File |
| --- | --- |
| Why is it built this way? | `DECISIONS.md` — 74 entries, each with reasoning and reversibility |
| What is still unanswered? | `QUESTIONS.md` |
| What do the apps connect to? | `MOBILE_HANDOFF.md` |
| Where does the project stand right now? | `STATE.md` |
| Where do the wireframes and the code disagree? | `docs/reports/DESIGN_SYSTEM_FINDINGS.md`, and D-044 for the rule |
| How do I run it? | `README.md` |

---

## Appendix — every screen, measured

`php artisan fidelity:check`, whole run, nothing omitted. Diff images for any
row are written to `_design/screenshots/diff/<screen>/` as original, actual and
diff, so a number here can be looked at rather than taken on trust.

### Gemini Console — 45

| # | Screen | Diff | | # | Screen | Diff |
| --- | --- | ---: | --- | --- | --- | ---: |
| 01 | Login | 0.06% | | 24 | Cross-Client Guard Roster | 1.53% |
| 02 | Platform Dashboard | 0.73% | | 25 | Standing Orders Library | 0.84% |
| 03 | Recent Activity | 1.03% | | 26 | Live Gate Activity — All Clients | 1.06% |
| 04 | Client Directory | 0.78% | | 27 | Security Incident Log | 0.72% |
| 05 | Client Detail — Phoenix Park | 1.25% | | 28 | Payroll Overview | 0.84% |
| 06 | Change Client Plan | 1.05% | | 29 | Payslip Detail — September 2026 | 1.64% |
| **07** | **Client Health** | **4.16%** | | 30 | Statutory Filings | 0.26% |
| 08 | Onboard New Client | 1.28% | | 31 | Rate Table | 0.71% |
| 09 | Client Detail — Ocean View | 0.64% | | 32 | Billing Overview | 0.88% |
| 10 | Manage Guard Assignment | 0.53% | | 33 | Invoice — Phoenix Park, Aug 2026 | 0.37% |
| 11 | Message Estate Admin | 1.74% | | 34 | Subscription Plans | 0.20% |
| 12 | Dispatch Live Map | 1.19% | | 35 | Payment Methods | 0.58% |
| 13 | Post Coverage Board | 1.12% | | 36 | Cross-Tenant Reports | 0.08% |
| 14 | Active Alerts Queue | 1.57% | | 37 | MRR Trend | 1.07% |
| 15 | Guard Alertness & Patrol | 1.64% | | 38 | Revenue by Tier | 1.52% |
| 16 | Requests Inbox | 0.47% | | 39 | Churn & Retention | 0.57% |
| 17 | Panic Alert Response | 1.51% | | 40 | Guard Utilization | 0.52% |
| 18 | Guard Workforce | 0.85% | | 41 | Access & Audit Log | 1.92% |
| 19 | Guard Profile — Marcus Whyte | 0.86% | | 42 | Platform Settings | 0.41% |
| 20 | PSRA Compliance | 1.10% | | 43 | Subscription Package Builder | 0.37% |
| 21 | Compliance Action — Devon Palmer | 0.60% | | 44 | Client Line Items | 0.47% |
| 22 | Add Guard | 0.62% | | 45 | Role Access Matrix | 0.41% |
| 23 | Guard Profile — Devon Palmer | 1.17% | | | | |

### Estate Console — 40

| # | Screen | Diff | | # | Screen | Diff |
| --- | --- | ---: | --- | --- | --- | ---: |
| 01 | Login | 0.12% | | 21 | Settings — Estate Profile | 0.20% |
| 02 | Dashboard | 0.83% | | 22 | Settings — Users & Roles | 1.93% |
| 03 | Estate Structure | 0.06% | | 23 | Feature Toggle Panel | 0.05% |
| 04 | Residents | 0.92% | | **24** | **Role Access Matrix** | **2.32%** |
| 05 | Arrears Command Centre | 1.99% | | 25 | Chart of Accounts | 1.39% |
| 06 | Unit Ledger — Lot 47 | 0.03% | | 26 | Vendors | 0.62% |
| 07 | Place on Payment Plan | 0.36% | | 27 | Bills & Payments | 0.84% |
| 08 | Dunning Log & Templates | 0.41% | | 28 | Bank Reconciliation | 1.61% |
| 09 | Election Control Room | 0.40% | | 29 | Reports | 0.00% |
| **10** | **Nominations Review** | **3.37%** | | 30 | Settings — Notification Defaults | 0.87% |
| 11 | Results & Certification | 0.48% | | 31 | Unit Claim Review | 0.16% |
| 12 | Meeting Scheduler | 1.57% | | **32** | **Notices** | **5.02%** |
| 13 | Payroll Run List | 0.21% | | 33 | Settings — Data & Privacy | 0.12% |
| 14 | Pre-Run Exceptions | 0.90% | | **34** | **Add Resident** | **5.52%** |
| 15 | Pay Run Approval | 1.80% | | 35 | New Charge | 1.79% |
| 16 | Statutory Filings | 1.42% | | 36 | Meetings | 1.54% |
| 17 | Maintenance Queue | 1.53% | | 37 | Payroll — Employees | 0.02% |
| 18 | Ticket Detail — #1042 | 0.15% | | 38 | Resident Detail — Andrea Fletcher | 0.07% |
| 19 | Amenity Bookings | 0.80% | | 39 | Vendor Detail — Island Electric | 0.73% |
| 20 | Amenity Settings | 0.00% | | 40 | Billing & Subscription | 0.28% |

**80 of 85 under 2%.** The five in bold are §4.
