# RBAC Seed Grid — parsed from the wireframe DOM

**Status: awaiting client sign-off. Nothing is seeded until this is approved.**

Parsed from the approved wireframes, not derived or inferred:

| Console | File | Screen | Element |
| --- | --- | --- | --- |
| Gemini (Super Admin) | `GeminiSecure Super Admin Screens/09 Platform Settings.html` | 45 · Role Access Matrix | `table.matrix-table`, lines 1066–1152 |
| Estate (Community Admin) | `GeminiSecure Community Admin Screens/06 Settings Estate Profile Users and Roles.html` | 24 · Role Access Matrix | `table.matrix-table`, lines 581–685 |

Per client instruction the **wireframe role names take precedence** over the ten-value
role enum in the Build Spec domain model (line 470), which is a generalisation.

---

## Grid 1 · Gemini Console (Super Admin) — 6 roles × 8 modules

Permission vocabulary: `Full` · `Scoped` · `View` · `—` (none)

| Module | Director | Operations Manager | Head of Security | Dispatcher | Admin Assistant | Accountant |
| --- | --- | --- | --- | --- | --- | --- |
| Dashboard | Full | Full | Full | Full | Full | Full |
| Clients | Full | Full | Scoped | View | View | View |
| Guard workforce | Full | Full | Scoped | Full | View | View |
| Payroll & Accounting | Full | View | — | — | — | Full |
| Billing & subscriptions | Full | View | — | — | — | Full |
| Cross-tenant reports | Full | Full | Scoped | — | — | View |
| Access & audit log | Full | View | — | — | — | — |
| Platform settings | Full | — | — | — | — | — |

**Role scopes** (from `.role-head-scope`, a distinct axis from permission level):

| Role | Scope |
| --- | --- |
| Director | All sites |
| Operations Manager | All sites |
| **Head of Security** | **Assigned sites only** |
| Dispatcher | All sites |
| Admin Assistant | All sites |
| Accountant | All sites |

Head of Security is the only scope-restricted role, and it is the only role that ever
receives `Scoped` — the two are the same mechanism. `Scoped` is therefore not a fourth
permission level but `Full` narrowed to assigned sites.

---

## Grid 2 · Estate Console (Community Admin) — 6 roles × 10 modules

Permission vocabulary: `Full` · `View` · `Entry` · `—` (none), plus an **`Approver`**
qualifier on certain `Full` cells.

Legend, verbatim from the wireframe (lines 575–578):

| Pill | Meaning |
| --- | --- |
| Full | Create, edit, approve |
| View | Read-only |
| Entry | Data entry, no approval |
| — | No access |

| Module | President | Vice President | Secretary | Property Manager | Treasurer | Admin Assistant |
| --- | --- | --- | --- | --- | --- | --- |
| Dashboard | Full | Full | Full | Full | Full | View |
| Estate structure | View | View | View | Full | — | — |
| Residents | View | View | Full | Full | View | Entry |
| Dues & ledger | View | View | — | **View** | Full · *Approver* | Entry |
| Accounting | View | View | — | **View** | Full · *Approver* | Entry |
| Payroll & HR | View | View | — | — | Full | — |
| Facilities | View | View | View | Full | View | Entry |
| Governance | Full · *Approver* | Full · *Approver* | Full | — | View | — |
| Reports | Full | Full | View | View | Full | — |
| Settings | View | View | — | — | — | — |

### A seventh, unlisted estate role

From the audit note at line 689, verbatim:

> "Super Admin (God mode) isn't shown here — it has Full access to everything **for this
> tenant only**, and is typically held by the property management company's lead or a
> technically-designated committee member. Every role assignment and permission change
> writes to an immutable audit log."

This is an estate-scoped god role, **not** a platform role, and must not be conflated with
the Gemini Console's Director. It needs seeding as a seventh estate role with blanket
`Full`, bounded by tenant.

---

## ⚠ Three things to resolve before I seed this

### 1. The Property Manager contradiction — needs your ruling

The Build Spec states twice that the property manager has no financial access:

- line 1267: *"The property manager role sees no financial modules at all"*
- line 813: *"The property manager row cannot be granted financial permissions"*

**The wireframe matrix contradicts both.** Property Manager holds **`View` on Dues &
ledger** and **`View` on Accounting` (lines 626 and 635).

Your stated hierarchy makes wireframes the visual/interaction truth and the Build Spec the
behavioural truth. A permission grid is behavioural, but it is *drawn* in a wireframe, so
the hierarchy does not settle this one. Flagging rather than choosing, because either
reading is defensible and the consequence is a real access-control decision:

- **Build Spec wins** → both cells become `—`, and line 813 becomes an enforced
  invariant that the matrix UI must refuse to grant.
- **Wireframe wins** → Property Manager gets read-only financial visibility, and the two
  Build Spec lines are amended.

### 2. The two consoles use different permission vocabularies

Gemini uses `Scoped`; Estate uses `Entry`. They are not synonyms — `Scoped` narrows *which
records*, `Entry` narrows *which actions* (data entry, no approval). Proposal, for
confirmation: model them as two orthogonal fields rather than one enum —

- `level`: `full` | `view` | `none`
- `can_approve`: bool — false for `Entry`, true for `Full`, explicit for `Approver`
- `scope`: `all_sites` | `assigned_sites` | `own_tenant`

That represents every cell in both grids without inventing a level, and keeps
`Full · Approver` distinct from plain `Full`.

### 3. `Approver` appears to be load-bearing, not decorative

It marks exactly four cells: Treasurer on Dues & ledger and Accounting; President and Vice
President on Governance. Plain `Full` on Payroll & HR (Treasurer) and Governance
(Secretary) carries no tag. Since the legend already defines `Full` as *"create, edit,
approve"*, the tag must mean something narrower — most likely *the* approver of record for
that module, i.e. a workflow role rather than a permission. Confirm before I model it.

---

## What is not covered by either grid

Neither matrix covers the **Resident** or **Guard** apps. Those surfaces have implicit
single-role models (a resident of a household; an officer on shift) and no in-app role
switching appears anywhere in their 40 screens. Seeding them as fixed roles unless told
otherwise.
