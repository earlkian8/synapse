# 0059 — The assistant covers the whole system: the system guide, notifications, app access, employee records, leave self-service and attendance review

- **Status:** Accepted
- **Date:** 2026-09-29
- **Extends:**
  - [0027 — Assistant employee retrieval behind a disclosure policy](./0027-assistant-employee-retrieval-and-disclosure-policy.md);
  - [0049 — The assistant assumes it will be prompt-injected](./0049-assistant-prompt-injection-defences.md);
  - [0057 — Nobody hands out access they do not hold](./0057-assistant-users-roles-activity-and-trash.md).
- **Related:** [Notifications](../modules/notifications.md#the-assistant),
  [Employees](../modules/employees.md#5c-app-access-and-records-in-the-assistant-adr-0059),
  [Leave](../modules/leave.md#self-service-and-balances-adr-0059),
  [Attendance](../modules/attendance.md#assistant).

## Context

Every screen named so far had an assistant capability. A review of every route
against every tool found what was still out of reach:

- **The system itself.** Nothing let the assistant answer "how do I invite someone?",
  "where are holidays set?", "what can I do here?", "which companies am I in?" or
  "what is left to set up?". It knew the records but not SYNAPSE.
- **Notifications:** the person's own inbox and delivery settings, and sending
  announcements.
- **App access** (Employees → App access): invitations, join requests and the company
  join code.
- **Employee records:** certifications and documents.
- **Leave:** balances on demand, setting an entitlement, and self-service. The Leave
  capability was offered only with `leave.view`, so staff, who hold only
  `leave.request`, had none.
- **Attendance review:** signing days off (one or all pending), and re-applying
  schedules and policies to a period.

The review also found defects in the screens:

- **Leave was open to staff across the board (IDOR).** The web routes gated on
  `leave.request` accepted any employee. A member of staff could:
  - file leave for a colleague, and a type without approval would auto-approve it;
  - edit a colleague's pending request;
  - cancel a colleague's pending or approved leave.

  The phone app was already confined to the caller's own record, and the Staff role's
  own description says "their own leave".
- **A notification's link could point anywhere.** The compose form accepted any
  string, and every recipient's inbox follows it. An announcement could carry a
  phishing link.
- **`Notifier::toRole()` did not confine recipients to members.** `role_user` is
  global, so a stale row could reach somebody who had left the workspace.
- **Removing a certification left no audit entry**, and its validation lived in the
  controller.
- **Missing canonical classes.** Leave entitlements, attendance sign-offs and
  re-application, and join-code changes were written in their controllers, so the
  assistant would have had to copy them. Leave review had already been copied.
- **`WorkspaceJoin::decline` wrote a garbled audit line:** "Declined Maria Santos
  request to join".
- **The assistant's Leave and Attendance modules resolved a person by "the first
  match".** "Maria" could act on either Maria.

## Decision

### 1. Knowing the system: the system guide

`App\Support\SystemGuide` is a catalogue of the real screens. For each it records:

- where it sits in the sidebar, and its address;
- who may open it (the sidebar's and the route's own permissions);
- what people do there;
- what the assistant can do there.

It is **always read for the asker.** A screen they cannot open is never described, so
the guide cannot map the parts of the system someone has no access to. It describes
the product, never its implementation.

`SystemGuideModule` offers:
- `find_help`: the screen, menu path, steps, and what chat can do;
- `get_my_access`: roles here, access by permission group, screens, and what chat
  never sees;
- `list_my_workspaces`;
- `get_setup_progress`, with `setup.company.view`: the setup steps done, skipped or
  still open.

A question like "how do I…", "where is…" or "what can you do" brings the list of
screens the asker can open before the model is called, so most answers need no tool
call.

### 2. Notifications

`NotificationsModule` is available to everyone, because the inbox is their own:
- `find_my_notifications`, `mark_my_notifications_read`;
- `get_my_notification_settings`, `set_my_notification_settings`.

Notification text is cleaned as record data. `send_notification` (`notifications.send`)
**always waits for Confirm**, whatever the audience. A message written by a model that
has read untrusted text is exactly what an injection would want to send. The card
says how many people it would reach. It never carries a link. It goes through
`Support\Notifications\Announcements`, which the compose form now uses too.

### 3. App access

`WorkspaceAccessModule` (`employees.invite`):
- **Reads:** join requests, unused invitations, employees without the app, and whether
  joining by code is on.
- **Writes:**
  - approve and decline a join request, and invite, are all confirmed;
  - revoking an invitation runs directly;
  - turning the join code on or off, or replacing it, is confirmed and needs
    `setup.company.manage`, as on the screen.

It goes through `WorkspaceJoin`, `EmployeeInvitations` and the new
`Support\Setup\JoinCodeSettings`. Two walls:
- **An invitation goes only to the address on the 201 file.** Whoever redeems it
  becomes that employee, and a redirected invitation would hand the record to a
  stranger.
- **The join code is never read out** in chat, only on the screen.

### 4. Employee records

`EmployeeRecordsModule`:
- **Certifications:**
  - read one person's, or the company's by expiry, with `employees.view`;
  - add, or remove (confirmed), with `employees.manage-documents`;
  - through the new `Support\Employees\EmployeeCertifications` and
    `StoreEmployeeCertificationRequest`, both of which the profile now uses; removal
    is audited.
- **Documents:** listed by title and type only, with `employees.manage-documents`,
  and audited as `viewed`. Files are never opened, uploaded or deleted in chat.

### 5. Leave: your own, unless you manage leave

`Support\Leave\LeaveAccess` is one rule:
- **Without `leave.manage`, you file, edit and cancel only your own leave.**
- The web request (`StoreLeaveRequestRequest`), the edit and cancel actions, and the
  assistant all ask it.

The Leave capability is offered to `leave.request` holders too:
- "me" means yourself;
- a list shows only your own requests without `leave.view`;
- `get_leave_balances` reads your own (anyone's with `leave.view`).

`set_leave_entitlement` (`leave.manage`, confirmed) goes through the new
`Support\Leave\LeaveEntitlements`, which the balances screen now uses. The card says
what would be left.

Approving or rejecting goes through the new `Support\Leave\LeaveReview`, which the
inbox's review action now uses too, against `ReviewLeaveRequestRequest`. The assistant
had its own copy of the review, and its note was never checked.

### 6. Attendance review

`Support\Attendance\AttendanceReview` holds signing one day off, signing off every
pending day (optionally one person's or a date range), and re-applying rules over a
range (62 days at most, the screen's limit). The controller now calls it. Nobody
signs off their own day.

In chat:
- `find_pending_sign_offs` reads;
- `sign_off_attendance`, `sign_off_pending_attendance` and `reapply_attendance_rules`
  are all confirmed, and the card says how much overtime a sign-off grants.

### 7. Walls fixed in passing

- **A notification's link must be an in-app path.** It starts with one `/`, with no
  scheme, no `//` or `/\`, and no spaces (`SendNotificationRequest::IN_APP_PATH`).
- **`Notifier::toRole()`** reaches current members only.
- **One person, exactly.** The assistant's Leave and Attendance modules resolve a
  person exactly (`resolveEmployee`), so an ambiguous name acts on nobody. Both
  modules now also refuse to run without a bound workspace.
- **Audit channels and wording:** `WorkspaceJoin`, `EmployeeInvitations` and the new
  support classes take `$channel`, and the decline line reads "Declined Maria Santos's
  request to join".

## Consequences

- **The assistant can answer "how do I…?" about any screen the asker can open.** It
  also covers the actions above that no module did before.
- **Staff can no longer act on colleagues' leave** through the web. Anyone relying on
  that needs `leave.manage`, which HR Managers and Department Heads have.
- **A notification link that isn't an in-app path is now refused.** The form's
  placeholder was already one.
- **Every turn is heavier.** An HR Manager's turn now offers 276 tool declarations
  (about 117 KB): 248 before this round and 28 added by it. It works, but the next
  step for cost is routing: offering only the modules a message is about, as retrieval
  already does for context.
- **Anyone with `leave.manage` can still approve their own leave**, on the screen and in
  chat alike, whereas nobody signs off their own attendance. Closing it is a policy
  choice (a company whose only approver takes leave would need a second one), so it is
  left open for the product owner.
- **Still not in chat, by design:** passwords, file uploads, the join code itself,
  emptying the trash, deleting audit entries, and import/export.
- **Open decision for the product owner.** The chat button is shown only to holders of
  certain view permissions, so Staff, who now have leave self-service, the system
  guide and their inbox in chat, do not see it. Showing it to them is a one-line
  change, weighed against the Gemini quota.

## Alternatives considered

- **Indexing the docs folder for the guide** (retrieval over `docs/`). The docs
  describe routes, classes and internal decisions, which is not for every user. They
  are also not permission-filtered. A curated, access-filtered catalogue says only
  what the asker may know.
- **Confirming only broadcasts, not messages to one person.** One message to the wrong
  person is still a message the user did not write. Every send is confirmed.
- **Letting chat read out the join code on request.** Convenient, but it would leave a
  standing credential in every transcript and with the model provider. Pointing to the
  screen costs one click.
