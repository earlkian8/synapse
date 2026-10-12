# 0081 — Search finds attendance days, and the app searches one's own records

- **Status:** Accepted
- **Date:** 2026-10-11
- **Related:**
  - [0069 — Global search: a command palette read for the searcher](./0069-global-search-a-command-palette-read-for-the-searcher.md)
    (the palette and its sources);
  - [0079 — A clock per site](./0079-shape-fences-and-a-clock-per-site.md);
  - module docs: [Global search](../modules/global-search.md),
    [Mobile app](../modules/mobile-app.md).

## Context

The ⌘K palette found people, records, screens and help. It did not find attendance:
"maria late", "absent today" and "santos oct 3" found Maria, and HR then opened the
board, picked the day and searched again. The mobile app had no search at all.

## Decision

### Attendance answers only when the words say attendance

`AttendanceSearchQuery` reads the words for:

- **a day**: today, yesterday, `2026-10-03`, `10/3`, `oct 3`, `3 october 2026`;
- **a month**: `october`, `oct 2026`;
- **the kind of day**: late, absent, undertime, overtime, on leave, holiday,
  incomplete, present, half-day, rest day, and their synonyms;
- the words *attendance*, *DTR*, *timesheet* and *punches*.

The rest is the person, by name or number.

`AttendanceRecordSource` (`attendance.view`) answers **only when one of those cues is
there**. A search for a name alone means the person and is not buried under a month
of their days. "Leave", "holiday", "present" and "rest" also name screens and leave
types, so they cue attendance only beside a person or a day: "leave" finds the Leave
screen, and "maria on leave" finds her days on leave.

Results come newest day first. A result opens the attendance board on that date with
the day's drawer open (`?date=…&search=<employee no>&open=<record>`).

It sits after leave in the palette's order, and is subject to the palette's
throttle and per-source limit.

### The app searches the person's own records

`GET /api/search` (`throttle:search`) runs `MySearch`. It only ever reads **the
signed-in person's own** records:

- leave requests, by type, status or date;
- attendance days, by date or kind ("late in october");
- invitations, by title or place (with `events.respond`);
- awards, by name;
- the holidays they keep.

Each result names the **app screen** that opens it, rather than a web address. The
app's own screens are found on the phone without asking the server.

The app opens search from a button on Home and from **Profile → Search**.

## Consequences

- HR goes from a question about a day to that day in one step.
- Nobody can search anybody else's records from the app. A manager's search of their
  team is web-only, through the palette and its permissions.
- Not built: searching punches by place or device, and attendance in the app's
  search for anyone but oneself.
