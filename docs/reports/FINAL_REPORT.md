# GeminiSecure — final report · web deliverable

**Status: complete, and Q-002 is ruled.** 85 of 85 approved screens built, plus
one added on the client's ruling. Six gates green. 397 tests, 2,382 assertions,
on MySQL. Larastan level 6 at zero with no baseline and no ignores.

This report is the handover. It states what was built, how to verify it without
taking my word for anything, what is deliberately absent, what is still waiting
on a client ruling, and the places where I was wrong and said so.

**Since the previous version of this report:** payroll approval is open on the
ruled PAYE (§9); employer contributions post and remit; a hex-literal gate and a
deposit door exist; the inert controls are tabled and fourteen of them are live.
`docs/RELEASE_NOTES.md` is the short version, and it leads with the one change
residents will notice first — amenity blocking is now on in every estate.

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
| **Gates** | `gate:console`, `gate:interactivity`, `gate:isolation`, `gate:ledger`, `gate:assumptions`, `gate:tokens` |
| **Decisions** | 88 recorded, each with its reasoning, reversibility and whether a client must confirm |

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
php artisan gate:assumptions      # every ASSUMPTION marker has a question, and back
php artisan gate:console          # navigation and route gating follow the matrix
php artisan gate:interactivity    # nothing looks interactive and does nothing
php artisan gate:isolation        # tenant isolation and append-only, at the database
php artisan gate:ledger           # debits equal credits, sub-ledgers tie, posted is immutable
php artisan gate:tokens           # no hex colour outside tokens.css and its documented allowlist

php artisan test                  # 397 tests, on MySQL, not SQLite
vendor/bin/phpstan analyse        # Larastan level 6
vendor/bin/pint --test
php artisan fidelity:check        # all 85 screens against their boards
```

The six gates exit non-zero on failure and print every assertion, passed or
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

## 4. Fidelity — 79 of 85 under 2%

`php artisan fidelity:check` drives a real browser to each screen as the role
that board's own sidebar footer names, screenshots it at the board's viewport,
and pixel-diffs it against the approved wireframe. Estate screens are clipped to
`.main-col` on both sides, for the reason in D-044: every estate board draws the
same ten sidebar items whatever persona it names, including modules that
persona's role is locked out of, so the sidebar belongs to no role. It is
asserted against the permission matrix in `EstateNavigationTest` instead — a
test, not a picture.

**Six screens sit above the 2% bar. None was trimmed to get under it, and each
has its cause recorded rather than described as "close enough".**

| Screen | Diff | Cause | Recorded |
| --- | --- | --- | --- |
| community-admin-34 Add Resident | 5.52% | A biometric consent control the board does not draw, plus a pronoun change | D-060 |
| super-admin-07 Client Health | ~4–6% | Two real clients where the board draws three, two of them illustrative and forbidden to seed (D-038). **Not a constant** — two of its seven bars are time-windowed metrics, so the figure moves with the clock | D-066, D-079 |
| community-admin-32 Notices | 3.52% | The board draws its composer mid-compose, with text typed into it. Down from 5.02% — its Elections tab is a link now | D-065 |
| community-admin-10 Nominations Review | 3.37% | Required invariant text the board has no room for | D-059 |
| community-admin-15 Pay Run Approval | 2.71% | The board draws the pre-ruling PAYE cliff and no acknowledgement checkbox; the screen draws the ruled band and the first-live-run tick. **Needs the board redrawn**, with 13 and 16 | D-082, D-086 |
| community-admin-24 Role Access Matrix | 2.30% | The matrix in the model has 13 modules and 7 roles; the board drew 10 and 6 — and two cells are now Full · Approver where it drew Full | D-057, D-086 |

Three of those six are the same story and it is worth stating plainly: **the
board is an illustration and the permission model is the product** (D-044).
Board 24 draws a Property Manager with View on Dues & ledger; Ruling 1 (D-010)
locks that role out of it, and the seeder throws rather than granting it. The
pixels disagree because the pixels are wrong, and closing that gap would mean
handing a resident's financial position to the person who commissions the work.

Two are honest excess: a control the client asked for after the board was
drawn, and a board photographed mid-interaction. The sixth is the ruling itself:
board 15's PAYE column is the arithmetic the client overruled, and the screen is
right to disagree with it until the board is redrawn.

**One screen is not in the table because no board draws it.** The amenity
booking detail — the deposit door — was added on the client's ruling and is
recorded as a deliberate addition in D-086. It borrows board 18's shapes from
the same stylesheet, so it reads as part of its module; it has no wireframe to
be measured against.

**One of the five is not a fixed number, and that is worth knowing before you
re-run the harness.** super-admin-07 draws Guard App coverage over "the last 7
days" and Visitor passes over "the last 30 days", counted from real events. The
clock moves, events fall out of those windows, the bar fills change length, and
the diff changes with them. It read 4.16% on one day and 5.42% on the next with
no code change between — I spent two diagnostic passes hunting the regression
before recognising it (D-079). The other four residuals are text and layout, and
are stable.

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
claim approval, meeting publication, and now keeping a resident's deposit — so
a role may prepare one without being able to commit it. Board 15's own banner
asks for a second approver; `Payroll::approvalRefusal()` enforces that the
preparer is not the approver, which no middleware can express.

**7 · Every displayed money total is traceable to posted journal lines.** In a
test, per screen, not by inspection.

**8 · No colour is written as a hex literal outside `tokens.css`** (D-085).
`gate:tokens` scans every component, stylesheet, view and PHP file and fails on
a literal outside a documented allowlist that prints its reasons on every run.
Ten components carried literals that matched tokens exactly, which is the
dangerous kind: change the token and each keeps the old colour, silently.

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
| The 102 controls still inert | Reviewed one by one, as ordered, not bulk-fixed. `docs/reports/INERT_CONTROLS.md` tables every one with its reason, its status and the effort to make it live; fourteen were made live in the review (D-087) |

**Two estates are illustrative and are never seeded** — Emerald Heights and
Coral Bay (D-038). They appear in wireframes as examples of clients; seeding
them would put two fictional communities into a production-shaped database.

---

## 9. Q-002 — ruled, and what the ruling changed

### The ruling

"PAYE is 25% on the amount ABOVE the threshold. A band on the excess, never a
flat rate on the whole. Your calculation was correct. The sample payslips are
wrong. Unblock payroll." (D-082)

The board's arithmetic is now identified, not merely narrowed: TAJ's 2026
**fortnightly** threshold, J$73,234.90, applied to monthly pay as a cliff on the
whole. On the ruled cards, the same four staff:

| employee | board PAYE | ruled PAYE |
| --- | ---: | ---: |
| Patricia Morgan | 42,928 | 5,230 |
| Neil Anderson | 22,044 | 0 |
| Wayne Thomas | 20,420 | 0 |
| Simone Clarke | 0 | 0 |

The board withholds **J$85,392 a month** where the ruling asks **J$5,230**:
**J$80,162 a month, J$961,944 a year** taken off four people who would then have
to reclaim it. The earlier figure in this report (J$7,362.50) was computed
against the annual threshold divided by twelve; TAJ's published monthly figure
is J$158,530.00, not J$158,530.00 by coincidence — the periodic figures are
stored, never derived, because the fortnightly one does not divide out.

### What the rate card holds now

- **Two cards a year.** The threshold changes on 1 April; income tax is assessed
  on a calendar year. January–March 2026 is the 2025-04 card (J$1,799,376;
  monthly 149,948.00), April–December the 2026-04 card (J$1,902,360; monthly
  158,530.00). `StatutoryRateVersion::forPayDate()` selects on the period end
  and the card is stored on the run.
- **TAJ's periodic figures as columns.** Division survives only as the fallback
  for a card with none recorded — and such a card is seeded unverified, so a
  derived threshold can never reach an approved payslip.
- **The 30% band** on chargeable income above J$6,000,000 a year.
- **The NIS ceiling rounded, not truncated** — 416,666.67 a month, where
  truncation gave 416,666.66.
- **The provisional card superseded, not deleted or edited.** Pay runs point at
  it; a `superseded_at` flag keeps it readable and out of the selector.

### Both worked payslips are golden tests, to the cent

Patricia Morgan, J$185,000.00: PAYE 5,230.00, net 166,482.37, employer cost
22,930.75. Simone Clarke, J$72,000.00: PAYE nil, Education Tax 1,571.40, net
66,828.60, employer cost 8,924.40. Plus the January–March variants (Patricia
7,375.50, Simone nil) that prove the selector chose the other card. Clarke's is
the one that matters: nil PAYE beside non-nil Education Tax proves the threshold
is a threshold, PAYE is a band, and Education Tax ignores the threshold.

### Employer contributions — D-072 unblocked

"Employer contributions go on the monthly S01, alongside employee deductions.
Post: Dr Employer statutory contributions / Cr Statutory payables. On
remittance: Dr Statutory payables / Cr Bank." (D-083)

Account 5010 Employer Statutory Contributions is seeded and inserted by
migration into every existing estate. Each payslip line stores the employer's
NIS, NHT, Education Tax and HEART; approval posts one balanced entry in four
lines; the S01 carries both halves and filing remits both, so 2100 returns to
nil. HEART is new with the ruling — 3% of gross where the monthly payroll
exceeds a floor that defaults to zero (Q-016).

### The caveat, carried as ruled

These figures come from TAJ's publications and the client's ruling on method,
and they reconcile to the cent. **They have not been countersigned by the
client's accountant.** So the first live pay run in each payroll — each estate's
and Gemini's own — asks its approver to tick a sentence naming the rate card it
was reconciled against. Not a block: a tick, refused server-side without it,
recorded on the run with the name and the card, and never asked again. Seeded
history does not count as a live run.

### Still waiting on you

Three points the ruling sent to the accountant, each behind a marked default:

| # | Question | Default meanwhile |
| --- | --- | --- |
| Q-016 | The HEART payroll floor | Zero — HEART applies to every payroll |
| Q-017 | Does an approved pension scheme apply? | No pension; statutory income is gross less NIS |
| Q-018 | Where is the 30% band measured from? | Chargeable income — statutory income less the threshold |

And two things the ruling changed on screens without changing their boards:
**boards 13, 15 and 16 need redrawing** (§4), and **the two derived Approver
cells on Facilities** (D-086) are mine to propose and yours to confirm.

### Q-008 to Q-015 — all eight now ruled

**Ruled after this report was first written (D-075 to D-077).** Q-010, Q-011 and
Q-012 to Q-015 confirmed the defaults below as they shipped. Two overturned them:

- **Q-008** — one arrears threshold across the estate, not two. The amenity
  module's own pair of settings is dropped and it reads the gate's. A payment
  plan lifts it, same as the gate. Consequence: the block was off and the gate
  restriction is on, so amenity blocking is now on for every estate.
- **Q-009** — my no-journal default was **wrong**. A deposit is a liability and
  posts: Dr Bank / Cr Deposits Held, reversed on refund, Dr Deposits Held / Cr
  Amenity Income on forfeit. Never receivables, never dues. Account 2200 had
  been in the chart since board 25 and read zero while three bookings said
  "held". **And the door is built** (D-086): the ruling put the control on a
  booking detail screen no board draws — Facilities update takes and refunds a
  deposit, Facilities approve forfeits one with a required reason — so the
  service that moved money with no route now has one, gated as ruled.

Q-010 also lost `meeting_notice_enforced`: the periods are configurable, the
refusal is not. Q-011's threshold moved 0 → 6 months and its flag left
`$fillable`, so it can never be enabled by a form posting one extra key.

The table below is what was assumed while they were open, kept because it is
what a reader needs when they ask why a threshold is 90 or a flag is off.

Each has the safest option applied behind a named flag, marked
`// ASSUMPTION Q-0xx` in the code, with the test that asserts the assumption
already written. A ruling is a change to what one test expects.

| # | Question | Assumed meanwhile |
| --- | --- | --- |
| Q-008 | How far into arrears is an amenity booking blocked? | Block ships **off**; where switched on it defaults to the same 90 days the gate uses, so the two cannot disagree by accident |
| Q-009 | Who posts the cash side of an amenity deposit? | Facilities records the **state** and raises no journal at all. Moving cash is a treasury act behind `payments` |
| Q-010 | What are this estate's statutory meeting notice periods? | Enforced **on**, AGM **21 days** — the longer of the two Jamaican readings — EGM 14, committee 7 |
| Q-011 | Is there a minimum tenure before standing for the committee? | Check ships **off**, threshold zero. An unrecorded tenure never disqualifies anybody |
| Q-012 | May an estate switch its own dues to a card gateway? | `payment_gateway_mode` = `manual`, not fillable, drawn locked with the reason |
| Q-013 | Which arrears-restriction settings may an estate change? | The module reads none and writes none. D-024's values stand |
| Q-014 | May a role locked out of the ledger be told a unit is in arrears? | The ageing bucket shows only to a viewer holding `estate.dues_ledger.view` |
| Q-015 | May staff give biometric consent on a resident's behalf? | Control ships off; enrolment refuses without consent; whether the control belongs on that screen at all is the open part |

**The convention is now checked rather than observed (D-078).** `php artisan
gate:assumptions` fails the build when an `// ASSUMPTION Q-0xx` marker has no
entry in `QUESTIONS.md`, and when an entry has no marker in the source — both
directions, at the client's instruction. On its first run it found five more
gaps in the reverse direction: Q-003, Q-004, Q-006, Q-009 and Q-015 were asked
in the queue and marked nowhere, so a reader at the code had no way to know which
line a ruling had landed on.

**The first four were found late, and how is worth recording (D-074).**
The project's convention is that an undecided rule sits behind a flag, the code
carries `// ASSUMPTION Q-0xx`, and `QUESTIONS.md` carries the entry a client
rules on. The two halves had never been checked against each other. Grepping the
markers found Q-008 through Q-011 live in the source — one of them referenced by
three docblocks reading "see QUESTIONS.md Q-009" — and **none of the four in
`QUESTIONS.md` at all.** The file went Q-007 then jumped to Q-012.

Every one falls inside that file's own admission rule: restriction, money,
voting. The defaults are all safe and all sensible, and that is not the point —
good defaults nobody was told about are still decisions taken on your behalf.

Two of them also pointed at tests that did not exist. **Governance had no test
file of any kind** — one of the four hard-stop categories, carrying two live
assumptions, with nothing asserting the notice-period refusal or the tenure
check. `EstateGovernanceTest` is new: 13 tests, green on its first run, which is
the good outcome rather than evidence it was unnecessary.

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

- **MySQL 8.4 on port 3307 is STILL NOT A SERVICE, and it needs one command from
  you.** Registering one requires an elevated shell; every shell this work ran in
  was unelevated, and `mysqld --install` answers *"Install/Remove of the Service
  Denied!"*. **It will not survive a reboot.** In an **Administrator** PowerShell:

  ```powershell
  & "C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqld.exe" --install MySQL84 --defaults-file="C:\ProgramData\MySQL\MySQL Server 8.4\my.ini"
  Start-Service MySQL84
  Set-Service MySQL84 -StartupType Automatic
  ```

  The `--defaults-file` is the one the running process is already using, so the
  service comes up on 3307 with the same configuration rather than a default one.

- **Reverb is running** on `127.0.0.1:8080`, started with
  `php artisan reverb:start`. It is a foreground process, not a service either —
  the same reboot caveat applies, and the same fix would be `nssm` or a scheduled
  task. Without it the dispatch screens still work: the poll continues at its
  unthrottled interval, which is what the adaptive design is for.
- **Redis/Memurai absent.** `CACHE_STORE=file`, `QUEUE_CONNECTION=database`.
- **Local serves both consoles from one host** with the estate in the path
  (`/estate/phoenixpark/...`), because `*.localhost` does not resolve on Windows
  and a second registrable domain breaks the session cookie. Production gives
  each estate its own subdomain. Nothing in the application knows which shape it
  is serving.

---

## 12. Where to look

| Question | File |
| --- | --- |
| Why is it built this way? | `DECISIONS.md` — 88 entries, each with reasoning and reversibility |
| What is still unanswered? | `QUESTIONS.md` |
| What changed in this release, for the client? | `docs/RELEASE_NOTES.md` |
| Which controls are inert, and what would it take? | `docs/reports/INERT_CONTROLS.md` |
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
| 05 | Client Detail — Phoenix Park | 1.25% | | 28 | Payroll Overview | 0.76% |
| 06 | Change Client Plan | 1.05% | | 29 | Payslip Detail — September 2026 | 1.72% |
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
| 22 | Add Guard | 0.62% | | 45 | Role Access Matrix | 0.81% |
| 23 | Guard Profile — Devon Palmer | 1.17% | | | | |

### Estate Console — 40

| # | Screen | Diff | | # | Screen | Diff |
| --- | --- | ---: | --- | --- | --- | ---: |
| 01 | Login | 0.12% | | 21 | Settings — Estate Profile | 0.20% |
| 02 | Dashboard | 0.83% | | 22 | Settings — Users & Roles | 1.93% |
| 03 | Estate Structure | 0.06% | | 23 | Feature Toggle Panel | 0.05% |
| 04 | Residents | 0.92% | | **24** | **Role Access Matrix** | **2.30%** |
| 05 | Arrears Command Centre | 1.99% | | 25 | Chart of Accounts | 1.39% |
| 06 | Unit Ledger — Lot 47 | 0.03% | | 26 | Vendors | 0.62% |
| 07 | Place on Payment Plan | 0.36% | | 27 | Bills & Payments | 0.84% |
| 08 | Dunning Log & Templates | 0.41% | | 28 | Bank Reconciliation | 1.61% |
| 09 | Election Control Room | 0.40% | | 29 | Reports | 0.00% |
| **10** | **Nominations Review** | **3.37%** | | 30 | Settings — Notification Defaults | 0.87% |
| 11 | Results & Certification | 0.48% | | 31 | Unit Claim Review | 0.16% |
| 12 | Meeting Scheduler | 1.57% | | **32** | **Notices** | **3.52%** |
| 13 | Payroll Run List | 0.21% | | 33 | Settings — Data & Privacy | 0.12% |
| 14 | Pre-Run Exceptions | 0.90% | | **34** | **Add Resident** | **5.52%** |
| **15** | **Pay Run Approval** | **2.71%** | | 35 | New Charge | 1.79% |
| 16 | Statutory Filings | 1.44% | | 36 | Meetings | 0.64% |
| 17 | Maintenance Queue | 1.57% | | 37 | Payroll — Employees | 0.02% |
| 18 | Ticket Detail — #1042 | 0.15% | | 38 | Resident Detail — Andrea Fletcher | 0.07% |
| 19 | Amenity Bookings | 0.80% | | 39 | Vendor Detail — Island Electric | 0.73% |
| 20 | Amenity Settings | 0.00% | | 40 | Billing & Subscription | 0.28% |
| — | Amenity Booking Detail | no board (D-086) | | | | |

**79 of 85 under 2%.** The six in bold are §4.
