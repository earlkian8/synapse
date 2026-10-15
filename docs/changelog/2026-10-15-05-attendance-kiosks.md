# Kiosks for people without a phone

Workers without a smartphone, and often without an account, had no way to clock in
themselves. HR typed their time in from paper later, and nobody's policy judged it
when it happened. A shared tablet at the site now does it: a **kiosk**, where each
person types their employee number and a PIN. The kiosk removed in ADR 0054 is not
back as it was. A tablet is paired with a one-time code and keeps a secret nobody
sees. Nobody is named until their number *and* PIN match. Every punch goes through
the same engine and policy as a phone's. Biometric scanners stay out.

See [ADR 0092](../decisions/0092-kiosks-for-people-without-a-phone-a-paired-device-and-a-pin-of-their-own.md)
and [Attendance kiosks](../modules/attendance-kiosks.md).

## Highlights

- **Attendance → Kiosks**, a new section beside *Time records* and *Shift roster*,
  for the new permission `attendance.kiosks.manage` (HR Manager by default). Two
  lists, each with its own actions: **Kiosks** (add, pair a device, edit, sign the
  device out, remove) and **Who clocks in at a kiosk** (give PINs, give a new PIN,
  withdraw).
- **Pairing a tablet**: open the kiosk page on it, then type the code from *Pair a
  device*. A code works once, for 15 minutes. The tablet then keeps an encrypted,
  HttpOnly, SameSite=Strict cookie. *Pair a new device* moves the kiosk to another
  tablet, and the old one stops at once.
- **PINs**: give them to one person or a whole crew (the picker opens on people who
  don't use the app). Each PIN is shown once, with **Print slips** to cut and hand
  out. The first time somebody uses theirs, the kiosk asks them to **choose their
  own**, so the PIN that clocks them in is one HR never saw.
- **At the kiosk**: a big bundy clock on the server's time, then *employee number →
  PIN → your day*. The punch you most likely came for is one big button, and the
  time is stamped onto your time card with a beep. It signs you out by itself if you
  walk away. The digits of a number are enough: *42* for *EMP-00042*. It works
  landscape, portrait, full screen, and with a keyboard.
- **Safe to leave on a wall**: a wrong number and a wrong PIN get the same answer.
  Five wrong tries lock that PIN for 15 minutes, and the lock is logged. Twenty
  wrong tries in ten minutes pause the kiosk. A browser somebody is signed in on
  asks them to sign out before it can be a kiosk.
- **Judged like any punch**: a kiosk punch is placed at the kiosk's site (on site at
  one of the person's own sites, off site elsewhere), held to the day's order and to
  the person's policy, and carries the camera's photo. The policy option is now *A
  photo with every app and kiosk punch*. The day's record shows *Warehouse gate
  kiosk* on the punch.

## Backend

- Migration `2026_10_15_020000_create_attendance_kiosks`: `attendance_kiosks`,
  `employee_kiosk_pins`, `attendance_punches.attendance_kiosk_id`. Policies that
  allowed all three ways to punch now allow `kiosk` too. Grants
  `attendance.kiosks.manage` to every company's HR Manager.
- `AttendancePunch::CAPTURE_SOURCES` is `web, mobile, kiosk, manual`.
  `AttendanceClock::capture()` takes a `kiosk` context: `placeAtKiosk()` places the
  punch, the photo rule covers kiosks, and the punch records its kiosk.
- New: `AttendanceKiosk` and `EmployeeKioskPin` models; `KioskPins` (issue, sign in
  with both locks, choose a PIN, weak-PIN rules); `KioskTickets` (two-minute tickets
  bound to a kiosk and to the PIN as issued); `KioskPresenter`; `KioskWorkflow` (every
  HR change, used by the screen and the assistant); `AuthenticateKiosk` middleware;
  `KioskDeviceController` (`routes/kiosk.php`); `KioskController` and
  `KioskPinController`; `KiosksScreen`.
- Pairing codes and PINs are answered as JSON with `no-store`, never as page props or
  flash data. `kiosk/*` and the two secret-returning HR endpoints render errors as
  JSON. Rate limits `kiosk` (120/min per device) and `kiosk-pairing` (10/min per
  address).
- `/attendance` sends a kiosk-only manager to the kiosks. `RequireWorkspace` and
  `RequireCompanySetup` leave the kiosk alone.
- Assistant: new `KiosksModule` with `find_kiosks`, `find_kiosk_pins`, and
  `sign_out_kiosk` / `withdraw_kiosk_pin` (both held for Confirm). It never issues a
  code or a PIN. Router entry, topic context, consequence lines.
- Data Export: a *Kiosks* dataset, without device secrets, codes or PIN hashes.
- Help Center: new *Attendance kiosks* article; *Clocking in and out* explains the
  kiosk; *Attendance policies* names it. System guide entry for the screen.

## Frontend

- `pages/kiosk.tsx` with `features/kiosk/` (pairing, signed-in and clock screens;
  keypad, entry dots, time card with the stamp; camera, server clock, wake lock and
  beep). It has its own fixed palette, whatever the browser's theme.
- `pages/attendance/kiosks.tsx` with `features/kiosks/` (the two lists, kiosk form,
  pairing-code dialog with a countdown, PIN picker, shown-once PINs with printable
  slips).
- `AttendanceNav` gains *Kiosks*; the sidebar's Attendance entry also shows for
  `attendance.kiosks.manage`. The punch trail shows a kiosk punch's kiosk and words
  its placement for a kiosk; the policy editor offers *Kiosk* as a way to punch.

## Notes

- A day opened before the migration keeps the rules it was opened with, so a kiosk
  punch on such a day can be refused until the next one.
- A photo policy needs a kiosk with a working camera, and the camera needs HTTPS.
- There is no kiosk mode in the mobile app; a tablet runs the web page.
- Kiosks are not in the Trash Bin: removing one signs its device out for good.
