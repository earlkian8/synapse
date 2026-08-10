# 27. Assistant employee retrieval: live tools, not an index, behind a disclosure policy

Date: 2026-08-11

## Status

Accepted. Extends [ADR 0024](0024-agentic-recruitment-and-permission-scoped-tools.md)
(permission-scoped tools) to the employee directory, and is bounded by
[ADR 0005](0005-multi-tenancy.md) (row-level tenancy) and
[ADR 0023](0023-identity-and-organization-membership.md) (global identity,
per-organisation employment).

## Context

The assistant could act on the employee directory — create, update, archive —
but could barely answer a question about it. `find_employees` returned eight
name cards; there was no way to ask "how many people are in Engineering", "who
reports to Ana", "who is still probationary a year in", or "when do I
regularise". That is most of what anybody actually asks an HR assistant.

The obvious shape for "let the AI answer questions about my data" is retrieval
augmented generation with an embedding index. For this data it is the wrong
shape, and the reason is not performance:

- **An index is a second copy of the workforce**, sitting outside the row-level
  tenancy that the rest of the system depends on. Isolation would stop being a
  property of the query and start being a property of whatever filter we
  remembered to attach at search time. ADR 0005 exists precisely so that
  isolation is not a thing anybody has to remember.
- **HR data is small and structured.** "How many probationary hires in Support"
  is a `count(*)` with two predicates. Nearest-neighbour search over embedded
  rows answers that question worse, not better.
- **An index is stale by construction.** Somebody archived at 09:00 is still in
  the index at 09:05. For a directory, a confidently wrong answer is the
  failure mode that matters.
- **Permissions are per-user, per-tool.** An index has no view of who is asking.

## Decision

**Retrieval is function calling over live, tenant-scoped, permission-checked
queries.** Five read tools join the four existing write tools on
`EmployeeModule`: `get_employee_profile`, `list_employees`, `count_employees`,
`list_direct_reports`, and `get_my_employee_record`. Each one is an ordinary
Eloquent query, so tenancy, soft deletes and permissions apply the same way they
do to the screens.

Three rules bound it.

### 1. Tenancy is asserted, not assumed

`OrganizationScope` is deliberately a no-op when no organisation is bound, so
console and login paths can run. That makes "no tenant bound" the one state in
which an employee query would see the whole instance. Every tool in the module
refuses outright rather than running in it.

### 2. Disclosure is a deny-list on the projection

`App\Support\Employees\EmployeeDisclosure::WITHHELD` names nine columns the
assistant will not return **to anybody, at any permission level**: `tin`,
`sss_no`, `philhealth_no`, `pagibig_no`, `bank_name`, `bank_account_no`,
`basic_salary`, `address`, `birth_date`.

The reasoning is about where a tool result goes, not about who asked for it. A
result is sent to Gemini as context, persisted in the chat transcript, and
rendered on a screen that may be shared. Statutory numbers and bank details
exist for filing; pay is retained for the ML models (payroll left HR scope in
ADR 0019); home address and date of birth are personal-safety data. None of them
have an operational reason to be in a chat window, so no permission unlocks
them. They remain writable through the assistant — the user supplying their own
data is a different act from the system volunteering it — and readable in the
201 file, which is access-controlled and audited.

Bulk reads are deliberately coarser than single reads: a list row carries
placement but no contact details, and is capped at 25 rows, so enumeration is
not the cheap path.

### 3. Retrieved content is data, never instruction

Free text a person controls — names, titles, department labels — is stripped of
control characters and line breaks and length-capped before it reaches the
model, so a record cannot lay out a fake prompt turn. The system instruction
states that tool results are data.

Neither is the actual guarantee, and the ADR should say so plainly: **the
guarantee is that the model only ever asks.** Every tool re-checks the
signed-in user's permission at execution. A perfectly successful injection can
only make the assistant attempt something the user was already allowed to do.

### Self-service

`get_my_employee_record` needs no permission and resolves from the session, not
from an argument. It is the one tool available to somebody with no directory
permission at all, because "when do I regularise?" is a question about the
asker. The module is therefore available to any user who either holds
`employees.view` or has a linked roster line.

### Audit

Reading a *named individual's* profile is written to `activity_logs` as a
`viewed` event against that employee. Searches and headcounts are not — "who
looked up whom" is the question an audit asks, and logging every aggregate would
bury it.

## Consequences

- Answers cannot be stale, cannot span organisations, and cannot exceed the
  asker's permissions, because they are the same queries the screens run.
- The assistant cannot answer questions about pay or statutory numbers. This is
  intended; it should say so rather than approximate.
- Aggregates are exact but unsuppressed: a `count_employees` grouped by
  department over a department of one tells you that department has one person.
  That is already visible on the directory screen to the same permission, so no
  k-anonymity threshold was added. If the assistant ever exposes a *withheld*
  field in aggregate, that decision has to be revisited.
- A question needing a field outside the projection means widening
  `EmployeeDisclosure` — a deliberate, reviewable act with a test asserting the
  current list.

## Related findings

Building this surfaced defects in shared code that the assistant merely
inherited; they are fixed alongside it and recorded here because they are
tenancy properties, not assistant features:

- **Validation rules bypassed the tenant boundary.** `Rule::exists` and
  `Rule::unique` are raw queries that `OrganizationScope` never sees, so
  `department_id`, `position_id`, `manager_id` and `work_schedule_id` could be
  pointed at another organisation's row, and `employee_no` was globally unique
  across tenants. Both were also an existence oracle. `App\Support\TenantRule`
  pins them to the current organisation.
- **Employee numbers were derived from the shared primary key.**
  `max(id) + 1` made a new tenant's first hire `EMP-00412`.
  `App\Support\Employees\EmployeeNumbers` derives from the tenant's own series.
- **`employees.store` returned a 500 whenever no number was supplied**, because
  `validated()` omits an absent optional key and the controller read it directly.
- **The assistant endpoint had no rate limit**, while every turn spends Gemini
  quota — now 12/minute and 240/day, per user.
