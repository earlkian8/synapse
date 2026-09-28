# Work Locations

**Where the company's people work.** Each site is a point on a map and a circle around
it, the **fence** a punch is checked against. A site can also give the people based
there a default schedule and attendance policy. The *why* is
[ADR 0040](../decisions/0040-punch-capture-geofences-device-ingestion-and-records-for-devices.md);
this is the *how*. Everything is tenant-scoped (ADR 0005).

> Status: **Active** · Route prefix: `/setup/locations`
> Sidebar: Company Setup → Locations (gated by `setup.locations.view`)

## Surfaces

- **Company Setup → Locations**: a map of every active site, each fence drawn and
  labelled (the labels stay on up to twelve sites), above a card list. Each card names
  the site and its address, the radius, how many people are based there, and the
  schedule and policy it gives them. Archived sites are listed apart, with restore and
  permanent delete.
  - When an attendance policy checks where people punch but there is no active site,
    the page names those policies and warns that nothing is being checked yet.
- **The location form** (a centred modal):
  - **Search for an address** (OpenStreetMap's Nominatim), **Use my location** (the
    browser's position), or click the map. The pin can be dragged. Latitude and
    longitude can also be typed.
  - A **radius slider** from 25 m to 5 km (default 150 m), drawn live on the map. The
    map fits the fence the first time it is placed.
  - Name (unique among live sites), address, **default schedule**, **attendance
    policy**, and active.
- **People based here**: a dialog listing everybody (search by name or number, filter
  by department), with a check to base them here and a star to make it their
  **primary** site. A person has at most one primary site; starring one here clears it
  elsewhere.

The map is Leaflet on OpenStreetMap tiles. The Content-Security-Policy allows exactly
`https://tile.openstreetmap.org` (images) and `https://nominatim.openstreetmap.org`
(connections). In dark mode the tiles are dimmed with a CSS filter; the fences keep
their colour.

## Data model

- **`work_locations`**: `name`, `address`, `latitude` / `longitude` (`decimal(10,7)`),
  `radius_meters`, `default_work_schedule_id`, `attendance_policy_id`, `is_active`, soft
  deletes; addressed by hashid.
- **`employee_work_locations`**: employee ↔ location, with `is_primary`.
- Punches name the nearest site (`attendance_punches.work_location_id`).

See [attendance tables](../database/attendance-tables.md).

## How it is used

- **The fence a person is checked against** (`WorkLocation::fenceFor()`) is the active
  sites they are based at, or every active site when they are based nowhere. A company
  with one office draws it and is done.
- **`GeofenceCheck`** picks the nearest site by haversine distance. A punch is inside
  when `distance − accuracy ≤ radius`. The nearest site, the distance and the verdict
  are stored on the punch, so a later change to a fence never rewrites where a punch
  was.
- **Only located sources are checked**: web and mobile. HR's entry is placed nowhere.
- **What the verdict does is the attendance policy's call** (`capture.geofence`: off,
  flag, block). See [Attendance Policies](./attendance-policies.md#settings).
- **Default schedule and policy.** A site is a link in both precedence chains, between
  the department and the organisation:
  *roster → assignment → employee → department → **location** → organisation →
  fallback*. The site used is the person's primary, or their only one
  (`WorkLocation::primaryFor()`). The roster and the day modal name it as the source.

## Archiving and deleting

- **Archive** stops checking punches against the site and takes it out of both chains.
  Punches keep naming it.
- **Delete permanently** is refused while any punch names the site. It stays archived,
  so the day modal can still say where those punches were.
- Changing or archiving a site queues **no** recompute (ADR 0041). A punch was judged
  where it was made.

## The assistant

`App\Services\Assistant\Modules\LocationsModule`
([ADR 0055](../decisions/0055-assistant-locations-leave-and-award-types-and-performance-framework.md)).
Every write goes through **`App\Support\Setup\WorkLocationWorkflow`**, which the
Locations screen uses too (`WorkLocationException` for the kept-for-its-punches refusal),
against `WorkLocationRequest::rulesFor()`.

- **Reads** (`setup.locations.view`):
  - `find_locations`: the sites, or — given an employee, which also needs
    `employees.view`, checked first — where somebody is based;
  - `get_location`: the fence, who is based there (primary marked), the default schedule
    and policy, and the last 30 days' punches placed nearest it and how many were outside.
- **Writes** (`setup.locations.manage`):
  - `base_at_location` (optionally as the primary site, which clears it elsewhere) and
    `unbase_from_location`, at most 25 people at a time;
  - `restore_location`;
  - **`update_location` and `archive_location` always wait for Confirm.** The card says
    how many are based there and how many have it as their primary site.
- **Never in chat:** creating a site and moving its pin (no tool declares a
  coordinate: a guessed point could refuse everybody's punches under a blocking policy),
  and permanent deletion.
- **Retrieval:** "what sites do we have?" carries the sites, the policies that check
  fences, and a warning when a policy checks a fence nobody drew.

## Permissions

`setup.locations.view` (the page) and `setup.locations.manage` (create, edit, people,
archive, restore, delete). Every change is activity-logged under `company-setup`.

## Not done yet

- **A site has no timezone of its own.** Every day is judged on the organisation's
  clock (ADR 0036).
- **Fences are circles.** There are no polygons.
