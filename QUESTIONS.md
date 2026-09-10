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
