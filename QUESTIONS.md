# Question Queue

Parked hard-stop items only — money, restriction, biometrics, voting. Everything
else is decided and recorded in `DECISIONS.md`.

Surfaced once per phase boundary, alongside the phase report. Never mid-phase.

Affected code carries `// ASSUMPTION Q-0xx` so a ruling can be applied in one pass.

---

## Q-001 · What is the currency? · MONEY · Phase 1
**Need:** the ISO code and display format for every monetary value in the system.
**Why it blocks:** `brick/money` requires an explicit currency at construction. Every
amount column, every formatter and every test fixture depends on it.
**Assumed meanwhile:** `JMD`, displayed `J$1,234.56`. Gemini Security Limited is
Jamaican, the payroll thresholds are Jamaican (NIS, Education Tax, PAYE), and the
PAYE threshold of 1,800,000 is a JMD figure.
**Breaks if wrong:** every stored amount is mislabelled. Recoverable by migration only
if no payments have been recorded against a real gateway.
**Evidence:** no document names JMD, J$ or any currency. The payslip shows nine
unqualified figures; billing shows "MRR" with no unit.

## Q-002 · Statutory payroll rates are unverified · MONEY · Phase 6
**Need:** TAJ-verified NIS, Education Tax and PAYE rates with effective dates.
**Why it blocks:** a payroll run must reproduce to the cent years later.
**Assumed meanwhile:** nothing. The rate table is built as versioned data with
effective dates and is seeded empty. A run with no applicable rate version throws
rather than defaulting.
**Breaks if wrong:** nothing yet — the structure is rate-agnostic by construction.
**Client position:** Build Spec open item [A] — "do not go live until the accountant
signs off". Gate payroll behind that sign-off.

## Q-003 · Biometric consent wording needs legal review · BIOMETRICS · Phase 3
**Need:** approved consent copy and retention statement under Jamaican privacy law.
**Why it blocks:** consent text is the lawful basis for capture.
**Assumed meanwhile:** feature flag `biometrics.enabled` defaults **off**. The
enrolment reference and verification result columns exist; no template is ever stored
and no image exists anywhere in the system, per invariant 9.
**Breaks if wrong:** nothing — the feature is dark until the flag is lifted.
**Client position:** Build Spec open item [B] — launch blocker for that feature only.

## Q-004 · Payment gateway not provisioned · MONEY · Phase 5
**Need:** the gateway, its capabilities, and settlement behaviour.
**Why it blocks:** card capture and reconciliation semantics.
**Assumed meanwhile:** manual cash recording is the day-one path. Card capture is
built behind an adapter interface with no live implementation.
**Breaks if wrong:** the adapter boundary may need reshaping; no stored data changes.
**Client position:** Build Spec open item [C].

## Q-005 · What triggers arrears restriction, exactly? · RESTRICTION · Phase 5
**Need:** the threshold — days overdue, amount, or both — and who may override.
**Why it blocks:** restriction is one of the four locked categories and it changes
stored state and gate behaviour.
**Assumed meanwhile:** nothing is auto-restricted. The `delinquency_flags` table and
the `access_restricted` boolean exist and are only ever set explicitly. Three rules
from the master prompt ARE implemented and are not in question: restriction follows
arrears and never a failed payment; a declined card or gateway outage never restricts
anything; restriction applies to guest passes only, never a resident's own entry and
never emergency or medical vehicles, which are hardcoded un-restrictable.
**Breaks if wrong:** the threshold is a config value; the mechanism does not change.

## Q-006 · No restriction UI exists in the Guard wireframes · RESTRICTION · Phase 3
**Need:** how a restricted household's pass should present at the gate.
**Why it blocks:** the Build Spec says the guard receives `access_restricted` as a
boolean, but a scan of all 10 Guard files found **no restriction wording anywhere** —
the boolean is never rendered on any of the 40 screens.
**Assumed meanwhile:** the API returns the boolean as specified; the Guard UI does not
render it, matching the approved screens exactly.
**Breaks if wrong:** a guard cannot act on a restriction they cannot see. If a distinct
visual state is required, **that screen does not exist and is new design work.**
**Note:** the invariant is not violated either way — no amount is ever exposed.

## Q-007 · Source document naming the seven permission verbs · Phase 1
**Need:** confirmation of the seventh verb.
**Why it blocks:** it does not block — proceeding on D-013.
**Assumed meanwhile:** `view · create · update · delete · approve · export · configure`.
Six are named in Ruling 3; `view` is inferred from the `View` level in both grids.
**Breaks if wrong:** one permission name changes; a seeder re-run fixes it.
