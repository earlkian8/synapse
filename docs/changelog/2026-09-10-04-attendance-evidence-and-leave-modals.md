# A punch says whether it was photographed, and Leave opens in the middle

Two surfaces, one complaint behind both: a panel that leaves space where its content
would have been tells you nothing, and it is the thing you notice first.

Attendance's day-detail modal kept its selfies in a column beside the punch trail. On a
day with photos that said each punch twice; on a day without — which is most days, and
every day on a fresh seed — the column vanished and took half the modal with it, leaving
a narrow strip of timeline in a wide dialog. Nothing anywhere said whether a photo was
*missing* or simply never taken, which are different findings.

Leave Management, meanwhile, was the last module still opening its panels from the right
edge: filing leave, reviewing a request and adjusting entitlements all slid in as 32rem
sheets while the inbox greyed out behind them.

## Highlights

- **Every punch is accounted for.** The photo now sits on the punch's own row, at 64px,
  opening full-size in a new tab. A punch without one gets a tile that says which of
  three things happened — the source never takes a photo (web, kiosk, biometric), the
  mobile app was expected to and did not, or the file has since gone. The section header
  counts them: *4 punches · 2 with a photo*.
- **Nothing is left blank.** Remarks and approval are always drawn, saying "Nothing was
  written about this day" and "This day needs no sign-off" rather than disappearing. An
  empty half of a row reads as a layout that broke; a sentence reads as a finding.
- **The day got shorter.** One list instead of a timeline plus a rail, a tighter header
  and totals band, and a four-punch day now fits a 1000px viewport without scrolling —
  including the photos, which are larger than the 40px thumbnails they replaced.
- **Recording a day shows its arithmetic.** The manual-entry modal reads back what the
  four times add up to as they are typed — *8h 30m worked after a 45m break* — and says
  plainly that lateness and overtime are worked out against the employee's schedule.
- **Leave opens in the middle of the screen**, on the same shared modal shell as
  Recruitment, Onboarding, Employees, Attendance and the framework editor.
- **A leave request is laid out the way it is read**: the person in the header, the ask
  (type, dates, days charged) in a strip that stays in view while the body scrolls, and
  the balance meter first and largest — it is the only part of an approval that is a
  number rather than a judgement.

## Frontend

- `features/attendance/components/punch-timeline.tsx` is now the trail itself: bordered
  rows, one per punch, each carrying its own evidence tile. `withPhotos` is gone — there
  is one behaviour, and `/attendance/me` gets it too.
- `record-detail-dialog.tsx` drops the conditional `aside`, the duplicated photo
  rendering and two `ModalSection` wrappers; the header absorbs a "Recorded by hand"
  marker and states "No shift scheduled" instead of omitting the line.
- `manual-entry-dialog.tsx` gains the live read-out and a properly formatted date in its
  description.
- `constants.ts` gains `SOURCE_LABELS` — a punch source in the words somebody checking a
  record would use ("Mobile app", "Entered by hand"), which is also the answer to why a
  photo is absent.
- **New** `features/leave/components/{file-leave,review-request,adjust-balance}-dialog.tsx`,
  replacing the three sheets. **Removed** `features/leave/components/confirm-dialog.tsx` —
  it was a byte-for-byte duplicate of the shared `components/confirm-dialog.tsx`, the
  same duplicate Attendance shed in September.
- Both modules' fact strips (attendance totals, leave's type/dates/charged) run four or
  three across on a desktop and fold two-up on a phone, where an 85px column cannot hold
  the word "Overtime".

## Verified

- Walked in Chromium against the seeded demo tenant at 1440×1000 and 390×844, light and
  dark: the attendance board and day-detail modal, manual entry, the leave inbox and
  review modal, file leave, and adjust entitlements.
- All three evidence states were checked in one modal by temporarily pointing two punches
  at a real same-origin file and a path that no longer resolves; both were cleared
  afterwards and no punch in the seed carries a photo again.
- A request was approved through the new modal with a review note, and the note, the
  toast and the database row were confirmed before the request was put back to pending.
- Pest **726/726**; Pint, `tsc`, ESLint, Prettier and `npm run build` green.

## Notes

- No backend, schema, route or permission change. The modals post the same payloads to
  the same endpoints.
- Seeded employee photos point at `randomuser.me`, which the app's own
  `img-src 'self' data: blob:` CSP blocks, so avatars fall back to initials in any local
  browser pass. Pre-existing and unrelated to these surfaces.
