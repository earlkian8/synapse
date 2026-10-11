# Exits, holidays, sites, the tour and search: five gaps closed on the web and in the app

Five lines of `not-yet-built.md` are now built, on the web and in the mobile app.

- Employees file their own resignation and answer an exit interview.
- An exit produces its papers as PDFs.
- Holy Week and other movable holidays work themselves out, and a holiday can be kept
  at some sites only.
- A site's fence can be drawn as a shape, and a site can keep its own clock.
- The tour has versions, records every run and has a version in the app.
- Search finds attendance days, and the app has a search of its own.

See ADRs
[0076](../decisions/0076-employees-resign-and-leavers-answer-an-exit-interview.md),
[0077](../decisions/0077-an-exits-papers-are-generated-as-pdfs.md),
[0078](../decisions/0078-holidays-that-move-and-holidays-kept-at-some-sites.md),
[0079](../decisions/0079-shape-fences-and-a-clock-per-site.md),
[0080](../decisions/0080-tour-versions-per-stop-runs-and-a-tour-in-the-app.md) and
[0081](../decisions/0081-search-finds-attendance-days-and-the-app-searches-ones-own.md).

## Highlights

- **Resigning.**
  - *My resignation* (web) and *Resignation* (app) file a resignation. The notice is
    counted as the last day is picked, with a note under the Labor Code's 30 days.
  - A waiting resignation can be withdrawn. A declined one shows HR's note.
  - Once the exit starts, the leaver follows their clearance item by item, including
    why an item is flagged.
  - HR reviews resignations on **Offboarding → Resignations**. Accepting one sets the
    agreed last day (and a clearance template) and starts the exit. Declining needs a
    note. Both sides are told.
- **Exit interviews.**
  - Every exit but a termination sends the leaver a short survey: the main reason,
    seven 1–5 ratings, whether they would recommend us or come back, and three open
    questions. Answering it signs off the checklist's exit-interview item.
  - The survey is reminded once after 3 days. HR can send one by hand or waive it.
  - The exit page gains *Clearance · Exit interview* tabs.
  - **Exit interviews** adds the answers up: why people leave, how they rate their time
    here, and the response rate, over 3, 6 or 12 months or all time.
- **Exit papers.**
  - The exit page's **Documents** menu downloads the clearance form, any time.
  - It also downloads the **Certificate of Employment** once the exit is completed,
    numbered once (`COE-<year>-<id>`).
  - Leavers download their certificate on the web, or from the app through a 10-minute
    link.
  - The Company Profile names who signs these papers.
- **Holidays.**
  - A holiday repeats yearly, a number of days from Easter, or on the n-th weekday of a
    month. **Add Holy Week** puts Maundy Thursday, Good Friday, Black Saturday and
    National Heroes Day on as rules.
  - A holiday can be kept at chosen sites only. Leave, attendance and the roster then
    treat it as a holiday only for the people based there.
  - The holidays list is a table.
  - The app gains **Holidays**: the year ahead as the person keeps it.
- **Sites.**
  - A fence is a circle or a shape drawn corner by corner.
  - A site can keep its own time zone. Its people's days are judged on that clock, and
    every screen shows their times on it.
  - The locations list is a table under the map.
- **The tour.**
  - The tour gains a **Search** stop. People who already took it are offered a short
    *What's new* with just that stop.
  - Every run is recorded. **System → Product Tour** shows how many people finish,
    skip or leave the tour, and the stop where they leave.
  - The app has its own tour of its tabs, replayed from Profile.
- **Search.**
  - ⌘K finds attendance days: "maria late", "absent today", "santos oct 3". A result
    opens the day on the board.
  - The app's search, from Home and Profile, finds the person's own leave, days,
    invitations, awards and holidays, and its screens.

## Server

- New permissions:
  - `offboarding.resign`, for Staff, Department Head and HR Manager (back-filled, and
    in the blueprints);
  - `product-tour.view`, for HR Manager.
- Migrations (`2026_10_11_010000` … `040000`):
  - `holidays.recurrence` and `holidays.rule`; the `holiday_work_location` pivot;
  - `work_locations.fence_type`, `polygon` and `timezone`;
  - `resignation_requests` and `exit_interviews`; `offboarding_cases.certificate_no`
    and `certificate_issued_at`; `organizations.document_signatory_name` and `_title`;
  - `users.tour_version`, `mobile_tour_version` and `mobile_tour_finished_at`;
    `product_tour_runs`.
- Offboarding:
  - `ResignationWorkflow`, `ExitInterviews`, `ExitInterviewQuestionnaire`,
    `OffboardingDocuments` (Dompdf, remote fetching off) and `MyExit`;
  - new command `offboarding:exit-interview-reminders`, daily at 09:00;
  - a public, signed, throttled certificate route for the app.
  - `dompdf/dompdf` ^3.1 is added.
- Holidays:
  - `MovableDates` (Easter, n-th weekday), `Holiday::occurrenceIn()` and
    `fallsOn()`;
  - `HolidayCalendar` takes a site, and `HolidayMap` answers many people across a
    range;
  - every caller passes the person's site: leave (web, API, assistant), the attendance
    engine, the boards, the roster and the reminders;
  - a holiday change queues a recompute of every date it fell on, in every year.
- Sites:
  - `FenceGeometry` and `GeofenceCheck::measure()`;
  - `OrganizationClock` methods take a zone, and `ResolvedShift` carries it;
  - `DayRules` version 4 snapshots the zone, `AttendanceRecordResource` exposes it, and
    the mobile session gives `employee.timezone`.
- Tour: `ProductTour` versions and runs, `ProductTourFunnel`, and run endpoints for the
  web and the app.
- Search: `AttendanceSearchQuery`, `AttendanceRecordSource`, `MySearch` and
  `GET /api/search`.
- App endpoints: `/api/holidays`, `/api/search`, `/api/offboarding/…` and
  `/api/tour/…`.
- The assistant gains 9 tools: `find_resignations`, `decide_resignation`†,
  `get_exit_interview`, `exit_interview_summary`, `send_exit_interview`,
  `waive_exit_interview`†, `get_my_exit`, `file_my_resignation`† and
  `withdraw_my_resignation`†. († waits for Confirm.)
  - `add_holiday` and `update_holiday` take the rule and the sites.
  - `update_location` sets a site's clock.
  - `update_company_profile` sets the document signatory.
- `SystemGuide` gains Resignations, Exit interviews, My resignation and Product Tour.
  The router knows exit interviews.
- Data Export covers `resignation_requests`, `exit_interviews` and
  `holiday_work_location`. `product_tour_runs` is excluded as telemetry.
- The seeder adds Holy Week as rules, exit interviews on past exits and two waiting
  resignations.
- Fixed while testing:
  - a resignation's notice is counted on the organisation's calendar. Just after
    midnight in Manila it was counted from the previous day in UTC;
  - accepting a resignation without a note left the note meant for exits HR starts by
    hand ("Accepted when HR started the exit");
  - the survey's errors read "The this question field is required"; they now say what
    to pick;
  - "leave", "holiday", "present" or "rest" alone in search means the screen, and finds
    days only beside a person or a date.

## Frontend

- New pages:
  - `offboarding/me`, `exit-interview`, `resignations` and `interviews`;
  - `system/product-tour`.
- `OffboardingNav` gives the header sections *My resignation ‖ Exits · Resignations ·
  Exit interviews*.
- The exit page gains tabs, the Documents menu and the interview panel.
- Holidays: `HolidayTable`, and a rewritten holiday form (repeats, Easter presets,
  weekday rules, a preview of the next dates, who keeps it).
- Locations: a table under the map, and a shape drawer with draggable corners and a
  clock switch in the form.
- Attendance: the board, drawer, weekly grid, exceptions, manual entry, My attendance
  and the clock card show times on each day's own clock. The roster names local
  holidays.
- Search: attendance results, which open the day's drawer.
- Tour: the Search stop, *What's new* runs and run recording.
- The company profile gains a Documents section.

## Mobile

- New screens: Resignation (follow the exit, withdraw, certificate), File a
  resignation, Exit interview, Holidays and Search.
- The tab tour.
- Profile gains Holidays, Resignation and a Help section.
- Home gains a search button.
- Times show on the person's site clock.

## Docs

- ADRs 0076–0081.
- Module docs:
  - offboarding, work-schedule-holidays and work-locations;
  - product-tour, global-search and mobile-app;
  - attendance, assistant, data-export, company-profile and help-center.
- Database docs: offboarding-tables, work-schedule-holidays-tables, attendance-tables,
  users-table, organizations-table, and ERD §1, §2a and §5.
- Help: new *Resignations, exit interviews and exit papers*, *Resigning*, *Search,
  holidays, resigning and the tour in the app* and *How people take the tour*. Updated:
  *Searching SYNAPSE*, *Schedules and holidays* and *Work locations*.
- `not-yet-built.md`:
  - removed: the Offboarding, Holidays, Work Locations, Product Tour and Global search
    lines these close;
  - added: exit-interview questions of the company's own, a certificate for people
    still employed, document templates, lunar holidays, seeded local holidays, several
    shapes per site, a clock that follows the punch, tours per role, and recent
    searches.

## Notes

- **Verified:** Pest (count in the commit message), Pint, tsc, ESLint, Prettier and the
  build; the mobile app's tsc. The migrations roll back and re-apply on a scratch
  database, and their columns match the table docs. HR and Staff were checked in
  headless Chromium at 1440 and 390 px. The whole flow ran in the browser: Staff
  resigns, HR accepts, Staff answers the survey, and both PDFs were rendered and read.
  That pass also fixed the clearance form's page breaks and signature lines, and made
  the survey's ratings and the leaver's clearance fit a phone. The app's new screens
  were checked on its web build. *File a resignation* cannot be shown there, because
  react-native-web lacks an API the keyboard handling uses (ADR 0065); it needs a
  phone or the emulator.
- Docker was not built or run. Gemini was never called: the key stays empty and the
  tests use the stub models.
