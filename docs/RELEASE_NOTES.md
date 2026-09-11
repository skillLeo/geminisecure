# Release notes

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
