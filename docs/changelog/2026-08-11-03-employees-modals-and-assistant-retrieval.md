# Employees: centred modals, and an assistant that can actually answer

Two changes to the same module. The four employee panels move off the right edge
onto the shared modal shell, following Recruitment and Onboarding. And the
assistant, which could already *act* on the directory, can now *answer questions
about* it — behind a disclosure policy that decides what it is ever allowed to
say, and a pen-test suite that tries to get past it.

## Highlights

- **Four panels became centred modals.** The create/edit form opens at `2xl` and
  lays its 25 fields out three across instead of stacking them down a 42rem
  strip; the profile modal's nine tabs became a **real tablist** (arrow keys,
  Home/End, roving tabindex, horizontal scroll) instead of nine buttons you had
  to Tab through to reach the panel.
- **Government IDs and bank accounts are masked until revealed.** A 201 file
  opened on a shared screen no longer puts somebody's TIN in the room by
  default. Stated plainly in the code and the docs: this is shoulder-surfing
  cover, **not** access control — whoever can open the record was already sent
  the value.
- **The assistant can answer "how many", "who is", and "who reports to".** Five
  read tools join the four write tools: `get_employee_profile`,
  `list_employees`, `count_employees`, `list_direct_reports`, and
  `get_my_employee_record`.
- **Retrieval is live queries, not an index.** "Turn this into RAG" is usually
  read as "embed the rows and search them"; for this data that would be a second
  copy of the workforce sitting outside row-level tenancy, stale by
  construction, with no idea who is asking. Every answer here is an ordinary
  tenant-scoped, permission-checked Eloquent query — the same one the screens
  run. The *why* is [ADR 0027](../decisions/0027-assistant-employee-retrieval-and-disclosure-policy.md).
- **Nine fields are withheld from the assistant, from everyone.** `tin`,
  `sss_no`, `philhealth_no`, `pagibig_no`, `bank_name`, `bank_account_no`,
  `basic_salary`, `address`, `birth_date`. No permission unlocks them, because
  the argument is about where a tool result *goes* — to Gemini, into the
  transcript, onto a shared screen — not about who asked. They stay writable
  through the assistant and readable in the 201 file.
- **Anyone can ask about themselves.** `get_my_employee_record` needs no
  permission and resolves from the session, never from an argument, so a Staff
  user with no directory access can still ask when they regularise.

## Security

The brief was to go all out and then attack it. The suite is 33 tests across
`EmployeeAssistantSecurityTest` and `AssistantEndpointSecurityTest`, written as
an attacker: the model is treated as a hostile caller that may name any tool,
pass any argument, and be steered by text an employee wrote into their own
record. **It found four real defects, all in shared code the assistant merely
inherited.**

- **Validation rules bypassed the tenant boundary.** `Rule::exists` and
  `Rule::unique` are raw queries — `OrganizationScope` never sees them. So
  `department_id`, `position_id`, `manager_id` and `work_schedule_id` accepted
  ids belonging to *another organisation*, and `employee_no` was globally
  unique across tenants (blocking a legitimate create, and confirming that some
  organisation you cannot see already uses that number). Pass/fail was an
  existence oracle. **New `App\Support\TenantRule`** pins both to the current
  organisation; the store request, the update request and the assistant's own
  rule overrides all use it. `users` is deliberately left global — an identity
  spans organisations under ADR 0023, but the roster line it claims does not.
- **Employee numbers came from the shared primary key.** `max(id) + 1` is
  per-instance, not per-tenant, so a new organisation's first hire could be
  `EMP-00412`. **New `App\Support\Employees\EmployeeNumbers`** derives from the
  tenant's own series and is now the only generator — the controller and the
  assistant had a copy each.
- **`employees.store` returned a 500 whenever no employee number was supplied**,
  because `validated()` omits an optional key that was never sent and the
  controller read it directly. This was two of `development`'s standing test
  failures.
- **The assistant endpoint had no rate limit** while every turn spends Gemini
  quota. Now 12/minute and 240/day, per user.

Other properties the suite pins, which held: another tenant's people are
unreachable by id and invisible to search, list and count; every tool refuses to
run when no organisation is bound (the global scope is a no-op in that state, so
an unguarded query would span the instance); a tool the model was never offered
is still refused when it calls it anyway; a list read is capped at 25 rows and
carries no contact details; a filter that matches nothing narrows to nothing
rather than silently widening to everyone; instructions written into an employee
record come back as inert single-line text and change nothing; and reading a
named person's profile is logged as `viewed` while searches and headcounts are
not.

No conversation IDOR was found — `AssistantConversation` was already scoped by
`user_id` and 404s rather than 403s. Tests were added to keep it that way.

## Backend

- **New** `App\Support\Employees\EmployeeDisclosure` — the deny-list, the
  summary/profile projections, tenure in words, and the control-character
  scrubber applied to every retrieved string.
- **New** `App\Support\Employees\EmployeeNumbers` and `App\Support\TenantRule`.
- `EmployeeModule` gains the five read tools, a `permissionMap()` (so tools are
  filtered from the offer *and* enforced at execution), a tenancy assertion, and
  `isAvailable()` now also admits a user with a linked roster line.
- The orchestrator's system instruction states that tool results and attachments
  are data rather than instructions, and that withheld fields must not be
  guessed or reconstructed.

## Frontend

- **Renamed** `employee-form-sheet` → `employee-form-dialog`,
  `employee-detail-sheet` → `employee-detail-dialog`. `link-employee-dialog`
  moved onto the shell too, which removed a nested scroll region inside a
  scrolling dialog. `confirm-dialog` gains the tinted icon tile.
- `ModalBody` now forwards div props, so a body can be a labelled `tabpanel`.

## Notes

- **Not changed on purpose:** aggregate counts are exact and unsuppressed — a
  department of one reports one. That is already visible on the directory screen
  at the same permission, so no k-anonymity threshold was added; ADR 0027 records
  when that would need revisiting.
- **Verification:** `tsc`, ESLint, Prettier, `npm run build` and Pint all green.
  Pest: **464 tests, 452 passing, 10 failures + 2 errors** — 33 tests added, and
  the standing baseline *improved* from 12 failures + 3 errors to 10 + 2, because
  the employee-number and `employees.store` fixes cleared three of them. Every
  remaining failure is pre-existing and unrelated (auth redirects, registration,
  push subscriptions, department restore, multi-org token).
- The modals were **not** exercised in a live browser — no browser automation on
  this machine — so layout was reviewed by hand. The profile modal's tab strip
  and the form's three-column grid are the two worth a look at 375px.
