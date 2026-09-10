# Question Queue

Parked hard-stop items only — money, restriction, biometrics, voting.
Surfaced once per phase boundary, alongside the phase report.

**All questions raised through Phase 2 have been answered.** The rulings are
recorded in `DECISIONS.md` as D-020 to D-026 and are implemented, not merely
noted. The open items below are Phase 5's.

---

## Open — Phase 5, Estate Console settings

### Q-012 · May an estate switch its own dues payments to a card gateway?

**What is needed:** D-023 settled that dues are recorded manually on day one and
that card capture sits behind the `PaymentGateway` interface. It did not settle
*who decides* when an estate starts taking cards — the community, from its own
feature panel, or Gemini Security, as a commercial change to the subscription.

**Why it blocks:** it is a money control on a resident-facing flow. An estate
that switches it on before a gateway exists offers residents a card form that
talks to nothing, and a resident who believes they have paid is a resident about
to have their guest passes restricted for arrears they thought were settled
(D-024, D-025).

**Assumed meanwhile — the safest option, behind a named flag:**
`estate_settings.payment_gateway_mode` defaults to `manual`, is absent from the
model's `$fillable`, and is drawn on board 23 under "Held back in this release"
as locked off with the reason on it. `Settings::setFeature()` refuses the key.
Marked `// ASSUMPTION Q-012` in the settings migration.

**What breaks if the assumption is wrong:** nothing that has to be undone. If
the ruling is that an estate may choose, the column and the row already exist
and the change is a route gate plus a fillable entry.

**The test that will assert the real rule:** `EstateSettingsTest` — "it ships
biometric consent off, dues payment manual and geofencing deferred, and offers
no way to change any of them" asserts the flag's value and the refusal; it
becomes the assertion of the ruling by changing what it expects.

### Q-013 · Which arrears-restriction settings may an estate change from its own console?

**What is needed:** `estate_settings` already holds
`arrears_restriction_days` (90), `arrears_notice_days` (14) and
`arrears_restriction_enabled` — the client's own defaults under D-024, and the
Build Spec calls them estate-configurable. None of boards 21 to 24 draws any of
them. So it is unstated whether an estate administrator may change them from
this console, and in particular whether `arrears_restriction_enabled` may be
switched off from a settings screen.

**Why it blocks:** it is a restriction control. Switching restriction off, or
widening the threshold from 90 days to 900, changes who gets through a gate on a
Friday night for several hundred households, and it does so without any of the
notice the policy is built around.

**Assumed meanwhile — the safest option:** the settings module READS none of
them onto a screen and WRITES none of them. `SettingsController::saveProfile()`
validates a fixed three-key allowlist, and the four boards this wave built draw
no arrears field. The values remain what D-024 set them to. Marked
`// ASSUMPTION Q-013` in the settings migration.

**What breaks if the assumption is wrong:** nothing stored. If the ruling is
that an estate may change them, the fields already exist on the model and the
work is a form group on board 21 plus a widened allowlist.

**The test that will assert the real rule:** `EstateSettingsTest` — "it holds no
column that could switch off a residents own entry or an emergency vehicle"
already asserts the two exemptions are not settings and that the two thresholds
read 90 and 14; a ruling that opens them adds the route assertion beside it.

---

## Open — Phase 5, Estate Console residents

### Q-014 · May a role locked out of the ledger be told that a unit is in arrears at all?

**What is needed:** board 31's third claim card prints, in its On-record column,
"Tanya Simms — 90+ days arrears". The persona named in that board's own sidebar
footer is Patricia Morgan, **Property Manager** — the role D-010 locks out of
`dues_ledger`, `payments`, `accounting_posting` and `payroll` on the principle
that "whoever commissions work must never be able to pay for it, nor see a
resident's financial position". Both cannot be true. An ageing bucket **is** a
financial position: "90+ days" is a label from the receivables ageing that feeds
the arrears report, and it is the same derivation, from the same ledger.

**Why it blocks:** it is a money control, and the ruling decides what a role
sees rather than merely where a screen puts it. Read one way the bucket is a
risk flag beside "Verify identity before approving" and belongs to whoever
reviews claims; read the other way it is a resident's financial position on a
screen a locked-out role opens every day.

**Assumed meanwhile — the safest option, behind a named flag:**
`Residents::ARREARS_FLAG_NEEDS_LEDGER_ACCESS` stands at `true`. The bucket is
appended to the on-record name only for a viewer holding
`estate.dues_ledger.view`; a Property Manager reviewing the same claim sees the
identity flag and the claimant's own details, and nothing about money. Marked
`// ASSUMPTION Q-014` on the constant.

**And one thing the ruling cannot reopen:** a RESTRICTED household shows the
bucket to nobody, whatever this is decided to be. D-025 fixed that and D-055
implements it — amber and the words, never the balance and never the days —
and the flag above sits behind that refusal rather than beside it.

**What breaks if the assumption is wrong:** nothing stored. No column holds an
ageing bucket; it is derived per request. A ruling that the flag is a risk
signal deletes three lines in `Residents::recordNameWithStanding()` and the
constant with them.

**The test that will assert the real rule:** `EstateResidentsTest` — "it keeps
the ageing flag on a claim behind ledger access while Q-014 stands open" asserts
the constant's value and then both readings of the card, so a ruling either way
is a change to what that one test expects.

---

## Answered and closed

| # | Question | Ruling | Recorded |
| --- | --- | --- | --- |
| Q-001 | Currency | JMD, `J$`, two decimals, `en_JM`. USD is a display toggle on subscription pricing only, never stored | D-020 |
| Q-002 | Statutory rates | Keep blocked. Seed as `2026-04-DRAFT`, approval disabled with the reason on screen | D-021 |
| Q-003 | Biometric consent | Flag off, derived score only, draft copy marked draft | D-022 |
| Q-004 | Payment gateway | Manual recording day one, card capture behind the adapter | D-023 |
| Q-005 | Arrears threshold | 90 days, 14-day written notice, guest passes only, Property Manager override with recorded reason, estate-configurable | D-024 |
| Q-006 | Restricted-household UI | "Access restricted — contact management". Amber verdict state on the scan verdict screen. No amount, no wording implying money | D-025 |
| Q-007 | Seventh verb | `view`. Confirmed | D-026 |

---

## How to raise a new one

A question belongs here only if it is a **hard stop**: money, restriction,
biometrics or voting, and only when a decision would change stored data or
committed behaviour. Everything else is decided, recorded in `DECISIONS.md`,
and built.

Each entry states: what is needed, why it blocks, what has been assumed
meanwhile, and what breaks if the assumption is wrong. Affected code carries
`// ASSUMPTION Q-0xx` so a ruling can be applied in one pass.

### Q-015 · May a member of staff give biometric consent on a resident's behalf?

**What is needed:** board 34's Add Resident form now carries a biometric consent
checkbox, ticked by whoever is filing the household — a Property Manager, on the
board's own persona. Its note reads "Off unless the resident says otherwise.
Biometric enrolment is refused without it, and no estate setting grants it on
anybody's behalf." Those two sentences are in tension: a manager ticking the box
IS granting it on somebody's behalf, and the person it binds is not in the room.

**Why it blocks:** biometrics is one of the four hard-stop categories. Consent
recorded by the wrong party is not consent, and a fingerprint enrolled against it
cannot be un-enrolled from the person it belongs to.

**Assumed meanwhile — the safest option:** the control ships **off** and nothing
turns it on but an explicit tick, `Residents::enrolBiometrics()` refuses without
it, and `biometric_consent.default` is false. So the current behaviour is safe in
the sense that nothing happens by accident. What is unsettled is whether the
control should be on this screen at all, rather than collected from the resident
in the Resident App at enrolment.

**What breaks if the assumption is wrong:** nothing stored — no estate has
enrolled anybody, because D-022 keeps the feature off entirely. If the ruling is
that consent must come from the resident, the checkbox is removed from board 34
and the field is written by the resident-app enrolment flow instead, which is
where D-022 already expects it to live.

**The test that will assert the real rule:** `EstateResidentsTest` — "it refuses
biometric enrolment without consent" asserts the refusal today; a ruling that
consent may only come from the resident adds an assertion that no staff-facing
route can set the flag.

---

## Open — Phase 5, payroll. Q-002 restated, and now specific.

### Q-002 (restated) · Your board's PAYE column and the law disagree. Which is right?

Q-002 was "the accountant owes us two worked payslips either side of the PAYE
threshold". It can now be asked far more precisely, because board 15's own
figures answer half of it.

**What the board draws, against what the rates produce:**

| employee | board PAYE | lawful PAYE |
|---|---|---|
| Patricia Morgan | 42,928 | 7,363 |
| Neil Anderson | 22,044 | 0 |
| Wayne Thomas | 20,420 | 0 |
| Simone Clarke | 0 | 0 |

NIS, NHT and Education Tax match your board **to the cent** on all four people,
so the rate card is not in question — 3%, 2% and 2.25% are agreed. Only the PAYE
step differs.

**The board's column is 25% of (gross − NIS − NHT − Education Tax), charged on
the whole and nil below a cliff.** That one rule reproduces all four staff — the
fourth is not an exception, she is under the cliff. Two departures from the rule
as we understand it, and they compound:

1. PAYE is charged on **statutory income**, which is gross less NIS only. NHT
   and Education Tax are not deductible against it.
2. It is charged on the **excess** above the annual threshold, not on the entire
   amount once the threshold is passed.

**Where the cliff sits is narrowed, not identified.** Wayne Thomas is charged on
a base of 81,679.40 and Simone Clarke is not charged on 66,828.60, so it lies
between them — and **four** divisors of the annual threshold land in that window:
23 (78,260.86), 24 (75,000.00), 25 (72,000.00) and 26 (69,230.76). The
fortnightly 26 is the natural reading and is what has been reported, but four
observations cannot separate it from the other three. Clarke's worked payslip is
the single thing that settles it, which is why she is on the list.

**Why it matters in money:** reproducing the board would have this platform
withhold **J$85,392 a month** across four staff where the rule as we read it asks
**J$7,362.50** — a difference of **J$78,029.50 a month**, roughly **J$936,000 a
year** taken off four people who would then have to reclaim it.

Two multiples can be read off those figures and they are easy to swap: Patricia
Morgan's own column overstates by **5.8×**, and the run total by **11.6×** — the
latter larger only because three of the four are charged tax they do not owe at
all. Client-facing wording quotes the money for that reason.

### Q-002 (second half) · Do employer contributions belong on the S01?

Found while making the request above answerable, and it is a separate gap in the
same module.

The rate version has carried `nis_employer_bp` (3%), `nht_employer_bp` (3%) and
`education_tax_employer_bp` (3.5%) since the schema was written. **No line of
code read any of them.** `PayrollCalculator::employerCost()` now computes them —
and posts nothing, deliberately.

For the same four staff, one month:

| | monthly |
| --- | ---: |
| Employer NIS | 13,200.00 |
| Employer NHT | 13,200.00 |
| Employer Education Tax | 14,938.00 |
| **Employer total** | **41,338.00** |
| Employee deductions, as remitted today | 38,965.51 |

If employer contributions belong on the monthly S01, the return as currently
computed remits **less than half** of what is owed — about **J$496,000 a year**
for four staff.

**Assumed meanwhile — the safest option:** compute and expose, change nothing.
`Payroll::approve()` still debits gross to 5000 and credits the employee
withholdings to 2100, exactly as before; `StatutoryFiling::deductionsMinor()`
still sums the four employee columns. Closing the gap needs a new expense
account, an accrual against 2100 and a larger S01 — three changes to an estate's
books that are not ours to make on a ruling nobody has given. The employer
Education Tax base is itself assumed to be statutory income, matching the
employee side; on gross it is 15,400.00 rather than 14,938.00. Marked
`// ASSUMPTION Q-002` in `PayrollCalculator::employerCost()`.

**What breaks if the assumption is wrong:** nothing stored, and no posted
journal changes. The rates were always there; only the reading of them is new.

**The test that will assert the real rule:** `PayrollGoldenPayslipTest` — the
golden fixture carries an employer figure per employee, so an accountant's
worked slip lands beside the employee half and is checked the same way.

**What we have done meanwhile:** the application uses the lawful calculation.
Approval of a pay run remains **blocked** while the rate version is marked
`2026-04-DRAFT`, with the reason shown on the control — D-021 unchanged. Nothing
has been disbursed on the strength of either figure.

**What we need from the accountant, and it is now a smaller ask than before:**

1. One worked payslip for a monthly salary **above** the threshold — Patricia
   Morgan's J$185,000 would do — showing the PAYE figure and the steps to it.
2. One **below** it — Simone Clarke's J$72,000. **The more important of the
   two**, because where the cliff sits is what her slip pins down.
3. The employer contributions for each, separately from the employee
   deductions, and the income the employer Education Tax is charged on.
4. The rate version those two were computed against, and the pay period.

**The message to forward is `docs/reports/Q-002_PAYROLL_CONFIRMATION.md` §1.**
It is written to be sent as it stands — no decision numbers, no file paths — with
the working behind it in §2 for whoever fields the reply.

If your accountant's figures come out as the board draws them, tell us and we
will change the calculator and record why. If they come out as we have them, the
board's PAYE and Net columns are wrong and boards 13, 15 and 16 need redrawing —
we have not touched them.

**The test that will assert the real rule:** `PayrollGoldenPayslipTest`, against
`tests/Fixtures/golden-payslips.php`. Type the accountant's figures into the
fixture, set `confirmed => true`, and run it: either it is green and the
calculation is confirmed by somebody with the authority to confirm it, or it
names the exact field and both values — `Simone Clarke · paye_minor` — so the
disagreement is a number rather than an argument.

`EstatePayrollTest` keeps the structural half — "it computes PAYE on statutory
income above the threshold, not on the whole" — and that is deliberate. A ruling
is entitled to change the figures. It must not be able to change the order the
deductions come off in, or turn the threshold back into a cliff, by being pasted
over the wrong file.

**Approval does not unblock from the fixture.** It reads
`statutory_rate_versions.is_verified`, which is a separate deliberate write.
Agreeing a calculation and authorising money to leave a bank are two decisions.
