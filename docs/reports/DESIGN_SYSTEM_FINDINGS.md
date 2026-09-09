# Design System Findings — all 43 imported files

Produced by a 9-agent extraction across every imported file (907k tokens, 220 tool
calls, 0 failures). Full transcript: workflow run `wf_9f6f8ed2-141`.

---

## ✅ Confirmed against the master prompt

| Claim | Verdict |
| --- | --- |
| 33 colour tokens in Foundation `:root` | **Confirmed exactly.** `resources/css/tokens.css` matches verbatim |
| Brand mark: 2 circles, viewBox `0 0 40 40`, r=12.5, cx=15/25, multiply right | **97 of 98 instances conform** — one defect, below |
| Web/mobile split (2 web 1440×900, 2 mobile 433×892, Guard dark) | **Fully specified and internally consistent across three independent statements** |
| Guard never sees an amount owed | **PASSED** — see caveats below |
| No third shape/mask/clip-path in the logo | Confirmed — overlap is produced solely by `mix-blend-mode:multiply` |

The Guard app's dark-only status has a stated safety rationale, not a preference:
*"never a light mode, because a white screen at 2am destroys night vision and marks the
guard's position from the road."* **No theme toggle may be added there.**

---

## 🔴 Blocking decisions

### 1. The success green — four different values

This is the headline defect and nothing with a success state can be built until it is settled.

| Value | Where | Form |
| --- | --- | --- |
| `#16A34A` | Foundation `.status-badge.active` + `.kpi-trend.up` (lines 148, 152), semantic swatch array line 714 labelled *"Active, paid, verified"*; Index; Super Admin ×102; Community Admin file 01 | **Inline literal — never a token** |
| `#15803D` | Community Admin files **02–10** | `--green-700` — the only one ever tokenised |
| `#4ADE80` | Guard app | Inline — dark-surface variant |
| amber | Resident screens 30, 31 (`.check-circle`) | Resident set contains **no green at all** |

Foundation's `:root` defines **no green**, yet Foundation itself *uses* `#16A34A` inline.
So the master prompt's "success `#16A34A` is LOCKED" is corroborated by the swatch
catalogue but contradicted by 9 of 10 Community Admin files.

The Guard's `#4ADE80` is probably legitimate — `#16A34A` and `#15803D` are both too dark
to read on `--surface-800` `#0E2036` — but "semantic colour is locked and never themed"
means a deliberate exception has to be recorded rather than assumed.

`tokens.css` deliberately leaves the green **undefined** so nothing inherits the wrong one.

### 2. RBAC matrix does not exist anywhere

**The most serious gap.** The Build Spec never prints a role-by-module grid. It gives:

- a flat ten-value role enum (line 470)
- two *screens* that would display a matrix (Super Admin 45, Community Admin 24)
- cross-cutting rule 9: *"The role access matrix screens are the source of truth and the
  navigation is derived from them at runtime"* — **circular**: the rule points at screens,
  the screens describe runtime-editable data

Since rule 9 also says *"A module a role cannot use is absent from the navigation, not
disabled or greyed out"*, **navigation cannot be built at all without the seed grid.**

Two further inconsistencies: `payroll_admin` appears in the Super Admin surface note but
is absent from the role enum (which has `platform_ops`); and two named constraints exist
with nothing to attach them to — *"The property manager role sees no financial modules at
all"* (line 1267) and *"The property manager row cannot be granted financial permissions"*
(line 813).

### 3. Brand mark defect — one instance

`GeminiSecure Community Admin Screens/06 Settings Estate Profile Users and Roles.html:252`

```html
<svg viewBox="0 0 40 40">
  <circle cx="15" cy="20" r="9" fill="#FFFFFF" opacity="0.92"/>
  <circle cx="25" cy="20" r="9" fill="#FFB627" opacity="0.92" style="mix-blend-mode:multiply"/>
</svg>
```

`r=9`, not `r=12.5`. At r=9 the circles barely intersect and the multiply overlap that
*is* the logo nearly disappears. Foundation line 327 says *"Never redraw the mark."*
Recommend correcting it rather than treating it as a small-size variant.

**Four authorised colourways** exist across the conforming 97, so the Vue `BrandMark`
component needs `fill`/`opacity` props: purple+blue `#3B2166`/`#1974D2` @0.92 (46×),
white+amber `#FFFFFF`/`#FFB627` @0.92 (45×), blue+amber `#1974D2`/`#FFB627` @1.0 (5×),
purple+blue @1.0 (2×). Fills are hard-coded hex because SVG presentation attributes as
written do not resolve `var()` — they cannot simply be tokenised during the port.

---

## 🟡 Guard money check — passed, with two things to handle

**No resident balance, arrears figure, ageing bucket or payment history appears on any of
the 40 Guard screens.** The arrears surface lives correctly and exclusively in Community
Admin file 02, which the Guard app never touches.

Three items that are *not* violations but need handling:

1. **No restriction UI exists.** A scan for restriction wording returned **nothing** across
   all 10 Guard files. The `access_restricted` boolean the Build Spec says the guard
   receives is never rendered on any screen. The invariant is not violated, but if a
   restricted household's pass must look different at the gate, **that screen state does
   not exist and is new design work.**
2. **A naming landmine.** Guard file 03 defines `.balance-card` / `.balance-row` /
   `.balance-item` / `.bv` / `.bl` on screen 10. Despite the name the values are **leave
   days** (12 vacation / 6 sick / 3 casual), never money. A developer grepping for
   "balance" could wire it to a financial endpoint. **Rename to `LeaveDaysCard` in the port.**
3. **Guard's own identifiers.** Screen 29 shows the guard's own TRN, NIS and salary
   account, all masked in the design. In scope, but the implementation must mask
   **server-side** and never ship full values to the client.

A near-miss that is fine: screen 21 shows `+4h OT` and a `1.5x` badge — hours and a
multiplier for the guard's own shifts, never currency.

---

## Build Spec coverage of the five missing documents

| Document | Coverage |
| --- | --- |
| `03_PHASE_PLAN` | **Substantially covered** — 8 dependency-ordered phases (Part 5, lines 336–347). **But:** contradicts the master prompt's own 5-step order; phases sum to **125 of 165 screens**, leaving 40 unassigned; and there are **no acceptance gates anywhere** |
| `04_RBAC` | **Not covered.** See blocking decision 2 |
| `02_WEB_MOBILE_SPLIT` | **Fully covered**, stated three times consistently. Nothing missing |
| `05_QUALITY_AND_REPORT` | **Quality not covered at all** — zero hits for QA, testing, WCAG, aria, contrast, performance. Reporting covered only at screen level |
| Open decisions | **Covered** — exactly four (statutory rates unverified; biometric consent needs legal review; payment gateway not provisioned; no public legal pages) |

**Still genuinely missing:** the RBAC grid, phase acceptance gates, the entire quality and
accessibility specification, report formats/schedules/retention/SLAs — and, notably,
**the currency is never named anywhere in any document.** Only bare amounts and "MRR".

---

## Other open questions worth resolving early

- **Guard token rebinding.** Guard files rebind `--navy-900` to `#0B1A2B` and `--navy-300`
  to `#5D87AE` rather than using `--g-navy-*`. 20 of 39 screen files also rebind
  `--page-bg` to `#EEF3F9`. Confirm the port rewrites onto the `--g-*` tokens.
- **`--red-500` conflict.** Foundation and Guard say `#F87171`; Community Admin file 02
  says `#EF4444`. The latter is dead code but is a same-name conflict.
- **Community Admin sidebar** ends its gradient in untokenised `#0A2D52` on all 40 screens;
  Super Admin ends in `var(--purple-900)`. Either `#0A2D52` becomes a token or it is the defect.
- **Untokenised gradient stops** `#2D1B69` and `#0B0A1F` on hero/login, while
  `--purple-800`/`--purple-900` sit unused two lines away with visibly different values.
- **`.kpi-row` column count** — Super Admin and Index say 4, Community Admin says 5,
  Foundation defines the class nowhere.
- **`.status-badge.paid`** means success (green) in Community Admin file 07 but
  neutral/processed (navy) in file 04. A domain question; one component cannot satisfy both.
- **Icon stroke weights.** The Build Spec declares four and forbids 2px; the files use
  between nine and fifteen distinct weights including **47 uses of 2**, and draw the
  identical X glyph at 2.2 in two files and 2.4 in another.
- **Monospace stack** — IBM Plex Mono (Build Spec, a third webfont Foundation never loads),
  Index's `ui-monospace` stack, or Super Admin's bare `monospace`.
- **Hex casing / alpha notation** (`#fff` vs `#FFFFFF`, `0.22` vs `.22`). Normalising
  creates a noisy diff against client-approved files and invites a false "you changed the
  design" review; not normalising ships the inconsistency.

---

## Recommendation on sequencing

Items 1 and 2 (green, RBAC) block real implementation work. Item 3 and the Guard naming
issues are small and can be fixed during the port. The token file is already correct for
the 33 confirmed values, so component conversion can begin on anything that does not use a
success state or role-gated navigation — which in practice means very little. **Resolving
the green and obtaining the RBAC grid are the two highest-value unblocks available.**
