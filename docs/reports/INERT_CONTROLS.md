# Inert controls — the review the client asked for

> "The 85 inert controls — review, do not bulk-fix. Produce a table: control,
> screen, reason, and estimated effort to make it live. Anything under an hour
> that is not genuinely out of scope, build. The rest stay inert with their
> reason." — Client ruling, Part D

**85 is the screen count, not the inert count.** `gate:interactivity --table`
counted **115** inert controls in the source when this review began — every
element that is permanently disabled and carries a reason, across 64 screens.
One more is inert through a computed binding the gate cannot see (the unit
ledger's "Record manual payment", listed below). This table covers all 116.

| Outcome | Controls |
| --- | ---: |
| **Built in this review** — the screen behind it already existed and the reason had gone stale | 9 |
| **Made live for roles that hold the gate** — inert twin kept, with a permission reason, for roles that do not | 5 |
| Already a permission branch — live for roles holding the gate, working as designed | 8 |
| Unreachable — the inert twin can never render today | 8 |
| Genuinely unbuilt, waiting on a decision, or waiting on an integration | 86 |
| **Total** | **116** |

The gate now counts **107**: the 115 minus the 9 built. The five converted
controls still count, because their inert twin is still in the markup for the
roles the gate refuses.

Effort is my estimate to make a control live, including its tests, on the
patterns already in the codebase. It is not a quote, and it does not include
the client decisions some of these are waiting on — those are named.

---

## 1. Built in this review — 9

Every one was a control whose reason said "not built" about a screen that had
since been built. A stale reason is a small lie, and these were each well under
an hour.

| Control | Screen | Was | Now |
| --- | --- | --- | --- |
| View | community-admin-19, Amenity bookings | "a booking detail screen … is not on any approved board" | Opens the booking detail, the new deposit-door screen (D-086) |
| Rate table (tab) | super-admin-28, Payroll & accounting | "Not built yet — the statutory rates the engine calculates from" | Link to `/payroll/rates`, which its two sibling tabs already linked |
| Place on payment plan | community-admin-06, Unit ledger | "needs its own screen, which is board 7" | Link to board 7 for this unit |
| Send reminder now | community-admin-06, Unit ledger | "… Board 8." | Link to board 8, where a notice is chosen, sent and logged verbatim |
| Dunning log (tab) | community-admin-06, Unit ledger | "… Board 8." | Link to board 8 |
| Payment plan (tab) | community-admin-06, Unit ledger | "needs its own screen, which is board 7" | Link to board 7 for this unit |
| Notices (tab) | community-admin-09, Election control room | "the one governance screen this phase does not deliver" | Link to board 32 |
| Notices (tab) | community-admin-36, Meetings | same | Link to board 32 |
| Elections (tab) | community-admin-32, Notices | "An election is addressed by its year. Open it from the Meetings tab" | Link to the latest election, by the rule board 36's own tab uses — now one method, `Governance::electionYear()` |

## 2. Made live for roles that hold the gate — 5

The screen existed, but its gate is not the gate of the screen the control
sits on. Each is a link for a viewer holding the target's gate and an inert
twin with a permission reason for everyone else — never a link that answers
403.

| Control | Screen | Gate asked | Who it stays inert for |
| --- | --- | --- | --- |
| Work order (bill reference) | community-admin-27, Bills & payments | `facilities.view` — board 18 | Nobody who can read Accounting today; the twin is for a future role that can |
| Vendors (tab) | community-admin-17, Maintenance | `accounting_posting.view` — board 26 | Property Manager (D-010) |
| Vendors (tab) | community-admin-19, Amenity bookings | same | Property Manager (D-010) |
| Post a notice | community-admin-04, Estate dashboard | `governance.create` — board 32 | Property Manager, Treasurer, Admin Assistant |
| Add guard | super-admin-19, Guard workforce | `guard_workforce.create` — board 22 | Every role without create on the roster |

## 3. Already a permission branch — 8

Not unbuilt: live for roles holding the gate. Listed because the gate counts
their twins.

| Control | Screen | Reason shown to a role without the gate |
| --- | --- | --- |
| View ledger | community-admin-04, Estate dashboard | The arrears panel is Dues & ledger's screen and needs its view access |
| Add resident | community-admin-04, Estate dashboard | Needs Residents create access |
| New charge | community-admin-04, Estate dashboard | Needs Dues & ledger create access |
| New charge | community-admin-05, Arrears command centre | Needs Dues & ledger create access |
| Add resident | community-admin-29, Residents | Needs Residents create access |
| Review now | community-admin-29, Residents | Needs Residents approve access |
| Open these officers' workforce records | super-admin-06, Client guards | Guard workforce is not part of the role's access |
| View billing history | super-admin-05, Client record | Billing & subscriptions is not part of the role's access |

## 4. Unreachable — 8

| Control | Screen | Why it cannot render |
| --- | --- | --- |
| Back to the arrears list (disabled twin) | community-admin-06, Unit ledger | The route is gated on the same permission as the nav item it reads its href from |
| Settings sub-navigation twin × 7 | community-admin-21 to 24, 33 to 35 | All seven settings sections now have screens and hrefs. Their three stale "not built yet" sentences were removed from `sections.js` in this review |

## 5. Genuinely unbuilt, waiting on a decision, or waiting on an integration — 86

Status key: **U** unbuilt; **D** needs a client decision first; **X** needs an
external integration; **B** inert by design.

### Estate console

| Control | Screen | Reason (as the screen gives it, shortened) | Status | Effort |
| --- | --- | --- | --- | --- |
| Forgot password? | Sign-in | A reset link is a way into an account: single-use token, expiry and a verified address first | U | 4–6 h, plus outbound mail |
| Record bill | community-admin-27 | Needs the expense account and work order chosen deliberately — a form | U | 1 day |
| View receipt | community-admin-27 | A receipt is a document the supplier keeps: template and retention rule | D | 1–2 days |
| Add account | community-admin-25 | Changes what every future entry can post against; needs a review step | U | 4–6 h |
| Import statement | community-admin-28 | Parsing a bank's own file format; a mis-parsed line is a false match | U | 2–3 days per bank format |
| Record new bill | community-admin-39 | Same form as Record bill | U | with Record bill |
| Edit vendor | community-admin-39 | Changes who the estate may pay; TRN rule stated on its own screen | U | 4–6 h |
| Add vendor | community-admin-26 | TRN, trade and contact captured together | U | 4–6 h |
| Notifications (bell) | community-admin-04 | A notification centre needs a read/unread model | U | 1–2 days |
| See all (activity) | community-admin-04 | A paginated read across the tables the panel merges | U | 4–6 h |
| Export | community-admin-05 | An arrears export names who owes what; needs a retention rule | D | 2–4 h once the rule is set |
| Charge schedule / Payment plans / Receipts (tabs) | community-admin-05 | Recurring run with preview and reversal; a register of every plan; a receipt numbering rule | U, U, D | 2–3 days; 4–6 h; 1–2 days |
| New template | community-admin-08 | A new dunning stage has legal weight: wording review first | D | 4 h after review |
| Save template | community-admin-08 | Changes the next notice and never the sent ones; the editor is board 8's write path | U | 4–6 h |
| Whole phase / Whole estate | community-admin-35 | Hundreds of entries at once: preview and confirmation first | U | 1–2 days |
| Record manual payment *(computed; not counted by the gate)* | community-admin-06 | A method, date and receipt number before an amount — the Receipts tab | D | 1 day after the numbering rule |
| Print statement | community-admin-06 | A document a resident keeps: template and retention rule | D | 1 day |
| Flag hardship / dispute | community-admin-06 | Changes what collection may do; needs a recorded committee decision | D | 1 day |
| Add amenity | community-admin-20 | Capacity, hours, fee and deposit captured together | U | 4–6 h |
| Edit (per amenity) | community-admin-20 | Must leave every confirmed booking exactly as it was; needs its own screen | U | 4–6 h |
| New work order | community-admin-17 | Starts an SLA clock: location, category and priority chosen deliberately | U | 4–6 h |
| Message resident | community-admin-18 | Opens a thread to a phone; belongs with the notices module | X | 3–5 days with a delivery adapter |
| Export minutes | community-admin-09 | The formal record of a meeting: template and retention rule | D | 1 day |
| Agenda / Minutes (row action) | community-admin-36 | The meeting detail and minutes screens are not in this phase | U | 1–2 days |
| View (nomination) | community-admin-10 | A nomination's own screen with the snapshotted ageing | U | 4–6 h |
| Export report | community-admin-11 | A signed certificate a returning officer stands behind | D | 1 day |
| Add employee | community-admin-37 | Bank details and NIS number: no consented intake yet | D | 1–2 days after consent is ruled |
| Compliance calendar | community-admin-16 | Every statutory due date for the year | U | 1 day |
| Export | community-admin-15 | Which format and for whom — a bank file or an accountant's summary | D | 2–4 h once decided |
| Start new run | community-admin-13 | Needs a payroll calendar of periods and close dates | U | 2–3 days |
| Generate (report cards × 4) | community-admin-30, Reports | P&L, arrears export, maintenance report, turnout — each unbuilt as an export | U / D | 4 h – 1 day each |
| Message household | community-admin-31 | Needs a template, a delivery adapter and a retention rule | X | with Message resident |
| Edit details | community-admin-31 | Changes who is authorised against a unit — its own screen | U | 4–6 h |
| View (invoice) | community-admin-33 | The invoice's line detail is a central Gemini record | U | 4–6 h |
| Save changes | community-admin-34, Data & privacy | Nothing on the screen is editable: statute, the matrix and Gemini's agreement | B | — |
| Upload / Replace logo | community-admin-21 | Printed on notices and receipts: storage and print rules | U | 4–6 h |
| Invite user | community-admin-22 and 24 | Issues a credential to somebody not yet a user | U | 1–2 days |
| Manage (user) | community-admin-22 | Changing a role changes what someone may do to this estate | U | 4–6 h |
| Import CSV | community-admin-38, Estate structure | Hundreds of addresses at once: preview and confirmation first | U | 1–2 days |
| Add phase / Add another phase | community-admin-38 | Blocks and lot range decided together — a form | U | 4–6 h |

### Gemini console

| Control | Screen | Reason (shortened) | Status | Effort |
| --- | --- | --- | --- | --- |
| Export | super-admin-41, Audit log | Not built; the whole log is readable and linkable | U | 4 h, and the export itself audited |
| Row action | super-admin-31, Billing | A row with no screen behind it; reason per row | U | per screen |
| Download PDF / Resend to client / Issue credit note | super-admin-33, Invoice | Resending emails the client; a credit note posts a financial record — approval path first | U / X | 1–2 days each |
| Payment method action | super-admin-32 | Card capture sits behind a payment gateway with no implementation (Q-012: held back) | X | out of scope by ruling |
| Notifications (bell) | super-admin-01, Dashboard | Arrives with the alert feed | U | 1–2 days |
| Message input / Dispatch a second guard / Escalate to JCF | super-admin-10, Alert | Guard App and JCF integrations do not exist | X | integration-bound |
| Alertness policy | super-admin-12 | A readout of the refresh policy, drawn as the board's button | B | — |
| Sub-navigation twins × 8 | super-admin-08, 09, 11, 12, 13, 17, 23, 26 and the settings tabs | Rendered only for a section with no screen; each carries its own reason | U | per screen |
| Today, {day} | super-admin-11, Coverage | Another day needs the forward roster | U | with the roster editor |
| Request history | super-admin-13 | Decided requests are audited and will get a screen | U | 4–6 h |
| Export | super-admin-17, Compliance | Licence register export | U | 4 h |
| Contact {guard} / Contact guard | super-admin-18 and 21 | No phone number or email is on the guard's record | B (data) | — |
| Post open shifts to cover | super-admin-18 | Posting a shift needs the roster | U | with the roster editor |
| Mark licence renewed × 2 | super-admin-18 and 21 | Needs the new expiry date; the renewal form is not built | U | 4–6 h |
| Reassign client | super-admin-21 | Ships with the shift roster | U | with the roster editor |
| Log incident / View | super-admin-25 | An incident record is evidence: structured intake first | U | 2–3 days |
| Post open shift | super-admin-23, Roster | The roster editor | U | 3–5 days |
| New order set | super-admin-24 | Changes what guards are instructed at a post: review and acknowledgement cycle | U | 2–3 days |
| Start new filing | super-admin-30 | Preparing a return is not implemented | U | 1–2 days |
| Employees (tab) × 3 | super-admin-28, 29, 30 | The people paid are the guards under Guard workforce | U | 1 day for a payroll view; 5 min to link Guard workforce, not done because it leaves the module |
| Open (report) | super-admin-35 | A report that is unavailable says why | U | 1 day each |
| Export | super-admin-36 to 40 | A cross-tenant export leaves the platform: audit trail first | D | 1 day |
| Save changes / Save package changes / line-item controls × 3 | super-admin-43, 44, 45 | Read only: a price change re-prices every client, so it needs an effective date and approval | U | 1–2 days |

---

## What was not done, and why

- **Nothing was bulk-fixed**, as ruled. Every build above was a stale reason
  over a screen that already existed; no new screen was added for an inert
  control.
- **Gemini's payroll "Employees" tab could link to Guard workforce in five
  minutes.** It was left, because the tab would lead out of the module it sits
  in — a design call, not a build.
- **Record manual payment** is inert through a computed binding, so the gate's
  count cannot see it. It is listed so the table is complete; the gate is not
  widened, because a computed `disabled` is also how a live button shows it is
  busy, and flagging those trains people to ignore the gate.
