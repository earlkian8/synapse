# Assistant: the system guide, notifications, app access, employee records, leave self-service and attendance review

A review of every route against every assistant tool found what chat still could not
reach, and fills it:

- the **system itself**, covering "how do I…", "where is…", "what can I do here?",
  "which companies am I in?" and "what's left to set up?";
- your **notifications**;
- **app access**: invitations, join requests and the join code;
- **certifications and documents**;
- **leave balances and self-service**;
- **attendance sign-offs**.

The review also found staff able to file and cancel their colleagues' leave, and
notifications able to carry off-site links. Both are closed. See
[ADR 0059](../decisions/0059-assistant-covers-the-whole-system.md).

## Highlights

- **The assistant knows SYSTEM.** It says which screen does what, where it is, and
  what chat can do there, and it describes only screens the asker can open.
- **"How many vacation days do I have left?", "file sick leave for me tomorrow"** now
  work for staff too, for their own leave only.
- **These work in chat, the ones marked behind a Confirm:**
  - "Who hasn't got the app yet?";
  - "approve Pia's request as Pia Pending" (confirmed);
  - "invite Ivy to the app" (confirmed, and only to the address on file);
  - "turn off joining by code" (confirmed);
  - "whose licences expire this quarter?", "add Nora's PRC licence";
  - "sign off Ana's overtime for the 21st" (confirmed);
  - "re-apply the rules to last week" (confirmed);
  - "announce the town hall to everyone" (confirmed).

## Security fixes

- **Staff could act on colleagues' leave (IDOR).** Routes gated on `leave.request`
  accepted any employee. A member of staff could file leave for a colleague (and
  auto-approve it for a type without approval), edit a colleague's pending request, or
  cancel a colleague's approved leave. Without `leave.manage`, a user now acts on their
  own leave only. The phone app already did.
- **A notification's link could point anywhere.** It must now be a path in the app.
- **`Notifier::toRole()`** could reach someone no longer in the workspace through a
  stale role row. It now reaches current members only.
- **Removing a certification left no audit entry.** It does now.
- **The assistant's leave review was its own copy**, and its note was never checked. It
  now goes through the inbox's own path.

## Backend

- **New assistant modules:**
  - `SystemGuideModule` (on `App\Support\SystemGuide`);
  - `NotificationsModule`;
  - `WorkspaceAccessModule`;
  - `EmployeeRecordsModule`.
- **`LeaveModule`:**
  - offered to `leave.request` holders;
  - "me" means yourself, and staff see only their own requests;
  - new `get_leave_balances` and `set_leave_entitlement` (confirmed);
  - exact person resolution.
- **`AttendanceModule`:**
  - new `find_pending_sign_offs`;
  - new `sign_off_attendance`, `sign_off_pending_attendance` and
    `reapply_attendance_rules`, all confirmed;
  - exact person resolution, and a workspace check.
- **New canonical classes**, each of which its screen now uses:
  - `Support\Notifications\Announcements`;
  - `Support\Setup\JoinCodeSettings`;
  - `Support\Leave\LeaveEntitlements`;
  - `Support\Leave\LeaveAccess`;
  - `Support\Attendance\AttendanceReview`;
  - `Support\Employees\EmployeeCertifications`, with
    `StoreEmployeeCertificationRequest`;
  - `Support\Leave\LeaveReview`, with `ReviewLeaveRequestRequest` (the inbox's review
    used to validate inline).
- **Matching:**
  - a join request named exactly ("Pia Pending", or first and last name without the
    middle) is that one, even when a longer name also matches;
  - certifications of employees in the trash are neither listed nor counted.
- **Audit:**
  - `WorkspaceJoin` and `EmployeeInvitations` take an audit `$channel`;
  - the decline line now reads "Declined Maria Santos's request to join".
- **New IMPERATIVES:** decline, notify, announce, broadcast, reapply.

## Frontend

No changes. The chat button's visibility is unchanged; see Notes.

## Notes

- **Staff still don't see the chat button.** It shows only for certain view
  permissions, so staff don't see it even though chat now serves their leave, their
  inbox and the guide. Showing it to them is a one-line change, to be weighed against
  the Gemini quota.
- **Each turn is getting heavy.** An HR Manager's turn offers 276 tool declarations
  (about 117 KB), 248 of them from before this round. The next cost step is offering
  only the modules a message is about.
- **Anyone with `leave.manage` can approve their own leave**, on the screen and in chat,
  though nobody may sign off their own attendance. Closing it is a policy call (a company
  with one approver would need a second), so it is left for the product owner.
- **Two sidebar links go nowhere.** "Email & Notifications" (`/setup/notifications`) and
  "Data Backup & Export" (`/system/backup`) have no route or page and 404. The guide
  doesn't point to them.

## Verification

- **Pest:** the full suite passes (1400 tests: the previous 1378 plus 22 new).
- Pint passes.
- **Frontend:** no files changed, but tsc, ESLint, Prettier and the build all pass.
- **Headless Chromium** against a freshly seeded throwaway database:
  - `find_help` and a held `send_notification` went through the real assistant;
  - the help card was App access (`/employees/access`), and the Confirm card read "It
    would reach 2 people (everyone in this workspace)…";
  - confirming delivered "Town hall Friday" to both members, with no link, and audited
    it via assistant;
  - no console errors beyond the seeded avatars' known CSP blocks.

## Tests

- `Leave/LeaveSelfServiceTest` (5): the web IDOR, staff self-service in chat, an
  ambiguous name acting on nobody, and setting an entitlement.
- `Notification/NotificationsAssistantTest` (3): inbox, settings, confirmed sends
  reaching this workspace only, and in-app links.
- `Employee/WorkspaceAccessAssistantTest` (4): approve and decline; invitations only to
  the file address; the join code never read out.
- `Employee/EmployeeRecordsAssistantTest` (4): certifications by expiry, add and remove
  (audited on the profile too), and the document list's permission and audit.
- `Attendance/AttendanceReviewAssistantTest` (2): sign-offs one or all, never your own,
  and re-applying within 62 days.
- `Assistant/SystemGuideTest` (4): help filtered by access, your access and
  workspaces, setup progress, and every address in the guide being a real page.
- Added to the files above:
  - a chat review through the inbox's path (note checked, audited, employee told);
  - an exactly named join request;
  - a trashed employee's certification left out.
- `WorkspaceAccessAssistantTest` pins its people's middle names and suffixes. The
  factory picks them at random, which made its audit-line check pass only sometimes.
