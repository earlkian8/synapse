# Attendance Devices

**Kiosks and biometric scanners.** A device authenticates with its own key, not a user.
It sends punches that the attendance engine records. A scanner that cannot push is fed
from its CSV export. A shared tablet can be turned into a kiosk. The *why* is
[ADR 0040](../decisions/0040-punch-capture-geofences-device-ingestion-and-records-for-devices.md);
this is the *how*. Everything is tenant-scoped (ADR 0005).

> Status: **Active** · Route prefix: `/setup/devices` · Device API: `/api/devices`
> · Kiosk: `/kiosk`
> Sidebar: Company Setup → Devices (gated by `setup.devices.manage`)

## Two kinds

| | Kiosk | Biometric scanner |
| --- | --- | --- |
| What it is | A shared tablet running `/kiosk` | A fingerprint, face or card reader on the wall |
| Who stands at it | A person, who can be told no | Nobody the device can talk to |
| How punches arrive | One at a time, as people tap | In batches, pushed or imported from CSV |
| The engine | **Enforces**: a double clock-in is refused, as on the web | **Records**: every punch is written, out of order or not, and the day is flagged |
| Source | `kiosk` | `biometric` |

Both record the device's site as the punch's location. The type is fixed once a device
is created.

## Surfaces

- **Company Setup → Devices**: a list of devices, each with its kind, site, key hint
  (`sdk_…abcd`), **last seen**, how many punches it has sent, and whether it is active.
  - **Add device**, **Edit**, **Replace key** and **Remove**. Removing deactivates the
    key and keeps the punches.
  - **The key is shown once**, in a dialog, straight after creating or replacing it. It
    is stored only as a hash and cannot be shown again; a lost key is replaced, not
    recovered.
  - For a kiosk, the dialog also gives the **kiosk link** (`/kiosk#key=…`) to open on
    the tablet. For a scanner, it gives the push endpoint.
  - **Import** (scanners): pick a CSV export. The dialog reads its header and offers
    each column for the employee reference, the date and time (one column or two), the
    type, and the device's own row id. The mapping is saved on the device for the next
    file. The result lists how many rows were recorded, how many had already been
    imported, and each row that was not recorded, by line.
- **`/kiosk`**: the kiosk itself. It is public, because nobody signs in on a shared
  tablet.
  - A large clock and a keypad. The employee types their number (bare digits are
    formatted as an employee number) or their **device enrolment id**. The kiosk greets
    them by name and offers the punches open to them.
  - A camera snapshot is taken with the punch when the tablet has a camera.
  - A stamped confirmation follows, and the screen resets itself after a short idle.
  - The key arrives in the link's fragment, which never reaches a server log. The page
    moves it into the browser's storage, then reloads without it. A tablet whose key
    no longer works says so.

## Device API

Authenticated by `Authorization: Bearer <key>` or `X-Device-Key: <key>`
(`AuthenticateDevice`), which binds the device's organisation as the tenant and updates
`last_seen_at`. Rate-limited to 120 requests a minute per key (`attendance-device`).

- **`GET /api/devices/me`**: the device's name, type, site, organisation and timezone.
- **`POST /api/devices/punches`**: a batch of at most 500 rows.

  ```json
  {
    "sent_at": "2026-09-19T08:03:10+08:00",
    "punches": [
      { "external_id": "4411", "employee_ref": "EMP-00042", "punched_at": "2026-09-19 08:01:55", "type": "clock_in" },
      { "external_id": "4412", "employee_ref": "7731", "punched_at": "2026-09-19T08:02:40+08:00" }
    ]
  }
  ```

  - `employee_ref` matches an employee number, then `employees.device_enrollment_id`,
    exactly and ignoring case.
  - `punched_at` with an offset is taken as it is. Without one it is read on the
    organisation's clock.
  - `type` is optional. An untyped punch is inferred from the day (in, out, in …).
  - `sent_at` is optional. When given, it measures the device's clock skew.

  Every row gets a result: `accepted`, `duplicate` (the same `external_id` from this
  device again; devices resend), `unknown_employee`, `invalid`, or `refused` (for
  example, a day inside a locked period). One activity entry is written per batch.
- **`POST /api/devices/kiosk/lookup`** and **`POST /api/devices/kiosk/punch`**: the
  kiosk page's calls, for a **kiosk's key only**. A scanner's key cannot reveal whose a
  number is.

## Records for devices

A scanner's punch goes through `AttendanceClock::capture()` with `record_only`. It
skips the transition check, the source check and the geofence block. It is stored as
it came, and the evaluator flags the day:

- `device_sequence_anomaly`: a device punch breaks the day's order (two clock-ins, a
  clock-out with nobody in).
- `source_not_allowed`: the day's policy does not list `biometric`.
- `clock_skew`: the device's clock was further off than the policy's
  `capture.max_clock_skew_minutes`.

All three are review flags, so the day waits for sign-off (ADR 0039) instead of being
lost or refused.

## CSV import

`DeviceCsvImport` reads a vendor export into the same rows the push API takes:

- It detects the delimiter (comma, semicolon or tab) and strips a byte-order mark.
- Time comes from one date-time column, or from a date column and a time column.
- A type word ("in", "out", "break out", …) or the common state codes 0–5 become a
  punch type. Anything else is left untyped and inferred.
- Without an id column, each row's id is derived from its content, so importing the
  same file twice records nothing new.
- At most 10,000 rows per file.

## Employees

`employees.device_enrollment_id` is the number a person is enrolled under on the
scanners, when it is not their employee number. It is set on the employee form, and it
is unique per organisation.

## Permissions

`setup.devices.manage` covers the page and every change, all activity-logged under
`company-setup`. The device API uses the device key; no user permission applies.
