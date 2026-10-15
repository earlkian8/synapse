# Attendance kiosks

A **kiosk** is a shared tablet or computer at a site where people **without a phone**
clock in with their employee number and a PIN. Nobody signs in to SYNAPSE on it. The
*why*, and how it answers ADR 0054's objections to the kiosk that was removed, is
[ADR 0092](../decisions/0092-kiosks-for-people-without-a-phone-a-paired-device-and-a-pin-of-their-own.md);
this is the *how*. Punches land in [Attendance](./attendance.md) like any other.

> Status: **Active** · HR screen: `/attendance/kiosks` (Attendance → **Kiosks**, for
> `attendance.kiosks.manage`) · Device page: `/kiosk` · Device routes: `routes/kiosk.php`

## Surfaces

- **`/attendance/kiosks`** — a section of Attendance (`AttendanceNav`: *Time records ·
  Shift roster · Kiosks*). Somebody holding only `attendance.kiosks.manage` is sent here
  from `/attendance`. A warning names any attendance policy that leaves kiosks out.
  Two lists, each with its own actions in its header:
  - **Kiosks** — name and site; the device (*Chrome on Android*, on line now or last
    seen), *waiting for its code until 9:42 AM*, or a **Pair a device** button; and
    today's punches. Row menu: *Pair a device* / *Pair a new device*, *Edit*, *Sign out
    the device*, *Remove*. The header shows the kiosk page's address, copyable.
  - **Who clocks in at a kiosk** — person, PIN state (*Not chosen yet*, *Their own*,
    *Locked until 9:30*, or *Has left*), last used, issued when and by whom. Row menu:
    *Give a new PIN*, *Withdraw PIN*. **Give PINs** opens a picker filtered to people
    who don't use the app by default (search, department, "add everyone without a
    PIN"). The PINs come back in a dialog shown once, with **Print slips** (one slip
    per person, printed from a window of its own).
- **`/kiosk`** — the device, with no app shell (`app.tsx` gives it no layout). Three
  screens, chosen by the server (`state`):
  - `unpaired` — **Set up this kiosk**: an 8-slot code field and *Pair this device*;
  - `signed_in` — **Sign out to use this browser as a kiosk**;
  - `ready` — the bundy clock (the server's time on the kiosk's site clock) beside the
    card a person works on: *employee number → PIN → (choose your own PIN) → your day
    → stamped*. The day shows the shift, the punches as stamps on a time card, the
    likeliest punch as one big button and anything else the day allows beneath it,
    and the camera's picture when there is one. The stamped confirmation stays six
    seconds. Idle sign-outs: 20 s on the PIN, 60 s choosing one, 30 s on the day.
    A keyboard works too (digits, Backspace, Enter, Escape). Full screen, a screen
    wake lock and a short beep on each punch (two low tones on a refusal).

## Data model

See [attendance tables](../database/attendance-tables.md#attendance_kiosks).

- **`attendance_kiosks`** — `name`, `work_location_id` (nullable), `device_token_hash`
  (SHA-256, unique), `device_label`, `paired_at`, `last_seen_at`, `pairing_code_hash`
  (SHA-256, unique), `pairing_expires_at`, `created_by`; soft-deleted.
  `AttendanceKiosk::status()` is `paired`, `pairing` or `unpaired`; `isOnline()` means
  heard from in the last 3 minutes; `clockZone()` is its site's clock or the company's.
- **`employee_kiosk_pins`** — one per employee: `pin_hash` (bcrypt), `must_change`,
  `failed_attempts`, `locked_until`, `issued_by`, `issued_at`, `changed_at`,
  `last_used_at`. `status()` is `locked`, `new` or `active`.
- **`attendance_punches.attendance_kiosk_id`** — the kiosk a punch was made at (the
  relation includes removed kiosks).

## How it works

### Pairing (`AttendanceKiosk`, `KioskWorkflow::pairingCode()`)

`issuePairingCode()` makes 8 characters of Crockford's base 32 and stores their hash
with a 15-minute expiry. `findByPairingCode()` looks across organisations (the code is
what says which), normalising case, dashes and O/0, I/L/1. `pair()` stores the hash of
64 random characters, the device label (from the user agent:
`KioskPresenter::deviceLabel()`), spends the code and returns the secret, which
`AuthenticateKiosk::cookie()` sets: encrypted, HttpOnly, SameSite=Strict, five years.

### The device (`AuthenticateKiosk`, `KioskDeviceController`)

Every device route but the page and pairing runs behind `AuthenticateKiosk`: 409 when
somebody is signed in, 401 (and the cookie cleared) when the secret matches no kiosk,
else the kiosk's organisation is bound as the tenant and `last_seen_at` refreshed (by
query, at most once a minute). Answers are `no-store`. All are JSON
(`bootstrap/app.php` renders `kiosk/*` exceptions as JSON), in the web group, so posts
carry the form token.

| Route | Does |
| --- | --- |
| `GET /kiosk` | The page; its `state` and the kiosk's header. |
| `POST /kiosk/pair` | Pairs with a code (throttle `kiosk-pairing`: 10/min per address). |
| `GET /kiosk/state` | The heartbeat, every minute: header, server time. |
| `POST /kiosk/sign-in` | Number and PIN → a ticket and the person's day. |
| `POST /kiosk/pin` | Choose one's own PIN, typed twice. |
| `POST /kiosk/punch` | Punch with the ticket, and the camera's photo. |
| `POST /kiosk/done` | Drop a ticket nobody will use. |

The paired routes share the `kiosk` limiter: 120 a minute per device.

### Signing in (`KioskPins`)

`holdersOf()` finds the PINs a typed number could be: an exact employee number (case
ignored), or, for digits, every number whose trailing digits are those digits
(zeros ignored). It leaves out people who have resigned or been let go, and archived
records. `signIn()` checks the PIN against each with `Hash::check`, and:

- a match that is not locked resets the count, records `last_used_at`, and wins;
- otherwise every candidate's count goes up; the fifth in a row locks it for 15
  minutes (logged once: *Kiosk PIN locked after 5 wrong tries at "Front gate"*);
- a locked PIN is refused even when the PIN is right;
- a number nobody holds is hashed anyway, and every miss is the same sentence;
- every miss counts against the kiosk: 20 in 10 minutes pause it.

`issue()` makes a random 6-digit PIN that is never weak and sets `must_change`.
`change()` refuses a PIN that is not 6 digits, is one digit repeated, is a straight
run up or down, or is the issued PIN.

### Tickets (`KioskTickets`)

48 random characters; the cache holds, under their SHA-256 for 120 seconds, the kiosk,
the PIN, the employee and the PIN's `issued_at`. `find()` needs all four to still hold,
so another kiosk, a withdrawn PIN or a reissued one ends it. A recorded punch and
`done` forget it; a refused punch keeps it.

### Punching

`KioskDeviceController::punch()` refuses a PIN still `must_change`, stores the photo
(if sent) under `attendance/punches`, and calls `AttendanceClock::capture()` with
`source = kiosk` and the kiosk. The engine:

- checks `kiosk` is among the policy's allowed sources;
- under `capture.selfie_required`, refuses a kiosk punch without a photo;
- places it with `placeAtKiosk()`: on the kiosk's site at 0 m when that site is one of
  the person's; else off site, at the distance from the kiosk's site to the person's
  nearest; with no site, not shown on site. `block` refuses with a message naming
  both sites;
- writes `attendance_kiosk_id`.

A refused punch writes nothing and the stored photo is deleted. A recorded one is
logged (*Clocked in at the kiosk "Front gate"*) and answered with the new day.
`KioskPresenter::day()` tells the kiosk in advance whether the policy allows kiosks
(`kiosk_allowed`) and needs a photo (`photo_required`).

## Permissions

`attendance.kiosks.manage` — *Set up kiosks & issue the PINs people without a phone
clock in with*. HR Manager has it (new companies through the provisioner, existing
ones through the migration's grant). The device itself needs no user permission: the
kiosk is known by its cookie, and a person by their number and PIN.

## Assistant

`KiosksModule` (`attendance.kiosks.manage`):

- **`find_kiosks`** — kiosks with their site, device, on line or last seen, and
  today's punches.
- **`find_kiosk_pins`** — who holds a PIN, by person or by state (`new`, `active`,
  `locked`). Never the PIN.
- **`sign_out_kiosk`**, **`withdraw_kiosk_pin`** — through `KioskWorkflow`
  (" via assistant"), each **waiting for Confirm**, with a consequence line.

It never issues a pairing code or a PIN, and says where to get one. The topic context
says how many kiosks there are, how each is doing, and how many PINs are new or locked.
