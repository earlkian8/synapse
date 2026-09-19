# Punches that can be trusted, and days that close themselves

Until now attendance took every punch on faith, and some could not arrive at all. Phones
sent their position and nothing looked at it. The policy editor offered on-site checks,
allowed sources, selfies and an IP allowlist, all marked *pending*. Kiosks and
fingerprint scanners were named in the data but had no way in. A phone with no signal
could not punch. And nothing ran on a schedule. A day nobody punched was never written.
A forgotten clock-out stayed open forever. Approving leave did not change a day already
marked absent. Nobody was told about any of it.

This is Phase 4 of making attendance fit any company. It has two decisions:

- **Punch capture** (ADR 0040): sites drawn on a map, the capture rules enforced, kiosks
  and scanners with their own keys, and phones that punch offline. The engine enforces
  its rules for people and records what devices report.
- **Days that close themselves** (ADR 0041): an hourly job records every working day,
  handles forgotten clock-outs as the policy says, and sends one digest per manager. A
  change of leave, holiday or roster reaches days already written.

## Highlights

- **Locations.** Company Setup → Locations shows the company's sites on a map. Each site
  is a circle, its fence.
  - Draw a site by searching an address, using your own position, or clicking the map.
    Drag the pin and slide the radius (25 m to 5 km).
  - Choose who is based there, starring one site as each person's primary.
  - A site can give its people a default schedule and attendance policy.
- **The attendance policy decides what a punch away from the site means.**
  - **Off** records where the punch was.
  - **Flag** accepts it and puts the day up for sign-off.
  - **Block** refuses it: "You are 1.2 km from Main Office."

  A GPS reading whose accuracy circle reaches the fence counts as on site. Approved
  remote work or official business excuses the day.
- **The other capture rules now work.** A source the policy does not allow is refused. A
  mobile punch without a required selfie is refused. A web punch from outside the
  allowed networks is refused.
- **Devices.** Company Setup → Devices registers kiosks and biometric scanners. Each
  gets a key, shown once, and the page shows when each device was last seen.
  - **Scanners** push punches over the device API, or HR imports the scanner's CSV
    export. The columns are mapped once and remembered.
  - **A scanner's punch is never refused.** It is recorded as the scanner reported it,
    even out of order or twice. The day is flagged for sign-off instead.
  - **Resending is safe.** A punch the device or the file already sent is recognised.
- **A web kiosk.** Open the link from the Devices page on a shared tablet. An employee
  types their number, sees their name and the punches open to them, and taps one. A
  camera snapshot goes with the punch. The key in the link is saved in the tablet's
  browser and removed from the address bar.
- **Offline punches on the phone.** With no signal, a punch is queued on the phone at the
  time it was made.
  - A banner says how many punches are waiting, with **Send now**, and the Clock tab
    shows a badge.
  - Queued punches are sent every 30 seconds and whenever the app is opened.
  - A punch older than the policy's window (default 72 hours) is refused, and the
    employee is told to ask for a correction.
  - A phone clock that is more than 10 minutes off is flagged.
- **Where each punch came from.** In the day modal, each punch shows whether it was on
  site, the distance to the nearest site, which device sent it, whether it was sent
  offline, and whether the clock was off. The mobile day screen shows the same.
- **Days close themselves.** Every hour, each company's past dates are closed on its own
  clock.
  - Everybody who was due at work and has no record gets one: absent, on leave, a
    holiday, or on official business.
  - A forgotten clock-out is flagged for HR or closed automatically at the shift's end,
    as the policy says. An automatically closed day needs sign-off.
  - Once a date is complete, each manager gets **one** digest of its exceptions, not
    one notification per problem.
- **Changes reach the record.** Approving leave for a day already marked absent turns it
  into leave. A new holiday, a moved one, or a changed assignment or roster entry
  updates the affected days. Nothing inside a locked period moves.
- **Clock-in reminders**, if the policy asks for them: "You haven't clocked in. Your
  shift started at 8:00 AM." Nobody is reminded on leave, on a holiday, on a rest day, or on approved official
  business or remote work.
- **"Not clocked in yet"** filters today's board to people whose shift has started and
  who have not clocked in. The exceptions panel gains groups for punched away from the
  site, closed automatically, device punches out of order, and clock was off.
- **The assistant** answers "who hasn't clocked in?", "who is missing a clock-out?" and
  "who punched outside the office this week?".

## Backend

- **Migration** (`2026_09_20_000000_create_work_locations_and_attendance_devices`):
  - New tables: `work_locations`, `employee_work_locations` and `attendance_devices`.
  - `attendance_punches` gains:
    - where it was: `work_location_id`, `distance_meters`, `within_geofence`;
    - what sent it: `attendance_device_id` and `external_id`, unique together;
    - offline timing: `device_punched_at`, `received_at` and `clock_skew_seconds`.
  - `attendance_records.closed_at`.
  - `employees.device_enrollment_id`, unique per organisation.
  - `organizations.attendance_closed_from` and `attendance_closed_through`.

  It syncs the new permissions. Rolled back and re-run cleanly.
- **`AttendanceClock::capture()`** is the one entry point. `punch()` wraps it. Its order:
  1. idempotency on the sender's id;
  2. type inference;
  3. the work date and the period lock;
  4. for people only: the source is allowed, an offline punch is in its window, the
     transition is valid *at the given instant*, and the capture rules hold;
  5. the geofence;
  6. write, then recompute.

  A device's punch is `record_only`. Also new: `materialise()`, `closeForgottenDay()`,
  `syncHoliday()` and `policyOf()`. `applyManualPunches()` now checks that the policy
  allows the `manual` source.
- **The evaluator derives five new review flags** from the punches: `outside_geofence`,
  `source_not_allowed`, `device_sequence_anomaly`, `clock_skew` and `auto_closed`. It
  also raises `missing_clock_out` on a day the job closed while still open.
  `DayContext` gains `closed`.
- **`GeofenceCheck` / `GeofenceVerdict`**: haversine distance to the nearest site, with
  accuracy allowed. `WorkLocation::fenceFor()` returns an employee's own sites, or every
  site when they have none. `WorkLocation::primaryFor()` answers for a whole roster in
  one query.
- **The location joins both precedence chains**, between the department and the
  organisation. `ShiftResolver` and `PolicyResolver` each take six queries whatever the
  range, and both record `location` as a source.
- **The device API** (`/api/devices`, key-authenticated by `AuthenticateDevice`,
  throttled at 120 requests a minute per key):
  - `GET me`;
  - `POST punches`: batches of up to 500, with an outcome per row;
  - `POST kiosk/lookup` and `POST kiosk/punch`, which accept a kiosk's key only.

  `DevicePunchIngestor` writes one log entry per batch. `DeviceCsvImport` detects the
  delimiter, strips a BOM, reads one time column or a date and a time column, maps type
  words and state codes, and derives an id when the file has none. `AttendanceDevice`
  issues `sdk_` keys and keeps only their SHA-256.
- **The mobile punch** takes `client_id`, `punched_at` (which marks it offline) and
  `sent_at`, and returns `duplicate` for a resend.
- **Policy settings:**
  - `reminders.clock_in_after_minutes`: off by default, 5–240.
  - `capture.offline_window_hours`: default 72.
  - `capture.max_clock_skew_minutes`: default 10.
- **`attendance:close-day`** runs hourly (`withoutOverlapping`) through `DayCloser`,
  which:
  - materialises records once each person's shift has ended;
  - handles forgotten clock-outs once the maximum shift span has passed;
  - advances `attendance_closed_through` over contiguous dates, looking at most 7 days
    back;
  - sends the digest through `Notifier::toPermission('attendance.view')`, plus each
    manager who lacks that permission (`Notifier::holdersOf()` was extracted for this).
- **`AttendanceInputs::watch()`** (registered in `AppServiceProvider`) watches leave,
  holidays, schedule assignments and roster entries. On a change it runs
  **`RecomputeAttendanceRange`** after the response (`dispatchAfterResponse`, so no queue
  worker is needed). The job:
  - re-evaluates each recorded day and re-reads its holiday;
  - re-judges placeholder days by the current plan;
  - writes missing closed working days, never before `attendance_closed_from`;
  - skips locked periods.
- **`attendance:remind`** runs every 15 minutes and reminds each person once per shift
  (`Cache::add`).
- **Setup:**
  - `WorkLocationController`: list, create, edit, people, archive, restore, and delete,
    which is refused while any punch names the site.
  - `AttendanceDeviceController`: list, create, edit, replace key, remove, and CSV
    import. The key and the import result are flashed.
- **Board and assistant:** `AttendanceRecordsIndexQuery` gains the `not_clocked_in`
  status. The assistant gains `find_attendance_exceptions`, and its 30-day brief counts
  the new flags. `AttendanceRecordResource` returns each punch's location, distance,
  verdict, device, offline marker, arrival time and skew.
- **Permissions:** `setup.locations.view` and `setup.locations.manage`, and
  `setup.devices.manage`, all held by HR Manager. Every write is logged.
- **Infrastructure:**
  - The CSP allows `https://tile.openstreetmap.org` (images) and
    `https://nominatim.openstreetmap.org` (connections).
  - `config/trustedproxy.php` reads `TRUSTED_PROXIES`, so the IP allowlist sees the real
    client behind a proxy.

## Frontend

- **Company Setup → Locations** (`pages/setup/locations.tsx`, `features/locations`):
  - the sites map (Leaflet on OpenStreetMap), with labelled fences;
  - the location form, with address search, "Use my location", a draggable pin and a
    radius slider;
  - the **People based here** dialog, with primary stars;
  - a warning when a policy checks locations but there is no site.
- **Company Setup → Devices** (`pages/setup/devices.tsx`, `features/devices`):
  - the device list, with last seen, punch count and key hint;
  - the form;
  - the **one-time key** dialog, with the kiosk link or the push endpoint;
  - the **import** dialog, which reads the CSV header for mapping and lists unrecorded
    rows by line.
- **`/kiosk`** (`pages/kiosk.tsx`, `features/kiosk/api.ts`): a full-screen navy page with
  no app shell. It has a large clock, a keypad, a camera preview, a stamped confirmation
  and an idle reset.
- **Attendance board:**
  - The punch timeline gains a capture line.
  - The exceptions panel gains four groups.
  - The toolbar offers **Not clocked in yet** on today's date.
  - Flag labels cover the new flags, and `system` is a source.
- **Policy editor:** the *Pending* notes are gone. It gains a **Reminders** section and
  fields for the offline window and clock skew, and the geofence options have hints.
- **Also:** the employee form gains the **device enrolment id**, the sidebar gains
  Locations and Devices, and the attendance-policy precedence text names the work
  location.
- **Mobile:**
  - `features/attendance/punch-queue.ts` (AsyncStorage) and `punch-queue-runner.tsx`.
  - The Clock screen queues punches and shows a banner with **Send now**; the Clock tab
    shows a badge.
  - The day screen shows each punch's site, device and offline marker.

## Notes

- **Deploying:**
  - Make sure `schedule:run` is in cron, then run `php artisan attendance:close-day` once.
    It closes yesterday and sets where recording starts.
  - Behind a load balancer or proxy, set `TRUSTED_PROXIES` for the IP allowlist.
- **Changed from the plan:**
  - **A location's own timezone is deferred.** Every day is still judged on the
    organisation's clock (ADR 0036). A company whose sites span zones is not served yet.
  - **Official business also excuses the geofence**, not only remote work. It is a day
    away from the site by definition.
  - **The kiosk is `/kiosk`, identified by its key, and takes a number or an enrolment
    id.** There is no PIN or QR scan yet.
- **Reminders reach a phone only through email and web push.** The mobile app has no push
  channel of its own. Reminders are off until a policy sets the minutes.
- **The shift resolver's query budget is now six** (was five), because of the location
  link. The test's budget moved with it.
- **The close-day job catches up at most seven days.** Older gaps still synthesise at read
  time.
- **Found, not caused, here:** the mobile app logs *"Unexpected text node: . A text node
  cannot be a child of a \<View\>"* on every punch. It reproduces on the committed
  files without this change.
- **Verification:**
  - Pest: **1049 passed, 7310 assertions**, serial on Postgres (was 975 + 1 todo). The
    todo left for Phase 4's remote-work exemption is now a real test.
  - Pint, tsc, ESLint, Prettier and `npm run build` are green.
  - Mobile tsc and ESLint report only problems that already existed.
  - `attendance:recompute --dry-run` on the dev database moved none of 676 days.
