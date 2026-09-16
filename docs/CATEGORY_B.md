# Category B: screens whose data comes from a handset

**Definition (13 C3):** a Category B screen is any web screen whose primary
dataset originates on a mobile device.

**How it is applied:** a screen is Category B when the records it exists to show
are written by an `/api/v1` endpoint that a Guard App or Resident App handset
calls. That covers endpoints built today and endpoints specified in work order
13 D2 and D3. A screen that shows a device figure beside records typed in a
console is not Category B. Every such near miss is listed below with its reason.

**What it obliges:** every Category B screen renders `SourceBadge` beside its
title. The badge reads "Live from Guard App", "Live from Resident App", or amber
"Simulated … data". It is computed from the `is_simulated` flag on the records
on screen, never from the environment (`App\Support\SourceBadge`). Every table
those endpoints write carries that flag.

**How it is enforced:** `App\Support\CategoryB` is the list. `CategoryBTest`
checks both directions: every listed page renders the badge, and no page renders
the badge without being listed. It also checks that every listed server
response carries `sourceBadge`, and that this file names every screen in the
list.

---

## The screens: 17

### Guard App (10)

| Screen | Board | Records it shows | Written by |
| --- | --- | --- | --- |
| `Gemini/Dispatch/Map` | super-admin-12 | `duress_alerts`, `shifts` | `POST /alerts`, `POST /shifts/{id}/clock-in` |
| `Gemini/Dispatch/Coverage` | super-admin-13 | `shifts` (clock-ins) | `POST /shifts/{id}/clock-in`, `/clock-out` |
| `Gemini/Dispatch/Alerts` | super-admin-14 | `duress_alerts` | `POST /alerts`, `POST /duress` |
| `Gemini/Dispatch/Alertness` | super-admin-15 | `alertness_checks`, `checkpoint_scans` | `POST /alertness/{id}/respond`, `POST /checkpoints/{id}/scan` |
| `Gemini/Dispatch/Requests` | super-admin-16 | `guard_requests` | `POST /requests` |
| `Gemini/Dispatch/RequestHistory` | none (board 16's history) | `guard_requests`, decided | `POST /requests` |
| `Gemini/Dispatch/Alert` | super-admin-17 | one `duress_alert` | `POST /alerts`, `POST /duress` |
| `Gemini/Operations/GateActivity` | super-admin-26 | `gate_events` | `POST /gate-events`, `/gate/entry`, `/gate/exit`, `/gate/override` |
| `Gemini/Operations/Incidents` | super-admin-27 | `security_incidents` | `POST /incidents` |
| `Gemini/Operations/Incident` | none (board 27's View) | one `security_incident` | `POST /incidents`, `POST /incidents/{id}/media` |

### Resident App (7)

| Screen | Board | Records it shows | Written by |
| --- | --- | --- | --- |
| `Estate/Governance/ControlRoom` | community-admin-09 | `ballot_receipts` (turnout) | `POST /elections/{id}/ballot` |
| `Estate/Governance/Results` | community-admin-11 | `ballot_receipts`, `ballot_marks` | `POST /elections/{id}/ballot` |
| `Estate/Facilities/Maintenance` | community-admin-17 | `maintenance_tickets` | `POST /tickets` |
| `Estate/Facilities/Ticket` | community-admin-18 | one `maintenance_ticket` | `POST /tickets`, `POST /tickets/{id}/media` |
| `Estate/Facilities/Bookings` | community-admin-19 | `amenity_bookings` | `POST /bookings` |
| `Estate/Facilities/Booking` | none (D-086) | one `amenity_booking` | `POST /bookings`, `POST /bookings/{id}/cancel` |
| `Estate/Residents/Claims` | community-admin-31 | `unit_claims` | `POST /auth/claim-unit` |

On ballots, the flag is on the **receipt**, never on the mark. A receipt already
names its household, so a simulated flag on it links nothing new. A flag on the
mark would be one more column to match a vote on.

---

## Near misses: 14 screens that are not Category B

| Screen | Why not |
| --- | --- |
| `Gemini/Dashboard` | A platform overview of clients, revenue and counts. Its alert tile links to the queue, which carries the badge. |
| `Gemini/Reports/ClientHealth` | Adoption percentages computed from device events, not the events themselves. Each capability's own screen carries the badge. |
| `Gemini/Reports/Utilisation` | Contracted guards against deployed guards, from the workforce record. No device row is on it. |
| `Gemini/Guards/Show` | The guard's HR record: licence, post, standing. |
| `Gemini/Clients/Guards` | Deployment, from the workforce record. |
| `Gemini/Operations/Roster` | Shifts as a supervisor rostered them in the console. Clock-ins are on the coverage board. |
| `Gemini/Operations/StandingOrders` | Orders written in the console. The acknowledgement count is secondary to the text. |
| `Gemini/Operations/StandingOrder` | One set's text, written in the console, with acknowledgements beneath it. |
| `Gemini/Simulator/Index` | The producer of simulated data, not a view of it. |
| `Estate/Dashboard` | An estate overview led by dues and notices. |
| `Estate/Reports/Show` | The shell for seven reports. Only one of them (security incidents) is device-derived, so a badge on the shell would mislabel the other six. |
| `Estate/Governance/Nominations` | Nominations are lodged with the estate office. No nominations endpoint is specified for the Resident App. |
| `Estate/Governance/Notices` | Written in the console and sent to residents. Read receipts are secondary. |
| `Estate/Residents/Show` | The household's register entry, maintained by the estate. |

---

## Adding a screen

A new screen whose records a handset writes goes into `CategoryB::SCREENS` and
into this file, and it renders `<SourceBadge v-bind="sourceBadge" />` in its
layout's `byline` slot. The test fails until all three are done.
