# Training in the app

People can now take part in training from the phone. They can browse the programs open
to them, join one or ask to join, follow their sessions (which now also sit on My
schedule), and see and share their certificates. HR still runs training on the web. See
[ADR 0094](../decisions/0094-training-in-the-app.md).

## Highlights

- **A new family in Services: Learning.** It has three services, each with a new
  drawn tile: **My training** (a mortarboard), **Browse** (books on a shelf) and
  **Certificates** (a sealed certificate). They are found by Search and can be pinned
  to Home.
- **My training** shows the next session on the navy card, with *Add to calendar*.
  Below it are *Coming up* (an agenda by day), *Programs* (grouped by where you stand)
  and *Certificates*.
- **Browse** lists the programs open to sign-ups, soonest first. Each card shows the
  first day as a calendar leaf, the sessions, the seats (one capsule a seat), who can
  join and the certificate. There's a search field and an *Open to join · On approval*
  filter.
- **A program** shows *Your place*: a request waiting, HR's note on a declined one,
  your attendance as one square a session, your score and your certificate. It lists
  every session with your mark or *Add to calendar*. Its one action is *Join program*,
  *Ask to join*, *Withdraw request* or *Leave program*, each confirmed first.
- **A certificate** is drawn as one: an engraved frame, your name in a book face, and
  a gold seal that comes down onto the sheet as the screen opens. An expired or
  renewed certificate wears a stamp. Its standing is given in words below, and you can
  share it.
- **My schedule** shows your training sessions under their day, at your site's hour.
  The week's line counts them.
- **Search** finds your own programs and certificates.

## Backend

- `App\Queries\MyTraining` is the one reading of a participant's training. Both the
  web's `MyTrainingController` and the new `Api\TrainingController` call it.
- New routes under `auth:sanctum` + `can:training.participate`:
  - `GET /api/training`, `GET /api/training/catalog` and
    `GET /api/training/programs/{hashid}`;
  - `POST …/join` and `DELETE /api/training/enrollments/{id}`, which go through
    `TrainingSelfService`, with the web's rules and words, logged "via the app";
  - `POST /api/training/sessions/{id}/calendar-link` and
    `GET /api/training/certificates/{id}`.
- `GET /api/documents/training-session/{session}/{user}` is public and signed. It serves
  a session's `.ics` for 10 minutes while the person still holds a seat. It was built
  the same way as the Certificate of Employment link.
- `MySchedule`: `days[].training` (converted to the person's site clock, so a late
  session can move to the next day) and `totals.training`.
- `MySearch`: a **Training** group of one's own programs and certificates, with
  renewal judged across one's certificates.
- `/api/me`: `can_train`.

## Fixes

- **Browse on the web no longer sends a program's budget or AI notes to the people
  joining it.** It used the HR program resource. It now uses the participant listing,
  and `CatalogProgram` (web types) is narrowed to match.
- **Browse is soonest first** on the web and the app. It was newest first, which put
  next month above next week.

## Mobile

- `app/training/index.tsx`, `browse.tsx`, `[id].tsx` and `certificate/[id].tsx`.
- `features/training/`: `api.ts`, `meta.ts` (the words and tones for statuses,
  standings and marks), `use-reload-on-return.ts`, `components/certificate-art.tsx`
  (`CertificateArt`, `CertificateThumb`) and `components/program-parts.tsx`
  (`SeatMeter`, `DateLeaf`).
- The services catalogue has the `learning` family (`#2E9D57`) and three services.
  The glyphs `mortarboard`, `books` and `certificate` were added, as were the system
  icons `training` and `share`.
- My schedule has training rows. Search has training and certificate targets.

## Docs

- [ADR 0094](../decisions/0094-training-in-the-app.md). The
  [Training & Development](../modules/training.md) and
  [Mobile app](../modules/mobile-app.md) module docs were updated.

## Notes

- Pest: `tests/Feature/Training/TrainingApiTest.php` (13 tests). `MyScheduleTest` has
  one new test, and its totals assertions now include `training`.
- The screens were reviewed in the web build at 390 pt in light and dark. The keyboard
  can't be seen there. Browse's search field sits in the same keyboard-aware page as the
  app's other forms, and needs a check on a phone.
