# Question Queue

Parked hard-stop items only — money, restriction, biometrics, voting.
Surfaced once per phase boundary, alongside the phase report.

**All questions raised through Phase 2 have been answered.** The rulings are
recorded in `DECISIONS.md` as D-020 to D-026 and are implemented, not merely
noted. This file is now empty of open items.

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
