# Recruitment opens in the middle of the screen

Every panel in Recruitment used to slide in from the right edge and take a quarter of
the window with it — a job posting, a candidate's whole profile, the hire decision, all
crammed into a 36rem strip while the board they came from sat greyed out behind. They
are now **centred modals**, built from one shell, sized to what they actually hold.
Nothing about what recruiters can do changed; where it happens, and how legible it is
while it happens, did.

## Highlights

- **One shell, four surfaces.** Posting form, posting detail, add candidate and the
  candidate profile all compose `Modal ─ ModalContent ─ ModalHeader / ModalBody /
  ModalFooter`. The content is height-capped at the viewport, the **body is the only
  thing that scrolls**, and the action bar is pinned — so *Hire candidate* and *Create
  posting* are on screen the moment the modal opens, on a laptop and on a phone.
- **The candidate modal is a workspace, not a strip.** From `lg` up it splits into a
  **decision column** (recommended next step → stage stepper → fit breakdown → AI
  insights → assessment → interviews) and a **reference rail** (profile, documents,
  other applications), with contact details in a full-width band under the header.
  Below `lg` it collapses to a single decision-first column.
- **The pipeline is a stepper.** *Applied → Screening → Interview → Offer* is drawn as
  the pipeline it is: where the candidate stands, and — for anyone who may manage the
  pipeline — the control that moves them. It replaces a row of four identical buttons
  that showed no position at all.
- **Rejecting and removing now ask twice.** Both confirm **inside the footer** rather
  than opening a second dialog over the first; rejecting collects its optional reason
  there, submits on Enter, and backs out on Escape without closing the candidate.
  Removing an application previously fired on a single click with no confirmation.
- **The posting form uses the width it gained.** Description and requirements sit side
  by side from `lg`, screening criteria pair up from `sm`, and each section is separated
  by a rule instead of by hope.

## Frontend

- **New** `features/recruitment/components/modal.tsx` — `Modal`, `ModalContent`
  (`sm`…`2xl`), `ModalHeader` (icon/avatar, title, description, status meta),
  `ModalIcon`, `ModalBody`, `ModalFooter`, `ModalSection`. Built on the existing
  `ui/dialog` primitives, so overlay, focus trap, Escape and the close button are
  Radix's, not ours.
- **New** `form-field.tsx` — one labelled control. It generates the id, points the
  label at it, and hands the control `aria-describedby` (hint *or* error) and
  `aria-invalid`, which the shared `Input` and `SelectTrigger` already style. Fields
  holding a cluster of controls (the rating stars) become a labelled `role="group"`
  instead of a label pointing at nothing.
- **New** `fk-select.tsx` — the module's select, forwarding those accessibility props
  onto the **trigger** (the element the label points at). Replaces the two divergent
  copies that lived in the posting form and the candidate detail panel.
- **New** `stage-stepper.tsx` — the pipeline stepper described above; read-only for
  anyone without `recruitment.manage-pipeline`, and never rendered for a hired or
  rejected candidate, who has left the pipeline.
- **Renamed** `posting-form-sheet` → `posting-form-dialog`, `posting-detail-sheet` →
  `posting-detail-dialog`, `add-candidate-sheet` → `add-candidate-dialog`,
  `application-detail-sheet` → `application-detail-dialog`. The two detail sheets
  previously rendered an **empty sheet with no title** when nothing was selected, which
  Radix flags as an accessibility violation; the dialogs render nothing at all.
- `confirm-dialog.tsx` gains a tinted icon tile keyed to the action's tone (rose alert
  for destructive, teal check otherwise) and a compact single-block layout.
- Contact rows in the candidate modal are now real `mailto:` / `tel:` links, and every
  icon-only control in these files carries an `aria-label` and a visible focus ring.

## Notes

- **Scope:** the recruitment module only. The other modules' sheets are untouched. The
  shell started module-local; Onboarding asked for it next, so it moved to
  `components/` — see
  [the onboarding entry](2026-08-11-02-onboarding-centred-modals.md).
- **Verification:** `tsc`, ESLint, Prettier and `npm run build` are green. Pest was run
  in full on the Postgres harness — **431 tests, 416 passing, 12 failures + 3 errors**,
  which is `development`'s documented standing baseline to the test, not a regression;
  no PHP was touched. The modals were **not** exercised in a live browser — this machine
  has no browser automation, so layout was reviewed by hand against the shell's
  constraints rather than screenshotted.
