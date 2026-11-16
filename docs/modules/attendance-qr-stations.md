# QR stations

A **QR station** is a screen at a site showing a code that changes every few seconds.
People scan it to clock in: with the SYNAPSE app, or — at a station set to take any
phone — with the phone's own camera, signing in in the browser with their employee
number and kiosk PIN. A scan counts only from a phone inside the station's site, with a
precise location (that the app hasn't seen faked), that hasn't clocked in anybody else
that day, and — when the station asks — on the screen's own network. The *why* is
[ADR 0103](../decisions/0103-qr-stations-a-changing-code-scanned-at-the-site.md); this is
the *how*. Punches land in [Attendance](./attendance.md) like any other, as source `qr`.

> Status: **Active** · HR screen: `/attendance/qr` (Attendance → **QR stations**) ·
> Station screen: `/attendance/qr/{station}/display` · App: Clock → **Scan** ·
> Browser scan: `/q/{code}`

## Surfaces

- **`/attendance/qr`**, a section of Attendance (`AttendanceNav`). For
  `attendance.qr.manage`:
  - a strip of the four checks every scan passes;
  - a warning naming any attendance policy that leaves QR punches out;
  - **QR stations**: name, site and rotation; how it is scanned (*SYNAPSE app only* or
    *Any phone's camera, with number and PIN*) and *office Wi-Fi only*; whether a screen is showing it now and on
    whose; today's accepted and refused scans; **Show code** (opens the station screen in
    a new tab); row menu *Edit*, *Switch off / on*, *Reset codes*, *Remove*;
  - **Latest scans** (60): who (*Not identified* for a browser scan refused before a PIN
    matched), *in a browser* for browser scans, the result (*Clocked in*, or the
    refusal's reason, red when suspicious), where the phone was, when. *All / Refused*
    filter.
  - The add/edit dialog: name, site, rotation, **How people scan it** (two cards), and
    **Only on the office Wi-Fi**. Choosing *any phone* shows how many people hold a kiosk
    PIN and links to Kiosks for whoever may issue them.

  Somebody holding only `attendance.qr.display` sees the stations with **Show code** and
  nothing else, and lands here from `/attendance`.
- **The station screen**, with no app shell (`app.tsx`): the code on a paper plate with a
  draining bar, the site's clock, three steps (the app's, or the camera's at an `any`
  station), and the last six people clocked in in the past ten minutes (first name and
  initial), refreshed with each batch of codes. Full screen and a screen wake lock; the X
  goes back to the list. States: *Getting the code*, live, *Reconnecting*, *switched
  off*, *no site*, *Signed out*.
- **The app.** Clock reads `capture` from `/api/attendance/today`. A QR-only policy turns
  the punch buttons into **Scan to …**; QR allowed beside the button adds **Scan a QR
  station instead**. `app/attendance/scan.tsx` is the scanner (it reads both the
  `synapse://qr/…` link and an `any` station's web address); `app/qr/[code].tsx` takes
  the `synapse://qr/…` link from the phone's own camera.
- **The browser scan**, `/q/{code}` (`pages/qr-scan.tsx`, no app shell, nobody signed
  in), in the station screen's look: *Clock in at …* (employee number, remembered on the
  phone; PIN; the location asked for on **Continue**), *choose your own PIN* the first
  time, the punch buttons the day allows (a photo first when the policy wants one, shrunk
  to 960 px before it is sent), and a stamped confirmation. An app-only station's code
  opens a page that sends the person to the app.

## Data model

- `attendance_qr_stations` — name, `work_location_id`, `rotate_seconds` (10/15/30/60),
  `scan_mode` (`app`/`any`), `same_network`, `secret` (encrypted, hidden), `is_active`,
  `last_shown_at` / `last_shown_by` / `last_shown_ip` (hidden), creator, soft deletes.
- `attendance_qr_scans` — every scan of a real station: employee, `client`
  (`app`/`browser`), `outcome`
  (accepted/refused), `reason`, punch type and punch once made, position, accuracy,
  distance, `mocked`, `device_hash`, `ip` (the last two hidden).
- `attendance_punches.attendance_qr_station_id` and `.device_hash`.

See [attendance tables](../database/attendance-tables.md).

## How it works

### Codes (`QrCodes`)

`SQ1.<hashid>.<window>.<signature>`, window = `floor(unix time ÷ rotate_seconds)`,
signature = the first 22 characters of base64url HMAC-SHA256 over
`hashid.window.rotate` with the station's secret. Shown as `synapse://qr/<code>` at an
`app` station and `<APP_URL>/q/<code>` at an `any` one; `parse()` reads the bare code,
either link, or nothing.

`issue()` hands the screen every window from now to `now + BATCH_SECONDS` (180 s): 13
codes at 15 s, 4 at 60 s. It keeps the **last window given** in the cache
(`attendance-qr:shown-through:{station}:{rotate}`, the larger of two screens'), marks the
station as showing by whom and from which address. `verify()` accepts the current window
or the one before, signed right, and **no later than the last window given**. A closed
screen's codes therefore stop within three minutes; *Reset codes* (a new secret) and
*Switch off* stop them at once.

### The screen (`QrStationController::code`, `use-qr-code.ts`, `qr-matrix.tsx`)

**Cost.** The screen asks once every `REFRESH_SECONDS` (120 s), whatever the rotation:
about 30 requests an hour per screen (it used to be one per code, 240 an hour at 15 s).
Each code arrives as its module grid (`QrImage::matrix()`: the size and the modules
packed eight to a byte, base64, about 300 bytes) and is drawn in the browser as one SVG
path, so a batch of 13 is a few kilobytes. While the tab is hidden it asks nothing; it
asks at once when it's visible again. Failures retry after 3, 6, 15, then 30 s. A
switched-off or site-less station is asked about once a minute. Answers are `no-store`.
Throttle `attendance-qr-code`: 10 a minute per user and station.

The screen shows the code whose window covers the server's time (its clock is kept on
the server's), wakes at each change, merges each new batch over what is still to come,
and shows *Reconnecting* when it has no code for now.

### The scan with the app (`QrScans::scan`)

Checks, in order: the code (`invalid`, `expired`, `not_showing`), the install id
(`no_device`), the station (`inactive`, `no_site`), simulated location (`mocked`), a
position (`no_location`), accuracy ≤ 100 m (`imprecise`), inside the station's site
(`away`, with the distance), the screen's network when `same_network` (`other_network`),
no other employee's punch from the device in 12 h (`shared_device`), and the person's
policy taking `qr` (`punch_refused`). Each refusal is a scan row; the suspicious six
(`invalid`, `not_showing`, `mocked`, `away`, `shared_device`, `other_network`) are in the
activity log too. A good scan writes an accepted row and returns a two-minute ticket and
the day (`next_expected`, `allowed`, `selfie_required`).

### The scan in a browser (`QrWebScanController`, `QrScans::claim` / `identify`)

Nobody signs in, so nothing here uses the session; the station's company is bound as the
tenant from the station, the claim or the ticket before anything else is read.

1. **`GET /q/{code}`** finds the station across companies by its hashid, verifies the
   code now, and — at an `any` station that is usable — **claims** it: 48 random
   characters in the cache for `CLAIM_SECONDS` (180), bound to this browser. The browser
   is known by `synapse_qr_device`, a random 40-character id in an encrypted, HttpOnly,
   SameSite=Lax cookie set on first visit (two years); its SHA-256 plays the install
   id's part. A forged or unshown code is written down without a name (suspicious); an
   old one is just refused. An `app` station's page sends the person to the app.
2. **`POST /qr-scan/identify`** (claim, number, PIN, position): the claim must be live
   and this browser's; the number and PIN go through `KioskPins::check()` (limiter
   `qr-sign-in:{station}`: 20 wrong tries in 10 minutes pause the station's browser
   sign-ins; five on one PIN lock it for 15 minutes, logged) — a mismatch is a
   `wrong_pin` row without a name. Then the same checks as the app's from the station on
   (a browser never reports `mocked`). Success spends the claim (one page, one person)
   and returns the ticket, the day, the first name and `must_change_pin`.
3. **`POST /qr-scan/pin`** chooses one's own PIN when HR's is still the one held.
4. **`POST /qr-scan/punch`** spends the ticket (only in the browser that bought it; not
   until the PIN is one's own) through the same `QrScans::punch()`.

`qr-scan/*` answers JSON (bootstrap/app.php). Throttles: `qr-scan-page` 120 a minute and
`qr-scan` 60 a minute, per address (an office shares one).

### The office network (`QrScans::sameNetwork`)

With `same_network`, a scan's address must equal the address the screen last asked from,
or share its /64 on IPv6. It answers a faked location from somewhere else, which a
browser can't detect; it turns away phones on mobile data. It depends on the server
seeing real client addresses: behind a proxy or load balancer, `TrustProxies` must trust
it.

### The punch (`QrScans::punch`)

Spends the ticket through `AttendanceClock::capture()` as `qr`, with the scan's position,
the station and the device hash; links the punch to the scan; logs *QR punch* on the day.
A refusal by the engine (the day's order, a missing selfie) keeps the ticket for a retry
and keeps no photo.

## Permissions

- `attendance.qr.manage` — set stations up, read every scan.
- `attendance.qr.display` — show a station's code.
- `attendance.clock` — scan with the app (the API's scan and punch).
- None for a browser scan: the employee number and kiosk PIN are the credential.

HR Manager holds all; the migration grants the first two.

## Assistant

None. The system guide points to the screen.

## Tests

`tests/Feature/Attendance/QrStationsTest.php`: codes (windows, never shown, forged, reset,
another company's, foreign text, a batch's last code and the one past it, both links),
the phone (away, another site, simulated, no or rough location, no install id, shared
device, a plain app punch's device), the station and the policy (off, archived site,
policy without QR, QR-only, selfie), HR's screen (CRUD and logging, validation, the code
endpoint's batch of grids, display-only access, scans, who may open what).

`tests/Feature/Attendance/QrBrowserScansTest.php`: the browser scan end to end, the
device cookie, app-only stations, old/forged/foreign links, the claim (this browser's,
three minutes, one person), wrong PINs and the lock, location checks, one browser one
person, HR's PIN changed before the first punch, a ticket in another browser, the
selfie, the office network (browser and app, IPv6 /64), and HR setting the mode.
