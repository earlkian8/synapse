# 0103 — QR stations: a changing code, scanned with one's own phone, only at the site

- **Status:** Accepted
- **Date:** 2026-11-16
- **Related:**
  - [0040 — Punch capture: geofences, device ingestion, and records-for-devices](./0040-punch-capture-geofences-device-ingestion-and-records-for-devices.md);
  - [0079 — Shape fences and a clock per site](./0079-shape-fences-and-a-clock-per-site.md);
  - [0092 — Kiosks for people without a phone](./0092-kiosks-for-people-without-a-phone-a-paired-device-and-a-pin-of-their-own.md);
  - module docs: [QR stations](../modules/attendance-qr-stations.md),
    [Attendance](../modules/attendance.md), [attendance tables](../database/attendance-tables.md).

## Context

The mobile app's clock button trusts the phone. Its position can be faked with a
location-spoofing app; it can be pressed at home; and one person signed in on a
colleague's phone can clock them both in. A policy can ask for a selfie and refuse
punches outside the fence (ADR 0040), but both are evidence after the fact, and the
fence is only as good as the GPS the phone reports.

Companies asked for the familiar answer: a QR code at the entrance that people scan. A
printed or static code doesn't help; it's photographed on the first day and scanned from
anywhere after that. The question is what has to be true of a scan for it to say the
person was at the door, and what the code itself can and can't prove.

## Decision

### A station is a screen at a site, showing a code that changes

- **`attendance_qr_stations`**: a name, the site it stands at (**required**: a scan is
  judged against it), how often its code changes (10, 15, 30 or 60 s; 15 by default),
  **how it is scanned** (`scan_mode`: the app only, or any phone's camera — below),
  whether scans must come **through the screen's own network** (`same_network`), a
  secret (encrypted, never sent anywhere), switched on or off. Soft-deleted.
- **HR opens it** from Attendance → **QR stations** → **Show code**: a full-screen page
  with no app around it, in the kiosk's look, showing the code on a paper plate with a
  bar that runs out with it, the time, three steps, and the last few people clocked in
  (first name and initial, what they punched, when; never refusals).
- **Two permissions.** `attendance.qr.manage` sets stations up and reads every scan;
  `attendance.qr.display` only shows a station's code. A company can sign a wall screen
  in with an account whose role holds the second alone, so the screen can do nothing
  else. That answers the objection ADR 0092 raised against a signed-in browser at a
  door.

### What a code proves: that the screen showed it, just now

`QrCodes` signs `SQ1.<station hashid>.<window>.<signature>`, where the window is the
stretch of `rotate_seconds` it belongs to and the signature is an HMAC with the
station's secret. An app-only station shows it as `synapse://qr/<code>`, so a phone's own
camera opens the app; a station that takes any phone shows `<APP_URL>/q/<code>`, which
any camera opens in the browser and the app's scanner reads just the same.

- **It runs out.** A code is accepted in its own window and the next: at most two
  rotations, 30 s at the default.
- **Only what a screen was given counts.** The screen asks the server for the next
  three minutes of codes every two minutes (`GET /attendance/qr/{station}/code`,
  `no-store`, throttled), and a window is accepted only up to the last one a screen was
  given. With the screen closed, every code stops within three minutes, even one signed
  correctly. The screen holds no secret, so nobody with the page open can make a code;
  what it holds ahead is at most three minutes of codes, each worth something only in
  its own half-minute at the site.
- **Reset codes** replaces the secret: every code shown before stops working at once.
- **The screen never shows a dead code.** If it can't reach the server, the plate says
  *Reconnecting* once the code it holds has run out.

### What the phone proves: that it is there, and whose it is

The scan (`POST /api/attendance/qr/scan`, `QrScans::scan`) carries the code, a fresh
high-accuracy position, the platform's word on whether that position is simulated, and
the app's install id. It is refused (422, with a reason and what to do) unless:

1. the code verifies, as above;
2. the app sent its install id (older apps are told to update);
3. the station is switched on and its site still exists;
4. the position isn't reported as simulated (Android's `mocked`);
5. there is a position, precise to 100 m or better;
6. the position is inside the **station's** site: the fence of ADR 0079, a shape or a
   circle. Being at another of the company's sites doesn't count;
7. with `same_network`, the phone is on the screen's network (below);
8. the phone hasn't clocked in **anybody else** in the last 12 hours (`device_hash`
   on punches, the plain app button's included);
9. the person's policy takes punches from a QR station.

A good scan buys a **ticket**: 48 random characters, two minutes in the cache (only the
SHA-256 is the key), good for that person alone and spent by the punch. The ticket gives
a policy's selfie time to be taken without the code running out mid-flow. The punch
(`POST /api/attendance/qr/punch`) goes through `AttendanceClock::capture()` as source
`qr`, with the scan's position, held to the day's order and the person's policy like any
punch; `attendance_punches` records the station and the hash of the install id.

**Every scan of a real station is written down** (`attendance_qr_scans`), accepted or
refused, with the reason, the distance, the accuracy and whether the position was
reported simulated. A refusal that looks like getting round the station (`invalid`,
`not_showing`, `mocked`, `away`, `shared_device`, `other_network`) also goes to the
activity log. HR reads
them on the QR stations screen, refusals in red. A code that is not a SYNAPSE code, or
another company's, is refused without a row, since there is no station of this company
to write it against.

### Without the app: any phone's camera, the employee number and the kiosk PIN

Not everybody installs the app. A station set to `any` takes a scan from any phone's
camera, which opens `/q/<code>` in the browser, where nobody is signed in:

- **Opening the page claims the code.** Typing a number and PIN takes longer than a
  code lives, so the page load verifies the live code and holds it for **that browser**
  for three minutes (a random claim in the cache). The page holds the claim, never the
  code's means. One claim, one person.
- **Who:** the employee number (its digits are enough) and the **PIN of ADR 0092**, the
  one HR issues for kiosks. An employee number alone is not an identity — it is on
  payslips, IDs and the screen's own list of who just scanned — so it is never enough
  by itself. The PIN keeps the kiosk's rules: a number is named only when number and
  PIN match, five wrong tries lock the PIN for 15 minutes (logged), many wrong tries
  pause the station's browser sign-ins, and HR's PIN must be replaced by one's own
  before the first punch.
- **The browser is "the phone"**: a random id in an encrypted, HttpOnly cookie, hashed
  like the app's install id, so one browser still clocks in one person.
- **Where:** the browser's location, asked for once, judged exactly as the app's.
- Then the same ticket and punch as the app's, as source `qr`; the scan row says
  `browser`.

A browser can't say when its location is faked (desktop browsers let anyone set one),
and a PIN is easier to pass on than a phone. The form says so, and offers the next
check.

### The office network

With `same_network`, a scan (app or browser) counts only when it reaches SYNAPSE through
the same internet connection as the screen: the same public address the screen last
asked from, or the same /64 on IPv6. Somebody at home with a faked location and a code
forwarded in real time is turned away; so is a phone on mobile data, which the refusal
says to switch off. It is only as good as the server's view of client addresses: behind
a proxy, `TrustProxies` must be configured.

### What a screen costs to keep up

A screen asking for each code as it came due was a request every 15 seconds, all day,
for every screen — 240 an hour — and the cost grew with how fast the code changed. A
batch every two minutes is about 30 an hour, whatever the rotation; none while the tab
is hidden. Each code travels as its module grid (a few hundred bytes) and the browser
draws it, so a batch is a few kilobytes rather than a dozen pictures. A browser scan is
one page load and three small posts; the selfie is shrunk on the phone before it is
sent.

### `qr` is a way to punch

- It is a capture source beside `web`, `mobile`, `kiosk` and `manual`, and a located
  one: the scan's position is placed against the person's own fence too, so `flag` and
  `block` mean what they mean for a phone.
- A policy that allowed every way there was allows the QR code too (the migration adds
  it); one that chose some ways keeps its choice. **A QR-only policy** (QR on, mobile and
  web off) is how a company makes everyone clock in at the screen: the app's button then
  reads **Scan to Time In** and opens the scanner.
- The policy's selfie rule reaches QR punches ("A photo with every app, QR and kiosk
  punch").
- A QR punch is **never queued offline**: a code is only good while the screen shows it.

### The app

- **Clock** reads `capture` from `GET /api/attendance/today` (mobile, qr, selfie). QR-only
  turns the buttons into scans; QR beside the button adds **Scan a QR station instead**.
- **The scanner** (`expo-camera`, a new native module) opens full screen, asks for the
  camera, finds a fresh precise fix while it looks, reads only codes that start
  `SQ1.`, then scans, takes a selfie if the policy wants one, and punches. A refusal says
  why and offers **Scan again** (and **Open Settings** when location is off).
- `synapse://qr/<code>` opens the scanner with the code, from the phone's own camera.
- The install id is random, kept in the keychain, and sent with every punch; the server
  keeps only `SHA-256("synapse-device|" + id)`.

### Not in the assistant

Stations are set up and shown on their screen. The system guide tells the assistant
where.

## Consequences

- **A forwarded photo, a screenshot or a video call of the screen gets someone nowhere
  but the site.** The code alone is not enough; the phone must be inside the site within
  the 30 seconds the code lives.
- **A spoofed GPS is caught when the platform says so** (Android reports it; iOS rarely
  can). A spoofer that hides itself still has to place the phone inside the site in the
  same half-minute as a live code, and each refusal is on HR's screen.
- **One phone can't clock in two people** in a day, so the colleague signed in on a
  friend's phone is turned away. What remains is somebody standing at the screen holding
  another person's phone, signed in as them. The selfie, the names on the screen as
  they land, and the scan log are the checks for that.
- **The install id is a deterrent, not an identity.** Reinstalling the app makes a new
  one; the refusal it causes is the point, not proof. A browser's cookie is weaker
  still: a private window makes a new one.
- **A browser scan is the weaker of the two**, said where HR chooses it: no faked-GPS
  flag, and a PIN can be shared. The office-network option closes the remote case; the
  selfie and the screen's list of names are the checks on the rest.
- **A closed screen's codes live up to three minutes**, the price of asking for codes
  in batches. *Reset codes* and *Switch off* still end them at once.
- **People need a kiosk PIN** to scan without the app; HR issues them under Kiosks, and
  the station's form says how many have one.
- **The app needs a new build** for the camera module (`expo-camera`, through EAS). The
  station's screen needs no camera of its own.
- **A paired kiosk does not show a QR code yet.** A station is shown in a signed-in
  browser; giving kiosks a QR mode is a later step.

*Amended 2026-11-16, before release:* the any-phone mode, the office network and the
batched codes were added the day the station was built, at the product owner's
request (an app-or-not option, and a server bill that doesn't grow with the rotation).
