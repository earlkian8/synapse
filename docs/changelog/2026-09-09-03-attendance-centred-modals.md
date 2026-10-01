# Attendance opens in the middle of the screen

Attendance was the last module still opening its panels from the right edge. A day's
record — the punch trail, the totals, the selfies that make a mobile punch checkable —
arrived in a 32rem strip while the board it came from sat greyed out behind. Recruitment,
Onboarding and Employees each moved to centred modals; this brings the last one across,
onto the same shared shell they already use.

## Highlights

- **The day-detail modal is three fixed regions, not one scrolling column.** The header
  states who and when — person, status pill, date, and the shift that applied — so the
  date row and the schedule line that used to eat two rows of the body are gone. A
  **totals band** (worked / break / late / overtime) sits under it as an inline divided
  strip instead of four bordered cards, and stays in view while the body scrolls. The
  body then splits the **audit trail** from the **evidence**.
- **The selfies get treated as evidence.** They used to float beside each timeline row at
  40px — too small to recognise anyone, which is the only reason they exist. They now
  collect into a **verification rail** at roughly 135px, each captioned with the punch it
  belongs to, opening full-size in a new tab. The rail also carries the remarks and the
  sign-off, so nothing is stacked below the timeline any more.
- **Recording a day is one row, in order.** The manual-entry modal puts *time in → break
  start → break end → time out* across a single row from `sm` up, in the sequence they
  actually happen, instead of a 2×2 block in a narrow strip. Employee and date pair up on
  the row above.
- **Compaction, measured.** For a day with four punches the detail surface lost roughly a
  third of its height while gaining the larger photos — the savings come from the header
  absorbing the date and shift, the totals losing four card borders and their padding,
  and the remarks/approval moving into the rail rather than queueing underneath.

## Frontend

- **New** `features/attendance/components/record-detail-dialog.tsx` and
  `manual-entry-dialog.tsx`, composing the shared `components/modal.tsx` shell
  (`Modal ─ ModalContent ─ ModalHeader / ModalBody / ModalFooter / ModalSection`) that
  Recruitment, Onboarding, Employees, KPI config and Performance already build on. The
  body is the only scrolling region and the action bar is pinned, so *Approve* and *Save
  record* are on screen the moment the modal opens.
- **Removed** `record-detail-sheet.tsx` and `manual-entry-sheet.tsx`.
- **Removed** `features/attendance/components/confirm-dialog.tsx` — it was a byte-for-byte
  duplicate of the shared `components/confirm-dialog.tsx` (same props, same body). The
  page imports the shared one now.
- `punch-timeline.tsx` gains **`withPhotos`** (default on). The detail modal turns it off
  because its rail shows the photos properly; `/attendance/me` is unchanged.
- The rail only claims its column when it has something to hold — no photos, no remarks
  and no approval means the timeline takes the full width.

## Two bugs fixed on the way

- **The timeline badge was sitting on its own label.** Each punch's icon is a 24px circle
  with a 4px `ring-4` *outside* it, but the list only reserved a 24px gutter — so the ring
  covered the first letter and "Clock in" read as "lock in". Widened to `pl-8` / `-left-8`.
  This was pre-existing and also affected `/attendance/me`.
- **A selfie that no longer resolves rendered as a broken image** with its alt text
  spilling across the rail. It now falls back to a labelled "Unavailable" tile.

## Verified

- Walked in a real browser at 1440×900, light and dark, signed in against the seeded demo
  tenant: the attendance board, the day-detail modal (with punches, GPS, selfies, remarks
  and a pending approval), and the manual-entry modal. Both bug fixes confirmed in the
  same pass.
- Photo rendering was verified with same-origin images; the fallback tile was verified by
  pointing a punch at an unreachable host.
- `tsc --noEmit`, ESLint and Prettier clean; `npm run build` succeeds.
- Pest: **640/641**. The one failure is
  `UserManagementTest::it_stores_an_uploaded_profile_photo` — the pre-existing local GD
  gap, unrelated. The 10 attendance tests pass.

## Notes

- No backend, schema or permission change. The modals call the same routes with the same
  payloads; `canManage` still gates the footer.
- Seeded demo data carries **no punch selfies at all**, so the verification rail never
  appears on a fresh seed. Two punches were temporarily given photos to check the layout
  and then cleared. One detail could not be restored exactly: those two punches' `source`
  was overwritten before it was recorded, and both are now `web` (the seeder assigns one
  source per record, `mobile` 35% of the time). It is randomised demo data and both
  punches on the record still agree, but `migrate:fresh --seed` is the way back to a
  pristine set.
- `exceptions-panel.tsx` and the three tab tables were not touched — they are not modals.
