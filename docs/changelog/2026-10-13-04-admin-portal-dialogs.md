# Admin portal: records and forms open in centred dialogs

The portal's detail panels and the admin form slid in from the right and spread each
fact across the full width: the label at one edge, the value at the other, with empty
space between. They now open as centred dialogs laid out compactly.

## Frontend (admin/)

- New `components/record-dialog.tsx`: `RecordDialog` (centred; compact header; the body
  scrolls inside the dialog, which never exceeds the screen; optional pinned footer),
  `RecordHeading`, `RecordSection`, `FactGrid` / `Fact` (facts in a bordered grid, label
  over value, two or four across), `RecordList` (people and places in one bordered list)
  and `RecordSkeleton`.
- Organisation, account and activity-entry details: `*-sheet.tsx` replaced by
  `*-dialog.tsx`. The organisation dialog puts its HR Managers and newest members side
  by side.
- Admin form: `admin-form-dialog.tsx`, with name and email side by side and the two
  roles as side-by-side choices. The form is re-keyed when it opens, not when it closes,
  so it animates out. Cancel and Save sit in the footer (the submit button uses
  `form=`).
- Account and security page: the same fact grids replace the full-width rows. Sessions
  and Authenticator sit side by side, and the password form is held to a readable
  width.
- Removed `components/detail-list.tsx` (no longer used).

## Verified

- `tsc -b`, ESLint, Prettier and `npm run build`.
- Playwright against a scratch ERP, at 1440 and 390 px (plus dark mode): every dialog
  measured centred horizontally and inside the viewport. The edit form opened
  prefilled. No console errors.
