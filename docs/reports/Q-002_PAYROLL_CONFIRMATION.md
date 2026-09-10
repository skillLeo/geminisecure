# Q-002 — payroll confirmation request

**Status: open. Payroll approval is blocked until it closes. Nothing has been
disbursed.**

This document has two halves. **§1 is the message to send to the client** — it
is written to be forwarded as it stands, and it deliberately contains no
internal references, no decision numbers and no file paths. **§2 is the working
behind it**, for whoever fields the reply.

---

## 1. The message

> Before we finalise the payroll engine, two figures need your accountant's
> confirmation.
>
> **PAYE.** Comparing the sample payslips against the rates we hold, NIS, NHT and
> Education Tax match to the cent — so the rate card itself is not in question.
> PAYE does not match. The samples withhold about **J$85,392 per month** across
> the four staff where our calculation gives **J$7,362.50** — roughly
> **J$936,000 a year** that employees would be over-deducted and would need to
> reclaim.
>
> Two things appear to differ, and they compound. PAYE looks to be charged on pay
> after *all* deductions rather than on statutory income, which is gross less NIS
> only. And it looks to be charged on the whole amount once a threshold is
> passed, rather than on the excess above it, with the threshold set at roughly a
> fortnight's share of the annual figure while the pay is monthly.
>
> **Employer contributions.** Our rate card holds employer rates for NIS, NHT and
> Education Tax, and the sample payslips show only the employee side. On those
> rates the employer contributions would come to about **J$41,300 a month** for
> these four staff. We would like to confirm whether those belong on the monthly
> S01 alongside the employee deductions, and on what income the employer
> Education Tax is charged.
>
> **To settle both we need two worked payslips from your accountant** — Patricia
> Morgan at J$185,000 and Simone Clarke at J$72,000. For each: gross, then NIS,
> Education Tax, PAYE and NHT, then net — plus the employer contributions
> separately. And the rate version and pay period they apply.
>
> Simone Clarke's is the more important of the two even though her PAYE is nil in
> both calculations, because where the threshold sits is exactly what her slip
> pins down.
>
> The platform is currently calculating the figure our reading of the rules
> gives. Payroll approval stays blocked until this is confirmed, and nothing has
> been disbursed.

---

## 2. The working

### 2.1 What has been checked, and how

Every figure in §1 is produced by the application, not typed. They are asserted
in `tests/Feature/PayrollGoldenPayslipTest.php` against
`tests/Fixtures/golden-payslips.php`, so a document that quoted a stale number
would fail the suite.

```
php artisan test tests/Feature/PayrollGoldenPayslipTest.php
```

### 2.2 The board's formula is fully identified

Board 15's PAYE column is **25% of (gross − NIS − NHT − Education Tax), charged
on the whole, and nil below a cliff.** That reproduces all four staff:

| employee | base | 25% of the whole | board draws | |
| --- | ---: | ---: | ---: | --- |
| Patricia Morgan | 171,712.37 | 42,928.09 | 42,928 | fits |
| Neil Anderson | 88,176.62 | 22,044.16 | 22,044 | fits |
| Wayne Thomas | 81,679.40 | 20,419.85 | 20,420 | fits |
| Simone Clarke | 66,828.60 | 16,707.15 | **0** | below the cliff |

Two departures from the rules as we read them, and they compound:

1. PAYE is charged on **statutory income** — gross less NIS only. NHT and
   Education Tax are not deductible against it.
2. It is charged on the **excess** above the threshold, not on the entire
   amount once the threshold is passed.

### 2.3 Where the cliff sits — and what is *not* known

Thomas is charged on a base of 81,679.40 and Clarke is not charged on 66,828.60,
so the cliff lies between them. **Four divisors of the annual threshold land in
that window**, and four data points cannot separate them:

| | per period | |
| --- | ---: | --- |
| annual ÷ 23 | 78,260.86 | in window |
| annual ÷ 24 | 75,000.00 | in window |
| annual ÷ 25 | 72,000.00 | in window |
| annual ÷ 26 | 69,230.76 | in window — the standard fortnightly divisor |

**26 is the natural reading and it is inferred, not proved.** The message in §1
says "roughly a fortnight's share" rather than naming 26, deliberately.

This was written by hand as *three* divisors before a test looped over every one
of them and found ÷23. That is why Clarke's payslip is requested and not only
Morgan's: hers is the single observation that constrains the cliff at all.

### 2.4 The two multiples, and why neither is quoted to the client

| | |
| --- | ---: |
| Morgan's own column overstates by | **5.8×** |
| The run total overstates by | **11.6×** |

Both are true of different things. The run ratio is larger only because three of
the four are charged tax they do not owe *at all*, so it is one person's 5.8 plus
three divisions by zero. Quoting either alone invites the reply "which?" — so §1
quotes the money: **J$85,392 against J$7,362.50, a difference of J$78,029.50 a
month.**

### 2.5 The employer contributions — a second finding

The rate version has carried `nis_employer_bp` (3%), `nht_employer_bp` (3%) and
`education_tax_employer_bp` (3.5%) since the schema was written. **Until this
change, no line of code read any of them.**

Computed on those rates, for the same four staff, one month:

| | monthly |
| --- | ---: |
| Employer NIS | 13,200.00 |
| Employer NHT | 13,200.00 |
| Employer Education Tax | 14,938.00 |
| **Employer total** | **41,338.00** |
| Employee deductions, as remitted today | 38,965.51 |

So if employer contributions belong on the S01, the return as currently computed
remits **less than half** of what is owed — about **J$496,000 a year** unremitted
for four staff.

**This is asked, not assumed, and nothing has been changed in the ledger.**
`Payroll::approve()` still debits gross to 5000 and credits the employee
withholdings to 2100, exactly as before. Closing the gap means a new expense
account, an accrual against 2100 and a larger S01 — three changes to an estate's
books that are not ours to make on a ruling nobody has given.

The employer Education Tax base is itself an assumption: 14,938.00 charges it on
statutory income, matching the employee side. On gross instead it is 15,400.00 —
a J$462 monthly difference that only a worked slip settles. Marked
`// ASSUMPTION Q-002` in `PayrollCalculator::employerCost()`.

### 2.6 What happens when the reply arrives

1. Type the accountant's figures into `tests/Fixtures/golden-payslips.php`.
2. Set `rate_version` and `period` to what they computed against.
3. Set `confirmed => true`.
4. Run the suite.

It either goes green — and the calculation is confirmed by somebody with the
authority to confirm it — or it names the exact field and both values, e.g.
`Simone Clarke · paye_minor`. The disagreement becomes a number rather than an
argument.

**Approval does not unblock from that file.** It reads
`statutory_rate_versions.is_verified`, which is a separate deliberate write.
Agreeing a calculation and authorising money to leave a bank are two decisions
and the platform keeps them two.

### 2.7 If the accountant's figures match the board

Then the board's PAYE column is right, our reading of the rules is wrong, the
calculator changes and the reason is recorded. Boards 13, 15 and 16 stay as
drawn.

If they match ours, **the board's PAYE and Net columns are wrong and those three
boards need redrawing.** They have not been touched.
