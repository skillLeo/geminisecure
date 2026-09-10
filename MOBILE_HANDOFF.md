# Mobile handoff — what the Guard App and the Resident App connect to

This engagement built the **web** surfaces: the Gemini Console (45 screens) and
the Estate Console (40 screens). The two mobile apps are a later phase. This
document is what their team needs on day one.

Nothing here is aspirational. Every endpoint below exists, is behind a real
token, is rate-limited, and is exercised on every test run by a simulator that
calls it over HTTP rather than reaching past it — `simulate:alerts` and
`simulate:gate`. If an endpoint is not in this document, it does not exist yet.

---

## 1. The one rule that shapes the whole API

**No endpoint reachable by a guard may return a monetary amount.** Not a
balance, not an ageing bucket, not a payment history, not a wage — and not a
form of words that implies one.

This is invariant 2, and it is enforced at the API layer rather than by leaving
a figure off a screen. A household in arrears is reported to a handset as
`access_restricted: true` and an amber verdict, and there is no field, query or
code path behind these endpoints that could carry the figure itself.

`tests/Feature/MobileApiTest.php` asserts it against the **whole response body**
of every endpoint a handset can reach, rather than against the absence of a
named field — so a field added later cannot smuggle one through.

Build the apps assuming the figure is unavailable. It is not an oversight to be
worked around; it is the product.

---

## 2. Authentication

### How a handset gets a token

A token belongs to the **guard**, not to a console user:

```
php artisan device:enrol GS-1041 --label="Samsung A15 · Main Gate"
```

That prints a device id and a bearer token **once**. Sanctum stores only a hash;
if the value is lost, enrol again.

```
php artisan device:enrol GS-1041 --revoke
```

takes the handset out of service.

**Why the guard and not a user.** A token minted on a console account is a
credential that can, in principle, reach a console. Making the guard the
tokenable closes that by construction: the principal holds no role, no
permission and no console, so the abilities on the token are the whole of what
it can do. A stolen handset can raise an alert and clock on. It cannot open a
single screen.

**One device per guard.** Enrolling a new handset revokes the previous one and
its token, and clears the device binding. A guard who replaces a lost phone ends
up with exactly one working credential, or the lost one keeps clocking them in.

### Abilities

| Ability | Grants |
|---|---|
| `alerts:raise` | `POST /api/v1/alerts` |
| `passes:verify` | `POST /api/v1/passes/verify`, `POST /api/v1/gate-events` |
| `shifts:clock` | `POST /api/v1/shifts/{shift}/clock-in`, `/clock-out` |

A **Guard App** handset gets all three. A **Resident App** handset gets
`alerts:raise` and nothing else — a resident's token must not be able to
adjudicate arrivals at a gate or clock anybody on.

Present the token as `Authorization: Bearer <token>`. Every request needs it;
there is no anonymous endpoint.

### What the enrolment flow still needs

There is no in-app enrolment yet. A handset is enrolled from the command line,
which is enough to build against and not enough to ship. The app team should
expect to design an enrolment code exchange; the token issuing side
(`App\Services\Devices\DeviceEnrolment`) is already written and audited.

---

## 3. Endpoints

All are `POST`, all take and return JSON, all live under `/api/v1`.

### `POST /alerts` — panic, duress, medical, fire, intrusion

Raised by **either** app: a guard's duress button and a resident's panic button
are the same event to dispatch.

```json
{
  "tenant_id": "phoenixpark",
  "kind": "panic",
  "guard_id": 12,
  "raised_by_name": "Andrea Fletcher",
  "unit_reference": "Lot 47",
  "latitude": 18.0179,
  "longitude": -76.8099,
  "device_time": "2026-09-10T18:42:11+05:00",
  "captured_offline": false,
  "idempotency_key": "a3f1…"
}
```

`kind` is one of `panic`, `duress`, `medical`, `fire`, `intrusion`.

Returns `201` with `id`, `status`, `server_time`, `clock_skewed`.

### `POST /passes/verify` — the scan verdict

```json
{ "household_id": 91, "pass_category": "guest", "pass_reference": "QR-…" }
```

Three verdicts, and **the middle one is the point**:

| verdict | tone | meaning |
|---|---|---|
| `admit` | green | valid pass, household clear |
| `restricted` | **amber** | valid pass, household restricted — call management |
| `deny` | red | the pass itself is invalid or expired |

Amber, not red, because the pass **is** valid and the person at the gate is
known. The guard's next action is to call management, not to turn somebody away.
Design the screen so those two are never confusable.

The response carries a household name, a unit, a verdict and
`access_restricted`. It carries no amount, and it never will.

### `POST /gate-events` — what the guard actually did

A **separate call** from the scan, deliberately. A guard can be shown a verdict
and still not admit somebody — the visitor changes their mind, the contractor
has no paperwork, the car turns around — and a system that logged the admission
at the moment it answered the scan would fill the gate log with arrivals that
never happened. The verdict is advice; this is the decision.

```json
{
  "tenant_id": "phoenixpark",
  "verdict": "admit",
  "category": "Visitor",
  "subject": "Andrea Fletcher",
  "basis": "QR pass",
  "guard_id": 12,
  "post_id": 3,
  "device_time": "2026-09-10T18:42:14+05:00",
  "idempotency_key": "b7c2…"
}
```

`verdict` is `admit`, `deny`, `override` or `exit`.

**`basis` is the field that matters most.** "QR pass", "Pre-approved" and "Tag
read" mean the platform issued or pre-approved the arrival; "Guard decision"
means the guard decided at the gate. The Gemini console counts the first three
against the fourth to tell an account manager how much of the pass system a
client is actually using. Send what actually happened.

**An override requires a `reason`** and the endpoint refuses one without. Board
guard-app-07 draws "Override admit" as its own control: somebody is being let in
against the system's advice, and the estate is entitled to know why.

### `POST /shifts/{shift}/clock-in` and `/clock-out`

```json
{ "geofence_distance_m": 12, "mock_location": false, "method": "app" }
```

Clock-in is idempotent: the **first** one is the one that happened, and a
handset syncing a queue it captured in a tunnel will not rewrite when somebody
arrived. Clock-out on a shift that never started returns **409**, not 422 — the
request is fine, the shift is simply in a state that cannot accept an ending,
and the queued clock-in the handset still holds is what resolves it.

`mock_location: true` is **recorded, never refused**. Rejecting it would leave a
post reading as unmanned while somebody stands at it, and would tell whoever
spoofed the location that they had been caught. Send it honestly.

`geofence_distance_m` is stored and never enforced — geofencing is deferred
(D-033). Send it if the handset knows it; nothing will refuse a clock-in for
being far from the post, because no distance has been agreed.

---

## 4. Offline, retries and clocks

**Every write takes a client-generated `idempotency_key`, and it is required.**
A handset at a gate loses signal constantly. The key is chosen by the client
before the first attempt, so a retry is safe by construction rather than by the
server guessing which rows look alike. Generate one per event, persist it with
the queued event, and reuse it on every retry until the server accepts.

A retry returns the row that already exists, with the same `id`. That is a
success, not a duplicate.

**The device clock is kept and never corrected.** Send `device_time`; the server
records both it and its own, and returns `clock_skewed` when they disagree by
more than two minutes. A disagreement is evidence about a handset, and silently
correcting it destroys the only record that it happened. Surface it to the guard
— it is the only way they would ever find out their phone is wrong.

**`captured_offline`** tells dispatch that a timestamp was taken away from the
network. Send it truthfully; the console shows it beside the event rather than
reconciling it away.

---

## 5. Rate limits

Keyed on the **device** — the bearer token — not the address, because every
guard at one estate can sit behind one mobile carrier NAT.

| Endpoint | Per minute, per device |
|---|---|
| `/alerts` | 30 |
| `/gate-events`, `/passes/verify` | 120 |
| `/shifts/*/clock-*` | 20 |

The alert ceiling is set where **no frightened person can reach it and only a
loop can**. A limit that silences an alert is worse than the flood it prevents.
If the app ever sees `429` on `/alerts`, something is wrong with the app, not
with the person holding it.

Exceeding a limit returns `429` with a `Retry-After` header. Queue and retry
with the same idempotency key.

---

## 6. Realtime

Alerts broadcast on a private channel, one per estate:

```
private-estate.{tenantId}.alerts        event: .alert.raised
```

Authorised through Laravel Broadcasting; a subscriber must be able to access
that estate. Reverb is the server (`REVERB_*` in `.env`).

**The payload is deliberately thin** — a notification that something happened,
never a position, an identity or an amount. It is enough to know to refresh; it
is not enough to render a row from. Ask the API for the detail.

**Do not treat the socket as a guarantee.** The web console pushes *and* polls,
and backs the poll off to a thirty-second heartbeat while the socket is up
rather than switching it off, because a socket that has silently stopped
delivering is indistinguishable from a calm night. A mobile app on a mobile
network has every reason to be at least as careful.

---

## 7. Where the rules live

The apps must not reimplement any of these. Each is one service, and the web
console calls the same one:

| Rule | Owner |
|---|---|
| Who may be admitted at a gate | `App\Services\Restriction\RestrictionPolicy` |
| What a guard may be told about a household | `Household::guardVisibleStanding()` |
| Alert intake, acknowledgement, resolution | `App\Services\Dispatch\AlertIntake` |
| Gate decisions | `App\Services\Dispatch\GateLog` |
| Clocking on and off | `App\Services\Dispatch\ShiftClock` |
| Device enrolment and revocation | `App\Services\Devices\DeviceEnrolment` |

Business rules live in services precisely so the web console and the mobile
clients cannot drift into different answers.

---

## 8. Building against it before the apps exist

```
php artisan simulate:alerts --count=5
php artisan simulate:alerts --offline          # skewed clock, offline capture
php artisan simulate:gate --count=20 --shift-change
```

Both enrol a real handset, speak over HTTP with its bearer token, and hand the
handset back. They exercise the middleware, the ability checks, the validation
and the idempotency rather than bypassing them — so anything built against the
simulator does not have to be rewritten when the apps arrive.

Every row they create is flagged `is_simulated`, and the web screens carry a
badge saying so. Send `"simulated": true` only from a simulator; a real handset
must never set it.

---

## 9. What is deliberately not here

| Not built | Why |
|---|---|
| In-app device enrolment | Needs an enrolment code exchange; token issuing is written |
| Geofence enforcement | Deferred (D-033). Distance is stored, never enforced |
| Biometric enrolment | Consent flag ships **off** (D-022). See QUESTIONS.md Q-015 |
| Card payments | Manual-first behind an adapter (D-023). See Q-012 |
| Resident-side endpoints | Dues, bookings, tickets, voting — the resident app's own phase |
| Messaging | No message model exists; the console says so rather than drawing a fake thread |

`DECISIONS.md` carries the full reasoning for each. `QUESTIONS.md` carries what
is still waiting on a client ruling.
