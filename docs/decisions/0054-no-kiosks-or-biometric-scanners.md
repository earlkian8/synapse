# 0054 — No kiosks or biometric scanners

- **Status:** Accepted
- **Date:** 2026-09-29
- **Supersedes:** the device half of [0040 — Punch capture: geofences, device ingestion, and records-for-devices](./0040-punch-capture-geofences-device-ingestion-and-records-for-devices.md).
  Its geofences, work locations, capture rules and offline phone punches stand.
- **Related:** [Attendance](../modules/attendance.md),
  [Work Locations](../modules/work-locations.md),
  [Attendance Policies](../modules/attendance-policies.md),
  [attendance tables](../database/attendance-tables.md),
  [0042 — No attendance requests or periods](./0042-no-attendance-requests-or-periods-the-roster-is-setup.md)
  (the same kind of removal).

## Context

ADR 0040 added two kinds of attendance device:

- a **kiosk**: a shared tablet at the door, running a public `/kiosk` page;
- a **biometric scanner**: it pushed batches to a key-authenticated API, or was fed
  from its CSV export.

Both reached into the core:

- the engine gained a second mode (`record_only`: a scanner's punch is written as it
  came, never refused);
- the evaluator gained a flag (`device_sequence_anomaly`);
- punches gained a device column, and employees an enrolment id;
- the app gained a public page, an unauthenticated API with its own middleware and
  rate limit, a Company Setup screen, a wizard step and a permission.

The system is meant to capture attendance on the web and in the mobile app, where a
person signs in as themselves, is placed against a fence, and can be told no. A key that
any holder can post punches with, and a public page that says whose an employee number
is, are standing risks. They are carried for hardware this system does not need.

## Decision

- **Devices are removed**, with every path to them:
  - the Devices screen and its wizard step;
  - the device API (`/api/devices/*`), `AuthenticateDevice` and the
    `attendance-device` rate limit;
  - the `/kiosk` page;
  - CSV import (`DeviceCsvImport`, `DevicePunchIngestor`);
  - the permission `setup.devices.manage`;
  - the employee form's device enrolment id.
- **The engine has one mode.** Every punch is enforced: its source, the day's order,
  the capture rules and the fence. `capture()` always takes a type; nothing infers
  one. The evaluator no longer derives `device_sequence_anomaly`.
- **The capture sources are `web`, `mobile` and `manual`** (plus `system`, which no
  policy chooses).
  - A policy that listed `kiosk` or `biometric` loses them.
  - One that listed only them reads as allowing every remaining way, not none. The
    migration rewrites it, and `AttendancePolicySettings` reads older snapshots the
    same way.
- **What devices left behind stays as history.** A punch a kiosk or scanner recorded
  keeps its `source`. No truer value could be written there, and a day already judged
  by it stays judged. The screens show such a source by its stored name.
- **Unchanged:**
  - `external_id`, `device_punched_at`, `received_at` and `clock_skew_seconds` stay:
    the phone's offline queue uses them;
  - a resend is recognised per employee;
  - `source_not_allowed` stays: re-applying a stricter policy to recorded punches can
    still raise it.
- **One forward migration** (`…_remove_attendance_devices`) does all of the
  following, and its `down()` restores the structure, not the data:
  - drops `attendance_devices`, `attendance_punches.attendance_device_id` (with its
    unique index) and `employees.device_enrollment_id`;
  - rewrites the policies;
  - removes the wizard's stored Devices step;
  - clears activity-log pointers to devices;
  - prunes the permission and its grants.

## Consequences

- **Attendance is captured by a signed-in person or by HR.** A company that clocked in
  at a shared tablet uses the web or the app instead. Punches from a scanner are
  entered by HR.
- **No unauthenticated surface records attendance.** The public kiosk page and the
  key-authenticated API were the only ones.
- **A day recorded under a device keeps what it said.** Its punches keep their source,
  its snapshot keeps its rules. A re-apply judges it by today's policy, which treats an
  unknown source as neither allowed nor refused.
- **The wizard has twelve steps**, and Company Setup one fewer screen.
- **Bringing devices back is a decision of its own**, not a revert of this one:
  hardware integration, key custody and a public page each need their own case.
