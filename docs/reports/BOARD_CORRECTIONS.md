# Board corrections — community-admin-13, 15 and 16

**For:** the designer redrawing the Estate Console payroll boards.
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

## What does not need redrawing

Board 14 (Pre-Run Exceptions) and board 37 (Employees) show no PAYE or Net figures and are unaffected.
