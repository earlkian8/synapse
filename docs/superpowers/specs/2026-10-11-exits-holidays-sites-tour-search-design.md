# Exits, holidays, sites, the tour and search — design

Date: 2026-10-11. Builds five lines of `docs/not-yet-built.md`, on the web and in the mobile app.

## 1. Offboarding (ADR 0076, 0077)

**Resignation by the employee.** New permission `offboarding.resign` (Staff, Department Head, HR
Manager). Table `resignation_requests` (hashid): employee, filed by user, proposed last day, reason
(optional), status `pending → accepted | declined | withdrawn`, decided by/at, decision note, the
exit it became. One pending request per person; refused while an exit is in flight or after they
left. Fewer than 30 days' notice (Labor Code Art. 300) is allowed with a visible note. HR accepts
(sets the last working day, optional clearance template → `OffboardingWorkflow::start`, type
resignation, notice date = the day it was filed) or declines with a note. The employee may withdraw
while pending. Notices: HR (`offboarding.manage`) on file/withdraw, the employee on a decision.
`App\Support\Offboarding\ResignationWorkflow` holds every write (screens and API).

**Exit interview.** Table `exit_interviews` (one per exit): status `pending | submitted | waived`,
the questions snapshotted as JSON, answers JSON, sent / reminded / submitted / waived stamps.
Sent automatically when an exit starts (not for terminations; HR can send one by hand). The fixed
questionnaire is `ExitInterviewQuestionnaire` (reason for leaving, seven 1–5 ratings, recommend,
come back, three open questions). Submitting signs off pending clearance items whose label mentions
"exit interview". Daily `offboarding:exit-interview-reminders` reminds once after 3 days. Answers
are read by `offboarding.view` only.

**Documents (dompdf).** `OffboardingDocuments` renders Blade views to PDF: the clearance form
(letterhead, employee details, checklist grouped by department with sign-off lines) any time; the
Certificate of Employment once the exit is completed, numbered `COE-<year>-<id>` and stamped on the
case the first time it is issued. Signatory name/title are new Company Profile fields. HR downloads
both from the case; the employee downloads their COE from My resignation (web) or the app (a
10-minute signed link opened in the browser).

**Screens.** One sidebar entry (view or resign). OffboardingNav: *My resignation* ‖ *Exits* ·
*Resignations (pending count)* · *Exit interviews*. `/offboarding` sends participants to
`/offboarding/me`. Tables everywhere: resignations list with review modal; exit-interview insights
(tiles, reasons table, ratings table, responses table); the case page gets underline tabs
*Clearance · Exit interview* and a *Documents* menu.

## 2. Holidays (ADR 0078)

`holidays.recurrence` (`none | yearly | easter | weekday`) + `rule` JSON (`{offset}` days from
Easter Sunday, or `{month, weekday, week}` with week −1 = last). `is_recurring` stays true for any
repeating rule. `App\Support\MovableDates` (Easter, nth weekday). The wizard, seeder and an
*Add movable holidays* action create Holy Week and National Heroes Day as rules.

**Local holidays.** Pivot `holiday_work_location`. No sites = everywhere; otherwise the holiday
applies to people whose base site (`WorkLocation::primaryFor`) is one of them.
`HolidayCalendar::inRange/datesInRange/on` take an optional site; `HolidayMap` answers many people
across a range (`on($employeeId, $date)`) for the attendance jobs and boards. The holidays list
becomes a table (name, next date, repeats, type, applies at).

## 3. Work locations (ADR 0079)

`fence_type` (`circle | polygon`), `polygon` JSON (3–50 `[lat, lng]`, not self-crossing, within
5 km), centroid and bounding radius derived server-side. `GeofenceCheck`: a polygon's distance is
0 inside, otherwise to its nearest edge, inside when `distance − accuracy ≤ 0`. The form draws a
shape by clicking corners (drag to move, undo, clear).

`timezone` (null = the organisation's). A person is judged on their base site's clock:
`ResolvedShift` and `DayRules` carry the zone, `OrganizationClock` methods take an optional zone,
`AttendanceClock` resolves the person's zone, and records expose `timezone` so the board and the
app show times on the clock the day was judged by. The locations list becomes a table.

## 4. Product tour (ADR 0080)

`ProductTour::VERSION = 2` (adds the Search stop). `users.tour_version`; people who answered v1 get
a *What's new* run of the stops added since. `product_tour_runs` records each run (platform,
version, mode, steps, furthest step, outcome) for a System → *Product tour* funnel table
(`product-tour.view`). The mobile app gets its own tour over the tab bar
(`users.mobile_tour_version`), replayable from Profile.

## 5. Search (ADR 0081)

`AttendanceRecordSource` (`attendance.view`) answers only when the words carry an attendance cue
(a date, a month, a status, "attendance"/"dtr"), parsed by `AttendanceSearchQuery`; it opens the
day's drawer on the board. The app gets `GET /api/search`: the person's own leave, days, events,
awards and holidays, plus its own screens filtered on the phone.

## Mobile

Profile hub: *Resignation* (file, withdraw, follow the exit, answer the exit interview, get the
COE), *Holidays*, *Take the tour*. Home gets a search button.
