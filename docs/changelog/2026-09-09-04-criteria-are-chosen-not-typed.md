# A framework's criteria are chosen, not typed

The criteria catalogue exists so a company asks a question one way. The framework
editor did not honour that: picking *Quality of work* from the catalogue copied its
name into a plain text box and left the box editable. Typing over it — `sample`, say —
did **not** unlink the line. The catalogue went on counting it ("in 1 framework"), the
scorecard asked "sample", and nothing on screen said the two had come apart. Choosing
*Something not in the catalogue…* was worse: a blank box, weight 0, no explanation of
what that line would become.

This turns the identity of a line into a choice, and rebuilds the section editor around
the two relative weights people were misreading.

## Highlights

- **The criterion is a select.** Every line names a catalogue criterion, and shows that
  criterion's meaning underneath it. Swapping the select re-derives the line — its
  wording, its scale, and, if the line had no weight yet, the catalogue's default. A
  criterion already measured in the framework is listed but greyed, "already in this
  framework", so nothing is silently missing from the list.
- **A one-off is a deliberate choice, and it explains itself.** *A one-off, written here
  only* opens a bordered block with its own name and meaning fields, and says plainly
  that only this framework asks it, that it stays out of the catalogue, and that nothing
  else can reuse it. That is what `sample` was actually creating, unlabelled.
- **The wording follows the catalogue, in all three places it is read.** A line naming a
  criterion takes the catalogue's current name and meaning when the framework is saved,
  when the editor reads it back, and when a scorecard is opened. Retitling *Teamwork* to
  *Collaboration* now reaches every framework drawing on it, without re-saving any of
  them. A criterion since archived leaves the framework its stored copy.
- **Both weights are stated, not implied.** Sections divide the appraisal; the criteria
  inside a section divide the section. Each line now carries what that resolves to
  overall — *35% of the section → 10.5% overall* — with a running total per section and
  across sections, amber until it reaches 100%, and **Split evenly** on each.
- **The rows read as a table.** `Criterion · Measured on · Of the section` column
  headers, weights in a box that shows its own `%`, and *Measured on* naming the
  instrument with its range underneath ("Competency level · 5 levels") instead of
  truncating a joined label. A section that measures nothing says so.
- **The false drag handle is gone.** Sections showed a grip that never dragged anything.

## Frontend

- **New** `features/kpi-config/components/measurement-editor.tsx` — the whole "what it
  measures" surface (sections, their criteria, the pickers, the weight arithmetic), plus
  the `SectionDraft` / `ItemDraft` types that moved out of the modal. `framework-modal.tsx`
  loses 339 lines of inline markup (854 → 545) and hands the editor `sections`, `items`
  and one `onChange`.
- A line's `rating_scale_id` is left **null** when it is added from the catalogue, so it
  follows the criterion's scale rather than pinning a copy of it. The select's first
  option says which — *Same as catalogue* / *Framework default* — and names the resolved
  scale underneath.
- `components/form-select.tsx` — `SelectOption` takes an optional `disabled`, which is
  how a criterion already spent in the framework is shown.

## Backend

- `ReviewTemplateRequest` takes each catalogue-backed item's `name` and `description`
  from the catalogue in `prepareForValidation`, so the stored line cannot carry one
  criterion's words while counting as another — whatever posts it. Archived criteria are
  read too, so a framework naming one keeps its wording.
- `ReviewTemplateRequest` rejects the same criterion twice in one framework
  (`items.N.kpi_criterion_id`): it only splits the criterion's weight in two.
- `ReviewTemplateResource` reads an item back in the catalogue's current words;
  `KpiSetupController` eager-loads `items.criterion` for it.
- `EvaluationOpener::wordingFor()` — a scorecard line is asked in the catalogue's current
  words. One-offs, and lines whose criterion has been archived, keep their own.

## Notes

- Pest: 646 tests, 645 passed — including five new ones (catalogue wording on save and on
  read-back, one-off wording preserved, duplicate criterion rejected, scorecard wording at
  open). The one failure is unrelated and pre-existing: `UserManagementTest`'s photo-upload
  test needs the GD extension, which is not installed on this machine. Pint, tsc, ESLint,
  Prettier and `npm run build` are green.
- Existing frameworks are repaired the first time they are saved; the editor and every
  new scorecard already show the catalogue's wording before that.
