# Board corrections — community-admin-13, 15, 16, 24, 34 and super-admin-07

**For:** the designer redrawing boards that no longer match what the platform was ruled to do.

The payroll boards (13, 15, 16) come first. Boards 24, 34 and super-admin-07 follow: each measures over the 2% fidelity bar for a reason that is the drawing's, not the build's (work order 13 E2). The last section covers the two over-bar boards whose residual is **not** a board error, so nobody redraws them.

**For the payroll boards:** the designer redrawing the Estate Console payroll boards.
**Why:** these three boards were drawn before the client's payroll ruling (Q-002, recorded as D-082 and D-083). They show PAYE charged as a cliff on the whole salary, and the Net figures that follow from it. The ruling says PAYE is 25% of the amount *above* the threshold, and the build follows the ruling. **The code has not been changed to match the boards, and should not be.** Please redraw the cells below.

Until they are redrawn, these three boards are left out of the pixel-fidelity sweep (they report `UNVERIFIED`, with a pointer to this file).

## The rule the boards break

- **Ruled:** PAYE = 25% of chargeable income *above* TAJ's monthly threshold (J$158,530.00 on the April–December 2026 card), and 30% above J$500,000 a month. Below the threshold, PAYE is nil. Education Tax is still charged from the first dollar.
- **Drawn:** PAYE charged on the *whole* salary as soon as pay passes J$73,234.90, which is TAJ's **fortnightly** threshold used as if it were monthly.
- **Effect on August 2026:** the board withholds J$85,392.00 of PAYE. The ruling requires J$5,230.00. That is J$80,162.00 a month, J$961,944.00 a year.
- **Also ruled (D-083):** the monthly S01 covers PAYE, NIS, NHT, Education Tax **and HEART**, and carries the employer's contributions as well as the deductions.

All engine figures below come from the same calculator the product uses, on the card in force on each pay date. They are also asserted in `tests/Feature/PayrollGoldenPayslipTest.php`.

---

## community-admin-15 · Pay Run Approval (August 2026)

### Summary cards

| Cell | Board draws | Engine produces | Why |
|---|---|---|---|
| Total gross | $440,000 | $440,000.00 | Correct. No change. |
| Total deductions | $116,995 | **$36,833.01** | Carries the over-charged PAYE (see below). |
| Total net pay | $323,005 | **$403,166.99** | Gross less the correct deductions. |

### Payslip table: PAYE column

| Employee | Gross | Board draws | Engine produces | Why |
|---|---|---|---|---|
| Patricia Morgan | $185,000 | $42,928 | **$5,230.00** | 25% of the excess over the monthly threshold only. |
| Neil Anderson | $95,000 | $22,044 | **$0.00** | Pay is below the monthly threshold, so no PAYE. |
| Wayne Thomas | $88,000 | $20,420 | **$0.00** | Pay is below the monthly threshold, so no PAYE. |
| Simone Clarke | $72,000 | $0 | $0.00 | Correct. No change. |

### Payslip table: Net column

| Employee | Board draws | Engine produces | Why |
|---|---|---|---|
| Patricia Morgan | $128,784 | **$166,482.37** | Follows from the PAYE correction. |
| Neil Anderson | $66,133 | **$88,176.62** | Follows from the PAYE correction. |
| Wayne Thomas | $61,259 | **$81,679.40** | Follows from the PAYE correction. |
| Simone Clarke | $66,829 | $66,828.60 | Correct; the board rounds to whole dollars. |

The NIS, NHT and Education Tax columns are correct. Differences under a dollar are only the board rounding to whole dollars: Ed. Tax is $4,037.63, $2,073.38, $1,920.60 and $1,571.40.

---

## community-admin-13 · Payroll Run List

Every Net figure on this board carries the same PAYE error.

| Cell | Board draws | Engine produces | Why |
|---|---|---|---|
| KPI "Last run — net total" | $323,005 | **$401,034.49** | This card shows the latest *paid* run (July 2026). |
| August 2026 · Net | $323,005 | **$403,166.99** | Same run as board 15. |
| July 2026 · Net | $323,005 | **$401,034.49** | As paid. |
| June 2026 · Net | $323,005 | **$401,034.49** | As paid. |
| May 2026 · Net (3 employees, $352,000 gross) | $261,746 | **$319,355.09** | As paid. |

The Gross, Employees and Status columns are correct.

*June and July differ from August by $2,132.50. Those runs were paid at a PAYE of $7,362.50 for Patricia Morgan, and the ruled card gives $5,230.00 from August. Paid runs are append-only, so they keep the figure they were paid at.*

---

## community-admin-16 · Statutory Filings

This board shows no amounts. One cell is out of date against the ruling on what an S01 covers.

| Cell | Board draws | Engine produces | Why |
|---|---|---|---|
| S01 August 2026, second line | "August 2026 · NIS, NHT, Education Tax, PAYE" | **"August 2026 · PAYE, NIS, NHT, Education Tax, HEART"** | D-083: the S01 also carries HEART and the employer's contributions. |

All other rows (the July S01 and S02, the GCT return and the P24) are correct.

*The "Compliance calendar" button on this board now opens a working screen. Its look is not affected.*

---

## What does not need redrawing (payroll)

Board 14 (Pre-Run Exceptions) and board 37 (Employees) show no PAYE or Net figures and are unaffected.

---

## community-admin-24 · Role Access Matrix

**Measured 2.30%.** The board draws a 10 × 6 grid. The permission model is 13 modules × 7 roles, and Ruling 1 (D-010) locks the Property Manager out of every money module. The screen draws the model; redraw the board to match it. Every cell below is what `role_module_access` holds for a freshly provisioned estate.

### Structure

| Cell | Board draws | Engine produces | Why |
|---|---|---|---|
| Columns | President, Vice President, Secretary, Property Manager, Treasurer, Admin Assistant | **Community Super Admin** first, then the same six | The estate's own administrator role is in the matrix (D-048); a permission screen that hides a role is not describing permissions. |
| Rows | Dashboard, Estate structure, Residents, Dues & ledger, Accounting, Payroll & HR, Facilities, Governance, Reports, Settings | Dashboard, Estate structure, Residents, Dues & ledger, **Payments**, Accounting, **Vendor costs**, **Maintenance budget**, Payroll & HR, Facilities, Governance, Reports, Settings | Ruling 1 split Accounting into the modules a Property Manager may and may not reach (D-010). |
| Note box | One paragraph (Super Admin) | That paragraph plus two: the matrix is read-only in the Estate Console, and Dues & ledger, Payments, Accounting and Payroll & HR are closed to the Property Manager by platform invariant | D-048 (read-only), D-010 (the lock). |

### Cells that change in the Property Manager column

| Module | Board draws | Engine produces | Why |
|---|---|---|---|
| Residents | Full | **Full · Approver** | Derived approver cell (D-053): approving a unit claim needs `approve`, and board 24 gave the verb to nobody. |
| Dues & ledger | View | **— · Locked** | Ruling 1 (D-010): whoever commissions work must never see a resident's financial position. |
| Accounting | View | **— · Locked** | Ruling 1 (D-010). |
| Payroll & HR | — | **— · Locked** | Same access; the tag says it is an invariant, not an estate choice. |
| Facilities | Full | **Full · Approver** | Derived approver cell (D-086): forfeiting a deposit needs `approve`. |

### New rows — every role

| Module | Community Super Admin | President | Vice President | Secretary | Property Manager | Treasurer | Admin Assistant |
|---|---|---|---|---|---|---|---|
| Payments | Full | View | View | — | — · Locked | Full · Approver | Entry |
| Vendor costs | Full | View | View | — | View | Full · Approver | Entry |
| Maintenance budget | Full | View | View | — | View | Full · Approver | Entry |

### New column — Community Super Admin

| Module | Cell | | Module | Cell |
|---|---|---|---|---|
| Dashboard | Full | | Payroll & HR | Full · Approver |
| Estate structure | Full | | Facilities | Full · Approver |
| Residents | Full · Approver | | Governance | Full |
| Dues & ledger | Full | | Reports | Full |
| Payments | Full | | Settings | Full |
| Accounting | Full | | Vendor costs | Full |
| Maintenance budget | Full | | | |

Every other cell on the board is correct.

---

## community-admin-34 · Add Resident

**Measured 5.21%** (5.52% before a type defect on the verification labels was fixed, 13 E1). Two cells are wrong on the board; the rest of the residual is the board drawing the form mid-entry, covered in the last section.

| Cell | Board draws | Engine produces | Why |
|---|---|---|---|
| Between Verification and "Add resident" | Nothing | **Checkbox "Resident consents to biometric enrolment"**, unchecked, and the note "Off unless the resident says otherwise. Biometric enrolment is refused without it, and no estate setting grants it on anybody's behalf." | Biometric consent ships off and is recorded per resident (D-022, Q-015 ruled in D-077); enrolment refuses without it. The control moves "Add resident" down by about 70px. |
| "What happens next" paragraph | "Simone will get an SMS … once **she** verifies with **her** ID, **her** status changes … unless **her** details don't match." | "**This resident** will get an SMS … claim **their** unit. Once **they** verify with **their** ID, **their** status changes … unless **their** details don't match." | The paragraph is a template for any resident; the platform never infers a pronoun from a name (D-056, D-060). |

---

## super-admin-07 · Client Health

**Measured 5.42%, and it moves with the clock** (two of the bars are 7- and 30-day windows over real events, D-079). The board draws clients the platform does not have and is forbidden to invent.

| Cell | Board draws | Engine produces | Why |
|---|---|---|---|
| Client cards | Three: **Emerald Heights Estate** (Healthy), **Phoenix Park Village 1** (Getting started), **Coral Bay Residences** (Getting started) | The platform's clients: **Phoenix Park** and **Ocean View**, each with its own status | D-038: two estates exist, and an illustrative third must not be seeded — it would reach every cross-client total. |
| Bars under each card | Illustrative percentages | Each estate's reported adoption: dues & ledger, facilities, governance and payroll from the estate's own report; Guard App coverage (7 days) and Visitor passes (30 days) from platform events | D-066: counted inside each estate and pushed outward, so this console never opens an estate database. |
| Below the cards | Nothing | "Dues & ledger, facilities, governance and the estate's own payroll are counted inside each estate's database. They appear here once that estate's console reports them — this console never opens an estate database to find out." | D-066: says why a new client's bars can be empty. |
| Sidebar footer | The board's persona | The signed-in Gemini user | Every console draws its own user. |

Suggested redraw: draw the list as a data-driven set of client cards (any number), with the note under it.

---

## Over the bar, but not a board error — do not redraw

| Board | Measured | Residual | Why it is not a correction |
|---|---|---|---|
| community-admin-32 · Notices | 3.52% | The composer is drawn mid-compose: Urgent selected, "Water interruption — Phase 3" typed, a message typed, "Phase 3 only" chosen. The screen opens it empty, on General, to the whole estate. | A mock-up of the control in use (D-065). An empty composer is the state a secretary finds; nothing on the board is wrong. |
| community-admin-10 · Nominations Review | 2.14% (3.37% before the chip fix, 13 E1) | The board draws an election with two positions and five nominations. The estate elects fourteen positions and has more nominations, so the chip row scrolls and the table is longer. | Illustrative subset of real data (D-059). The chips were squeezing and wrapping instead of scrolling — a build defect, now fixed; what remains is data volume. |
| community-admin-34 · Add Resident (part) | — | The board draws the form filled in for "Simone Barrett", Phase 5, Lot 112 with the lot field focused; the screen opens empty with placeholders. | A mock-up in use. Only the two cells in the section above are corrections. |
