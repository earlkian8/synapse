# 0092 — Kiosks for people without a phone: a paired device and a PIN of their own

- **Status:** Accepted
- **Date:** 2026-10-15
- **Supersedes:** the kiosk half of [0054 — No kiosks or biometric scanners](./0054-no-kiosks-or-biometric-scanners.md).
  Biometric scanners, the key-authenticated device API and CSV import stay removed.
- **Related:**
  - [0040 — Punch capture: geofences, device ingestion, and records-for-devices](./0040-punch-capture-geofences-device-ingestion-and-records-for-devices.md);
  - [0049 — Assistant prompt-injection defences](./0049-assistant-prompt-injection-defences.md);
  - [0079 — Shape fences and a clock per site](./0079-shape-fences-and-a-clock-per-site.md);
  - [0087 — Uploads are private to their workspace](./0087-uploads-are-private-to-their-workspace.md);
  - [Attendance kiosks module](../modules/attendance-kiosks.md),
    [Attendance](../modules/attendance.md),
    [attendance tables](../database/attendance-tables.md).

## Context

Attendance is captured by a person signed in as themselves, on the web or in the
mobile app, or by HR. That leaves out a large group: warehouse, plant, construction
and retail workers who **have no smartphone**, and often no account. Today their
time is written in by HR from a paper logbook, which is slow, easy to dispute, and
judged by nobody's policy until somebody types it.

ADR 0054 removed the old kiosk with the scanners. It said bringing devices back is
"a decision of its own", and named three objections:

- **Key custody.** The old kiosk was set up with a long-lived device key shown to a
  person, carried in a link and kept in the browser's storage. Anybody holding it
  could post punches for the company.
- **A public page that says whose an employee number is.** The old kiosk named the
  person as soon as their number was typed, to whoever stood at it.
- **Hardware integration.** Scanners needed an unauthenticated API, a second engine
  mode that recorded instead of judging, and a CSV importer.

The third does not apply to a kiosk: it is a browser at a door, and the person
standing at it can be told no. The first two have to be answered.

## Decision

### A kiosk is a paired device, not a key

- **`attendance_kiosks`**: a name, the site it stands at (optional), and the state
  of its pairing. Soft-deleted, so the punches made at a removed kiosk still name it.
- **Pairing takes a one-time code.** HR asks for a code on Attendance → Kiosks. It
  is 8 characters of Crockford's base 32 (no I, L, O or U), **works once, for 15
  minutes**, and is answered as JSON with `Cache-Control: no-store`, never as page
  props or flash data, which the browser keeps in its history. Typing it allows for
  lower case, a dash, and O/0 and I/L/1 mix-ups. `POST /kiosk/pair` is throttled to
  10 a minute per address.
- **The device then holds a secret of its own**: 64 random characters in an
  **encrypted, HttpOnly, SameSite=Strict cookie** (`synapse_kiosk`, five years).
  No script on the page can read it, no other site can post with it, and nobody is
  ever shown it. Only the SHA-256 of the code and of the secret is stored.
- **One device per kiosk.** Pairing again (a replaced tablet) ends the old device's
  secret at once. **Sign out the device** ends it without a replacement; removing the
  kiosk does both.
- **`AuthenticateKiosk`** knows the device by its cookie, binds the kiosk's
  organisation as the tenant, records `last_seen_at` (by query, at most once a
  minute), and marks every answer `no-store`. A device that is no longer a kiosk is
  answered 401 and its cookie is cleared; the page then shows pairing again.
- **A browser somebody is signed in on is not a kiosk.** Its pages carry that
  person's name, permissions and workspaces, and whoever stood at it could walk into
  their account. The page asks them to sign out; the device routes answer 409; and
  pairing is refused until they do.
- **The page keeps itself alive**: a heartbeat every minute reads the kiosk's state.
  That keeps the session and its form token from expiring overnight, sets the
  kiosk's clock to the server's, and notices a device HR has signed out. A post that
  meets an expired form token refreshes it and is sent once more.

### A person is a number and a PIN, named only when both match

- **`employee_kiosk_pins`**: one per employee, a password hash (bcrypt, through
  `Hash`), with its wrong-try count and lock. HR issues PINs on the Kiosks screen,
  one person or up to 100 at once, **all or none in one transaction**, and each is
  shown once, as JSON, to print as slips.
- **The PIN HR issues works once**, to choose one's own (`must_change`). The PIN
  that clocks somebody in is one HR never saw. A chosen PIN is 6 digits; one digit
  over and over, a straight run up or down, or the issued PIN is refused. Issued
  PINs are never weak.
- **The number is the employee number as printed, or its digits.** A keypad has only
  digits, so `42` finds `EMP-00042` (the trailing number, zeros ignored), never
  `EMP-00142`. Hand-typed numbers can share digits, so several holders may match;
  the PIN tells them apart. People who have resigned or been let go, and archived
  records, are nobody here.
- **One answer for every miss.** A wrong PIN, a number nobody holds and somebody who
  has left all get "That number and PIN don't match". A number nobody holds still
  costs a hash, so timing does not tell them apart either.
- **Guessing is slowed twice over.** Five wrong tries in a row lock that PIN for 15
  minutes, even against the right PIN, and the lock is written to the activity log.
  Twenty wrong tries in ten minutes pause the kiosk, so one person cannot walk the
  numbers a PIN at a time. A lock tells the person to wait or ask HR; a fresh PIN
  from HR unlocks it.
- **A match buys a ticket, not a session.** Signing in returns a 48-character ticket
  that lives two minutes in the cache (only its SHA-256 is the key). It is good only
  at the kiosk that issued it, for the PIN as issued then (HR reissuing ends it), and
  is spent by the punch it was for. Walking away drops it. The PIN is sent once and
  never kept on the device.
- **The kiosk shows little.** First name, initials, the day's shift, today's
  punches, and what may be punched next. No photo, department, pay or anybody else.

### A kiosk punch is a person's punch

- **`kiosk` is a capture source again**, between `mobile` and `manual`. Every kiosk
  punch goes through `AttendanceClock::capture()`, held to the day's order and the
  person's policy exactly as a phone's is. `attendance_punches.attendance_kiosk_id`
  records which kiosk.
- **Placed at the kiosk's site.** A kiosk reports no position of its own: it stands
  still. The person is placed at its site and the site is judged against their own
  fence: a kiosk at one of their sites is on site, 0 m. A kiosk at somebody else's
  site is off theirs, by the distance from it to their nearest site. A kiosk with no
  site places nobody, as a phone with no fix doesn't. `flag` and `block` mean what
  they do for a phone. Latitude and longitude stay empty: the site is not a fix.
- **The policy's photo rule reaches kiosks.** `capture.selfie_required` ("A photo
  with every app and kiosk punch") refuses a kiosk punch without the camera's frame.
  A kiosk with a camera always sends one, stored with the punch under the existing
  `attendance/punches` folder and access rule (ADR 0087). The camera is on only while
  somebody is signed in, and a refused punch keeps no photo.
- **The kiosk says before the punch what would refuse it**: a policy that leaves
  kiosks out, or needs a photo the kiosk cannot take.
- **Policies.** One that allowed every way to punch there was (web, mobile and HR
  entry) allows the kiosk too; the migration adds it. One that chose some ways keeps
  its choice. A policy with nothing set allows every way. A day opened before the
  migration keeps the rules it was opened with, so a kiosk punch on such a day can be
  refused until the next one.

### Where it lives

- **Attendance → Kiosks** (`/attendance/kiosks`), a section of Attendance beside the
  time records and the roster, for the new permission **`attendance.kiosks.manage`**
  (HR Manager by default). Its two lists carry their own actions: *Kiosks* (add, pair,
  edit, sign out, remove) and *Who clocks in at a kiosk* (give PINs, give a new PIN,
  withdraw). Somebody who only looks after kiosks lands there from `/attendance`.
  It warns of policies that leave kiosks out.
- **The kiosk page** (`/kiosk`) has no app shell: its own full-screen surface, a
  tablet's whole screen, with its own fixed look whatever the browser's theme.
- **One code path.** `KioskWorkflow` writes and logs every change, for the screen and
  the assistant alike. `KioskPins` holds signing in and choosing a PIN, `KioskTickets`
  the tickets, `KioskPresenter` what the kiosk is told.
- **The assistant** reads kiosks and PIN holders, signs a device out and withdraws a
  PIN, both held for Confirm. It **never** issues a pairing code or a PIN: a secret in
  a chat sits in its history. It says where to get one.
- **Data Export** gains *Kiosks* without the device secret, the codes or the PIN
  hashes. Kiosks are not in the Trash Bin: removing one signs its device out for
  good, and restoring it would only need pairing again.

## Consequences

- **People without a phone are judged by the rules everybody else is**, at the moment
  they punch, instead of being typed in later from paper.
- **No long-lived secret is ever shown to anyone.** The one secret a person sees, the
  pairing code, works once for 15 minutes. A stolen tablet is ended from the screen.
- **A kiosk punch is only as good as the PIN.** A colleague who learns somebody's PIN
  can punch for them, as on any time clock. The photo, the activity log and the
  per-person lock are the checks; faces are evidence, not identification, and are
  never matched.
- **A kiosk needs a browser with a camera for photo policies**, and HTTPS for the
  camera at all. Without one it still clocks in anybody whose policy needs no photo.
- **No mobile-app kiosk mode.** A tablet runs the web page; the app stays one person's.
- **Scanners stay out.** A scanner's punch cannot be told no, and nothing here
  changes ADR 0054's reasons for that.
