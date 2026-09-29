# Product Tour

The short, skippable walk around the app that everybody is offered **the first time
they reach the app shell**: the owner who has just finished company setup, and every
employee who joins after them. It lights up each part of the screen the person can
use, in turn, with a card beside it. At the end it suggests where to start. It can be
replayed any time from **Help** in the top bar.

> Status: **Active** · Endpoint: `POST /settings/tour`
> Replayed from: the top bar → Help (the question mark) → *Take the tour*
> See [ADR 0060](../decisions/0060-a-first-run-tour-of-the-app.md).

## Why it exists

The setup wizard ([Company Setup Wizard](./company-setup-wizard.md)) configures a
company, but it does not show anybody around the app. After it, an owner lands on a
dashboard beside a sidebar of up to 35 links in seven sections. An employee who joins
lands there with no wizard at all. Nothing said what the sections are for, that the
bell and the question mark do anything, or that the sparkle button bottom-right is an
assistant that can do the work. The tour does, once, in about a minute. It only covers
what that person's role can open.

## What it shows

| # | Stop | Points at | Shown when |
| --- | --- | --- | --- |
| — | **Welcome** | centred | always: what the tour is, how long it takes, the stops as chips, *Show me around* or *Skip for now* |
| 1 | Workspace | the sidebar's company brand, or the workspace switcher | always ("Your workspaces" when the person belongs to more than one) |
| 2 | Dashboard | the *Main* section | always; the copy differs for management and self-service dashboards |
| 3 | Talent acquisition | the section | the person can see a screen in it |
| 4 | Your workforce | the section | 〃 |
| 5 | Offboarding | the section | 〃 |
| 6 | Analytics & AI | the section | 〃 |
| 7 | Company setup | the section | 〃 (Email & Notifications does not count, see below) |
| 8 | System | the section | 〃 (Data Backup & Export does not count) |
| 9 | Notifications | the bell | always |
| 10 | Help | the question mark | always; mentions the Setup Guide to `setup.company.manage` |
| 11 | Your account | the avatar | always |
| 12 | Assistant | the sparkle launcher | the assistant is offered to the person |
| — | **Send-off** | centred | always: up to three places to start, and where the tour lives now |

A section's card lists **the section's screens the person can open**, each with a
one-line summary: up to six, then "and N more". Those lines come from the sidebar's own
definition, so the tour cannot describe a screen the sidebar does not show. A link with
**no screen behind it yet** (*Email & Notifications*, *Data Backup & Export*) has no
summary. It is never listed, and a section made only of such links is not a stop. That
is why Staff get no Company Setup stop.

The **send-off** suggests the first three of these that the person can do: *Bring your
people in* (`employees.invite`), *Finish setting up* (`setup.company.manage`), *Start
hiring* (`recruitment.view`), *Review leave requests* (`leave.view` and
`leave.manage`), *Check today's attendance* (`attendance.view`), *Your time record*
(`attendance.clock`), and *Complete your profile* (everyone). An owner is pointed at
bringing people in; a member of staff at their own time record.

In practice, the built-in **HR Manager** gets all 12 stops and **Staff** get 6
(workspace, dashboard, system, notifications, help, account).

### Only what is on screen

A stop is included only if its target is **on screen when the tour starts**. On a
phone the sidebar is a closed sheet, so the sidebar stops are passed over and the tour
covers notifications, help, the account menu and the assistant. If a target leaves the
screen **mid-tour** (the window is narrowed until the sidebar folds away), that stop's
card is shown in the middle of the screen instead of pointing at nothing.

## Behaviour

- **Offered once.** The server keeps whether the tour is still owed (see *Data model*).
  The app shell offers it about a second after a page loads, once per page load, and
  only while it is owed. It waits while another dialog is open, so it never competes
  with a page's own modal for focus.
- **Only in the app shell.** It is mounted beside the assistant in the sidebar layout.
  Company setup, the workspace picker, the invitation page and the sign-in screens have
  no sidebar and never show it. The owner therefore sees the setup wizard first and
  the tour on the first page after it.
- **Any way out is an answer.** *Skip tour*, *Skip for now* or Escape before the end
  records `skipped`. A toast then says where to replay it. Finishing on the send-off,
  by any means (its button, Escape, or one of its suggestions), records `completed`.
  A click on the dimmed page does nothing, so nobody leaves by accident.
- **Replays are not recorded.** Once answered, the tour can be taken again from Help as
  often as wanted. The first answer stands on the server, and a double submit cannot
  rewrite it.
- **The keyboard works it.** Focus opens on the forward button, so Enter walks the tour.
  → and ← step through it, and Escape leaves. Every card is a modal dialog with a
  labelled title and description.
- **Motion.** The spotlight glides from stop to stop and each card animates in from
  its target's side. Under `prefers-reduced-motion` both are still.

## Data model

No new table. Two columns on `users` (see [users table](../database/users-table.md)):

| Column | Notes |
| --- | --- |
| `tour_finished_at` | Null means "offer the tour". Set the first time it is finished or skipped. |
| `tour_outcome` | `completed` or `skipped`. Null on accounts back-filled by the migration, which were never offered the tour. |

The tour belongs to the **person**, not to a workspace, because it describes the app
and the app is the same in every company they belong to. Neither column is fillable or
serialised on the user (`#[Hidden]`). The browser reads them only as `auth.tour`.
Writing them does not touch `updated_at`, which `UserResource` reports as the last
edit to the account.

Every account that existed before the tour was back-filled as finished, with no
outcome, so nobody who has been using the app is interrupted by it. Accounts created
afterwards, including by the seeder, owe it. On a freshly seeded database the demo
login is therefore offered the tour once.

## Backend

- **`Support\ProductTour`**: `COMPLETED`, `SKIPPED`, `OUTCOMES`; `owes(User)`; and
  `finish(User, outcome)`, which is idempotent (the first answer stands) and written
  with `forceFill` without timestamps.
- **`Settings\ProductTourController@finish`**: validates through
  `Settings\FinishProductTourRequest` (`outcome` ∈ `ProductTour::OUTCOMES`) and answers
  `204 No Content`. It is a background call, so the page underneath must not reload.
- **Route:** `POST /settings/tour` → `tour.finish`, under `auth` + `verified` beside the
  other account settings.
- **Shared props:** `HandleInertiaRequests` adds `auth.tour = { owed: bool }` (null for
  guests).
- **Not activity-logged.** Like a profile edit, it is the person's own account state,
  not something the company keeps.
- **The assistant** knows where the tour is: `SystemGuide` has a `tour` entry ("how do
  I replay the tutorial?").

## Frontend

`resources/js/features/product-tour/`:

- `targets.ts`: the `TourTarget` vocabulary, `tourTarget(id)` (spread onto an element
  as `data-tour`), and `findTourTarget(id)`, which treats hidden or zero-sized elements
  as absent.
- `steps.ts`: `buildTourStops(context)` (the stops and their wording, from the visible
  navigation and permissions) and `buildNextSteps(can)`.
- `store.ts`: the run's state (running, index, the step ids fixed at start, trigger,
  whether it records, and whether it was offered or answered since the page loaded).
  It is a module-level `useSyncExternalStore` store, like the appearance store, so the
  Help menu and the layout share one run and a layout remount cannot lose it.
- `use-product-tour.ts`: the orchestration hook (`start`, `next`, `back`, `skip`,
  `complete`). It resolves which stops are on screen at start, scrolls a target into
  view before moving to it, and posts the answer only for the owed run.
- `use-target-rect.ts`: the target's rectangle, re-read once a frame while a stop is
  showing and read synchronously during render, so moving between stops never shows an
  unmeasured frame.
- `api.ts` / `routes.ts`: `finishTour(outcome)`, a plain fetch with the XSRF header,
  which accepts **only** `204`. A refusal on a web route is a redirect that fetch would
  follow to a 200 page.
- `components/product-tour.tsx`: the auto-offer, and the stage (spotlight + card).
  `tour-spotlight.tsx` is one box whose huge box-shadow is the dim, portalled to
  `body`. `tour-coachmark.tsx` is a stop's card: a modal Radix **Popover** anchored to
  the lit area, falling back to a centred dialog. `tour-welcome.tsx` and
  `tour-finish.tsx` are the centred cards on the brand's navy field (`SynapseField`).
  `tour-dialog.tsx` is their shared modal shell. `help-menu.tsx` is Help & resources in
  the top bar.

Around it:

- **`lib/app-navigation.ts`**: the sidebar's sections and items, now defined once
  (with each item's `summary`). `hooks/use-app-navigation.ts` returns the sections the
  person can see, with empty sections dropped. `AppSidebar` renders exactly that, and
  the tour describes exactly that.
- **Anchors:** the sidebar brand (`workspace`), each `NavMain` section
  (`nav-<section>`), the bell, Help, the avatar and the assistant launcher.
- **`components/ui/popover.tsx`**: the standard shadcn Popover, added with
  `@radix-ui/react-popover` (its dependencies were already installed at the same
  versions).
- Layering: spotlight `z-60`, cards `z-70`, above the assistant (`z-50`).

## Integrations

- **Company Setup Wizard**: runs first for an owner. The tour's Help stop and Help
  menu point back to its Setup Guide.
- **Sidebar**: one definition shared with the tour. A screen added to the sidebar is
  picked up by the tour automatically. Give it a `summary` if it should be listed.
- **Assistant**: a tour stop, and the tour is a `SystemGuide` entry.
- **Tests**: `Settings/ProductTourTest` covers the owed flag in the shared props, the
  flag kept off the user payload, finishing, skipping, the first answer standing,
  `updated_at` left alone, validation, guests, a new registration owing the tour, the
  migration's back-fill, and the guide entry.
