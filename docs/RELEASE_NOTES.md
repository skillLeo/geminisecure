# Release notes

## Unreleased · Work order 13

### What committees will notice

- **Receipt numbers carry the estate's own prefix.** Phoenix Park Village numbers `PPV-R-…` and Ocean View Gardens `OVG-R-…`. A new estate's prefix is set when it is provisioned. It is suggested from the initials of its name, is at most six characters, and is fixed once the estate issues its first receipt. Receipts already issued keep their numbers under the new prefix: `PHOENIXPARK-R-04471` is now `PPV-R-04471`. Journal memos written before the change still name the old form.
- **Payroll files are kept.** When a pay run is exported as a bank file or an accountant's summary, a copy is kept first. It is byte for byte what left, is held for seven years, and can be downloaded again from the run's Export panel. The database refuses to delete a kept document before its retention date or to change one after issue.
- **Every document is on the audit log twice over:** when somebody asks for it and when somebody downloads it.
- **Arrears reminders go out on their own.** Every morning at 9:00 each live estate sends the reminder step a household's arrears have reached, once per step. Households flagged for hardship or dispute are skipped, and so are households keeping to an agreed payment plan. The dunning log names these reminders "Automated dunning run". They are logged as queued: no SMS, email or push delivery is connected yet.
- **Lifting a hardship or dispute flag now needs a reason and a committee minute,** the same as raising one. A flagged household shows a Hardship or Dispute pill on the arrears list.
- **Payroll — the accountant's three are ruled.** The HEART floor is zero, as it already was. Each employee and guard now has an approved pension field. It is zero for everybody and can be set when somebody joins a scheme: it comes off statutory income before Education Tax and PAYE, and posts to 2150 Pension Contributions Payable (the estate adds that account first). The 30% PAYE band now applies to statutory income above J$500,000 a month. Nobody on either payroll is near that line, so no payslip changes.
- **Documents open only to roles that hold the record behind them.** Before this release, any estate user could download a statement, receipt or minutes by its link. A statement now needs Dues & ledger, a receipt Payments, a remittance Accounting, meeting papers and certificates Governance, and payroll files Payroll export.

### What Gemini staff will notice

- **A client can be invoiced from the console.** On a client's "Not yet invoiced" preview, "Raise this invoice" numbers the period (for example `PPV-INV-202610`), lists the tier, the per-guard add-on and the client's line items, and posts it to Gemini's receivable. You confirm before it posts. A raised invoice is never edited, and a correction is a credit note. Raising does not email the invoice; use Resend.
- **Gemini has a receivable ledger.** Each invoice debits the client's account receivable and credits revenue. Each credit note against such an invoice reverses its share. The database refuses an entry that does not balance and any change to one that has posted. Invoices from before this release are not in it.
- **Dispatch screens show whether they are live.** A pill beside the source badge reads "Live channel" while alerts and clock-ins are pushed, or amber "Fallback · polling every 3s" while the live channel is down, with how long it has been down. Screens no longer poll while the channel is up. A dead channel is noticed within forty seconds.
- **The coverage board updates as guards clock on and off,** without a reload.
- **A Reverb outage no longer fails a panic alert or a clock-in** on the handset. The record is kept, and the screens catch up by polling.
- **Not yet:** there is no way to record that a client has paid. GCT is not charged on invoices until that is ruled (Q-019).

### For whoever deploys it

```bash
php artisan migrate --force          # central first: tenants.receipt_prefix, backfilled;
                                     # the platform ledger (three tables, six triggers, a two-account chart);
                                     # guards.approved_pension_minor, payslips.pension_minor
php artisan tenants:migrate --force  # every estate: renames issued receipts to the prefix,
                                     # documents.content_type and the two retention triggers,
                                     # unit_collection_flags.lifted_minute_reference,
                                     # employees.approved_pension_minor, payroll_run_lines.pension_minor
```

- **`docs/DEPLOY.md` is the deployment guide.** It covers MySQL 3307, Reverb 8080, the queue worker and the scheduler as Windows services, and the release sequence. PDFs do not render without the queue worker, and reminders do not go out without the scheduler.
- **Production will not boot with `MAIL_MAILER=log`**, or with the `.env.example` placeholders still set. Configure a real SMTP relay first.
- **Re-apply the central grants** after migrating, so Gemini's journals are insert-only for the application user as well as by trigger: `php artisan grants:append-only`.
- **The scheduler must run** for reminders to go out: a task calling `php artisan schedule:run` every minute (see `docs/DEPLOY.md`). `php artisan dunning:run --dry-run` shows what a morning's run would send.
- Check each estate's prefix before its first receipt: `php artisan estate:receipt-prefix <estate>` shows it, and `php artisan estate:receipt-prefix <estate> <PREFIX>` corrects it. Once the estate issues a receipt, the command refuses.

## 2026-09-16 · Work order 12 — every remaining control, ruled

Every control the work order ruled on is now built. The only ones still inert are the set the ruling deferred, plus buttons that stay inert for a role without the permission. `docs/reports/INERT_CONTROLS.md` lists all 33.

### What committees will notice

- **Receipts are numbered per estate.** Numbers are allocated when a payment posts and are never reused. A number no receipt carries shows as a gap in the Receipts register. *Correction:* this note first said numbers run `PPV-R-00001` upward. This release actually took the prefix from the subdomain (`PHOENIXPARK-R-04471`). Work order 13 moves the prefix onto the estate record (D-089).
- **Statements, receipts, agendas, minutes, remittances and election certificates are PDFs.** They are rendered in the background, carry the estate's logo, and are kept for seven years. Invoices render on demand from the invoice record, which never changes.
- **Every export is on the audit log**, with who took it, what it covered and how many rows it held. A cross-client export also names the clients in it.
- **The maintenance fee can be scheduled.** Under Dues → Charge schedule, set the amount, who pays it and the due day once. Each month then shows its unit count and total before it posts. A wrong month is reversed whole, with a reason. That month's charges come off the ageing, and it can be posted again.
- **Payment plans have a register**, with progress counted from the instalments.
- **The arrears list shows each household's last reminder** from the dunning log. Before this release every household showed "—".
- **Dunning wording is edited as a draft** and put in force against a committee resolution reference. Notices already sent never change.
- **A household can be flagged for hardship or dispute**, with a reason and a committee minute. The flag stops automated reminders and does not change what the household owes.
- **Meetings, nominations and invoices open their own screens.** A meeting shows its agenda, quorum count and minutes. A nomination shows the eligibility snapshot the decision was taken against; the arrears figure is shown only to roles that read the ledger. An estate can open its own invoices, line by line, and download the PDF.
- **Reports:** six of the seven run, including the Security Incident Log for your estate. Budget vs Actual needs a budget the platform does not yet hold.
- **Payroll:** add an employee behind a consent checkbox, export an approved run as an XLSX summary and a bank CSV, and open a compliance calendar of returns due.

### What Gemini staff will notice

- **Standing orders run a real acknowledgement cycle.** A set is published at version 1. Guards acknowledge the version they read from their handset. A revision asks them again. Every version's text is kept.
- **The incident log takes a structured intake.** Closing an incident needs a note of what was done, and a closed incident is final.
- **The roster:** post open shifts, assign officers, release a leaving guard's future shifts, reassign a guard to another client, and see coverage for any day.
- **The dashboard bell** opens a notification centre: open alerts, pending requests, licences lapsed or lapsing, open incidents, invoices past due and returns owed. Each item is shown only to roles that can open the record behind it.
- **Platform admins:** invite staff to a console role, change a person's role, sites or standing, and resend or withdraw invitations. You cannot change your own account, and the last active Director cannot be demoted.
- **Billing:** resend an invoice, credit it (the invoice itself is never edited), download its PDF, and preview a client's next, not-yet-raised invoice. Tier prices change on an effective date with a reason.
- **Payroll:** "Start new filing" prepares the S01 from the oldest approved run, and the Employees tab opens Guard workforce.
- **Four cross-tenant reports were wrongly shown as unavailable** because their cards looked up the wrong route names. All six report cards open now.

### For whoever deploys it

```bash
php artisan migrate --force          # central: plan price changes, credit notes and invoice sends,
                                     # open shifts, standing order versions, platform notification reads
php artisan tenants:migrate --force  # every estate: receipt numbering, dunning drafts, collection flags,
                                     # notification reads, documents, charge schedules
php artisan queue:work               # documents are rendered by a worker
```

- **New device ability `orders:acknowledge`.** Handsets enrolled before this release lack it until they are re-enrolled.
- `standing_order_versions` is backfilled from the order sets in force. Earlier versions were never stored, and none is invented.

### Known and unchanged

- **Boards community-admin-13, 15 and 16 still need redrawing** (see `docs/reports/BOARD_CORRECTIONS.md`). Until they are, the fidelity sweep reports them as UNVERIFIED.
- **Deferred, as ruled:** bank statement import; messaging a resident or household; card capture and payment methods; dispatching a second guard, JCF escalation and guard messaging; contacting a guard (no phone or email on record); the alertness policy editor; saving Data & privacy.

## 2026-09-11 · The Q-002 ruling release

### What residents will notice first

**Amenity bookings are now refused for a household more than 90 days in
arrears — in every estate, from this release.** The client ruled one arrears
threshold across the estate, not two (Q-008, D-075): the diary reads the same
90 days, from the same settings, as the gate's guest-pass restriction, and an
active payment plan lifts both. Before this release the amenity block was
**off** while the gate restriction was **on**, so for residents this switches
on immediately. A household that could book the Club House last week may be
refused this week. Committees should expect the question, and the answer is
the same one the gate gives: agree a payment plan and the block lifts.

A refused household is never shown the amount — the refusal says the booking
cannot be taken, and nothing about the balance.

### Payroll — unblocked

- **Approval is open.** The client ruled Q-002: PAYE is 25% on the amount
  **above** TAJ's published periodic threshold, 30% on chargeable income above
  J$6,000,000 a year. Two rate cards run each year — January to March on the
  2025-04 card, April to December on the 2026-04 card — chosen by the pay
  period's end date and stored on the run.
- **TAJ's own periodic figures, never a division.** The 2026 fortnightly
  threshold is J$73,234.90; the annual figure divided by 26 would be J$73,167.69.
- **The first live pay run in each payroll asks for one tick.** The approver
  confirms the run was reconciled against the current TAJ tables, with the rate
  card named on the checkbox. It is asked once per payroll and never again. The
  figures reconcile to TAJ's publications and have **not been countersigned by
  the client's accountant**; the tick keeps that responsibility where it
  belongs.
- **Employer contributions post.** NIS, NHT, Education Tax and HEART are
  computed per payslip, posted on approval (Dr 5010 Employer Statutory
  Contributions, Cr 2100 Statutory Payables) and remitted on the monthly S01
  with the employee deductions, due by the 14th.
- **Gemini's own guard payroll can be approved** by the Director.
- **Three questions went to the accountant:** the HEART payroll floor (Q-016),
  whether any approved pension scheme applies (Q-017), and where the 30% band is
  measured from (Q-018). The defaults are marked in the code and in
  `QUESTIONS.md`.

### Amenity deposits — the deposit door

- **A booking detail screen**, opened from "View" on the booking diary. It is not
  on an approved board and was added on the client's ruling (D-086).
- **Record deposit received** and **Refund deposit** need Facilities update.
  **Forfeit deposit** needs Facilities approve and a reason — keeping a
  resident's money is the one deposit act a household will ask the committee to
  justify.
- The Property Manager and the Community Super Admin now hold **Full · Approver**
  on Facilities. The Admin Assistant can record and refund a deposit and cannot
  forfeit one. The Treasurer can read the screen and move nothing through it.
- In the books: taken is Dr 1000 Bank, Cr 2200 Resident Deposits Held; refunded
  reverses it; forfeited is Dr 2200, Cr 4100 Amenity Booking Fees. None of the
  three touches what a household owes.

### Controls that now work

Fourteen controls that were drawn inert are live — nine for everyone who can
reach their screen, five for the roles that hold the target's gate:
the booking diary's View; Gemini payroll's Rate table tab; the unit ledger's
Place on payment plan, Send reminder now, Dunning log and Payment plan; the
Notices tab on the election control room and on the meetings register; the
Notices screen's Elections tab; the work order on each bill; the Vendors tab on
maintenance and on bookings; the dashboard's Post a notice; and Gemini's Add
guard. The full review of every inert control, with the effort to make each one
live, is `docs/reports/INERT_CONTROLS.md`.

### For whoever deploys it

```bash
php artisan migrate --force                          # central: rate-card columns, payslip employer columns
php artisan tenants:migrate --force                  # every estate: employer columns, account 5010
php artisan db:seed --class=StatutoryRatesSeeder --force
php artisan db:seed --class=RbacMatrixSeeder --force # Facilities and Gemini payroll Approver cells
php artisan gate:tokens                              # new gate: no hex colour outside tokens.css
```

- The provisional 2026-04 rate card is **superseded, not deleted**: pay runs
  point at it, and editing it would restate every payslip computed from it.
- Laravel's stock `welcome.blade.php` is removed. No route reached it.

### Known and unchanged

- Pay runs, pay run approval and statutory filings (community-admin-13, 15 and
  16) draw PAYE and Net as the pre-ruling cliff and need redrawing before a
  fidelity sweep can hold them to their boards again (D-082, D-086).
- MySQL on port 3307 and Reverb on 8080 still run as foreground processes, not
  services. Registering them needs an Administrator shell — the commands are in
  the final report, §11.
