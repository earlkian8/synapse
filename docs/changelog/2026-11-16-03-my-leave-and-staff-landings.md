# My leave on the web, and staff land on their own pages

A review of every screen at phone and desktop widths, as HR and as staff, found the
staff dashboard's first two shortcuts leading to pages staff may not open. Staff now have
**My leave** on the web, and the Leave and Attendance entries take them to their own.

## Highlights

- **My leave** (`/leave/me`): what's left of each leave type this year, every request
  and its status, **File leave**, change a pending one, cancel one. Filing and cancelling
  use the endpoints HR uses, held to one's own record.
- **Leave and Attendance in the sidebar for everyone who may use them.** Somebody who
  only files their own leave or clocks themselves in lands on *My leave* or *My
  attendance* instead of a 403. The dashboard shortcuts now work.
- A QR station's subtitle reads as one phrase.

## Backend (`server/`)

- `MyLeaveController`. `/leave` keeps its `can:leave.view` guard and sends a
  self-service-only user to My leave first (`SendSelfServiceToMyLeave`); `/attendance`
  sends a clock-only user to My attendance. `ShiftRosterTest` now pins that.

## Frontend

- `pages/leave/me.tsx`; `LeaveNav` gains *My leave*; the file-leave dialog files for
  oneself when there is nobody else to choose.

## Notes

- Every HR page (33) and staff page (12) was loaded at 390 px: none overflows the screen
  or throws. Tests: `MyLeaveTest` (5).
