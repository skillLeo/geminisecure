# Mobile handoff — the Guard App and Resident App API

> **Generated.** `php artisan api:handoff` writes this file from `App\Api\Catalogue`, `App\Api\AppMatrix`
> and the rate limiter; `MobileHandoffTest` fails when the two disagree. Do not edit it by hand —
> change the catalogue entry and regenerate.

Everything below exists, is routed from the same catalogue this was written from, and is exercised on
every test run: `GuardApiContractTest` walks every Guard App route, `ResidentApiContractTest` every
Resident App route, each response held to the keys its table lists. A key not in a table is a test
failure, not a surprise.

---

## 1. Conventions

| | |
|---|---|
| Base URL | `https://{platform host}/api/v1` |
| Format | JSON in and out. Send `Accept: application/json`. Multipart only for the two media uploads. |
| Authentication | `Authorization: Bearer {token}` on every endpoint except the four sign-in endpoints |
| Writes | `Idempotency-Key` and `X-Device-Time` headers — see §3 |
| Times | ISO 8601 with offset, e.g. `2026-09-17T09:41:02-05:00`. Dates `YYYY-MM-DD`. The server answers in UTC; display in `America/Jamaica`. |
| Money | Decimal strings, `"38450.00"`, never numbers. Currency `JMD`. |
| Ids | Integers unless named `pass_id` (a UUID) |
| Lists | Bounded, not paginated: most return the latest 100, and the ones that take a range say so. |
| Tokens | Do not expire. A `401` means the token was revoked — a rebind, a new sign-in on that install, or the office. Enrol or sign in again. |
| Unknown keys | Ignore response keys the app does not know; new keys are added to this document before they ship. |
| Errors | `{"error": {"code": "snake_case", "message": "A sentence to show."}}` — §5 |

### The rule that shapes the Guard App

**No endpoint a guard's token reaches returns a household's money.** A household in arrears reaches a
handset as `access_restricted: true` and an amber verdict, and nothing else — no balance, no bucket, no
wording that implies one. The one money a guard's handset reads is their **own** payslip. The contract
tests scan every guard response for money-named keys and currency figures. Build the Guard App assuming
the figure is unavailable; it is the product, not an oversight.

### The rule that shapes the Resident App

**One household, the token's.** Every dues, pass, ticket and booking endpoint is keyed on the account's
own unit; an id belonging to another household answers `404 not_found`, exactly as an id nobody has.

---

## 2. Getting a token

### Guard App — enrolment by code

1. Gemini's office issues a one-time code for the guard: `php artisan device:enrolment-code GS-1041`
   (hashed at rest, 24 hours, once).
2. The app generates an **Ed25519 key pair** on the device and keeps the secret half in the secure enclave.
3. `POST /devices/enrol` with the code, a stable install UID, the platform and the public key.
   - A guard with no handset (or this same install): `201`, `status: enrolled`, and the token — shown once.
   - A guard already bound to a different handset: `202`, `status: pending_approval`, a
     `rebind_request_id` and a `claim_secret`. A supervisor approves on the guard's profile; the app polls
     `POST /devices/enrol/{rebind}/collect` and receives the token once. The old handset's token stops
     working at that moment, and the approval is recorded on the guard's shift.
4. Store the token in the keychain/keystore. One guard, one working handset.

### Resident App — sign-in by code, then a unit claim

1. `POST /auth/otp/request` with the estate, `email` or `sms`, and the address. The answer is identical
   whether or not an account exists. A code lasts 10 minutes and 5 tries.
2. `POST /auth/otp/verify` with the code and the install. The response carries the token and `next`:
   - `claim_unit` — a new account is **pending**. `POST /auth/claim-unit`.
   - `await_approval` — the estate is reviewing the claim. Poll `GET /me`.
   - `home` — the account is active.
3. A pending token reaches `GET /me` and `POST /auth/claim-unit` and nothing else (`403 account_pending`).
   When the estate approves the claim, the account is active on its next request — no new sign-in.
4. Text messages need an SMS provider the platform does not have yet: `sms` answers
   `503 sms_unavailable` in production. Offer email.

---

## 3. Writes: idempotency and the device clock

**`Idempotency-Key`** — required on every write marked *write* below (the first seven endpoints accept it
without requiring it). Choose it when the act happens, store it with the queued act, and reuse it on
every retry until the server answers. The first answer is stored against this token and key, and every
retry gets it back byte for byte with `Idempotent-Replayed: true`; nothing happens twice.

| Same key and… | Answer |
|---|---|
| the same request | the stored response, replayed |
| a different request | `422 idempotency_key_reused` — a bug in the app |
| the first attempt still running | `409 request_in_progress` — retry with the same key |
| the first attempt failed with a 5xx | nothing was stored — the retry is a real attempt |

The ballot is the one exception to "a different request": its record keeps a hash of the method and path
only, never the paper, so a stored hash cannot be tried against the options to learn a vote. A different
paper under the same key is answered with the first answer.

**`X-Device-Time`** — the handset's own clock at the moment the act happened (for a queued act, when it
was captured, not when it was sent). Required on writes. The server never corrects it: every successful
write answers `server_time`, `device_time` and `clock_skewed` (true beyond 120 seconds). Show a guard
whose clock is wrong that it is wrong.

---

## 4. Offline

### Verifying a visitor pass with no signal

A pass in a QR code is `base64url(payload) . "." . base64url(signature)`. The payload is canonical JSON
with exactly these fields in this order: pass_id · tenant_id · site_id · valid_from · valid_to · single_use · nonce · key_version. It names no visitor, unit or household.

On the handset:

1. Split at the `.`; base64url-decode both halves. Two halves, a 64-byte signature and those eight keys in
   that order — anything else is `malformed`.
2. `site_id` must be the site the handset is posted to — else `wrong_site`. Decide this before any key.
3. Look up the cached public key for `site_id` and `key_version` (`GET /sites/{site}/pass-keys`, refreshed
   at every sync) — none is `unknown_key`.
4. Verify the Ed25519 detached signature **over the base64url payload segment exactly as it appears in the
   token** (the ASCII bytes, not the decoded JSON) — failure is `bad_signature`.
5. Compare `valid_from`/`valid_to` to the handset's clock with 120 seconds' tolerance —
   `not_yet_valid` / `expired`.
6. Otherwise the verdict is **`valid_offline`** — never `valid`. Show it as "Valid pass — not checked
   against cancellations".

What the handset cannot know offline — a cancellation, or a single-use pass already used at another gate —
it learns at sync: `GET /sync/pull` returns every still-unexpired pass revoked since the last pull; refuse
those locally from then on. An admission made offline is sent as `POST /gate/entry` with
`verified_offline: true`; the server records it and answers `reconciliation`, which the app shows the guard.

Retired signing keys stay published for 31 days — the longest a pass runs — so a rotation never
strands a pass already issued. `libsodium` (`crypto_sign_verify_detached`) or any Ed25519 implementation
verifies it; `App\Services\Passes\OfflinePassVerifier` is the reference implementation and runs in a test
with every database connection severed.

### The queue

Capture every write offline with its Idempotency-Key and its device time, in order. When signal returns:

1. `GET /sync/pull?since={last next_since}` — shifts, order versions, messages, pending alertness checks,
   revoked passes and current pass keys, request and claim decisions.
2. `POST /sync/batch` with up to fifty queued operations, oldest first, each naming its endpoint by the
   catalogue name in the heading of its table below. Each runs through that endpoint exactly as if sent
   live. The batch stops at the first server failure (`failed`, then `not_attempted`) and never at a
   refusal (`refused` is an answer — show it). Resend what was not `ok` or `refused`, with the same keys.
3. Media uploads are not batchable: send them after the report they belong to has an id.

Panic and duress are the exception to queueing quietly: send the moment any signal exists, and prompt the
user to call the emergency number meanwhile.

---

## 5. Abilities

A token carries the abilities its app holds in `App\Api\AppMatrix` — `{capability}:read`, and `{capability}:write` where the app may change it. Each endpoint below names the one it needs; a token without it is `403 missing_ability`.

| Capability | Covers | Guard App | Resident App |
|---|---|---|---|
| `alerts` | Raise, and cancel within the grace period, a panic or duress alert | write | write |
| `gate` | Verify passes, record entries, exits and overrides, search units, read the gate log | write | none |
| `passes` | Issue, share, cancel and read visitor passes; the resident's own e-pass | none | write |
| `approvals` | Answer a guard's walk-up entry request for the resident's own unit | none | write |
| `shifts` | Read the roster, pre-flight, clock on and off, take breaks, claim open shifts | write | none |
| `orders` | Read and acknowledge standing orders for the guard's posts | write | none |
| `patrol` | Read a site's checkpoints and scan them | write | none |
| `alertness` | Answer a random alertness check | write | none |
| `presence` | Report on-post activity and read the guard's own activity summary | write | none |
| `incidents` | File incident reports with media, and read the guard's own | write | none |
| `requests` | Raise leave and equipment requests, and read the guard's own | write | none |
| `payslips` | Read the guard's own payslips — their own wage, and nobody else's | read | none |
| `messages` | Read and send messages with dispatch | write | none |
| `sync` | Upload a queue captured offline, and pull what changed | write | none |
| `household` | The resident's own profile, household members, vehicles and emergency contacts | none | write |
| `dues` | The resident's OWN household's invoices, balance, statement and receipts | none | read |
| `payments` | Start a payment and manage AutoPay for the resident's own household | none | write |
| `tickets` | Report maintenance problems with media, and follow them | none | write |
| `notices` | Read notices and mark them read | none | write |
| `meetings` | Read meetings and RSVP | none | write |
| `elections` | Read elections, check eligibility, cast the household's ballot, read results | none | write |
| `bookings` | Read amenities, book and cancel | none | write |

### Errors any endpoint can return

Shape: `{"error": {"code": "…", "message": "…"}}`. A `422 validation_failed` also carries `errors`: `{"field": ["message", …]}`. Branch on `error.code`, never on the status alone; show `error.message`, which is written for the person holding the phone.

| Status | Code | When |
|---|---|---|
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 404 | `not_found` | Nothing at this address, or nothing this handset may see — the two are deliberately the same. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |

---

## 6. Rate limits

Exceeding one answers `429 rate_limited` with `Retry-After`; retry after it with the same Idempotency-Key. The alert limit sits where no frightened person can reach it and only a loop can.

| Limiter | Per minute | Keyed | Endpoints |
|---|---|---|---|
| `api-alerts` | 30 | per device (token) | `alerts.store`, `duress.store`, `duress.cancel` |
| `api-gate-events` | 120 | per device (token) | `passes.verify`, `gate_events.store`, `gate.verify`, `gate.entry`, `gate.exit`, `gate.override`, `gate.approvals.store` |
| `api-shift-clock` | 20 | per device (token) | `shifts.clock_in`, `shifts.clock_out`, `shifts.break.start`, `shifts.break.end`, `orders.acknowledge` |
| `api-reads` | 240 | per device (token) | `standing_orders.index`, `passes.keys`, `shifts.me.current`, `shifts.me`, `shifts.preflight`, `shifts.open`, `orders.current`, `patrol.checkpoints`, `presence.summary`, `gate.search`, `gate.activity`, `gate.approvals.show`, `incidents.me`, `requests.index`, `payslips.me`, `messages.index`, `sync.pull`, `me.show`, `me.household`, `members.index`, `vehicles.index`, `contacts.index`, `visitor_passes.index`, `epass.show`, `invoices.index`, `households.balance`, `households.statement`, `payments.index`, `payments.receipt`, `tickets.index`, `notices.index`, `meetings.index`, `elections.index`, `elections.eligibility`, `elections.results`, `amenities.index`, `bookings.index` |
| `api-writes` | 120 | per device (token) | `shifts.claim`, `patrol.scan`, `alertness.respond`, `presence.activity`, `incidents.store`, `incidents.media`, `requests.store`, `messages.store`, `sync.batch`, `auth.claim_unit`, `me.update`, `members.store`, `vehicles.store`, `contacts.store`, `visitor_passes.store`, `visitor_passes.update`, `visitor_passes.cancel`, `visitor_passes.share`, `approvals.respond`, `invoices.pay_intent`, `autopay.store`, `autopay.destroy`, `tickets.store`, `tickets.media`, `notices.read`, `meetings.rsvp`, `elections.ballot`, `bookings.store`, `bookings.cancel` |
| `api-enrol` | 10 | per IP address | `devices.enrol`, `devices.enrol.status`, `auth.otp.request`, `auth.otp.verify` |

---

## 7. Endpoint index

| Endpoint | Method | Path | App | Ability | Write |
|---|---|---|---|---|---|
| [`devices.enrol`](#devicesenrol) | POST | `/devices/enrol` | Guard, before enrolment — no token | — | — |
| [`devices.enrol.status`](#devicesenrolstatus) | POST | `/devices/enrol/{rebind}/collect` | Guard, before enrolment — no token | — | — |
| [`alerts.store`](#alertsstore) | POST | `/alerts` | Guard + Resident | `alerts:write` | yes |
| [`passes.verify`](#passesverify) | POST | `/passes/verify` | Guard | `gate:write` | — |
| [`gate_events.store`](#gate_eventsstore) | POST | `/gate-events` | Guard | `gate:write` | yes |
| [`shifts.clock_in`](#shiftsclock_in) | POST | `/shifts/{shift}/clock-in` | Guard | `shifts:write` | yes |
| [`shifts.clock_out`](#shiftsclock_out) | POST | `/shifts/{shift}/clock-out` | Guard | `shifts:write` | yes |
| [`standing_orders.index`](#standing_ordersindex) | GET | `/standing-orders` | Guard | `orders:read` | — |
| [`passes.keys`](#passeskeys) | GET | `/sites/{site}/pass-keys` | Guard | `gate:read` | — |
| [`shifts.me.current`](#shiftsmecurrent) | GET | `/shifts/me/current` | Guard | `shifts:read` | — |
| [`shifts.me`](#shiftsme) | GET | `/shifts/me` | Guard | `shifts:read` | — |
| [`shifts.preflight`](#shiftspreflight) | POST | `/shifts/{shift}/preflight` | Guard | `shifts:read` | — |
| [`shifts.break.start`](#shiftsbreakstart) | POST | `/shifts/{shift}/break/start` | Guard | `shifts:write` | yes |
| [`shifts.break.end`](#shiftsbreakend) | POST | `/shifts/{shift}/break/end` | Guard | `shifts:write` | yes |
| [`shifts.open`](#shiftsopen) | GET | `/shifts/open` | Guard | `shifts:read` | — |
| [`shifts.claim`](#shiftsclaim) | POST | `/shifts/{shift}/claim` | Guard | `shifts:write` | yes |
| [`orders.current`](#orderscurrent) | GET | `/sites/{site}/standing-orders/current` | Guard | `orders:read` | — |
| [`orders.acknowledge`](#ordersacknowledge) | POST | `/standing-orders/{version}/acknowledge` | Guard | `orders:write` | yes |
| [`patrol.checkpoints`](#patrolcheckpoints) | GET | `/sites/{site}/checkpoints` | Guard | `patrol:read` | — |
| [`patrol.scan`](#patrolscan) | POST | `/checkpoints/{checkpoint}/scan` | Guard | `patrol:write` | yes |
| [`alertness.respond`](#alertnessrespond) | POST | `/alertness/{check}/respond` | Guard | `alertness:write` | yes |
| [`presence.activity`](#presenceactivity) | POST | `/presence/activity` | Guard | `presence:write` | yes |
| [`presence.summary`](#presencesummary) | GET | `/guards/me/activity-summary` | Guard | `presence:read` | — |
| [`gate.verify`](#gateverify) | POST | `/gate/verify` | Guard | `gate:write` | — |
| [`gate.entry`](#gateentry) | POST | `/gate/entry` | Guard | `gate:write` | yes |
| [`gate.exit`](#gateexit) | POST | `/gate/exit` | Guard | `gate:write` | yes |
| [`gate.override`](#gateoverride) | POST | `/gate/override` | Guard | `gate:write` | yes |
| [`gate.search`](#gatesearch) | GET | `/gate/search` | Guard | `gate:read` | — |
| [`gate.activity`](#gateactivity) | GET | `/gate/activity` | Guard | `gate:read` | — |
| [`gate.approvals.store`](#gateapprovalsstore) | POST | `/gate/approvals` | Guard | `gate:write` | yes |
| [`gate.approvals.show`](#gateapprovalsshow) | GET | `/gate/approvals/{approval}` | Guard | `gate:read` | — |
| [`incidents.store`](#incidentsstore) | POST | `/incidents` | Guard | `incidents:write` | yes |
| [`incidents.me`](#incidentsme) | GET | `/incidents/me` | Guard | `incidents:read` | — |
| [`incidents.media`](#incidentsmedia) | POST | `/incidents/{incident}/media` | Guard | `incidents:write` | yes |
| [`duress.store`](#duressstore) | POST | `/duress` | Guard + Resident | `alerts:write` | yes |
| [`duress.cancel`](#duresscancel) | POST | `/duress/{alert}/cancel` | Guard + Resident | `alerts:write` | yes |
| [`requests.index`](#requestsindex) | GET | `/requests` | Guard | `requests:read` | — |
| [`requests.store`](#requestsstore) | POST | `/requests` | Guard | `requests:write` | yes |
| [`payslips.me`](#payslipsme) | GET | `/payslips/me` | Guard | `payslips:read` | — |
| [`messages.index`](#messagesindex) | GET | `/messages` | Guard | `messages:read` | — |
| [`messages.store`](#messagesstore) | POST | `/messages` | Guard | `messages:write` | yes |
| [`sync.batch`](#syncbatch) | POST | `/sync/batch` | Guard | `sync:write` | yes |
| [`sync.pull`](#syncpull) | GET | `/sync/pull` | Guard | `sync:read` | — |
| [`auth.otp.request`](#authotprequest) | POST | `/auth/otp/request` | Resident, before sign-in — no token | — | — |
| [`auth.otp.verify`](#authotpverify) | POST | `/auth/otp/verify` | Resident, before sign-in — no token | — | — |
| [`auth.claim_unit`](#authclaim_unit) | POST | `/auth/claim-unit` | Resident | `household:write` | yes |
| [`me.show`](#meshow) | GET | `/me` | Resident | `household:read` | — |
| [`me.update`](#meupdate) | PATCH | `/me` | Resident | `household:write` | yes |
| [`me.household`](#mehousehold) | GET | `/me/household` | Resident | `household:read` | — |
| [`members.index`](#membersindex) | GET | `/household-members` | Resident | `household:read` | — |
| [`members.store`](#membersstore) | POST | `/household-members` | Resident | `household:write` | yes |
| [`vehicles.index`](#vehiclesindex) | GET | `/vehicles` | Resident | `household:read` | — |
| [`vehicles.store`](#vehiclesstore) | POST | `/vehicles` | Resident | `household:write` | yes |
| [`contacts.index`](#contactsindex) | GET | `/emergency-contacts` | Resident | `household:read` | — |
| [`contacts.store`](#contactsstore) | POST | `/emergency-contacts` | Resident | `household:write` | yes |
| [`visitor_passes.index`](#visitor_passesindex) | GET | `/visitor-passes` | Resident | `passes:read` | — |
| [`visitor_passes.store`](#visitor_passesstore) | POST | `/visitor-passes` | Resident | `passes:write` | yes |
| [`visitor_passes.update`](#visitor_passesupdate) | PATCH | `/visitor-passes/{pass}` | Resident | `passes:write` | yes |
| [`visitor_passes.cancel`](#visitor_passescancel) | POST | `/visitor-passes/{pass}/cancel` | Resident | `passes:write` | yes |
| [`visitor_passes.share`](#visitor_passesshare) | POST | `/visitor-passes/{pass}/share` | Resident | `passes:write` | yes |
| [`epass.show`](#epassshow) | GET | `/e-pass` | Resident | `passes:read` | — |
| [`approvals.respond`](#approvalsrespond) | POST | `/gate/approval/{approval}/respond` | Resident | `approvals:write` | yes |
| [`invoices.index`](#invoicesindex) | GET | `/invoices` | Resident | `dues:read` | — |
| [`households.balance`](#householdsbalance) | GET | `/households/{household}/balance` | Resident | `dues:read` | — |
| [`households.statement`](#householdsstatement) | GET | `/households/{household}/statement` | Resident | `dues:read` | — |
| [`invoices.pay_intent`](#invoicespay_intent) | POST | `/invoices/{invoice}/pay-intent` | Resident | `payments:write` | — |
| [`payments.index`](#paymentsindex) | GET | `/payments` | Resident | `dues:read` | — |
| [`payments.receipt`](#paymentsreceipt) | GET | `/payments/{payment}/receipt` | Resident | `dues:read` | — |
| [`autopay.store`](#autopaystore) | POST | `/autopay` | Resident | `payments:write` | — |
| [`autopay.destroy`](#autopaydestroy) | DELETE | `/autopay/{autopay}` | Resident | `payments:write` | — |
| [`tickets.index`](#ticketsindex) | GET | `/tickets` | Resident | `tickets:read` | — |
| [`tickets.store`](#ticketsstore) | POST | `/tickets` | Resident | `tickets:write` | yes |
| [`tickets.media`](#ticketsmedia) | POST | `/tickets/{ticket}/media` | Resident | `tickets:write` | yes |
| [`notices.index`](#noticesindex) | GET | `/notices` | Resident | `notices:read` | — |
| [`notices.read`](#noticesread) | POST | `/notices/{notice}/read` | Resident | `notices:write` | yes |
| [`meetings.index`](#meetingsindex) | GET | `/meetings` | Resident | `meetings:read` | — |
| [`meetings.rsvp`](#meetingsrsvp) | POST | `/meetings/{meeting}/rsvp` | Resident | `meetings:write` | yes |
| [`elections.index`](#electionsindex) | GET | `/elections` | Resident | `elections:read` | — |
| [`elections.eligibility`](#electionseligibility) | GET | `/elections/{ballot}/eligibility` | Resident | `elections:read` | — |
| [`elections.ballot`](#electionsballot) | POST | `/elections/{ballot}/ballot` | Resident | `elections:write` | yes |
| [`elections.results`](#electionsresults) | GET | `/elections/{ballot}/results` | Resident | `elections:read` | — |
| [`amenities.index`](#amenitiesindex) | GET | `/amenities` | Resident | `bookings:read` | — |
| [`bookings.index`](#bookingsindex) | GET | `/bookings` | Resident | `bookings:read` | — |
| [`bookings.store`](#bookingsstore) | POST | `/bookings` | Resident | `bookings:write` | yes |
| [`bookings.cancel`](#bookingscancel) | POST | `/bookings/{booking}/cancel` | Resident | `bookings:write` | yes |

---

## 8. Endpoints

### `devices.enrol`

Bind this handset to the guard an enrolment code was issued to, and receive its token. A guard who already has a handset gets a rebind request a supervisor must approve instead.

| | |
|---|---|
| Method and path | `POST /api/v1/devices/enrol` |
| App | Guard, before enrolment — no token |
| Ability | none — no token |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `201` |
| Rate limit | `api-enrol` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `enrolment_code` | string · required · the one-time code Gemini's office gave the guard |
| `device_uid` | string · required · a stable identifier for this install, max 120 |
| `platform` | string · required · `ios` or `android` |
| `public_key` | string · required · the handset's Ed25519 public key, base64url (32 bytes) |
| `label` | string · optional · what the handset is, e.g. "iPhone 15 Pro · Main Gate" |

**Response** `201`

| Key | Meaning |
|---|---|
| `status` | `enrolled`, or `pending_approval` for a rebind |
| `token` | the bearer token — shown once, store it in the secure enclave. Absent while pending. |
| `abilities.*` | the abilities the token carries, from the app matrix. Absent while pending. |
| `guard.id` | the guard |
| `guard.name` | their name, to confirm on screen |
| `guard.employee_number` | GS-1041 |
| `site.id` | the estate the guard is posted to |
| `site.name` | its name |
| `rebind_request_id` | while pending: the request to poll |
| `claim_secret` | while pending: present this to collect the token once approved. Shown once. |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 422 | `enrolment_code_invalid` | The code is unknown, expired or already used. |
| 403 | `guard_not_active` | The code's guard is suspended or no longer employed. |
| 422 | `public_key_invalid` | The public key is not 32 base64url-encoded bytes. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |

**Offline** — Online only. Enrolment happens once, at the office.

### `devices.enrol.status`

Poll a pending rebind, and collect the token once a supervisor approves it. The previous handset's token stops working at that moment.

| | |
|---|---|
| Method and path | `POST /api/v1/devices/enrol/{rebind}/collect` |
| App | Guard, before enrolment — no token |
| Ability | none — no token |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-enrol` |
| Path parameters | `rebind` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `claim_secret` | string · required · from the enrol response |
| `device_uid` | string · required · the same UID the request was made with |

**Response** `200`

| Key | Meaning |
|---|---|
| `status` | `pending_approval`, `approved` (token below, once), `denied`, or `collected` |
| `token` | present only on the first poll after approval |
| `abilities.*` | with the token |
| `decision_note` | the supervisor's note, when denied |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | No such request, or the claim secret or device UID does not match. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |

**Offline** — Online only.

### `alerts.store`

Raise a panic, duress, medical, fire or intrusion alert. A guard's duress and a resident's panic are the same event to dispatch.

| | |
|---|---|
| Method and path | `POST /api/v1/alerts` |
| App | Guard + Resident |
| Ability | `alerts:write` |
| Idempotency-Key | accepted, not required |
| X-Device-Time | accepted, not required |
| Success | `201` |
| Rate limit | `api-alerts` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `kind` | string · required · `panic`, `duress`, `medical`, `fire` or `intrusion` |
| `unit_reference` | string · optional · the resident's unit, e.g. "Lot 47"; a resident token's own unit is used when omitted |
| `latitude` | number · optional |
| `longitude` | number · optional |
| `captured_offline` | boolean · optional · true when the alert was raised with no signal and is being sent now |
| `tenant_id` | string · optional, legacy · must equal the token's own estate when sent |
| `guard_id` | integer · optional, legacy · must equal the token's own guard when sent |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the alert |
| `status` | `open` |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `wrong_site` | `tenant_id` or `guard_id` in the body names another estate or guard. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue with its Idempotency-Key and `captured_offline: true`; send the moment signal returns. The device time is the moment of the press. Also call the local emergency number.

### `passes.verify`

Legacy household verdict by household id. Superseded by `POST /gate/verify`, which verifies a signed pass; kept for the simulator.

| | |
|---|---|
| Method and path | `POST /api/v1/passes/verify` |
| App | Guard |
| Ability | `gate:write` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-gate-events` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `household_id` | integer · required |
| `pass_category` | string · required · e.g. `guest` |

**Response** `200`

| Key | Meaning |
|---|---|
| `verdict` | `admit` or `restricted`; `deny` when the household is unknown |
| `tone` | `green`, `amber` or `red` |
| `headline` | what the guard is told |
| `detail` | one sentence, or null |
| `household` | the household's name |
| `unit` | the unit reference |
| `access_restricted` | boolean — the ONLY thing a guard is ever told about a household's standing |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `deny` | No such household at this estate (body carries `verdict: deny`). |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Not available offline. Use `POST /gate/verify` with a signed pass, which verifies on the handset.

### `gate_events.store`

Record what the guard decided at the gate, after the verdict. Legacy form of `/gate/entry`, `/gate/exit` and `/gate/override`.

| | |
|---|---|
| Method and path | `POST /api/v1/gate-events` |
| App | Guard |
| Ability | `gate:write` |
| Idempotency-Key | accepted, not required |
| X-Device-Time | accepted, not required |
| Success | `201` |
| Rate limit | `api-gate-events` |
| In `sync/batch` | yes |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `verdict` | string · required · `admit`, `deny`, `override` or `exit` |
| `category` | string · required · e.g. `Visitor`, `Contractor`, `Delivery` |
| `subject` | string · required · who or what arrived |
| `basis` | string · required · `QR pass`, `Pre-approved`, `Tag read` or `Guard decision` |
| `reason` | string · required for `override` |
| `post_id` | integer · optional · defaults to the guard's own post |
| `tenant_id` | string · optional, legacy · must equal the token's estate |
| `guard_id` | integer · optional, legacy · must equal the token's guard |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the gate event |
| `verdict` | as recorded |
| `occurred_at` | the server's time of the event |
| `pass_based` | true when the basis was a platform-issued pass or approval |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `wrong_site` | A body field names another estate, guard or post. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue with its Idempotency-Key; the device time is when the guard decided.

### `shifts.clock_in`

Clock on. The first clock-in is the one that happened; a replayed queue never moves it.

| | |
|---|---|
| Method and path | `POST /api/v1/shifts/{shift}/clock-in` |
| App | Guard |
| Ability | `shifts:write` |
| Idempotency-Key | accepted, not required |
| X-Device-Time | accepted, not required |
| Success | `200` |
| Rate limit | `api-shift-clock` |
| Path parameters | `shift` matches `[0-9]+` |
| In `sync/batch` | yes |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `geofence_distance_m` | integer · optional · metres from the post boundary; stored, not enforced here (see `/preflight`) |
| `mock_location` | boolean · optional · the handset's own mock-location detection |
| `method` | string · optional · `app` (default) or `manual` |

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the shift |
| `status` | `on_duty` after clock-in, `completed` after clock-out |
| `actual_start` | the server's record of when the shift started — the FIRST clock-in, however many were sent |
| `actual_end` | when it ended, or null |
| `mock_location_flag` | true when the handset reported a mocked location at clock-in; recorded, never refused |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `not_your_shift` | The shift is rostered to another guard. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 404 | `not_found` | Nothing at this address, or nothing this handset may see — the two are deliberately the same. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue with its Idempotency-Key and the device time of the tap. The first clock-in wins.

### `shifts.clock_out`

Clock off, with an optional handover note for the next shift.

| | |
|---|---|
| Method and path | `POST /api/v1/shifts/{shift}/clock-out` |
| App | Guard |
| Ability | `shifts:write` |
| Idempotency-Key | accepted, not required |
| X-Device-Time | accepted, not required |
| Success | `200` |
| Rate limit | `api-shift-clock` |
| Path parameters | `shift` matches `[0-9]+` |
| In `sync/batch` | yes |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `handover_note` | string · optional · max 500 · read by whoever relieves the post |

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the shift |
| `status` | `on_duty` after clock-in, `completed` after clock-out |
| `actual_start` | the server's record of when the shift started — the FIRST clock-in, however many were sent |
| `actual_end` | when it ended, or null |
| `mock_location_flag` | true when the handset reported a mocked location at clock-in; recorded, never refused |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `not_your_shift` | The shift is rostered to another guard. |
| 409 | `shift_not_started` | No clock-in is recorded. Send the queued clock-in first. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 404 | `not_found` | Nothing at this address, or nothing this handset may see — the two are deliberately the same. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue after the clock-in it follows; the sync batch preserves order.

### `standing_orders.index`

Every order set the guard works to: general orders and their post's. Superseded by `GET /sites/{site}/standing-orders/current`.

| | |
|---|---|
| Method and path | `GET /api/v1/standing-orders` |
| App | Guard |
| Ability | `orders:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `orders.*.id` | the order set |
| `orders.*.title` | its title |
| `orders.*.version` | the current version |
| `orders.*.effective_on` | YYYY-MM-DD |
| `orders.*.body` | the full text |
| `orders.*.requires_acknowledgement` | true for the guard's post orders |
| `orders.*.acknowledged` | whether this guard has acknowledged THIS version |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Cache the last response and show it; an acknowledgement made offline is queued.

### `passes.keys`

The site's Ed25519 PUBLIC keys, every version a still-valid pass may carry. Cache them: they are what verifies a pass with no signal.

| | |
|---|---|
| Method and path | `GET /api/v1/sites/{site}/pass-keys` |
| App | Guard |
| Ability | `gate:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |
| Path parameters | `site` matches `[a-z0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `site_id` | the site |
| `algorithm` | `Ed25519` |
| `keys.*.version` | the key version a pass payload names |
| `keys.*.public_key` | base64url, 32 bytes |
| `keys.*.current` | true for the version new passes are signed with |
| `refresh_after` | fetch again after this time |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `wrong_site` | A site other than the guard's own. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 404 | `not_found` | Nothing at this address, or nothing this handset may see — the two are deliberately the same. |

**Offline** — Serve from cache. Refresh at every sync; a key the handset has never seen verifies as `unknown_key`.

### `shifts.me.current`

The dashboard: the shift the guard is on, or the next one rostered to them. Board guard-app-01.

| | |
|---|---|
| Method and path | `GET /api/v1/shifts/me/current` |
| App | Guard |
| Ability | `shifts:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `shift` | null when nothing is running or rostered ahead |
| `shift.id` | the shift |
| `shift.status` | `rostered`, `open`, `on_duty`, `completed` or `missed` |
| `shift.post.id` | the post |
| `shift.post.name` | e.g. "Main Gate" |
| `shift.site.id` | the estate |
| `shift.site.name` | its name |
| `shift.rostered_start` | ISO 8601 |
| `shift.rostered_end` | ISO 8601 |
| `shift.actual_start` | the first clock-in the server recorded, or null |
| `shift.actual_end` | the clock-out, or null |
| `shift.on_break` | true while a break is open |
| `shift.break_minutes` | breaks taken on this shift so far |
| `shift.previous_handover_note` | what the last guard on this post left for the next, or null |
| `shift.orders_to_acknowledge` | how many of the post's order sets this guard has not acknowledged at their current version |
| `shift.colleagues.*.name` | another guard on duty at the same estate now |
| `shift.colleagues.*.post` | their post |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Show the cached response with its age. Clock-in works offline; this screen reflects it after the next sync.

### `shifts.me`

My hours: the guard's shifts in a range, with hours worked and night hours. Board guard-app-05. Hours, never pay.

| | |
|---|---|
| Method and path | `GET /api/v1/shifts/me` |
| App | Guard |
| Ability | `shifts:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `from` | date · required (query) · YYYY-MM-DD |
| `to` | date · required (query) · on or after `from`, at most 31 days later |

**Response** `200`

| Key | Meaning |
|---|---|
| `from` | YYYY-MM-DD |
| `to` | YYYY-MM-DD |
| `items.*.id` | the shift |
| `items.*.status` | `rostered`, `open`, `on_duty`, `completed` or `missed` |
| `items.*.post.id` | the post |
| `items.*.post.name` | e.g. "Main Gate" |
| `items.*.site.id` | the estate |
| `items.*.site.name` | its name |
| `items.*.rostered_start` | ISO 8601 |
| `items.*.rostered_end` | ISO 8601 |
| `items.*.actual_start` | the first clock-in the server recorded, or null |
| `items.*.actual_end` | the clock-out, or null |
| `items.*.worked_hours` | clock-in to clock-out less breaks, two decimals |
| `items.*.night_hours` | the part of that between 22:00 and 06:00 |
| `totals.worked_hours` | sum |
| `totals.night_hours` | sum |
| `totals.shifts` | count |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 422 | `range_too_long` | More than 31 days asked for at once. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Serve the cached range.

### `shifts.preflight`

Before clock-in: the window, the handset binding, the geofence and location integrity. Advisory — boards guard-app-01 and -10. The coordinates are compared and not stored.

| | |
|---|---|
| Method and path | `POST /api/v1/shifts/{shift}/preflight` |
| App | Guard |
| Ability | `shifts:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |
| Path parameters | `shift` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `latitude` | number · optional · omit when location is refused |
| `longitude` | number · optional |
| `accuracy_m` | integer · optional · the fix's accuracy in metres |
| `mock_location` | boolean · optional · the OS reports a mocked location |
| `device_uid` | string · optional · the enrolled install UID |

**Response** `200`

| Key | Meaning |
|---|---|
| `shift_id` | the shift |
| `allowed` | false draws "Move closer to clock in"; the clock-in endpoint still records if sent |
| `flagged_for_review` | true when the location looks mocked — clock-in proceeds and a supervisor reviews |
| `distance_m` | metres from the post, or null |
| `radius_m` | the post's geofence radius |
| `within_geofence` | true, false, or null when the post has not been surveyed |
| `checks.*.key` | `window`, `device`, `geofence` or `location_integrity` |
| `checks.*.passed` | boolean |
| `checks.*.detail` | the sentence to show |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `not_your_shift` | The shift is rostered to another guard. |
| 404 | `not_found` | No such shift. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Run the window and device checks on the handset against the cached shift and post; clock in and let the server record the distance.

### `shifts.break.start`

Start a break. A break already open is returned as it is (200).

| | |
|---|---|
| Method and path | `POST /api/v1/shifts/{shift}/break/start` |
| App | Guard |
| Ability | `shifts:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-shift-clock` |
| Path parameters | `shift` matches `[0-9]+` |
| In `sync/batch` | yes |

**Request**

No body.

**Response** `201`

| Key | Meaning |
|---|---|
| `break_id` | the break |
| `shift_id` | its shift |
| `started_at` | the server's time the break started |
| `ended_at` | when it ended, or null while it runs |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent in X-Device-Time |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `not_your_shift` | The shift is rostered to another guard. |
| 404 | `not_found` | No such shift. |
| 409 | `shift_not_on_duty` | The guard is not clocked on to this shift. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue with the device time of the tap.

### `shifts.break.end`

End the open break.

| | |
|---|---|
| Method and path | `POST /api/v1/shifts/{shift}/break/end` |
| App | Guard |
| Ability | `shifts:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-shift-clock` |
| Path parameters | `shift` matches `[0-9]+` |
| In `sync/batch` | yes |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `break_id` | the break |
| `shift_id` | its shift |
| `started_at` | the server's time the break started |
| `ended_at` | when it ended, or null while it runs |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent in X-Device-Time |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `not_your_shift` | The shift is rostered to another guard. |
| 404 | `not_found` | No such shift. |
| 409 | `no_break_open` | No break is open on this shift. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue after the break start it closes.

### `shifts.open`

Open shifts at the guard's estate in the next 14 days. Board guard-app-06. Hours, not pay.

| | |
|---|---|
| Method and path | `GET /api/v1/shifts/open` |
| App | Guard |
| Ability | `shifts:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `items.*.id` | the open shift |
| `items.*.post.id` | the post |
| `items.*.post.name` | its name |
| `items.*.site.id` | the estate |
| `items.*.site.name` | its name |
| `items.*.rostered_start` | ISO 8601 |
| `items.*.rostered_end` | ISO 8601 |
| `items.*.hours` | length in hours |
| `items.*.claim_status` | null, or this guard's claim: `pending`, `approved`, `declined` |
| `items.*.rest_warning` | true when it falls within 11 hours of another of the guard's shifts |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Online only — an open shift is first come, and a cached list is stale.

### `shifts.claim`

Claim an open shift. A supervisor approves; the claim's status comes back on `/sync/pull`.

| | |
|---|---|
| Method and path | `POST /api/v1/shifts/{shift}/claim` |
| App | Guard |
| Ability | `shifts:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |
| Path parameters | `shift` matches `[0-9]+` |
| In `sync/batch` | yes |

**Request**

No body.

**Response** `201`

| Key | Meaning |
|---|---|
| `claim_id` | the claim |
| `shift_id` | the shift |
| `status` | `pending` |
| `claimed_at` | ISO 8601 |
| `requires_approval` | always true |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent in X-Device-Time |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | No such shift at the guard's estate. |
| 409 | `shift_not_open` | Already filled. |
| 409 | `shift_started` | Already started. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only.

### `orders.current`

The orders in force for the guard's post and the company, each with its version id, and the guard's acknowledgement history. Boards guard-app-03 and -04.

| | |
|---|---|
| Method and path | `GET /api/v1/sites/{site}/standing-orders/current` |
| App | Guard |
| Ability | `orders:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |
| Path parameters | `site` matches `[a-z0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `site_id` | the site |
| `items.*.set_id` | the order set |
| `items.*.version_id` | THE id to acknowledge |
| `items.*.version` | its number |
| `items.*.title` | title |
| `items.*.effective_on` | YYYY-MM-DD |
| `items.*.body` | the full text |
| `items.*.requires_acknowledgement` | true for the post's own orders |
| `items.*.acknowledged_at` | when this guard acknowledged THIS version, or null |
| `history.*.set_id` | order set |
| `history.*.title` | its title |
| `history.*.version` | the version acknowledged |
| `history.*.acknowledged_at` | when |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `wrong_site` | A site other than the guard's own. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 404 | `not_found` | Nothing at this address, or nothing this handset may see — the two are deliberately the same. |

**Offline** — Cache and show the cached text; queue acknowledgements.

### `orders.acknowledge`

Acknowledge the version the guard read, by its version id. A revision published meanwhile has a different id, so an old one is refused.

| | |
|---|---|
| Method and path | `POST /api/v1/standing-orders/{version}/acknowledge` |
| App | Guard |
| Ability | `orders:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-shift-clock` |
| Path parameters | `version` matches `[0-9]+` |
| In `sync/batch` | yes |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `set_id` | the order set |
| `version_id` | the version acknowledged |
| `version` | its number |
| `acknowledged_at` | the first acknowledgement of this version, however many were sent |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent in X-Device-Time |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | No such version. |
| 409 | `orders_changed` | Revised since, not the guard's post, or the guard cannot stand the post (licence lapsed). |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue. If a revision landed meanwhile the sync answers 409; show the new version from `/sync/pull`.

### `patrol.checkpoints`

The site's active patrol checkpoints in tour order, with what this guard has scanned today. Board guard-app-02.

| | |
|---|---|
| Method and path | `GET /api/v1/sites/{site}/checkpoints` |
| App | Guard |
| Ability | `patrol:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |
| Path parameters | `site` matches `[a-z0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `site_id` | the site |
| `items.*.id` | the checkpoint |
| `items.*.label` | e.g. "Pool gate" |
| `items.*.sequence` | tour order |
| `items.*.post_id` | the post it belongs to, or null |
| `items.*.last_scanned_at` | this guard's last scan today, or null |
| `tour.total` | checkpoints |
| `tour.scanned_today` | distinct checkpoints this guard scanned today |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `wrong_site` | A site other than the guard's own. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 404 | `not_found` | Nothing at this address, or nothing this handset may see — the two are deliberately the same. |

**Offline** — Cache. The tag codes are not in this list — the tag itself carries its code.

### `patrol.scan`

Record a checkpoint scan. The code read from the QR or NFC tag must be the checkpoint's own.

| | |
|---|---|
| Method and path | `POST /api/v1/checkpoints/{checkpoint}/scan` |
| App | Guard |
| Ability | `patrol:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |
| Path parameters | `checkpoint` matches `[0-9]+` |
| In `sync/batch` | yes |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `code` | string · required · what the tag encodes |
| `captured_offline` | boolean · optional |

**Response** `201`

| Key | Meaning |
|---|---|
| `scan_id` | the scan |
| `checkpoint_id` | the checkpoint |
| `label` | its label |
| `tour.total` | checkpoints |
| `tour.scanned_today` | distinct checkpoints scanned today |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent in X-Device-Time |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | No such checkpoint at the guard's site. |
| 422 | `code_mismatch` | The tag read belongs to a different checkpoint, or to none. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue with the device time of the scan and `captured_offline: true`.

### `alertness.respond`

Answer a random alertness check within two minutes. Board guard-app-04. Any on-device identity score is sent as a number; no image or template ever leaves the handset.

| | |
|---|---|
| Method and path | `POST /api/v1/alertness/{check}/respond` |
| App | Guard |
| Ability | `alertness:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `check` matches `[0-9]+` |
| In `sync/batch` | yes |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `score` | integer · optional · 0–100, derived on the device |

**Response** `200`

| Key | Meaning |
|---|---|
| `check_id` | the check |
| `outcome` | `pending`, `passed`, `missed`, `failed` or `declined` |
| `issued_at` | ISO 8601 |
| `respond_by` | two minutes after issue |
| `responded_at` | or null |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent in X-Device-Time |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | No such check for this guard. |
| 409 | `check_expired` | The two minutes ran out; the check is recorded missed. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only: a check answered after signal returns is late by definition. The sync records it as missed.

### `presence.activity`

Report on-post activity. Coordinates, when sent, are compared against the post's geofence and discarded — only `within_geofence` is kept. Answers with any pending alertness check.

| | |
|---|---|
| Method and path | `POST /api/v1/presence/activity` |
| App | Guard |
| Ability | `presence:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |
| In `sync/batch` | yes |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `state` | string · required · `on_post`, `patrolling`, `on_break` or `away` |
| `latitude` | number · optional |
| `longitude` | number · optional |
| `accuracy_m` | integer · optional |
| `battery_pct` | integer · optional · 0–100 |

**Response** `201`

| Key | Meaning |
|---|---|
| `ping_id` | the ping |
| `shift_id` | the shift on duty, or null |
| `state` | as sent |
| `within_geofence` | true, false, or null when it could not be decided |
| `pending_alertness_check` | null, or the check to answer now |
| `pending_alertness_check.check_id` | the check |
| `pending_alertness_check.outcome` | `pending`, `passed`, `missed`, `failed` or `declined` |
| `pending_alertness_check.issued_at` | ISO 8601 |
| `pending_alertness_check.respond_by` | two minutes after issue |
| `pending_alertness_check.responded_at` | or null |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent in X-Device-Time |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Drop, do not queue: a stale presence report is not presence.

### `presence.summary`

The guard's own day: minutes on post, breaks, checkpoints, incidents, alertness. Board guard-app-04.

| | |
|---|---|
| Method and path | `GET /api/v1/guards/me/activity-summary` |
| App | Guard |
| Ability | `presence:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `date` | YYYY-MM-DD |
| `shift_id` | today's shift, or null |
| `on_post_minutes` | on duty less breaks |
| `break_minutes` | breaks |
| `checkpoints_scanned` | distinct today |
| `checkpoints_total` | active at the site |
| `incidents_filed` | today |
| `alertness_passed` | today |
| `alertness_missed` | today |
| `last_activity_at` | last presence report, or null |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Serve the cached summary with its age.

### `gate.verify`

Verify a signed pass (or its short code) online: signature, site, window, cancellation, use, and the household's access restriction. Does NOT admit — record the entry after. Board guard-app-07.

| | |
|---|---|
| Method and path | `POST /api/v1/gate/verify` |
| App | Guard |
| Ability | `gate:write` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-gate-events` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `pass` | string · required · the QR token (`payload.signature`), or the short code, e.g. `PPV2-4471` |

**Response** `200`

| Key | Meaning |
|---|---|
| `verdict` | `valid`, `restricted`, `cancelled`, `already_used`, `expired`, `not_yet_valid`, `wrong_site`, `unknown_key`, `bad_signature`, `malformed` or `unknown_pass` |
| `tone` | `green`, `amber` (restricted) or `red` |
| `headline` | what the guard is told |
| `detail` | one sentence |
| `admit_allowed` | true only for `valid` |
| `pass` | null unless the pass is this estate's and on record |
| `pass.pass_id` | UUID |
| `pass.category` | `single`, `recurring`, `contractor` or `delivery` |
| `pass.visitor_name` | who it is for |
| `pass.vehicle_plate` | or null |
| `pass.purpose` | or null |
| `pass.valid_from` | ISO 8601 |
| `pass.valid_to` | ISO 8601 |
| `pass.single_use` | boolean |
| `pass.status` | `active`, `used` or `cancelled` |
| `unit` | the unit reference, or null |
| `household` | the household's name, or null |
| `access_restricted` | boolean, or null when the pass itself failed — the ONLY thing a guard learns about a household's standing |
| `server_time` | the server's time |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Verify on the handset with the cached public keys (`/sites/{site}/pass-keys`): the payload's eight fields and its Ed25519 signature, with no database. Cancellation and use are unknown offline; `/sync/pull` delivers the revoked list and `/gate/entry` reconciles.

### `gate.entry`

Record an admission — on a pass, on an approved walk-up request, or on the guard's own decision. A single-use pass is consumed here.

| | |
|---|---|
| Method and path | `POST /api/v1/gate/entry` |
| App | Guard |
| Ability | `gate:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-gate-events` |
| In `sync/batch` | yes |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `category` | string · required · e.g. `Visitor`, `Contractor`, `Delivery`, `Resident` |
| `pass_id` | string · optional · the pass admitted on |
| `approval_id` | integer · optional · an approved walk-up request |
| `subject` | string · required without a pass or approval · who came in |
| `verified_offline` | boolean · optional · the pass was verified on the handset with no signal |
| `post_id` | integer · optional · defaults to the guard's post |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the gate event |
| `verdict` | `admit`, `exit` or `override` |
| `basis` | `QR pass`, `QR pass · verified offline`, `Pre-approved`, `guard decision`, or `Override — {reason}` |
| `pass_based` | true when the basis was a platform pass or approval |
| `occurred_at` | the server's time of the event |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent in X-Device-Time |
| `clock_skewed` | true when the two disagree by more than two minutes |
| `reconciliation` | null without a pass; else `consumed`, `valid`, or — offline only — `pass_cancelled`, `pass_already_used`, `pass_expired`, `household_restricted`, `pass_unknown`: tell the guard and dispatch sees it |
| `access_restricted` | boolean, or null without a pass |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | An online entry on a pass or request that does not exist. |
| 409 | `pass_not_admissible` | Online, on a pass that is cancelled, used, expired or restricted. Use `/gate/override`. |
| 409 | `approval_not_granted` | The walk-up request is not approved. |
| 422 | `subject_required` | No pass, no approval and no subject. |
| 403 | `wrong_site` | A post at another estate. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue with `verified_offline: true` and the device time of the admission. The server records it and answers `reconciliation`.

### `gate.exit`

Record a departure.

| | |
|---|---|
| Method and path | `POST /api/v1/gate/exit` |
| App | Guard |
| Ability | `gate:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-gate-events` |
| In `sync/batch` | yes |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `category` | string · required |
| `subject` | string · required |
| `pass_id` | string · optional |
| `post_id` | integer · optional |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the gate event |
| `verdict` | `admit`, `exit` or `override` |
| `basis` | `QR pass`, `QR pass · verified offline`, `Pre-approved`, `guard decision`, or `Override — {reason}` |
| `pass_based` | true when the basis was a platform pass or approval |
| `occurred_at` | the server's time of the event |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent in X-Device-Time |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `wrong_site` | A post at another estate. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue with the device time.

### `gate.override`

Admit against the system's advice, with a reason. Its own verdict on the gate log.

| | |
|---|---|
| Method and path | `POST /api/v1/gate/override` |
| App | Guard |
| Ability | `gate:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-gate-events` |
| In `sync/batch` | yes |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `category` | string · required |
| `subject` | string · required |
| `reason` | string · required · 5–160 characters |
| `pass_id` | string · optional |
| `post_id` | integer · optional |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the gate event |
| `verdict` | `admit`, `exit` or `override` |
| `basis` | `QR pass`, `QR pass · verified offline`, `Pre-approved`, `guard decision`, or `Override — {reason}` |
| `pass_based` | true when the basis was a platform pass or approval |
| `occurred_at` | the server's time of the event |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent in X-Device-Time |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `wrong_site` | A post at another estate. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue with the device time.

### `gate.search`

Find a unit or household. `access_restricted` is a boolean and the ONLY thing returned about a household's standing — no figure, no bucket, no wording implying money.

| | |
|---|---|
| Method and path | `GET /api/v1/gate/search` |
| App | Guard |
| Ability | `gate:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `unit` | string · required without `name` (query) · part of a unit reference |
| `name` | string · required without `unit` (query) · part of a household or resident name, 2+ characters |

**Response** `200`

| Key | Meaning |
|---|---|
| `items.*.unit` | the unit reference |
| `items.*.household` | the household's name |
| `items.*.primary_resident` | the primary resident's name, or null |
| `items.*.access_restricted` | boolean |
| `items.*.expected_visitors.*.visitor_name` | an active pass valid today |
| `items.*.expected_visitors.*.category` | its category |
| `items.*.expected_visitors.*.valid_to` | ISO 8601 |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Online only.

### `gate.activity`

Today's gate log at the guard's estate, newest first.

| | |
|---|---|
| Method and path | `GET /api/v1/gate/activity` |
| App | Guard |
| Ability | `gate:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `limit` | integer · optional (query) · 1–100, default 50 |

**Response** `200`

| Key | Meaning |
|---|---|
| `date` | YYYY-MM-DD |
| `counts.admitted` | today |
| `counts.exited` | today |
| `counts.overridden` | today |
| `counts.denied` | today |
| `items.*.id` | the event |
| `items.*.verdict` | verdict |
| `items.*.category` | category |
| `items.*.subject` | who |
| `items.*.basis` | on what |
| `items.*.pass_based` | boolean |
| `items.*.guard_name` | recorded by |
| `items.*.post_name` | at |
| `items.*.occurred_at` | server time |
| `items.*.device_time` | handset time, or null |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Show the cached log plus the queued events not yet synced, marked as such.

### `gate.approvals.store`

A walk-up visitor: record them and ask the household, who answers in the Resident App. Boards guard-app-08 and -02. No photo is stored.

| | |
|---|---|
| Method and path | `POST /api/v1/gate/approvals` |
| App | Guard |
| Ability | `gate:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-gate-events` |
| In `sync/batch` | yes |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `unit` | string · required · the unit reference |
| `visitor_name` | string · required |
| `id_type` | string · optional |
| `id_number` | string · optional |
| `purpose` | string · optional |
| `vehicle_plate` | string · optional |

**Response** `201`

| Key | Meaning |
|---|---|
| `approval_id` | the walk-up request |
| `status` | `pending`, `approved`, `denied` or `expired` |
| `unit` | the unit |
| `household` | its household's name |
| `visitor_name` | as recorded |
| `requested_at` | ISO 8601 |
| `respond_by` | three minutes after the request |
| `responded_at` | or null |
| `responded_by_name` | the resident who answered, or null |
| `guidance` | the sentence to show the guard |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent in X-Device-Time |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | No such unit. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only — the household cannot be asked without signal. Apply the estate's policy.

### `gate.approvals.show`

Poll a walk-up request. Past `respond_by` with no answer it reads `expired`.

| | |
|---|---|
| Method and path | `GET /api/v1/gate/approvals/{approval}` |
| App | Guard |
| Ability | `gate:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |
| Path parameters | `approval` matches `[0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `approval_id` | the walk-up request |
| `status` | `pending`, `approved`, `denied` or `expired` |
| `unit` | the unit |
| `household` | its household's name |
| `visitor_name` | as recorded |
| `requested_at` | ISO 8601 |
| `respond_by` | three minutes after the request |
| `responded_at` | or null |
| `responded_by_name` | the resident who answered, or null |
| `guidance` | the sentence to show the guard |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | No such request. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Online only.

### `incidents.store`

File an incident report. It lands in the same register the console keeps. Board guard-app-03.

| | |
|---|---|
| Method and path | `POST /api/v1/incidents` |
| App | Guard |
| Ability | `incidents:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |
| In `sync/batch` | yes |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `kind` | string · required · e.g. "Attempted unauthorized access" |
| `severity` | string · required · `low`, `med` or `high` |
| `detail` | string · required · at least 20 characters |
| `location` | string · optional |
| `latitude` | number · optional |
| `longitude` | number · optional |
| `occurred_at` | ISO 8601 · optional · defaults to now; not in the future |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the incident |
| `kind` | as filed |
| `severity` | `low`, `med` or `high` |
| `status` | `open` or `resolved` |
| `detail` | the account |
| `location` | or null |
| `shift_id` | the shift on duty when filed, or null |
| `occurred_at` | ISO 8601 |
| `resolution` | what was done, once resolved |
| `closed_at` | or null |
| `media_count` | attachments |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent in X-Device-Time |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 422 | `occurred_in_future` | `occurred_at` more than five minutes ahead of the server. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue with the device time; upload media after the report syncs, against the id it returns.

### `incidents.me`

The guard's own reports from the last 90 days.

| | |
|---|---|
| Method and path | `GET /api/v1/incidents/me` |
| App | Guard |
| Ability | `incidents:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `items.*.id` | the incident |
| `items.*.kind` | as filed |
| `items.*.severity` | `low`, `med` or `high` |
| `items.*.status` | `open` or `resolved` |
| `items.*.detail` | the account |
| `items.*.location` | or null |
| `items.*.shift_id` | the shift on duty when filed, or null |
| `items.*.occurred_at` | ISO 8601 |
| `items.*.resolution` | what was done, once resolved |
| `items.*.closed_at` | or null |
| `items.*.media_count` | attachments |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Serve the cache plus queued reports.

### `incidents.media`

Attach a photo or video (multipart, field `file`). Stored privately and hashed on arrival.

| | |
|---|---|
| Method and path | `POST /api/v1/incidents/{incident}/media` |
| App | Guard |
| Ability | `incidents:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |
| Path parameters | `incident` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `file` | file · required · JPEG, PNG, HEIC, MP4 or MOV, at most 50 MB |

**Response** `201`

| Key | Meaning |
|---|---|
| `media_id` | the attachment |
| `incident_id` | the incident |
| `filename` | as uploaded |
| `content_type` | as detected |
| `bytes` | size |
| `sha256` | hash of the stored bytes |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent in X-Device-Time |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | No incident of this guard's with that id. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue the file; upload after the incident has an id. Not batchable.

### `duress.store`

Duress (guard) or panic (resident). Dispatch sees it at once. Cancellable for ten seconds. Guard board guard-app-03; resident panic.

| | |
|---|---|
| Method and path | `POST /api/v1/duress` |
| App | Guard + Resident |
| Ability | `alerts:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-alerts` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `mode` | string · optional · `silent` (default) or `audible` — the handset's behaviour only |
| `latitude` | number · optional |
| `longitude` | number · optional |
| `captured_offline` | boolean · optional |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the alert |
| `kind` | `duress` from a guard, `panic` from a resident |
| `mode` | `silent` or `audible` |
| `status` | `open`, `acknowledged`, `responding`, `resolved` or `false_alarm` |
| `cancellable_until` | ten seconds after the server received it |
| `cancelled_at` | or null |
| `acknowledged_at` | when dispatch took it, or null |
| `server_time` | the server's time of the alert |
| `device_time` | the handset's time of the press |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue with its key and `captured_offline: true`; send the moment any signal returns. Also call the local emergency number.

### `duress.cancel`

Cancel within ten seconds of the server receiving it, and before dispatch acknowledges.

| | |
|---|---|
| Method and path | `POST /api/v1/duress/{alert}/cancel` |
| App | Guard + Resident |
| Ability | `alerts:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-alerts` |
| Path parameters | `alert` matches `[0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the alert |
| `kind` | `duress` from a guard, `panic` from a resident |
| `mode` | `silent` or `audible` |
| `status` | `open`, `acknowledged`, `responding`, `resolved` or `false_alarm` |
| `cancellable_until` | ten seconds after the server received it |
| `cancelled_at` | or null |
| `acknowledged_at` | when dispatch took it, or null |
| `server_time` | the server's time of the alert |
| `device_time` | the handset's time of the press |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not this handset's alert. |
| 409 | `cancel_window_closed` | More than ten seconds. Call dispatch. |
| 409 | `already_acknowledged` | Somebody is responding. Call dispatch. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Not possible offline: the alert has not reached anyone to cancel.

### `requests.index`

The guard's leave, equipment and swap requests, with decisions, and their leave balance in days. Board guard-app-05.

| | |
|---|---|
| Method and path | `GET /api/v1/requests` |
| App | Guard |
| Ability | `requests:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `items.*.id` | the request |
| `items.*.kind` | `leave`, `equipment` or `shift_swap` |
| `items.*.subject` | leave type, or the item |
| `items.*.quantity` | equipment count, or null |
| `items.*.starts_on` | YYYY-MM-DD or null |
| `items.*.ends_on` | YYYY-MM-DD or null |
| `items.*.days` | inclusive, or null |
| `items.*.reason` | or null |
| `items.*.certificate_attached` | boolean — the flag, never the document |
| `items.*.status` | `pending`, `approved`, `denied` or `info_requested` |
| `items.*.decided_at` | or null |
| `items.*.decision_note` | the supervisor's note, or null |
| `items.*.created_at` | ISO 8601 |
| `leave.entitlement_days` | per year |
| `leave.approved_days_this_year` | days |
| `leave.remaining_days` | days |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Serve the cache.

### `requests.store`

Raise a request. It reaches the dispatch inbox at once.

| | |
|---|---|
| Method and path | `POST /api/v1/requests` |
| App | Guard |
| Ability | `requests:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |
| In `sync/batch` | yes |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `kind` | string · required · `leave`, `equipment` or `shift_swap` |
| `subject` | string · required · for leave: `vacation`, `sick`, `bereavement`, `maternity`, `paternity`, `unpaid`, `other` |
| `quantity` | integer · required for equipment |
| `starts_on` | date · required for leave |
| `ends_on` | date · required for leave |
| `reason` | string · optional |
| `certificate_attached` | boolean · optional |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the request |
| `kind` | kind |
| `subject` | subject |
| `quantity` | or null |
| `starts_on` | or null |
| `ends_on` | or null |
| `days` | or null |
| `reason` | or null |
| `certificate_attached` | boolean |
| `status` | `pending` |
| `decided_at` | null |
| `decision_note` | null |
| `created_at` | ISO 8601 |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 422 | `leave_type_unknown` | A leave subject not in the list. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue.

### `payslips.me`

The guard's OWN payslips on approved runs, newest first, as decimal strings. The one route where a guard's handset reads money — their own wage. Board guard-app-05.

| | |
|---|---|
| Method and path | `GET /api/v1/payslips/me` |
| App | Guard |
| Ability | `payslips:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `items.*.id` | the payslip |
| `items.*.run_reference` | the payroll run |
| `items.*.period_label` | e.g. "September 2026" |
| `items.*.period_start` | YYYY-MM-DD |
| `items.*.period_end` | YYYY-MM-DD |
| `items.*.status` | `approved` or `paid` |
| `items.*.currency` | `JMD` |
| `items.*.gross` | decimal string, e.g. "38450.00" |
| `items.*.deductions.nis` | decimal string |
| `items.*.deductions.nht` | decimal string |
| `items.*.deductions.education_tax` | decimal string |
| `items.*.deductions.paye` | decimal string |
| `items.*.deductions.pension` | decimal string — an approved pension, zero unless one is on file |
| `items.*.net` | decimal string |
| `items.*.paye_note` | why PAYE is what it is, or null |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Serve the cache from the secure store; never write a payslip to shared storage.

### `messages.index`

Broadcasts to the estate and the guard's own thread with dispatch, newest first. Board guard-app-04.

| | |
|---|---|
| Method and path | `GET /api/v1/messages` |
| App | Guard |
| Ability | `messages:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `since` | ISO 8601 · optional (query) · only newer messages |

**Response** `200`

| Key | Meaning |
|---|---|
| `items.*.id` | the message |
| `items.*.direction` | `broadcast`, `outbound` (dispatch to this guard) or `inbound` (this guard to dispatch) |
| `items.*.from` | `dispatch` or `you` |
| `items.*.body` | text, at most 500 |
| `items.*.sent_at` | ISO 8601 |
| `items.*.read_at` | or null |
| `unread` | messages from dispatch not yet read |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Serve the cache plus queued outgoing messages.

### `messages.store`

Write to dispatch. Marks the thread read.

| | |
|---|---|
| Method and path | `POST /api/v1/messages` |
| App | Guard |
| Ability | `messages:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |
| In `sync/batch` | yes |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `body` | string · required · at most 500 |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the message |
| `direction` | `broadcast`, `outbound` (dispatch to this guard) or `inbound` (this guard to dispatch) |
| `from` | `dispatch` or `you` |
| `body` | text, at most 500 |
| `sent_at` | ISO 8601 |
| `read_at` | or null |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue with the device time.

### `sync.batch`

Upload the offline queue, in order. Each operation runs through its own endpoint with its own Idempotency-Key and device time; a retried batch replays. Stops at the first server failure.

| | |
|---|---|
| Method and path | `POST /api/v1/sync/batch` |
| App | Guard |
| Ability | `sync:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| In `sync/batch` | yes |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `operations` | array · required · 1–50, in the order they happened |
| `operations.*.id` | string · required · the app's own id for the operation, echoed back |
| `operations.*.endpoint` | string · required · a catalogue name, e.g. `shifts.clock_in`, `gate.entry`, `patrol.scan` |
| `operations.*.params` | object · optional · path parameters, e.g. `{"shift": 812}` |
| `operations.*.body` | object · optional · the endpoint's request body |
| `operations.*.idempotency_key` | string · required · the key chosen when the operation was queued |
| `operations.*.device_time` | ISO 8601 · required · when it happened on the handset |

**Response** `200`

| Key | Meaning |
|---|---|
| `results.*.id` | the operation id, as sent |
| `results.*.endpoint` | as sent |
| `results.*.outcome` | `ok`, `refused` (a 4xx answer — show it), `failed` (5xx — resend) or `not_attempted` (after a failure — resend) |
| `results.*.status` | the HTTP status the endpoint answered, or null |
| `results.*.replayed` | true when answered from a previous attempt with the same key |
| `results.*.body` | the endpoint's own response body, exactly as documented for it |
| `results.*.body.**` | opaque here — see the named endpoint |
| `counts.ok` | count |
| `counts.refused` | count |
| `counts.failed` | count |
| `counts.not_attempted` | count |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent in X-Device-Time |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — This IS the offline path. Batchable: every guard write except `incidents.media` and sync itself; an unbatchable name comes back `refused` with `endpoint_not_batchable`.

### `sync.pull`

What changed since the last pull: shifts, order versions, messages, pending alertness checks, revoked passes and the current pass keys, request decisions and claim decisions.

| | |
|---|---|
| Method and path | `GET /api/v1/sync/pull` |
| App | Guard |
| Ability | `sync:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `since` | ISO 8601 · optional (query) · the previous `next_since`; default seven days |

**Response** `200`

| Key | Meaning |
|---|---|
| `since` | as applied |
| `server_time` | the server's time |
| `next_since` | send this as `since` next time |
| `shifts.*.id` | the shift |
| `shifts.*.status` | `rostered`, `open`, `on_duty`, `completed` or `missed` |
| `shifts.*.post.id` | the post |
| `shifts.*.post.name` | e.g. "Main Gate" |
| `shifts.*.site.id` | the estate |
| `shifts.*.site.name` | its name |
| `shifts.*.rostered_start` | ISO 8601 |
| `shifts.*.rostered_end` | ISO 8601 |
| `shifts.*.actual_start` | the first clock-in the server recorded, or null |
| `shifts.*.actual_end` | the clock-out, or null |
| `orders.*.set_id` | order set |
| `orders.*.version_id` | a version published since |
| `orders.*.version` | number |
| `orders.*.title` | title |
| `orders.*.effective_on` | YYYY-MM-DD |
| `orders.*.requires_acknowledgement` | boolean |
| `messages.*.id` | message |
| `messages.*.direction` | `broadcast` or `outbound` |
| `messages.*.body` | text |
| `messages.*.sent_at` | ISO 8601 |
| `alertness_checks.*.check_id` | a check to answer now |
| `alertness_checks.*.issued_at` | ISO 8601 |
| `alertness_checks.*.respond_by` | ISO 8601 |
| `passes.revoked.*.pass_id` | a still-unexpired pass cancelled or used since — refuse it offline from now on |
| `passes.revoked.*.status` | `cancelled` or `used` |
| `passes.revoked.*.at` | when |
| `passes.keys.*.version` | key version |
| `passes.keys.*.public_key` | base64url Ed25519 public key |
| `passes.keys.*.current` | boolean |
| `requests.*.id` | a request decided since |
| `requests.*.kind` | kind |
| `requests.*.status` | decision |
| `requests.*.decided_at` | ISO 8601 |
| `requests.*.decision_note` | or null |
| `claims.*.claim_id` | a claim decided since |
| `claims.*.shift_id` | the shift |
| `claims.*.status` | `approved` or `declined` |
| `claims.*.decided_at` | ISO 8601 |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `guard_not_active` | The guard the token belongs to is suspended, on leave or no longer employed. |

**Offline** — Pull first whenever signal returns, then upload the batch.

### `auth.otp.request`

Send a six-digit sign-in code by email or text. The answer is the same whether or not an account exists. Board resident-app-01.

| | |
|---|---|
| Method and path | `POST /api/v1/auth/otp/request` |
| App | Resident, before sign-in — no token |
| Ability | none — no token |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `202` |
| Rate limit | `api-enrol` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `estate` | string · required · the estate's id, e.g. `phoenixpark` — the app ships the list or reads it from a QR code at the estate office |
| `channel` | string · required · `email` or `sms` |
| `destination` | string · required · the email address, or the number in any common format |

**Response** `202`

| Key | Meaning |
|---|---|
| `sent` | always true |
| `channel` | as sent |
| `destination_hint` | masked |
| `expires_at` | ten minutes from issue |
| `resend_after` | a request before this reuses the code already sent |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `estate_not_found` | No active estate by that id. |
| 503 | `sms_unavailable` | No SMS provider on this platform — sign in with email. |
| 429 | `rate_limited` | Ten requests a minute per address. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |

**Offline** — Online only.

### `auth.otp.verify`

Prove the code and receive this install's token. A new account is `pending` until the estate approves a unit claim; signing in again on the same install replaces its token.

| | |
|---|---|
| Method and path | `POST /api/v1/auth/otp/verify` |
| App | Resident, before sign-in — no token |
| Ability | none — no token |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-enrol` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `estate` | string · required |
| `channel` | string · required |
| `destination` | string · required · as sent to `/auth/otp/request` |
| `code` | string · required · six digits |
| `device_uid` | string · required · a stable id for this install |
| `platform` | string · required · `ios` or `android` |

**Response** `200`

| Key | Meaning |
|---|---|
| `token` | the bearer token — store it in the keychain/keystore |
| `abilities.*` | the Resident App's abilities, from the app matrix |
| `account.id` | the account |
| `account.status` | `pending` or `active` |
| `account.full_name` | or null |
| `estate.id` | the estate |
| `estate.name` | its name |
| `claim` | null, or the account's latest claim |
| `claim.id` | the claim |
| `claim.status` | `pending`, `approved` or `rejected` |
| `claim.submitted_unit` | as typed, e.g. "Phase 2 · Lot 47" |
| `claim.submitted_name` | as typed |
| `claim.decision_reason` | why it was rejected, or null |
| `claim.document_requested` | the document the estate asked for, e.g. "photo ID", or null |
| `claim.submitted_at` | ISO 8601 |
| `next` | `claim_unit`, `await_approval` or `home` — the screen to open |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `estate_not_found` | No active estate by that id. |
| 422 | `otp_invalid` | Wrong code; the message says how many tries are left. |
| 422 | `otp_expired` | Expired, used, or replaced by a newer code. |
| 423 | `otp_locked` | Five wrong codes. Request a new one. |
| 403 | `account_suspended` | The estate withdrew access. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |

**Offline** — Online only.

### `auth.claim_unit`

Claim a unit. The estate reviews it on its claims screen; once approved, the account is active on its next request. A pending claim is returned as it is (200). Board resident-app-04.

| | |
|---|---|
| Method and path | `POST /api/v1/auth/claim-unit` |
| App | Resident — pending accounts too |
| Ability | `household:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `full_name` | string · required |
| `lot` | string · required · e.g. `47` or `Lot 47` |
| `phase` | string · optional · e.g. `Phase 2` |
| `phone` | string · optional |
| `relationship` | string · optional · `owner`, `tenant`, `spouse`, `child`, `parent`, `relative`, `other` |

**Response** `201`

| Key | Meaning |
|---|---|
| `account.id` | the account |
| `account.status` | `pending` |
| `account.full_name` | as claimed |
| `claim.id` | the claim |
| `claim.status` | `pending`, `approved` or `rejected` |
| `claim.submitted_unit` | as typed, e.g. "Phase 2 · Lot 47" |
| `claim.submitted_name` | as typed |
| `claim.decision_reason` | why it was rejected, or null |
| `claim.document_requested` | the document the estate asked for, e.g. "photo ID", or null |
| `claim.submitted_at` | ISO 8601 |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent in X-Device-Time |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 409 | `already_linked` | The account is already active on a unit. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only.

### `me.show`

The account, its claim, and once linked its unit, household and register entry. Poll while pending. Board resident-app-10.

| | |
|---|---|
| Method and path | `GET /api/v1/me` |
| App | Resident — pending accounts too |
| Ability | `household:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `account.id` | the account |
| `account.status` | `pending` or `active` |
| `account.channel` | `email` or `sms` |
| `account.destination_hint` | masked, e.g. "a•••@example.com" |
| `account.full_name` | or null |
| `estate.id` | the estate |
| `estate.name` | its name |
| `claim` | null until a unit is claimed |
| `claim.id` | the claim |
| `claim.status` | `pending`, `approved` or `rejected` |
| `claim.submitted_unit` | as typed, e.g. "Phase 2 · Lot 47" |
| `claim.submitted_name` | as typed |
| `claim.decision_reason` | why it was rejected, or null |
| `claim.document_requested` | the document the estate asked for, e.g. "photo ID", or null |
| `claim.submitted_at` | ISO 8601 |
| `unit` | null while pending |
| `unit.id` | the unit |
| `unit.reference` | e.g. "Lot 47" |
| `unit.phase` | e.g. "Phase 2" |
| `household` | null while pending |
| `household.id` | the household — the id `/households/{household}/…` takes |
| `household.name` | its name |
| `resident` | the register entry this account signs in as, or null |
| `resident.id` | the resident on the register |
| `resident.full_name` | name |
| `resident.relationship` | `owner`, `tenant`, `spouse`, `child`, … |
| `resident.is_primary` | the household's primary resident |
| `resident.status` | `verified`, or `pending` until the estate verifies them |
| `resident.phone` | or null |
| `resident.email` | or null |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |

**Offline** — Serve the cache.

### `me.update`

Change the account's display name and the register's phone and email. The name on the register is the estate's.

| | |
|---|---|
| Method and path | `PATCH /api/v1/me` |
| App | Resident |
| Ability | `household:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `full_name` | string · optional |
| `phone` | string · optional |
| `email` | string · optional |

**Response** `200`

| Key | Meaning |
|---|---|
| `account.id` | the account |
| `account.status` | `pending` or `active` |
| `account.channel` | `email` or `sms` |
| `account.destination_hint` | masked, e.g. "a•••@example.com" |
| `account.full_name` | or null |
| `estate.id` | the estate |
| `estate.name` | its name |
| `claim` | null until a unit is claimed |
| `claim.id` | the claim |
| `claim.status` | `pending`, `approved` or `rejected` |
| `claim.submitted_unit` | as typed, e.g. "Phase 2 · Lot 47" |
| `claim.submitted_name` | as typed |
| `claim.decision_reason` | why it was rejected, or null |
| `claim.document_requested` | the document the estate asked for, e.g. "photo ID", or null |
| `claim.submitted_at` | ISO 8601 |
| `unit` | null while pending |
| `unit.id` | the unit |
| `unit.reference` | e.g. "Lot 47" |
| `unit.phase` | e.g. "Phase 2" |
| `household` | null while pending |
| `household.id` | the household — the id `/households/{household}/…` takes |
| `household.name` | its name |
| `resident` | the register entry this account signs in as, or null |
| `resident.id` | the resident on the register |
| `resident.full_name` | name |
| `resident.relationship` | `owner`, `tenant`, `spouse`, `child`, … |
| `resident.is_primary` | the household's primary resident |
| `resident.status` | `verified`, or `pending` until the estate verifies them |
| `resident.phone` | or null |
| `resident.email` | or null |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent in X-Device-Time |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue.

### `me.household`

The home screen's household: members, vehicles, emergency contacts, and any guard waiting on an answer at the gate. Boards resident-app-02, -09.

| | |
|---|---|
| Method and path | `GET /api/v1/me/household` |
| App | Resident |
| Ability | `household:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `household.id` | the household |
| `household.name` | its name |
| `unit.id` | the unit |
| `unit.reference` | reference |
| `unit.phase` | phase |
| `members.*.id` | the resident on the register |
| `members.*.full_name` | name |
| `members.*.relationship` | `owner`, `tenant`, `spouse`, `child`, … |
| `members.*.is_primary` | the household's primary resident |
| `members.*.status` | `verified`, or `pending` until the estate verifies them |
| `members.*.phone` | or null |
| `members.*.email` | or null |
| `vehicles.*.id` | the vehicle |
| `vehicles.*.plate` | upper-cased |
| `vehicles.*.make` | or null |
| `vehicles.*.model` | or null |
| `vehicles.*.colour` | or null |
| `emergency_contacts.*.id` | the contact |
| `emergency_contacts.*.name` | name |
| `emergency_contacts.*.relationship` | or null |
| `emergency_contacts.*.phone` | number |
| `pending_approvals.*.id` | a walk-up request to answer with `/gate/approval/{id}/respond` |
| `pending_approvals.*.visitor_name` | who is at the gate |
| `pending_approvals.*.purpose` | or null |
| `pending_approvals.*.vehicle_plate` | or null |
| `pending_approvals.*.post_name` | which gate |
| `pending_approvals.*.guard_name` | the guard asking |
| `pending_approvals.*.requested_at` | ISO 8601 |
| `pending_approvals.*.respond_by` | after this the estate's policy applies |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |

**Offline** — Serve the cache. Pending approvals are live only.

### `members.index`

The household's members on the register.

| | |
|---|---|
| Method and path | `GET /api/v1/household-members` |
| App | Resident |
| Ability | `household:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `items.*.id` | the resident on the register |
| `items.*.full_name` | name |
| `items.*.relationship` | `owner`, `tenant`, `spouse`, `child`, … |
| `items.*.is_primary` | the household's primary resident |
| `items.*.status` | `verified`, or `pending` until the estate verifies them |
| `items.*.phone` | or null |
| `items.*.email` | or null |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |

**Offline** — Serve the cache.

### `members.store`

Add a household member. They are `pending` until the estate verifies them.

| | |
|---|---|
| Method and path | `POST /api/v1/household-members` |
| App | Resident |
| Ability | `household:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `full_name` | string · required |
| `relationship` | string · required · `spouse`, `child`, `parent`, `relative`, `tenant`, `domestic_staff`, `other` |
| `phone` | string · optional |
| `email` | string · optional |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the resident on the register |
| `full_name` | name |
| `relationship` | `owner`, `tenant`, `spouse`, `child`, … |
| `is_primary` | the household's primary resident |
| `status` | `verified`, or `pending` until the estate verifies them |
| `phone` | or null |
| `email` | or null |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent in X-Device-Time |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue.

### `vehicles.index`

The household's registered vehicles.

| | |
|---|---|
| Method and path | `GET /api/v1/vehicles` |
| App | Resident |
| Ability | `household:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `items.*.id` | the vehicle |
| `items.*.plate` | upper-cased |
| `items.*.make` | or null |
| `items.*.model` | or null |
| `items.*.colour` | or null |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |

**Offline** — Serve the cache.

### `vehicles.store`

Register a vehicle. A plate already registered to the household is returned as it is (200).

| | |
|---|---|
| Method and path | `POST /api/v1/vehicles` |
| App | Resident |
| Ability | `household:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `plate` | string · required · letters, digits, spaces, hyphens |
| `make` | string · optional |
| `model` | string · optional |
| `colour` | string · optional |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the vehicle |
| `plate` | upper-cased |
| `make` | or null |
| `model` | or null |
| `colour` | or null |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent in X-Device-Time |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue.

### `contacts.index`

Who to call for this household. Never shown to a guard.

| | |
|---|---|
| Method and path | `GET /api/v1/emergency-contacts` |
| App | Resident |
| Ability | `household:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `items.*.id` | the contact |
| `items.*.name` | name |
| `items.*.relationship` | or null |
| `items.*.phone` | number |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |

**Offline** — Serve the cache.

### `contacts.store`

Add an emergency contact.

| | |
|---|---|
| Method and path | `POST /api/v1/emergency-contacts` |
| App | Resident |
| Ability | `household:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `name` | string · required |
| `relationship` | string · optional |
| `phone` | string · required |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the contact |
| `name` | name |
| `relationship` | or null |
| `phone` | number |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent in X-Device-Time |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue.

### `visitor_passes.index`

The household's visitor passes from the last 30 days. Board resident-app-06.

| | |
|---|---|
| Method and path | `GET /api/v1/visitor-passes` |
| App | Resident |
| Ability | `passes:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `status` | string · optional (query) · `active`, `used`, `cancelled` or `expired` |

**Response** `200`

| Key | Meaning |
|---|---|
| `items.*.id` | the pass — the id the pass endpoints take |
| `items.*.pass_id` | UUID, in the signed payload |
| `items.*.category` | `single`, `recurring`, `contractor`, `delivery`; `resident` for the e-pass |
| `items.*.visitor_name` | who it is for |
| `items.*.visitor_phone` | or null |
| `items.*.purpose` | or null |
| `items.*.vehicle_plate` | or null |
| `items.*.valid_from` | ISO 8601 |
| `items.*.valid_to` | ISO 8601 |
| `items.*.single_use` | boolean |
| `items.*.status` | `active`, `used`, `cancelled` or `expired` |
| `items.*.code` | the short code a visitor can read out, e.g. `PPV2-4471` |
| `items.*.token` | the QR payload: `base64url(payload).base64url(signature)` |
| `items.*.share_count` | times shared |
| `items.*.used_at` | or null |
| `items.*.cancelled_at` | or null |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |

**Offline** — Serve the cache — the QR codes render offline.

### `visitor_passes.store`

Issue a signed visitor pass. At most 31 days long. Board resident-app-05.

| | |
|---|---|
| Method and path | `POST /api/v1/visitor-passes` |
| App | Resident |
| Ability | `passes:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `category` | string · required · `single`, `recurring`, `contractor` or `delivery` |
| `visitor_name` | string · required |
| `visitor_phone` | string · optional |
| `purpose` | string · optional |
| `vehicle_plate` | string · optional |
| `valid_from` | ISO 8601 · required |
| `valid_to` | ISO 8601 · required · after `valid_from` |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the pass — the id the pass endpoints take |
| `pass_id` | UUID, in the signed payload |
| `category` | `single`, `recurring`, `contractor`, `delivery`; `resident` for the e-pass |
| `visitor_name` | who it is for |
| `visitor_phone` | or null |
| `purpose` | or null |
| `vehicle_plate` | or null |
| `valid_from` | ISO 8601 |
| `valid_to` | ISO 8601 |
| `single_use` | boolean |
| `status` | `active`, `used`, `cancelled` or `expired` |
| `code` | the short code a visitor can read out, e.g. `PPV2-4471` |
| `token` | the QR payload: `base64url(payload).base64url(signature)` |
| `share_count` | times shared |
| `used_at` | or null |
| `cancelled_at` | or null |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent in X-Device-Time |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 409 | `access_restricted` | The household is restricted for this category. The wording is the gate's; the app shows nothing more. |
| 422 | `pass_refused` | Longer than 31 days, or otherwise refused; the message says why. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only: a pass is signed by the server.

### `visitor_passes.update`

Change what is not signed: the visitor's name, number, purpose or plate. A different window or category is a new pass.

| | |
|---|---|
| Method and path | `PATCH /api/v1/visitor-passes/{pass}` |
| App | Resident |
| Ability | `passes:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `pass` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `visitor_name` | string · optional |
| `visitor_phone` | string · optional |
| `purpose` | string · optional |
| `vehicle_plate` | string · optional |

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the pass — the id the pass endpoints take |
| `pass_id` | UUID, in the signed payload |
| `category` | `single`, `recurring`, `contractor`, `delivery`; `resident` for the e-pass |
| `visitor_name` | who it is for |
| `visitor_phone` | or null |
| `purpose` | or null |
| `vehicle_plate` | or null |
| `valid_from` | ISO 8601 |
| `valid_to` | ISO 8601 |
| `single_use` | boolean |
| `status` | `active`, `used`, `cancelled` or `expired` |
| `code` | the short code a visitor can read out, e.g. `PPV2-4471` |
| `token` | the QR payload: `base64url(payload).base64url(signature)` |
| `share_count` | times shared |
| `used_at` | or null |
| `cancelled_at` | or null |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent in X-Device-Time |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not a pass of this household's. |
| 409 | `pass_not_active` | Used, cancelled or expired. |
| 422 | `validation_failed` | A signed field (`valid_from`, `valid_to`, `category`) was sent. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue.

### `visitor_passes.cancel`

Cancel a pass. Guards' handsets learn of it at their next sync; online verification refuses it at once.

| | |
|---|---|
| Method and path | `POST /api/v1/visitor-passes/{pass}/cancel` |
| App | Resident |
| Ability | `passes:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `pass` matches `[0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the pass — the id the pass endpoints take |
| `pass_id` | UUID, in the signed payload |
| `category` | `single`, `recurring`, `contractor`, `delivery`; `resident` for the e-pass |
| `visitor_name` | who it is for |
| `visitor_phone` | or null |
| `purpose` | or null |
| `vehicle_plate` | or null |
| `valid_from` | ISO 8601 |
| `valid_to` | ISO 8601 |
| `single_use` | boolean |
| `status` | `active`, `used`, `cancelled` or `expired` |
| `code` | the short code a visitor can read out, e.g. `PPV2-4471` |
| `token` | the QR payload: `base64url(payload).base64url(signature)` |
| `share_count` | times shared |
| `used_at` | or null |
| `cancelled_at` | or null |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent in X-Device-Time |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not a pass of this household's. |
| 409 | `pass_already_used` | Nothing left to cancel. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue, and tell the resident the gate learns of it when both are online.

### `visitor_passes.share`

Count a share and get the message to send. The app renders the QR image from `token` and hands both to the OS share sheet.

| | |
|---|---|
| Method and path | `POST /api/v1/visitor-passes/{pass}/share` |
| App | Resident |
| Ability | `passes:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `pass` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `channel` | string · optional · `whatsapp`, `sms`, `email` or `copy` |

**Response** `200`

| Key | Meaning |
|---|---|
| `pass_id` | UUID |
| `share_count` | after this share |
| `code` | short code |
| `token` | QR payload |
| `message` | the text to send the visitor |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent in X-Device-Time |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not a pass of this household's. |
| 409 | `pass_not_active` | Used, cancelled or expired. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Share from cache; send the count when online.

### `epass.show`

The resident's own signed e-pass, good for 24 hours and reused until an hour before it ends. Never restricted. Board resident-app-08.

| | |
|---|---|
| Method and path | `GET /api/v1/e-pass` |
| App | Resident |
| Ability | `passes:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the pass — the id the pass endpoints take |
| `pass_id` | UUID, in the signed payload |
| `category` | `single`, `recurring`, `contractor`, `delivery`; `resident` for the e-pass |
| `visitor_name` | who it is for |
| `visitor_phone` | or null |
| `purpose` | or null |
| `vehicle_plate` | or null |
| `valid_from` | ISO 8601 |
| `valid_to` | ISO 8601 |
| `single_use` | boolean |
| `status` | `active`, `used`, `cancelled` or `expired` |
| `code` | the short code a visitor can read out, e.g. `PPV2-4471` |
| `token` | the QR payload: `base64url(payload).base64url(signature)` |
| `share_count` | times shared |
| `used_at` | or null |
| `cancelled_at` | or null |
| `refresh_after` | fetch a new one after this |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `account_pending` | No register entry linked yet. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |

**Offline** — Show the cached pass until `valid_to`; a guard verifies it offline.

### `approvals.respond`

Approve or deny a visitor a guard is holding at the gate. Board resident-app-07.

| | |
|---|---|
| Method and path | `POST /api/v1/gate/approval/{approval}/respond` |
| App | Resident |
| Ability | `approvals:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `approval` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `decision` | string · required · `approve` or `deny` |

**Response** `200`

| Key | Meaning |
|---|---|
| `approval_id` | the request |
| `status` | `approved` or `denied` |
| `visitor_name` | who |
| `responded_at` | ISO 8601 |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent in X-Device-Time |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not a request for this household. |
| 409 | `approval_expired` | The three minutes ran out. |
| 409 | `approval_decided` | Already answered. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only — a late answer is no answer.

### `invoices.index`

The household's charges, newest first, each with what is still owed on it (payments clear the oldest first). Board resident-app-11.

| | |
|---|---|
| Method and path | `GET /api/v1/invoices` |
| App | Resident |
| Ability | `dues:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `currency` | `JMD` |
| `items.*.id` | the charge — the id `/invoices/{invoice}/pay-intent` takes |
| `items.*.reference` | e.g. `DUES-2026-09-L47` |
| `items.*.type` | `dues`, `special_assessment`, `fine` or `amenity` |
| `items.*.period` | `2026-09`, or null |
| `items.*.description` | text |
| `items.*.amount` | decimal string |
| `items.*.outstanding` | decimal string |
| `items.*.due_on` | YYYY-MM-DD |
| `items.*.status` | `paid`, `part_paid`, `overdue` or `due` |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |

**Offline** — Serve the cache with its age; never show a cached balance as current.

### `households.balance`

What the household owes, from the ledger. Board resident-app-11.

| | |
|---|---|
| Method and path | `GET /api/v1/households/{household}/balance` |
| App | Resident |
| Ability | `dues:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |
| Path parameters | `household` matches `[0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `household_id` | the household |
| `unit` | reference |
| `balance` | decimal string; negative is credit |
| `currency` | `JMD` |
| `as_at` | YYYY-MM-DD |
| `oldest_unpaid_due_on` | or null |
| `days_overdue` | integer |
| `on_payment_plan` | boolean |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not this account's household — the same answer as an id nobody has. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |

**Offline** — Serve the cache with its age.

### `households.statement`

The running statement from the journal, newest first. Board resident-app-12.

| | |
|---|---|
| Method and path | `GET /api/v1/households/{household}/statement` |
| App | Resident |
| Ability | `dues:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |
| Path parameters | `household` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `limit` | integer · optional (query) · 1–200, default 50 |

**Response** `200`

| Key | Meaning |
|---|---|
| `unit` | reference |
| `currency` | `JMD` |
| `closing_balance` | decimal string |
| `items.*.date` | YYYY-MM-DD |
| `items.*.description` | the line's memo |
| `items.*.reference` | the journal entry |
| `items.*.charge` | decimal string, or null |
| `items.*.payment` | decimal string, or null |
| `items.*.balance` | running, decimal string |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not this account's household — the same answer as an id nobody has. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |

**Offline** — Serve the cache with its age.

### `invoices.pay_intent`

How to pay this charge. Dues are paid by hand on this platform (Q-012): the answer is the amount, the reference to quote and the estate's instructions — never a card form. Board resident-app-13.

| | |
|---|---|
| Method and path | `POST /api/v1/invoices/{invoice}/pay-intent` |
| App | Resident |
| Ability | `payments:write` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `invoice` matches `[0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `invoice_id` | the charge |
| `online_payment_available` | false |
| `reason` | why, in a sentence |
| `amount_due` | decimal string still owed on this charge |
| `currency` | `JMD` |
| `quote_reference` | what to write on the transfer or cheque |
| `instructions` | the estate's own payment instructions |
| `estate` | the estate's name |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not a charge of this household's. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |

**Offline** — Serve the cached instructions.

### `payments.index`

Payments recorded for the household, newest first. Board resident-app-14.

| | |
|---|---|
| Method and path | `GET /api/v1/payments` |
| App | Resident |
| Ability | `dues:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `currency` | `JMD` |
| `items.*.id` | the payment |
| `items.*.receipt_no` | e.g. `PPV-R-04471` |
| `items.*.amount` | decimal string |
| `items.*.currency` | `JMD` |
| `items.*.method` | `bank`, `cash`, `cheque` or `card` |
| `items.*.reference` | the payer's reference, or null |
| `items.*.received_at` | ISO 8601 |
| `items.*.status` | `recorded` or `reversed` |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |

**Offline** — Serve the cache.

### `payments.receipt`

One receipt, as the estate issued it.

| | |
|---|---|
| Method and path | `GET /api/v1/payments/{payment}/receipt` |
| App | Resident |
| Ability | `dues:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |
| Path parameters | `payment` matches `[0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the payment |
| `receipt_no` | e.g. `PPV-R-04471` |
| `amount` | decimal string |
| `currency` | `JMD` |
| `method` | `bank`, `cash`, `cheque` or `card` |
| `reference` | the payer's reference, or null |
| `received_at` | ISO 8601 |
| `status` | `recorded` or `reversed` |
| `estate` | name |
| `unit` | reference |
| `household` | name |
| `received_by_name` | who took it, or null |
| `entered_at` | when it was keyed |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not a payment of this household's. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |

**Offline** — Serve the cache.

### `autopay.store`

AutoPay. Refused on this platform while dues are paid by hand (Q-012); the endpoint exists so the app can say so from the server rather than hide the control.

| | |
|---|---|
| Method and path | `POST /api/v1/autopay` |
| App | Resident |
| Ability | `payments:write` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `409` |
| Rate limit | `api-writes` |

**Request**

No body.

**Response** `409`

No success body: this endpoint answers with an error envelope.

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 409 | `autopay_unavailable` | Always, until a card gateway is ruled in. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |

**Offline** — Not applicable.

### `autopay.destroy`

Cancel AutoPay. No arrangement can exist, so this answers 404.

| | |
|---|---|
| Method and path | `DELETE /api/v1/autopay/{autopay}` |
| App | Resident |
| Ability | `payments:write` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `404` |
| Rate limit | `api-writes` |
| Path parameters | `autopay` matches `[0-9]+` |

**Request**

No body.

**Response** `404`

No success body: this endpoint answers with an error envelope.

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Always, while dues are paid by hand. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |

**Offline** — Not applicable.

### `tickets.index`

The household's maintenance tickets with their timelines. Board resident-app-16.

| | |
|---|---|
| Method and path | `GET /api/v1/tickets` |
| App | Resident |
| Ability | `tickets:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `items.*.id` | the ticket |
| `items.*.number` | e.g. 1042 |
| `items.*.title` | title |
| `items.*.category` | or null |
| `items.*.location` | where |
| `items.*.description` | or null |
| `items.*.priority` | `low`, `medium` or `high` |
| `items.*.status` | `submitted`, `acknowledged`, `assigned`, `in_progress`, `completed`, `verified` or `cancelled` |
| `items.*.reported_at` | ISO 8601 — the SLA runs from here |
| `items.*.technician_name` | or null |
| `items.*.eta_starts_at` | or null |
| `items.*.eta_ends_at` | or null |
| `items.*.resolution` | or null |
| `items.*.closed_at` | or null |
| `items.*.media_count` | attachments |
| `items.*.timeline.*.event` | `reported`, `acknowledged`, `assigned`, `started`, `resolved`, … |
| `items.*.timeline.*.status` | status after the event, or null |
| `items.*.timeline.*.note` | or null |
| `items.*.timeline.*.occurred_at` | ISO 8601 |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |

**Offline** — Serve the cache.

### `tickets.store`

Report a problem. It lands in the estate's maintenance queue. Board resident-app-15.

| | |
|---|---|
| Method and path | `POST /api/v1/tickets` |
| App | Resident |
| Ability | `tickets:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `title` | string · required |
| `category` | string · optional · `plumbing`, `electrical`, `security`, `landscaping`, `roads`, `water`, `waste`, `amenity`, `other` |
| `description` | string · optional |
| `location` | string · optional · defaults to the unit |
| `priority` | string · optional · `low`, `medium` (default) or `high` |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the ticket |
| `number` | e.g. 1042 |
| `title` | title |
| `category` | or null |
| `location` | where |
| `description` | or null |
| `priority` | `low`, `medium` or `high` |
| `status` | `submitted`, `acknowledged`, `assigned`, `in_progress`, `completed`, `verified` or `cancelled` |
| `reported_at` | ISO 8601 — the SLA runs from here |
| `technician_name` | or null |
| `eta_starts_at` | or null |
| `eta_ends_at` | or null |
| `resolution` | or null |
| `closed_at` | or null |
| `media_count` | attachments |
| `timeline.*.event` | `reported`, `acknowledged`, `assigned`, `started`, `resolved`, … |
| `timeline.*.status` | status after the event, or null |
| `timeline.*.note` | or null |
| `timeline.*.occurred_at` | ISO 8601 |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent in X-Device-Time |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue; attach photos after it syncs.

### `tickets.media`

Attach a photo or video (multipart, field `file`). Stored privately, hashed on arrival.

| | |
|---|---|
| Method and path | `POST /api/v1/tickets/{ticket}/media` |
| App | Resident |
| Ability | `tickets:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |
| Path parameters | `ticket` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `file` | file · required · JPEG, PNG, HEIC, MP4 or MOV, at most 50 MB |

**Response** `201`

| Key | Meaning |
|---|---|
| `media_id` | the attachment |
| `ticket_id` | the ticket |
| `filename` | as uploaded |
| `content_type` | as detected |
| `bytes` | size |
| `sha256` | hash |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent in X-Device-Time |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not a ticket of this household's. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue the file.

### `notices.index`

Published notices for the whole estate and the household's phase. Board resident-app-17.

| | |
|---|---|
| Method and path | `GET /api/v1/notices` |
| App | Resident |
| Ability | `notices:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `items.*.id` | the notice |
| `items.*.kind` | `general` or `urgent` |
| `items.*.title` | title |
| `items.*.body` | text |
| `items.*.author_name` | who posted it |
| `items.*.posted_as_role` | e.g. "Secretary", or null |
| `items.*.audience` | "Whole estate" or the phase |
| `items.*.published_at` | ISO 8601 |
| `items.*.read_at` | when this resident read it, or null |
| `unread` | count |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |

**Offline** — Serve the cache; queue reads.

### `notices.read`

Mark a notice read — board 30's "seen" count. Idempotent.

| | |
|---|---|
| Method and path | `POST /api/v1/notices/{notice}/read` |
| App | Resident |
| Ability | `notices:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `notice` matches `[0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `notice_id` | the notice |
| `read_at` | the first read |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent in X-Device-Time |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not a notice for this household. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue.

### `meetings.index`

General and phase meetings from the last six months and ahead, with agendas and the household's RSVP. Board resident-app-18.

| | |
|---|---|
| Method and path | `GET /api/v1/meetings` |
| App | Resident |
| Ability | `meetings:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `items.*.id` | the meeting |
| `items.*.type` | `agm`, `egm` or `phase` |
| `items.*.title` | title |
| `items.*.starts_at` | ISO 8601 |
| `items.*.venue` | or null |
| `items.*.virtual_link` | or null |
| `items.*.status` | `scheduled`, `held` or `cancelled` |
| `items.*.recording_enabled` | boolean — tell the resident before they join |
| `items.*.agenda.*.start_time` | or null |
| `items.*.agenda.*.text` | item |
| `items.*.minutes_available` | boolean |
| `items.*.my_rsvp` | `attending`, `apologies`, `not_attending` or null |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |

**Offline** — Serve the cache.

### `meetings.rsvp`

Say whether the household will attend. Not attendance — quorum is counted from the register taken at the meeting.

| | |
|---|---|
| Method and path | `POST /api/v1/meetings/{meeting}/rsvp` |
| App | Resident |
| Ability | `meetings:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `meeting` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `response` | string · required · `attending`, `apologies` or `not_attending` |

**Response** `200`

| Key | Meaning |
|---|---|
| `meeting_id` | the meeting |
| `response` | as recorded |
| `responded_at` | ISO 8601 |
| `households_attending` | households that said they will attend |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent in X-Device-Time |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not a meeting for this household. |
| 409 | `meeting_closed` | Started, held or cancelled. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue.

### `elections.index`

Elections and resolutions past draft, with the paper and whether the household has voted — never how. Board resident-app-19.

| | |
|---|---|
| Method and path | `GET /api/v1/elections` |
| App | Resident |
| Ability | `elections:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `items.*.id` | the ballot |
| `items.*.year` | year |
| `items.*.title` | title |
| `items.*.kind` | `election` or `resolution` |
| `items.*.question` | for a resolution, or null |
| `items.*.stage` | machine stage |
| `items.*.stage_label` | e.g. "Voting open" |
| `items.*.opens_at` | or null |
| `items.*.closes_at` | or null |
| `items.*.voting_open` | boolean |
| `items.*.has_voted` | boolean — turnout, not choice |
| `items.*.results_published` | boolean |
| `items.*.positions.*.id` | a position |
| `items.*.positions.*.name` | e.g. "Phase 2 Representative" |
| `items.*.positions.*.seat_count` | how many to mark at most |
| `items.*.positions.*.options.*.id` | an option id to send |
| `items.*.positions.*.options.*.label` | the candidate |
| `items.*.options.*.id` | a resolution's option id |
| `items.*.options.*.label` | e.g. "For" |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |

**Offline** — Serve the cache. Voting is online only.

### `elections.eligibility`

Whether the household may vote, under the estate's rules. Board resident-app-20.

| | |
|---|---|
| Method and path | `GET /api/v1/elections/{ballot}/eligibility` |
| App | Resident |
| Ability | `elections:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |
| Path parameters | `ballot` matches `[0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `ballot_id` | the ballot |
| `eligible` | boolean |
| `reason` | e.g. "arrears >90 days", or null |
| `checked_on` | YYYY-MM-DD |
| `voting_open` | boolean |
| `has_voted` | boolean |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | No such election. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |

**Offline** — Online only.

### `elections.ballot`

Cast the household's paper. One per household. The answer names no mark, option or receipt, and the idempotency record keeps no trace of the body. Board resident-app-21.

| | |
|---|---|
| Method and path | `POST /api/v1/elections/{ballot}/ballot` |
| App | Resident |
| Ability | `elections:write` |
| Idempotency-Key | required · opaque (the body is not hashed) |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |
| Path parameters | `ballot` matches `[0-9]+` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `option_ids` | array of integers · required · across every position, at most each position's seat count |

**Response** `201`

| Key | Meaning |
|---|---|
| `ballot_id` | the ballot |
| `voted` | true |
| `voted_on` | YYYY-MM-DD — a date, never a time |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent in X-Device-Time |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 403 | `not_eligible` | The estate's rules exclude the household; the reason is given. |
| 409 | `poll_closed` | Not open. |
| 409 | `already_voted` | The household's paper is in. |
| 422 | `paper_invalid` | Blank, over-marked, or an option from another ballot. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 404 | `not_found` | Nothing at this address, or nothing this handset may see — the two are deliberately the same. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only. Never queue a vote.

### `elections.results`

Certified, published results: counts per option, turnout and quorum.

| | |
|---|---|
| Method and path | `GET /api/v1/elections/{ballot}/results` |
| App | Resident |
| Ability | `elections:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |
| Path parameters | `ballot` matches `[0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the ballot |
| `year` | year |
| `title` | title |
| `kind` | `election` or `resolution` |
| `question` | for a resolution, or null |
| `stage` | machine stage |
| `stage_label` | e.g. "Voting open" |
| `opens_at` | or null |
| `closes_at` | or null |
| `certified_at` | ISO 8601 |
| `published_at` | ISO 8601 |
| `outcome_statement` | or null |
| `turnout.cast` | households |
| `turnout.eligible` | households |
| `turnout.percent` | integer |
| `quorum.required` | households |
| `quorum.met` | boolean |
| `positions.*.id` | position |
| `positions.*.name` | name |
| `positions.*.seat_count` | seats |
| `positions.*.options.*.id` | option |
| `positions.*.options.*.label` | candidate |
| `positions.*.options.*.votes` | marks |
| `options.*.id` | option |
| `options.*.label` | label |
| `options.*.votes` | marks |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | No such election. |
| 409 | `results_not_published` | Not yet certified and published. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |

**Offline** — Serve the cache once fetched.

### `amenities.index`

Bookable amenities and their terms, and whether the household may book. Board resident-app-22.

| | |
|---|---|
| Method and path | `GET /api/v1/amenities` |
| App | Resident |
| Ability | `bookings:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `may_book` | false while the household is restricted from bookings |
| `items.*.id` | the amenity |
| `items.*.name` | e.g. "Club House" |
| `items.*.icon_key` | icon |
| `items.*.capacity` | guests |
| `items.*.opens_at` | HH:MM:SS |
| `items.*.closes_at` | HH:MM:SS |
| `items.*.booking_fee` | decimal string |
| `items.*.deposit` | decimal string |
| `items.*.currency` | `JMD` |
| `items.*.cancellation_hours` | or null |
| `items.*.booking_window_days` | or null |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |

**Offline** — Serve the cache.

### `bookings.index`

The household's bookings. Board resident-app-23.

| | |
|---|---|
| Method and path | `GET /api/v1/bookings` |
| App | Resident |
| Ability | `bookings:read` |
| Idempotency-Key | — |
| X-Device-Time | — |
| Success | `200` |
| Rate limit | `api-reads` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `items.*.id` | the booking |
| `items.*.reference` | e.g. `BKG-2026-09-0004` |
| `items.*.amenity.id` | amenity |
| `items.*.amenity.name` | its name |
| `items.*.starts_at` | ISO 8601 |
| `items.*.ends_at` | ISO 8601 |
| `items.*.guests` | or null |
| `items.*.status` | `pending`, `confirmed`, `declined`, `cancelled` or `completed` |
| `items.*.booking_fee` | decimal string |
| `items.*.deposit` | decimal string |
| `items.*.deposit_state` | `none`, `awaiting`, `held`, `refunded` or `forfeited` |
| `items.*.currency` | `JMD` |
| `items.*.cancellable_until` | the app may cancel until this moment |
| `items.*.declined_reason` | or null |
| `items.*.cancelled_at` | or null |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |

**Offline** — Serve the cache.

### `bookings.store`

Request a booking. It is `pending` and holds the slot until the estate decides.

| | |
|---|---|
| Method and path | `POST /api/v1/bookings` |
| App | Resident |
| Ability | `bookings:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `201` |
| Rate limit | `api-writes` |

**Request**

| Field | Type · rule · meaning |
|---|---|
| `amenity_id` | integer · required |
| `starts_at` | ISO 8601 · required · in the future, within the booking window |
| `ends_at` | ISO 8601 · required |
| `guests` | integer · optional |
| `notes` | string · optional |

**Response** `201`

| Key | Meaning |
|---|---|
| `id` | the booking |
| `reference` | e.g. `BKG-2026-09-0004` |
| `amenity.id` | amenity |
| `amenity.name` | its name |
| `starts_at` | ISO 8601 |
| `ends_at` | ISO 8601 |
| `guests` | or null |
| `status` | `pending`, `confirmed`, `declined`, `cancelled` or `completed` |
| `booking_fee` | decimal string |
| `deposit` | decimal string |
| `deposit_state` | `none`, `awaiting`, `held`, `refunded` or `forfeited` |
| `currency` | `JMD` |
| `cancellable_until` | the app may cancel until this moment |
| `declined_reason` | or null |
| `cancelled_at` | or null |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent in X-Device-Time |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | No such amenity. |
| 422 | `booking_refused` | Outside hours, over capacity, the slot is taken, or bookings are closed to the household; the estate's own sentence. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 422 | `validation_failed` | A field is missing or malformed. `errors` maps each field to its messages. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Online only — a slot is first come.

### `bookings.cancel`

Cancel within the booking's cancellation terms. A deposit already held stays held until the office refunds it.

| | |
|---|---|
| Method and path | `POST /api/v1/bookings/{booking}/cancel` |
| App | Resident |
| Ability | `bookings:write` |
| Idempotency-Key | required |
| X-Device-Time | required |
| Success | `200` |
| Rate limit | `api-writes` |
| Path parameters | `booking` matches `[0-9]+` |

**Request**

No body.

**Response** `200`

| Key | Meaning |
|---|---|
| `id` | the booking |
| `reference` | e.g. `BKG-2026-09-0004` |
| `amenity.id` | amenity |
| `amenity.name` | its name |
| `starts_at` | ISO 8601 |
| `ends_at` | ISO 8601 |
| `guests` | or null |
| `status` | `pending`, `confirmed`, `declined`, `cancelled` or `completed` |
| `booking_fee` | decimal string |
| `deposit` | decimal string |
| `deposit_state` | `none`, `awaiting`, `held`, `refunded` or `forfeited` |
| `currency` | `JMD` |
| `cancellable_until` | the app may cancel until this moment |
| `declined_reason` | or null |
| `cancelled_at` | or null |
| `server_time` | the server's time |
| `device_time` | the handset's time, as sent in X-Device-Time |
| `clock_skewed` | true when the two disagree by more than two minutes |

**Errors** — every one in the envelope `{"error": {"code", "message"}}`

| Status | Code | When |
|---|---|---|
| 404 | `not_found` | Not a booking of this household's. |
| 409 | `cancellation_refused` | Inside the cancellation window, already started, or not cancellable. |
| 429 | `rate_limited` | Too many requests from this handset. `Retry-After` says when; retry with the same key. |
| 401 | `unauthenticated` | No token, or a token that has been revoked. |
| 403 | `missing_ability` | The token does not carry the ability this endpoint needs. |
| 403 | `wrong_app` | A Guard App token on a Resident App endpoint, or the reverse. |
| 403 | `no_site` | The token's guard or account is not attached to an estate on this platform. |
| 403 | `account_suspended` | The estate withdrew this resident's access. |
| 403 | `account_pending` | A Resident App account whose unit claim is not yet approved. |
| 409 | `request_in_progress` | A write with this Idempotency-Key is still being handled. Retry with the same key. |
| 422 | `idempotency_key_required` | A write without Idempotency-Key. |
| 422 | `device_time_required` | A write without X-Device-Time. |
| 422 | `idempotency_key_reused` | The key was already used for a different request. Use a new key for a new act. |
| 422 | `device_time_invalid` | X-Device-Time is not an ISO 8601 timestamp. |

**Offline** — Queue.

---

## 9. Where the rules live

The apps must not reimplement any of these; the consoles call the same services.

| Rule | Owner |
|---|---|
| Who may be admitted at a gate | `App\Services\Restriction\RestrictionPolicy` |
| What a guard may learn about a household | `Household::guardVisibleStanding()` — a boolean |
| Signing and verifying passes | `App\Services\Passes\VisitorPasses`, `OfflinePassVerifier`, `PassSigningKeys` |
| Alerts | `App\Services\Dispatch\AlertIntake` |
| Gate decisions | `App\Services\Dispatch\GateLog` |
| Clocking on and off | `App\Services\Dispatch\ShiftClock` |
| Handset enrolment | `App\Services\Devices\DeviceEnrolment` |
| Resident sign-in and unit claims | `App\Services\ResidentApp\ResidentSignIn`, `ResidentAccounts` |
| Dues figures | `App\Services\Estate\Dues` — the ledger |
| The vote | `App\Services\Estate\Governance::castVote` |
| Every endpoint's contract | `App\Api\Catalogue` |

## 10. Building before the apps ship

`php artisan simulate:alerts` and `php artisan simulate:gate --shift-change` speak to this API over HTTP
with borrowed guard handsets; every row they write is flagged `is_simulated` and badged on the consoles.
Send `"simulated": true` only from a simulator.

## 11. Not here, deliberately

| Not built | Why |
|---|---|
| SMS delivery | No provider chosen; `sms` answers `503 sms_unavailable` in production. Email works. |
| Card payments and AutoPay | Dues are paid by hand (Q-012). `pay-intent` gives instructions; `autopay` refuses. |
| Push notifications | No APNs/FCM integration. The apps poll `GET /me/household` (gate approvals), `GET /sync/pull` and `GET /messages`. |
| Realtime sockets for handsets | The Reverb channels are console channels. Handsets poll. |
| Biometric templates | Never leave the device. `alertness/{check}/respond` takes a 0–100 score at most. |
| Photos of walk-up visitors | No ruling on keeping images of the public; the approval works without one. |
| Deleting vehicles, contacts or members | Not specified by 13 D3; the estate office can. |
