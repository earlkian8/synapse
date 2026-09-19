# 0040 — Punch capture: geofences, device ingestion, and records-for-devices

- **Status:** Accepted
- **Date:** 2026-09-19
- **Builds on:** [0010 — Attendance and the mobile API](./0010-attendance-and-mobile-api.md),
  [0038 — Attendance policies: presets and typed options, snapshotted per day](./0038-attendance-policies-presets-and-typed-options-snapshotted-per-day.md),
  [0039 — Attendance requests and period lock](./0039-attendance-requests-and-period-lock-the-engine-guards-the-lock.md)
- **Related:** [Attendance module](../modules/attendance.md),
  [Work locations](../modules/work-locations.md),
  [Attendance devices](../modules/attendance-devices.md),
  [Mobile app](../modules/mobile-app.md),
  [attendance tables](../database/attendance-tables.md),
  [0041 — Attendance days close themselves](./0041-attendance-days-close-themselves.md)

## Context

After ADR 0039 a day was judged by the company's rules and could be corrected and
frozen, but the punches it was built from could not be trusted, and some could not
arrive at all:

- **GPS was recorded, never checked.** Every mobile punch stored a latitude, longitude
  and accuracy. Nothing compared them with anywhere.
- **The policy's capture options did nothing.** `capture.allowed_sources`,
  `capture.selfie_required`, `capture.web_ip_allowlist` and `capture.geofence` were
  typed, stored and shown as *pending* in the policy editor (ADR 0038).
- **Kiosks and biometric scanners were named, never ingested.** `source` listed them;
  no endpoint accepted them (ADR 0010 left this out).
- **A device's punch would have been refused.** The engine throws on an unexpected
  transition. That is right for a person, who can be told "you're already clocked in".
  It is wrong for a scanner on a wall, which reports what happened and cannot be told
  anything.
- **A phone needed a connection to punch.** The server stamped the time it received the
  request.

## Decision

### The engine enforces for people and records for devices

- **One entry point, `AttendanceClock::capture(employee, ?type, context)`.** `punch()`
  wraps it. Web, mobile, kiosk, device push, CSV import and the assistant all go
  through it. It runs in one transaction with a row lock on the employee, in this order:
  1. Idempotency on the device's (or the phone's) own id for the punch.
  2. The type, inferred from the day when not given (in → out → in …).
  3. The work date (ADR 0036) and the period lock (ADR 0039).
  4. **For a person only:** the source is allowed; an offline punch is inside the window;
     the transition is valid *at the instant given* (including punches recorded after it,
     so a late-arriving offline punch cannot break the day's order); the capture rules
     hold (IP allowlist for web, selfie for mobile).
  5. Placement (the geofence), which may refuse a person under `block`.
  6. Write the punch, recompute the day.

  A refused punch still writes nothing.
- **`record_only` is the device half.** A biometric scanner's punch (push or CSV) skips
  step 4 and never blocks at step 5. It is written as it came, even out of order or
  twice under different ids, and the **evaluator** flags the day
  `device_sequence_anomaly`. The flag is a review flag, so the day waits for sign-off
  (ADR 0039). What a device *can* be refused is only what cannot be written at all: a
  row naming nobody the company employs, a row that is not a punch, a date inside a
  locked period.
- **A kiosk is a person, not a device.** Somebody stands at it and can be told, so a
  kiosk punch is enforced like a web one (`PERSON_SOURCES` = web, mobile, kiosk,
  manual). It takes the device's location rather than a GPS fix.
- **Flags are derived, never stored by hand.** `AttendanceCalculator` adds
  `outside_geofence`, `source_not_allowed`, `device_sequence_anomaly`, `clock_skew` and
  `auto_closed` from the punches on every evaluation. All five are in
  `DayResult::REVIEW_FLAGS`, so each puts the day to pending.

### Work locations and the geofence

- **`work_locations`**: name, address, a centre (`latitude`/`longitude`,
  `decimal(10,7)`) and `radius_meters` (25–5000, default 150). Optionally a
  `default_work_schedule_id` and an `attendance_policy_id`. Soft-deleted.
  **`employee_work_locations`** says who is based where, one marked primary.
- **The fence an employee is checked against** is their own active sites, or every
  active site when they have none, so a company with one office needs no assignment.
- **`GeofenceCheck`** measures the haversine distance to each site and picks the
  nearest. The punch is inside when `distance − accuracy ≤ radius`: a fix whose error
  circle reaches the fence is given the benefit of the doubt.
- **Stored on the punch**, not only judged: `work_location_id` (the nearest),
  `distance_meters`, `within_geofence`. The day modal can then say where each punch
  was, and a later change to a fence does not rewrite it.
- **The policy decides** (`capture.geofence`). `off` records the facts and flags
  nothing. `flag` accepts and flags `outside_geofence`. `block` refuses the person with
  the nearest site's name and the distance ("You are 1.2 km from Makati Office"). Under
  `flag` or `block`, a web or mobile punch with no position counts as not shown to be
  on site.
- **Approved remote work *or* official business excuses the fence**, for both the
  block and the flag. The plan named only remote work. Official business is a day away
  from the site by definition (ADR 0039), and refusing it would make it impossible to
  punch.
- **A location slots into both precedence chains** between the department and the
  organisation: *roster → assignment → employee → department → **location** →
  organisation → fallback* for the shift, and the same for the policy. The location
  used is the employee's primary site, or their only one. `ShiftResolver::forMany()`
  now takes six queries whatever the range (it was five).
- **The map is Leaflet on OpenStreetMap tiles; search is Nominatim.** No key, no
  account, no billing. The CSP allows exactly `https://tile.openstreetmap.org` for
  images and `https://nominatim.openstreetmap.org` for connections.

### Capture rules

Enforced for people, in the engine, from the day's policy:

- **`allowed_sources`**: refuse a source the policy does not list. The `manual` source
  is checked too, so a policy can forbid HR's hand entry. A device punch from a source
  not listed is recorded and flagged `source_not_allowed`.
- **`selfie_required`**: refuse a mobile punch without a photo.
- **`web_ip_allowlist`**: CIDR list, refuse a web punch from elsewhere
  (`IpUtils::checkIp`). The real client address is only known behind a trusted proxy,
  so **`config/trustedproxy.php`** reads `TRUSTED_PROXIES` (a list, or `*`); unset
  means no proxy is trusted.

### Devices

- **`attendance_devices`**: name, `type` (`kiosk` | `biometric`, fixed once created),
  `work_location_id`, `api_key_hash` (sha256, unique), `api_key_hint` (last four),
  `last_seen_at`, `is_active`, `csv_mapping`, `created_by`. Soft-deleted.
- **A device authenticates by key, not a user.** `AttendanceDevice::issueKey()`
  returns `sdk_` plus 40 random characters once and stores only the hash. The key is
  shown once; rotating it invalidates the old one. `AuthenticateDevice` reads
  `Authorization: Bearer` or `X-Device-Key`, binds the device's organisation as the
  tenant, and records `last_seen_at`. No user permission applies. A route can require a
  kiosk, so a scanner's key cannot drive the interactive kiosk, which reveals whose an
  employee number is. The `attendance-device` rate limiter allows 120 requests a minute
  per key.
- **`POST /api/devices/punches`** takes a batch of up to 500
  `{external_id, employee_ref, punched_at, type?}`, plus an optional `sent_at`, and
  answers per row: `accepted`, `duplicate`, `unknown_employee`, `invalid` or `refused`.
  - **Idempotent** on `(attendance_device_id, external_id)`, unique in the database:
    devices resend.
  - **`employee_ref`** matches `employees.employee_no`, then the new
    `employees.device_enrollment_id` (unique per organisation), exactly and ignoring
    case.
  - **A `punched_at` with no offset** is read on the organisation's clock. That is how
    scanners keep time.
  - **One activity-log entry per batch**, not per punch.
- **CSV import** for a scanner that cannot push. The file goes through the same
  ingestor (`DeviceCsvImport`):
  - The delimiter is detected and a BOM is stripped.
  - Columns are either one date-time column, or a date column and a time column.
  - Type words and the common 0–5 state codes are mapped to punch types.
  - When the file carries no id, one is derived from the row, so importing the same
    file twice records nothing new.
  - The column mapping is kept per device. A file is at most 10,000 rows.
- **The web kiosk** is `/kiosk`: public, because nobody signs in on a shared tablet.
  - The setup link carries the key in the URL fragment (`#key=…`), which never reaches
    a server log. The page moves it into the browser's storage, then reloads without it
    (Inertia writes the fragment back on every history update, so it cannot be patched
    out).
  - The employee types their number (or enrolment id; bare digits are formatted as an
    employee number), sees their name and the punches open to them, and punches. A
    camera snapshot is taken when the tablet has a camera.
  - The punch is `source = kiosk` at the device's location.

### Offline mobile punches

- **The app queues a punch it cannot send**, stamped with the phone's time and a
  client id, and sends the queue on reconnect, on returning to the foreground, and every
  30 seconds.
- **`punched_at` is the phone's time** when the punch is accepted. `device_punched_at`
  keeps it, `received_at` says when it arrived, and `clock_skew_seconds` is the phone's
  clock at sending minus the server's.
- **Window and skew are policy.** A queued punch older than
  `capture.offline_window_hours` (default 72, 1–720) is refused and needs a correction
  request (ADR 0039). Skew beyond `capture.max_clock_skew_minutes` (default 10) is
  accepted and flagged `clock_skew`.
- **The client id makes a resend harmless**: the same id twice returns `duplicate`
  with the original punch.

## Consequences

- **A location has no timezone of its own yet.** The plan offered a nullable
  `work_locations.timezone`. Every judgement still reads the organisation's clock
  (ADR 0036), and a second clock would reach every "today" in the app. A company whose
  sites span zones is not served yet.
- **A fence change never rewrites a punch.** The facts were stored when it was made, and
  no recompute is queued for a location change (ADR 0041).
- **The shift resolver costs one more query**, whatever the range.
- **A device key is a bearer secret.** Anybody holding it can post punches for that
  company, and only as that device. It is hashed at rest and rotatable, and the device
  can be deactivated.
- **The kiosk's lookup says whose a number is** to whoever stands at the tablet. That
  is the point of a kiosk, and the reason only a kiosk's key can call it.
- **No vendor SDK.** The push API and CSV import cover most scanners (open decision 5
  of the plan). An adapter is written only when a customer needs one.
- **Faces are evidence, not identification.** Selfies and kiosk snapshots are stored,
  never matched.
