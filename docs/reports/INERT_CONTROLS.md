# Inert controls — final register (work orders 12 and 13)

**For:** the client, and whoever picks up the next phase.

**Work order 13 changed no count.** It built the mobile API behind several of these controls without making any of them live, because each stays deferred by the 12 §1 ruling. Where the reason on the screen still holds, it is unchanged; where the API now exists behind it, the table says so:

| Control | What exists now | Why it stays inert |
| --- | --- | --- |
| Message the guard (input), super-admin-17 | The Guard App reads dispatch messages (`GET /messages`, `GET /sync/pull`), and the table accepts a message addressed to one guard | Guard messaging is deferred by ruling, and no Guard App has shipped to read it. The screen's reason — "opens when the Guard App ships" — is still true |
| Dispatch a second guard, super-admin-17 | Handsets are enrolled and addressable | Deferred by ruling; nothing records the assignment |
| Message resident / household, community-admin-18 and -38 | The Resident App exists as an API, with no push channel | No SMS, WhatsApp or push delivery exists |
| Add / Edit payment method, super-admin-35 | The Resident App's `pay-intent` answers with manual instructions | Card capture stays behind Q-012 |

The type on thirty button controls was corrected in work order 13 E1 (they had been drawing the page's font size and weight, not the board's). None of those controls was inert.

**Where this stands.** When the review began, `gate:interactivity` counted 115 inert controls, and one more was inert through a computed binding the gate cannot see. Work order 12 ruled on every one of them. It gave a build order for everything not deferred, and named the controls that stay inert on purpose.

The gate now counts **33**. None of them is an unbuilt feature waiting for code:

| What kind of inert | Count | What it means |
| --- | ---: | --- |
| **Deferred by ruling** (12 §1) | 11 | Inert on purpose, and the reason says so. Listed in §1. |
| **Permission branch** | 20 | Live for every role that holds the gate. The inert copy, with its reason, shows only to roles that don't. Listed in §2. |
| **Data gap or out of scope, not in the §1 list** | 2 | No data exists for the control to act on. Listed in §3. |
| **Total** | **33** | |

`php artisan gate:interactivity --table` lists every page and its count. The gate passes on 108 pages: no element looks interactive but does nothing.

---

## 1. Deferred by ruling — 11

> "Deferred, genuinely — do not build, keep the inert control and its reason." — 12 §1

| Control | Screen | Ruling | Reason shown |
| --- | --- | --- | --- |
| Import statement | community-admin-28, Bank reconciliation | Bank statement import | Each bank's file format has to be parsed, and a mis-parsed line becomes a false match. The lines on screen were entered from the statement. |
| Message resident | community-admin-18, Maintenance ticket | Message resident / household | No SMS or WhatsApp delivery adapter exists yet. |
| Message household | community-admin-38, Resident profile | Message resident / household | No SMS or WhatsApp delivery adapter exists yet. Notices to every household go out from Governance → Notices. |
| Add / Edit (payment method) | super-admin-35, Payment methods | Card capture and payment methods (Q-012) | Card capture sits behind a payment gateway that has no implementation. Settlement is recorded manually. |
| Message the guard (input) | super-admin-17, Alert detail | Guard messaging | No Guard App exists to deliver it to. |
| Dispatch a second guard | super-admin-17, Alert detail | Dispatch second guard | It would have to reach the guard's handset, and nothing records that assignment yet. |
| Escalate to police / JCF | super-admin-17, Alert detail | JCF escalation | No JCF integration exists, so no escalation can be recorded. |
| Contact {guard} about renewal | super-admin-21, Compliance case | Contact guard (data gap) | The guard's record has no phone number or email address. |
| Contact guard | super-admin-19, Guard profile | Contact guard (data gap) | The guard's record has no phone number or email address. |
| Alertness policy | super-admin-15, Alertness | Alertness policy readout (inert by design) | Shows the figures in force; they can't be edited from here. |
| Save changes | community-admin-33, Data & privacy | Data & Privacy save (inert by design) | There is nothing to save. The page states the estate's policy rather than setting it. |

## 2. Permission branches — 20

Each of these is a link or a button for every role that holds the target's gate. The inert copy exists only so a role without the gate gets a sentence instead of a 403. The gate counts that copy because it is in the markup.

| Control | Screen | Live for roles holding |
| --- | --- | --- |
| Work order (bill reference) | community-admin-27, Bills & payments | `facilities.view` |
| View ledger | community-admin-02, Estate dashboard | `dues_ledger.view` |
| Post a notice | community-admin-02, Estate dashboard | `governance.create` |
| Add resident | community-admin-02, Estate dashboard | `residents.create` |
| New charge | community-admin-02, Estate dashboard | `dues_ledger.create` |
| Export | community-admin-05, Arrears | `dues_ledger.export` |
| New charge | community-admin-05, Arrears | `dues_ledger.create` |
| Export | Receipts register (board 5 tab) | `payments.export` |
| Back to the arrears list | community-admin-06, Unit ledger | `dues_ledger.view` |
| Vendors (tab) | community-admin-19, Amenity bookings | `accounting_posting.view` (not the Property Manager: D-010) |
| Vendors (tab) | community-admin-17, Maintenance | `accounting_posting.view` (not the Property Manager: D-010) |
| Generate (report card) | community-admin-29, Reports | `reports.export`. The same control also carries the data gap in §3. |
| Add resident | community-admin-04, Residents | `residents.create` |
| Review now | community-admin-04, Residents | `residents.approve` |
| Invite user | community-admin-24, Role access | `settings.create` |
| Open these officers' workforce records | super-admin-10, Client guards | `guard_workforce.view` |
| View billing history | super-admin-05, Client record | `billing_subscriptions.view` |
| Export | super-admin-20, PSRA compliance | `guard_workforce.export` |
| Add guard | super-admin-18, Guard workforce | `guard_workforce.create` |
| Open (report card) | super-admin-36, Cross-tenant reports | Each report's own module. All six cards open for a Director; a test enforces this. |
| Export | Report screens (shared shell) | `cross_tenant_reports.export` |
| Statutory rates (tab) | super-admin-42 to 45, Platform settings | `payroll_accounting.view`. The strip's Notifications tab is in §3. |

*This table has 22 rows but counts 20. Two rows share a single control in the markup with §3: the Reports card's Generate button (refused either by role or, on Budget vs Actual, by the data gap) and the Platform settings tab strip (Statutory rates by role, Notifications as out of scope). Each of those two controls is counted once, in §3.*

## 3. Data gap or out of scope, not in the §1 list — 2

| Control | Screen | Why it stays inert |
| --- | --- | --- |
| Budget vs Actual (report card) | community-admin-29, Reports | No approved budget exists anywhere on the platform, so there is nothing to compare actuals against. The actuals half is already in the ledger. |
| Notifications (tab) | super-admin-42 to 45, Platform settings | There is nothing to configure here. The console's notification centre (the dashboard bell) reads each item from its own record, and each estate sets its own event delivery defaults under its Settings. |

## 4. Outside the gate

Two search boxes sit in the layouts rather than on pages, so the gate does not count them. Both are disabled and say why:

- **Estate console top-bar search.** There is no search across units, residents and tickets yet. Each list screen filters its own rows.
- **Gemini dashboard search.** There is no search across modules. The client directory and the guard directory each search their own list.

---

## What work order 12 built, by item

Every item in 12 §2 is built. The ones that closed inert controls are:

| Wave | Built |
| --- | --- |
| 1 · Pilot blockers | Manual payment, receipts register, invite user, forgot password, add vendor, record bill, add account, start payroll run, add phase, CSV import |
| 2 · Daily operations | Amenities add/edit, work order, edit vendor, edit resident, manage user, whole-phase/estate charge, **charge schedule** (preview, post, reverse), **payment plans register**, dunning template editor, hardship/dispute flag, agenda/minutes and **meeting detail**, **nomination detail**, add employee, logo, licence renewal, request history, **incident intake and view**, **standing orders with the acknowledgement cycle** (including the handset endpoints), **start new filing** |
| 3 · Documents and exports | Statement and receipt PDFs, arrears export, payroll XLSX and bank CSV, **election-room minutes export**, election certificate, report generators (six of seven, including the **security incident log**), audit export, licence register export, cross-tenant exports, invoice PDF/resend/credit note, **compliance calendar** |
| 4 · Platform administration | Estate notification centre and activity log, **Gemini notification centre and bell**, subnav twins (Guards, Dispatch, Operations, Dues, **Platform admins**), **billing preview** row action, **cross-tenant report cards** (four had been disabled by a route-name mismatch), plan and package editing, **estate invoice view**, payroll Employees tab linking to Guard workforce |
| 5 · Roster cluster | Shift roster editor, open shifts, assignment, release, reassignment, coverage on any day |

Items in **bold** were finished in the final stretch of this work order.
