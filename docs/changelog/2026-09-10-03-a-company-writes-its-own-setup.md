# The setup wizard takes the company's own rules, not just ours

The guided setup shipped a week's worth of Company Setup as four decisions: pick a
department list, a set of leave entitlements, a hiring process, an appraisal framework.
A company whose answer was not on the list had nowhere to put it. A coffee shop hiring
through a walk-in and a trial shift had to adopt "Standard Hiring" and rewrite it
afterwards; an employer granting typhoon leave had to take something close and edit it;
an appraisal that asks about latte art and shift handovers meant adopting "Balanced
Appraisal" and then dismantling it. The wizard was inviting a company to describe
itself, and then handing it a template.

Every step now takes what the company actually does. The offers stay — nobody starts at
an empty form unless they ask to — and each one gains a way in.

## Highlights

- **Choose one, then make it yours.** Every suggestion carries **Customise**, which
  lifts it out of the list of offers and into the company's own, prefilled with what it
  said. The suggestion then reads "In your list" instead of standing there twice.
- **Departments and kinds of leave become editable rows.** A leave type the company
  writes gets its name, code, description, colour, entitlement and all three policy
  switches — paid, half-days, needs approval — which is everything the Leave Types
  screen asks for.
- **A hiring process can be drawn stage by stage.** Name each stage the way the team
  already says it, say what it means, reorder it, and see live whether the process can
  function yet. **Design your own** starts from the three stages every process needs
  rather than an empty list, and a new stage lands *before* the terminal ones — nobody
  ever meant to put "Trial shift" after "Rejected".
- **An appraisal framework can be designed here.** Sections and their weights, the
  criteria inside them at their weight, what each is measured on, and the words a result
  is reported in — with the rating ladder drawn as you rename it. Each line states what
  it ends up worth overall, because a weight is relative twice over and that is the part
  people misread.
- **A criterion the company writes joins its catalogue.** In the framework editor
  proper, a one-off stays inside one framework on purpose (ADR 0028). In the wizard
  there is no catalogue yet, so writing "Latte art" is how the catalogue gets built —
  it becomes a real criterion any later framework can measure.
- **What the modules read is still resolved server-side.** A stage's meaning, a
  statutory entitlement, a catalogue criterion's wording and a rating scale's anchors
  are never things a request body defines. What the company sends is its own *words*.

## Backend

- **New** `Support\Setup\SetupDefinition` — where an adopted answer and a bespoke one
  become the same thing. A department is a name, a code and a description whether it was
  ticked or typed; a hiring process is a name and an ordered list of stages whether it
  came from a card or was drawn by hand.
- **Renamed** `Support\Setup\BlueprintInstaller` → **`SetupInstaller`**, now taking
  definitions rather than blueprint keys. It never learns which route an answer came by,
  which is what makes a bespoke pipeline a first-class pipeline. Still idempotent
  against what the company already has; scales and criteria still resolve by name.
- `SetupBlueprints` gains `instruments()` (the rating-scale library with its
  three-word descriptors) and `criterion()`. A framework blueprint's lines now carry
  their catalogue **key**, so opening one up to change it keeps its lines linked rather
  than copying their wording.
- The four step requests each validate two answers now — a blueprint key, or the
  company's own definition held to the rules of the module's own request. A hand-drawn
  pipeline needs exactly one hired stage and at least one rejected one; a designed
  framework needs sections every line belongs to, no criterion measured twice, and a
  rating model whose lowest band starts at 0%.
- A leave type left without a code gets one shaped like the ones the blueprints carry:
  initials for a multi-word name (`Typhoon Leave` → `TL`), three letters otherwise
  (`Sabbatical` → `SAB`).
- `SetupWizardController` is thinner for it — resolve a definition, install it, log it,
  report it — and its `show` now carries the criteria catalogue, the instruments and the
  default rating ladder a bespoke framework draws on.

## Frontend

- **New** `features/setup-wizard/components/` — `custom-section.tsx` (the region that
  holds what the company wrote, and one row of it), `leave-type-fields.tsx`,
  `stage-editor.tsx` and `framework-editor.tsx`.
- Everything bespoke sits on a **dashed hairline** — the same signal the framework
  editor already uses for a criterion written by hand. The difference between an offer
  and the company's own is authorship, not importance, so it is drawn without a second
  accent colour.
- `choice-card.tsx` gains an `action` slot for **Customise**. The card is a `<label>`,
  so the slot swallows its own click: pressing the action never also ticks the box.
- **New** `features/kpi-config/components/weight-controls.tsx` — `PercentInput`, the
  running tally with its "Split evenly", and the share-of-the-whole arithmetic, lifted
  out of `measurement-editor.tsx` so the wizard and the framework editor render and
  compute a weight identically. `RESULT_DISPLAYS` moved to `features/kpi-config/constants.ts`
  for the same reason.
- The wizard's framework editor is deliberately the smaller of the two: no eligibility
  rule (a day-one framework applies to everyone, because there is nobody to divide yet)
  and instruments chosen from the library rather than designed.

## Notes

- Pest: **726 tests, 726 passed** — 26 new ones covering a customised department in the
  company's own words, a leave type it wrote whole, a code claimed twice in one
  submission, the derived code's shape, a hand-drawn pipeline (and the three ways one
  can fail to end in hired-or-not), a designed framework with a catalogue line beside a
  written one, the standard ladder when the ratings are left alone, bands ordered
  highest-first with keys derived from their labels, the eight ways a designed framework
  is refused, and a written criterion reusing one the company already has. Pint, tsc,
  ESLint, Prettier and `npm run build` are green.
- Walked it in Chromium over CDP as a genuinely new registration ("Kapetirya Nayon"):
  customised a department and a kind of leave, drew a hiring process from nothing, and
  opened "Balanced Appraisal" up to add a criterion of the company's own ("Latte art",
  on the 5-point scale) before saving. Checked in the database afterwards that the
  framework's lines are all catalogue-backed, that "Latte art" is a real criterion on
  its instrument, and that the pipeline's stages carry the right kinds. Light and dark,
  1440px and 390px, clean console throughout. The throwaway tenant was removed
  afterwards.
- `docs/IMPLEMENTATION-METHOD.txt` still quotes a Pest baseline of 897 and the Opus 4.8
  attribution line; the real baseline before this change was 700 (per the previous
  changelog) and commits have been carrying Opus 5. Left alone — that file is the user's.
- See [ADR 0034](../decisions/0034-a-company-writes-its-own-setup.md).
