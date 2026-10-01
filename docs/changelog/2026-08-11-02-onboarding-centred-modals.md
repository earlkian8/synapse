# Onboarding opens in the middle of the screen too

Onboarding's four panels — start onboarding, add/edit task, case details, and the
program builder — followed Recruitment off the right edge and into the middle of the
screen. Because this is the **second** module to want the shell, it stopped being a
recruitment file: `Modal`, `FormField` and `FormSelect` now live in `components/` and
both modules import the same ones.

## Highlights

- **Four sheets became centred modals.** Start onboarding, task form, case settings and
  the program builder all compose `ModalHeader / ModalBody / ModalFooter`, height-capped
  with the body as the only scrolling region — so **Save** is on screen the moment the
  modal opens rather than at the bottom of a 36rem column.
- **The program builder finally has room to build in.** It opens at `xl`, and each
  checklist row lays its title, category and due offset out across the width instead of
  squeezing them into a strip.
- **The drag handle that never dragged is gone.** Each blueprint task's position *is*
  its `sort_order`, so the row now carries working **move-up / move-down** controls and
  its number. Nothing about the payload changed — `sort_order` was always the array
  index; you just could not change it before without deleting and re-adding rows.
- **You can edit a program's description.** The form already sent `description` on
  submit and the card already displayed it — there was no input for it, so the field
  could only ever be set by seeding. Now it is a field.
- **Every field is wired to its label.** Labels, hints and validation errors are
  attached through the shared `FormField`, which also hands the control `aria-invalid` —
  the styling shared `Input` and `SelectTrigger` were already listening for.
- **Two small honesty fixes.** "Everyone is already onboarding" was a non-selectable
  line *inside* the employee dropdown; it is now an empty state you can actually read,
  and the employee picker is hidden when there is nobody to pick. The program picker's
  hint now names the task count of the program you actually chose.

## Frontend

- **Promoted, unchanged in behaviour:** `features/recruitment/components/modal.tsx` →
  `components/modal.tsx`, `form-field.tsx` → `components/form-field.tsx`, and
  `fk-select.tsx` → `components/form-select.tsx` (`FkSelect` → **`FormSelect`**, since
  the "foreign key" framing was recruitment's, not the app's). Recruitment's four
  dialogs now import from `@/components/…`.
- **Renamed** `start-onboarding-sheet` → `start-onboarding-dialog`, `task-form-sheet` →
  `task-form-dialog`, `case-settings-sheet` → `case-settings-dialog`,
  `program-form-sheet` → `program-form-dialog`. Sizes: `md` for start and case
  settings, `lg` for the task form, `xl` for the program builder.
- `features/onboarding/components/confirm-dialog.tsx` gains the same tinted icon tile
  and compact layout as Recruitment's — rose alert for destructive, teal check
  otherwise.
- The case page's `taskSheetOpen` state is now `taskFormOpen`; no sheet is involved.
- The program form's toggles (Default / Active) are properly labelled controls — the
  label points at the switch, so clicking the text flips it — and sit side by side from
  `sm` instead of stacking full-width.

## Notes

- **Deliberately not changed:** the task *checklist* on the case page stays inline on
  the page. It is the page's content, not a panel, and does not belong in a modal.
- **Still module-local:** each feature keeps its own `confirm-dialog.tsx`. Nine of them
  exist across the app; consolidating them is its own change, not a side effect of this
  one.
- **Verification:** `tsc`, ESLint and `npm run build` are green, and Prettier is clean
  on every file this touched (44 files elsewhere in `resources/js` fail `--check` on
  `development` already and were left alone). Pest was run in full on the Postgres
  harness — **431 tests, 416 passing, 12 failures + 3 errors**, `development`'s
  documented standing baseline exactly; no PHP was touched. (One full run showed a
  thirteenth failure, `MultiOrganizationTest → a user cannot switch to an organisation
  they do not belong to`. It passes in isolation and did not recur on a confirming full
  run — a full-run flake, not a regression.) As with the recruitment
  change, the modals were **not** exercised in a live browser — there is no browser
  automation on this machine.
