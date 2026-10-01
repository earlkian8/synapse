# 0055 — The assistant keeps the sites, leave types, award types and performance framework, but never places a fence

- **Status:** Accepted
- **Date:** 2026-09-29
- **Extends:**
  - [0049 — The assistant assumes it will be prompt-injected](./0049-assistant-prompt-injection-defences.md);
  - [0053 — The assistant sets the company's clock, calendar and attendance rules, and says whom a change reaches](./0053-assistant-company-profile-schedules-and-attendance-policies.md)
    (the reach line on a held call's card).
- **Related:** [Work Locations](../modules/work-locations.md#the-assistant),
  [Leave](../modules/leave.md#leave-types-in-the-assistant),
  [Awards](../modules/awards.md#award-types-in-the-assistant),
  [Performance](../modules/performance.md#the-framework-in-the-assistant).

## Context

Four Company Setup screens were still screen-only:

- **Locations**: each site is a fence (a point and a radius) with default schedules
  and policies, and a list of who is based there;
- **Leave Types**: codes, default entitlements and flags;
- **Award Types**: the catalogue of recognitions;
- **Performance Framework**: appraisal frameworks, the criteria catalogue, rating
  scales and review cycles.

Each writes the same way the earlier screens did, from a controller, and one
(frameworks) is a whole document of sections, items and bands validated together. Two
of them carry risks that a chat does not see:

- **A fence placed wrong refuses real punches.** Under a policy that blocks off-site
  punches, a pin the model guessed at would turn away everybody based there.
- **A framework change moves somebody's next appraisal.** Which framework a person
  gets is decided by a resolver, not by the rule that names them. A "rename" can be
  harmless, and a re-targeting can change who is measured how.

## Decision

1. **One path per screen:**
   - `WorkLocationWorkflow`, `LeaveTypeWorkflow`, `AwardTypeWorkflow` and
     `PerformanceFrameworkWorkflow` hold every write, each with an exception worded to
     be shown as it is;
   - the controllers are thin callers, with the same toasts and audit lines;
   - the requests are usable without a route: `WorkLocationRequest::rulesFor()`,
     `LeaveTypeRequest::rulesFor()` / `normaliseCode()`,
     `ReviewTemplateRequest::documentRules()` / `normalise()` /
     `validateDocument()`, and `RatingScaleRequest::normalise()`.
2. **Locations: everything but the pin.**
   - The assistant reads sites, who is based where and a site's punches this month.
   - It edits a site's name, address, radius, defaults and active flag, behind a
     Confirm.
   - It bases people at a site or stops basing them, up to 25 at a time.
   - It archives (behind a Confirm) and restores.
   - **Creating a site and moving its pin stay on the map.** No tool declares a
     coordinate.
   - "Where is Maria based?" also needs `employees.view`, checked before the name is
     looked up.
3. **Leave types:**
   - The assistant reads the catalogue and a type's use this year.
   - It creates types by the screen's rules: a unique, upper-cased code, the screen's
     defaults, and a palette colour no live type wears yet.
   - Editing and archiving wait for a Confirm. The card says how many people's
     balance is the default, and how many requests are pending.
4. **Award types:**
   - The assistant reads the catalogue and how often a type was given, **never to
     whom**. That is the Awards capability's, under its own permission.
   - It creates, edits, retires and reactivates types; archiving waits for a
     Confirm.
5. **Performance framework, a line at a time:**
   - **Reads:** a framework section by section, with who it covers today (the
     resolver's answer), and the catalogue, scales and cycles. Cycles are listed here
     only for users without `performance.view`, whose capability already lists them.
   - **Framework edits** (an item, a section, eligibility by names, the default,
     archiving) rebuild the whole document, pass it through the editor's own
     normalise and rules, and wait for a Confirm. The card gives the coverage.
   - **A new framework** is made by copying one, or from catalogue criteria. It
     applies to everyone and is not the default, so it only reaches people no
     framework covers until it is re-targeted.
   - **Catalogue, scales and cycles:** criteria can be added, edited and archived,
     scales created and made preferred, and cycles created (as drafts) and changed.
     Editing or archiving a criterion waits for a Confirm: every framework measuring it
     takes its wording and scale when a scorecard opens. Changing a cycle waits too: an
     open cycle is the one that takes new appraisals.
   - **Screen-only:** the rating bands, editing or archiving a scale (every line on it
     would change), removing a section, restoring, and permanent deletion.
6. **Names are one per catalogue.** A name that differs from another live one only in
   case is refused for sites, leave types, award types, frameworks, criteria, scales
   and cycles. The screens do not all insist, and two records answering to one name
   cannot be told apart in chat.

## Consequences

- **"Base Maria and Ben at the Cebu plant", "give everyone 18 days of vacation leave",
  "retire Perfect Attendance", "add Customer focus to the Staff framework at 15%" work
  in chat.** The three that reach other people wait for a Confirm that says whom.
- **A defect found on the way.** `TemplateResolver` passed one-argument key closures to
  `sortBy()`, which calls each entry as a two-argument comparator: the same mistake
  ADR 0049 found in `EvaluationOpener`. "The most specific framework first, then the
  default, then the oldest" was therefore not what decided a person's framework.
  One explicit comparator does it now, and a test pins the order. It fails on the old
  code.
- **A second defect.** `Employee::scopeSearch()` did not search `suffix`, so a full
  name as the app shows it ("Juan Cruz Jr.") found nobody. This affected the directory
  search and every assistant lookup of a person. The shared scope now searches it.
- **The tool budget keeps growing.** A super admin is now offered about 23,000 tokens
  of tool declarations and 6,600 of guidance on every turn (from 19,800 and 5,600).
  This round kept the new tools terse and left rare actions to the screens. Offering a
  module's tools only when a turn raises its topic is the next lever, if the free tier's
  token limits start to bite.

## Alternatives considered

- **Creating a site from an address in chat.** It would need a server-side geocoder
  (a new outbound dependency) and still asks the person to trust a point they have not
  seen. The map already geocodes, previews the fence and lets the pin be dragged.
- **A generic "edit the framework" tool taking the whole document.** It is the
  editor's own shape, but it asks the model to reproduce every section, band and item
  to change one weight, and one dropped line would silently remove it. Line-level
  tools change one thing and rebuild the rest from the record.
- **Counting the rule's matches as a framework's reach.** Cheaper, but a person is
  appraised against the framework the resolver picks, not every one whose rule matches
  them. The count has to be the resolver's.
