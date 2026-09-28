# No kiosks or biometric scanners

Attendance devices are removed completely: the kiosk page, the device API, CSV import,
the Devices screen and wizard step, the permission, and the device columns. Attendance
is captured on the web, in the mobile app, or by HR, and every punch is held to the
day's policy. Punches a device recorded before keep their source as history. See
[ADR 0054](../decisions/0054-no-kiosks-or-biometric-scanners.md).

## Highlights

- **No unauthenticated surface records attendance any more.** The public `/kiosk` page
  and the key-authenticated `/api/devices/*` were the only ones.
- **One engine mode.** A scanner's "record it as it came" path (`record_only`), the
  untyped punch and the `device_sequence_anomaly` flag are gone. Every punch is
  enforced.
- **Nothing recorded is rewritten.** A kiosk or scanner punch keeps `kiosk` /
  `biometric` as its source, and the screens show it by that name.
- **The wizard has twelve steps.**

## Backend

- **Removed:**
  - `AttendanceDevice`;
  - `Api\DeviceController`, `Setup\AttendanceDeviceController`, `AuthenticateDevice`;
  - `KioskPunchRequest`, `DevicePunchesRequest`, `AttendanceDeviceRequest`,
    `ImportDevicePunchesRequest`, `AttendanceDeviceResource`;
  - `DevicesScreen`, `DeviceCsvImport`, `DevicePunchIngestor`;
  - the `attendance-device` rate limit, the routes, and `setup.devices.manage`.
- **`AttendancePunch::CAPTURE_SOURCES`** is `web`, `mobile`, `manual`. `PERSON_SOURCES`
  and the `device()` relation are gone.
- **`AttendanceClock::capture()`** takes a type always. A resend is recognised by the
  phone's client id per employee, and a punch is placed only when it reports a position.
- **`AttendancePolicySettings`**: a stored source list that named only retired sources
  reads as allowing every remaining one, never none.
- **`AttendanceCalculator` / `DayResult` / `DayCloser`**: no `device_sequence_anomaly`.
  `source_not_allowed` stays (a stricter policy re-applied to recorded punches).
- **The employee record** loses `device_enrollment_id` (request rules, resource, the
  device-reference scope).
- **Locations** lose their device count. **The assistant** loses the device exception
  and brief line.
- **Migration `2026_09_28_000000_remove_attendance_devices`:**
  - drops `attendance_devices`, `attendance_punches.attendance_device_id` (with its
    unique index) and `employees.device_enrollment_id`;
  - rewrites policies' sources, forgets the wizard's Devices step, clears activity-log
    pointers, and prunes the permission.

  `down()` restores the structure only.

## Frontend

- **Removed:** the `/kiosk` page, `pages/setup/devices.tsx`, `features/devices`,
  `features/kiosk`, the sidebar item and the wizard step.
- **Punch trail:** no device line. A retired source is shown by its stored name
  (`sourceLabel()`).
- **Exceptions table and flag chips:** no "Device punches out of order".
- **Policy editor:** offers web, mobile and HR entry.
- **Employee form:** no device enrolment ID.

## Verification

- **The migration against planted data** on the throwaway database: roll it back, plant
  data, migrate again.
  - **Planted:** a device, a scanner punch, an enrolment id, a kiosk-only policy, a
    mixed policy, the wizard's Devices step, a device activity entry, and the
    permission with a role grant.
  - **Result:** the table and columns are gone; the punch keeps `biometric`; the
    policies read `web, mobile, manual` and `mobile`; the step, the pointer, the
    permission and its grant are gone.
  - The rollback's `down()` ran cleanly.
- **Pest** (the full suite passes; see the next entry for the count):
  - `Attendance/DevicesRemovedTest` (4);
  - `Setup/SetupWizardStepsTest`, updated to twelve steps and a Locations step;
  - `AttendanceCaptureTest`, updated to the remaining sources.

## Notes

- **Docs:**
  - `docs/modules/attendance-devices.md` is deleted;
  - ADR 0040 is marked superseded for its devices;
  - the attendance, policies, locations, mobile, wizard, README and schema docs describe
    what remains.
