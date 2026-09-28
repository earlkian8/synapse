# 0051 — Offboarding and Reports join the assistant; a user is picked from the workspace's members

- **Status:** Accepted
- **Date:** 2026-09-28
- **Extends:**
  - [0049 — The assistant assumes it will be prompt-injected](./0049-assistant-prompt-injection-defences.md);
  - [0050 — Training, Awards and Events](./0050-assistant-training-awards-and-events.md);
  - [0023 — Identity and organisation membership](./0023-identity-and-organization-membership.md)
    (what it means for tenancy).
- **Related:** [Offboarding](../modules/offboarding.md#the-assistant),
  [Reports](../modules/reports.md#the-assistant).

## Context

Offboarding and Reports were the last two modules without an assistant capability.

- **Offboarding is the most consequential module the assistant can reach.** Completing
  an exit changes an employee's employment status. Starting one tells the organisation
  that somebody is leaving. A bulk sign-off attests to a checklist in the user's name.
  The screens already had rules; they lived in two controllers:
  - inline validation;
  - no audit trail for half of the clearance writes;
  - a lifecycle that let a completed exit be "completed" again;
  - audit lines that read "Canceld" and "Reopend".
- **Reports are figures, not records.** The value of a report in chat is its totals.
  So is the risk: a filter the model gets wrong silently changes the numbers. The
  workspace passed any select value straight to the report's query.

Auditing both turned up a wider problem. ADR 0023 made users identities shared across
workspaces, joined to them through `organization_user`. So `users` has no
`organization_id`, and no global scope keeps a query on them inside one company.
Every place that picks a user had to ask for members of this workspace, and several
did not:

- **"Everyone" announcements** (`Notifier::toAll`) went to every active user on the
  instance, in every organisation.
- **The notification compose page** listed every active user on the instance, with
  names and emails, to anyone holding `notifications.send`. A message could be sent to
  any of them by id.
- **Validation used `exists:users,id`** for:
  - an onboarding task's assignee, who is also notified;
  - an interviewer;
  - the account an employee is linked to;
  - a notification's recipient.
- **The Onboarding and Recruitment assistant modules** resolved an assignee or
  interviewer by name across all users. They would assign and notify a stranger, and
  they confirmed that the name existed somewhere.

Tenant-row ids had the same gap. Earlier notes called the remaining unscoped `exists`
rules "harmless because the controllers re-resolve". That was wrong for:

- a clearance item's department and an offboarding template item's department;
- an onboarding program's department;
- a job posting's pipeline, department and position;
- award ids (fixed in ADR 0050).

All of these were stored raw.

## Decision

### 1. A user is picked from this workspace's members

- `TenantRule::member()` is the validation rule. It is an `exists` on
  `organization_user` for the bound organisation, and falls back to `users` when no
  tenant is bound, as `TenantRule` does. Every rule that named `users` now uses it.
- `Notifier::toAll()` means everyone *in this workspace*.
- The compose page lists members only, and the send resolves the recipient among
  members.
- `Module::resolveMember()` is the assistant's resolver. It returns an active member
  of this workspace, and exactly one: the exact name wins among several matches,
  otherwise nobody. An unknown interviewer is now an error rather than a silently
  unassigned interview.

### 2. Every other id is confined with `TenantRule`

This covers every remaining offboarding, onboarding, recruitment and role id. No
unscoped `Rule::exists` remains in a FormRequest. Each one is `TenantRule`, carries an
explicit `where('organization_id', …)`, or (an interview's stage) is constrained to the
posting's own pipeline.

### 3. Offboarding: one workflow, a guarded lifecycle, full audit

`Support\Offboarding\OffboardingWorkflow` holds every write, and the refusals are
`OffboardingException`:

- start: not twice, and not for someone who has already left;
- update;
- complete: only in progress. Cancel: only in progress. Reopen: only when closed;
- delete: the employment status is left alone;
- the clearance writes;
- a template: "already on the checklist";
- a bulk sign-off: "nothing pending".

Every write is audited, with the audit description built by a closure, never a
format string (an item may be called "100% of the kit"). The validation that was
inline became five FormRequests, and a last working day may not precede the notice.

In the assistant, **starting, completing / cancelling / reopening, deleting, bulk
sign-off and removing an item always wait for Confirm.** Reading a named person's
exit is audited as `viewed` (ADR 0027), since its reason may be a termination's.

### 4. Reports: one parameter resolver, strict for the assistant

`Support\Reports\ReportParameters` resolves raw input to a report's declared params,
for the workspace and the assistant alike:

- a select takes a declared option, by value or label;
- dates must be real dates.

It reports what it could not use. The workspace falls back to the defaults, so an old
link still opens. The assistant refuses, because "all departments" is a wrong answer
to a question about one.

`ReportsModule` offers `run_report` with the report as an enum of the keys this user
may run, and re-checks the permission at run time. A filter the report does not
declare is refused. A run returns what the workspace's AI panel is given — totals,
chart aggregates, ML signals, a few rows — plus the link that reopens it. The model
writes the analysis: one request, no second model.

`ReportInsights` treats its digest as untrusted, because applicant names come from
the public careers page. It cleans every value and accepts only plain-text answers.

## Consequences

- **The assistant now covers every workforce module**, plus reports, with no change to
  the defences of ADR 0049. Offboarding adds the most confirmation cards, deliberately.
- **Cross-workspace reach is closed** for announcements, messages, assignees,
  interviewers, linked accounts and stored foreign keys. A user of another workspace
  cannot be picked, listed, notified or confirmed to exist.
- **The screens changed where they were wrong:**
  - a completed exit cannot be completed again;
  - an exit cannot be started for someone who has left;
  - a last day cannot precede the notice;
  - the toasts read "Offboarding reopened." (before: "Offboarding clearance.");
  - clearance changes appear in the audit trail;
  - a report URL with an undeclared select value opens on the default rather than
    running with it.
- **Data already stored is not rewritten.** A task assigned, or a key saved, across
  workspaces before this change stays as it is. No such rows are expected outside
  tampered requests.

## Alternatives considered

- **A global scope on `User`.** Users are deliberately identities that span workspaces:
  sign-in, the workspace picker and invitations must see across them. A scope would
  break those, and every place that picks a user should say "members" explicitly
  anyway.
- **Letting the assistant widen a bad report filter, as the workspace does.** A chat
  answer is read as a fact. A silently widened filter is a wrong fact, and nothing on
  the page shows that it happened.
- **Confirming every offboarding write.** Signing off one item on a plain instruction
  is the common path and is attributed to the user. The confirmations sit where the
  effect is large or collective.
