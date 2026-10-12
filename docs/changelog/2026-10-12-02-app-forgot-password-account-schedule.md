# The app resets a forgotten password, edits one's own account and shows one's schedule

Three lines of `not-yet-built.md`'s mobile list are now built. The sign-in screen
has **Forgot password?**. Profile edits the person's own sign-in account, while the
employee record stays HR's. **My schedule** shows one's own week of shifts.

See ADRs
[0082](../decisions/0082-the-app-resets-a-forgotten-password-and-edits-ones-own-account.md)
and [0083](../decisions/0083-my-schedule-in-the-app.md).

## Highlights

- **Forgot password?**
  - On the app's sign-in screen, under the password. The typed address carries over.
  - The app mails the web's own reset link, valid for an hour. It opens the reset
    page on the phone; the new password then signs in to the app.
  - The answer is the same whether or not an account uses the address. *Send it again*
    waits out the minute between links.
- **Your account in the app.**
  - Profile gains **Account**: **Name and email**, and **Change password**.
  - A new sign-in email asks for the current password first, and a code is mailed to
    the new address to confirm it.
  - A new password signs the app out on the person's other phones. The phone that
    changed it stays signed in. *Forgot your current password?* mails the reset link.
  - The employee record is read-only, as before, and now says HR keeps it.
- **A new password ends the app's other sessions everywhere.** This applies whether the
  password was changed on the web's Settings → Security, in the app, or through a
  reset link. A phone whose session ended signs out, and sign-in says why.
- **My schedule.**
  - One's own week, Monday to Sunday: each day's hours, rest days, holidays (local ones
    at one's site included) and one's own leave, approved or waiting.
  - A day HR changed for that date shows **Changed**. HR's note on the change is not
    shown.
  - The week's shift count and hours; step or swipe between weeks. A day that has
    started opens its attendance record.
  - Opens from Profile, the Clock tab's shift and Search.

## Server

- New endpoints:
  - `POST /api/auth/forgot-password` (public, `throttle:6,1`). It uses Fortify's
    password broker, gives the same answer for any address, and returns 503 when mail
    fails.
  - `GET` / `PATCH /api/account` and `PUT /api/account/password`
    (`throttle:10,1` and `throttle:6,1`). `UpdateAccountRequest` needs
    `current_password` for a new email; the password reuses `PasswordUpdateRequest`.
  - `GET /api/schedule?week=` (`ShowScheduleRequest`); 403 without an employee record.
- `App\Support\Users\OwnAccount` is the one write path for a person's own account.
  It serves the web's Settings → Profile and → Security, the app's account endpoints
  and Fortify's `ResetUserPassword`.
  - A new email address is marked unconfirmed and mailed its code. The web's own email
    change now sends the code straight away.
  - A new password stamps `password_changed_at` and revokes app tokens: all of them
    from the web or a reset, all but the current one from the app.
- `App\Queries\MySchedule` builds one person's week from `ShiftResolver::forMany()`,
  `HolidayCalendar::forEmployees()` and their own pending or approved leave, on their
  site's clock. It does not send the shift's source or the override's reason.
- No migrations. Help Center: *Joining your company from the app*, *Clocking in from
  your phone*, *Your leave, events, recognition and profile in the app*, *Signing in and
  out*, *Your profile and appearance* and *Password, two-factor and passkeys*.

## Mobile

- New screens:
  - `(auth)/forgot-password`;
  - `account/details` and `account/password` (form sheets);
  - `schedule/index`.
- The sign-in screen gains *Forgot password?* and the signed-out note.
- Profile gains *My schedule*, the Account section and the employee-record note.
- The Clock tab's shift row opens My schedule.
- Search finds My schedule, Name and email and Change password.
- `lib/api.ts`:
  - gains `put`;
  - reports a 401 to a request that carried the current token. `lib/auth.tsx` then
    signs the phone out and sets `sessionEnded`.
- `components/ui/step-button.tsx`: the month/week chevron, taken out of Attendance and
  shared with My schedule.
- A `schedule` icon (`calendar.badge.clock` / `event_upcoming`).

## Docs

- ADRs 0082 and 0083.
- Module docs: mobile-app, user-management, attendance (Shift Roster) and
  work-schedule-holidays.
- `not-yet-built.md`:
  - removed: the forgot-password, profile-editing and schedule lines;
  - added: asking HR to correct the employee record from the app, the account photo in
    the app, two-factor sign-in in the app, and a month of one's schedule at a glance.

## Notes

- **Verified:** Pest (count in the commit message), Pint, and the mobile app's tsc,
  `expo lint` and iOS/Android export. The web's frontend is unchanged, so its tsc,
  ESLint, Prettier and build were not needed.
- **Checked in the app's web build** at 390 px, light and dark:
  - sign-in, both forgot-password states, Profile, both weeks of My schedule, both
    account sheets and the Clock tab;
  - saving a name, a wrong password on an email change, changing the password (the
    other session refused, this one kept), and a revoked session signing the app out
    with the note;
  - the mailed link opening the web's reset page at phone width, and a reset ending the
    app's sessions.
- A temporary copy of the app outside the repo stood in for `expo-secure-store` and
  react-native-web's focused-input call. Nothing in `mobile/` was changed for this.
- **Not checked:** the keyboard on a real phone or the emulator.
- Docker was not built or run. Gemini was never called.
