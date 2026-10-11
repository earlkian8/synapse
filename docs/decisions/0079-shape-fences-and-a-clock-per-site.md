# 0079 — Fences drawn as shapes, and a site that keeps its own clock

- **Status:** Accepted
- **Date:** 2026-10-11
- **Related:**
  - [0036 — Attendance judged in local time on shift-anchored dates](./0036-attendance-judged-in-local-time-on-shift-anchored-dates.md)
    (which this extends from one clock to one per site);
  - [0040 — Punch capture, geofences, device ingestion](./0040-punch-capture-geofences-device-ingestion-and-records-for-devices.md)
    (the fence check);
  - module docs: [Work Locations](../modules/work-locations.md),
    [Attendance](../modules/attendance.md).

## Context

A site's fence was a circle: a point and a radius. A long, narrow site fits a circle
badly. A plant along a highway or a campus between two roads is either half outside
it or swallows the street. HR widened the radius until punches stopped being
flagged, and the fence stopped meaning much.

Every attendance day was judged on the organisation's clock (ADR 0036). For a
company with an office in Singapore or Tokyo, a 09:00 start there was judged as
09:00 Manila, an hour off. The day's date also turned over at Manila's midnight.

## Decision

### A fence is a circle or a shape

`work_locations` gains:

- `fence_type`: `circle` (the default) or `polygon`;
- `polygon`: 3–50 `[lat, lng]` corners.

The server derives the centre (the shape's centroid) and stores it in `latitude` /
`longitude`. It also stores the furthest corner's distance in `radius_meters`, so
the map, the list and anything that only knows circles still have a point and a
reach.

A shape is refused when:

- its edges cross;
- its area is smaller than a 25 m square;
- it reaches more than 5 km from its centre.

`App\Support\Attendance\FenceGeometry` does the geometry on an equirectangular
projection around the shape, accurate to well under a metre at site scale: point in
polygon by ray casting, distance to the nearest edge, centroid, area and
self-intersection.

`GeofenceCheck::measure()` gives one distance for either shape:

- for a circle, the distance from its centre less the radius;
- for a shape, 0 inside it, otherwise the distance to its nearest edge.

A punch is inside when that distance, less the phone's reported accuracy, is ≤ 0.
That is the same doubt a circle already gave.

The form draws a shape by clicking corners on the map; corners can be dragged,
undone and cleared. Switching back to a circle drops the shape.

### A site may keep its own clock

`work_locations.timezone` holds an IANA name. Null means the organisation's clock. A
person is judged on their **base site's** clock (`WorkLocation::timezoneFor`):

- `OrganizationClock`'s `now`, `today`, `at`, `localDate`, `local` and `parse`
  take an optional zone;
- `ShiftResolver` puts the zone on the `ResolvedShift` (`inZone()`), so shift
  start, end, core hours and the windows a punch is accepted in are that clock's;
- the work date a punch belongs to turns over at that site's midnight;
- `DayRules` **version 4** snapshots the `timezone` each day was judged on, and
  days recorded before keep their own.

`AttendanceRecordResource` exposes the day's `timezone`. Every place that shows a
day's times shows them on the clock it was judged by:

- the board, the drawer, the weekly grid, exceptions and manual entry;
- My attendance and the clock card;
- the app's home, clock, history and day screens.

## Consequences

- A long or odd-shaped site gets a fence that fits it, and the radius stops being
  inflated to compensate.
- An office abroad is judged on its own hours, and its people see their own times.
- A person's clock follows their base site, not where they punched. Somebody based
  in Manila who punches in Tokyo is judged on Manila time.
- Changing a site's clock changes the days recorded after the change. Days already
  recorded keep their clock until HR re-applies them.
- The assistant reads both (the fence label, "keeps Asia/Tokyo time") and
  `update_location` sets or clears a site's clock, waiting for Confirm. A shape is
  only drawn on the map, and a shape's radius is refused in chat.
- Not built: several shapes for one site, and holes in a shape.
