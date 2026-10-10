# 0075 — Training: certificates that expire, and budgets read against recorded costs

- **Status:** Accepted
- **Date:** 2026-10-11
- **Related:**
  - [0013 — Training & Development](./0013-training-and-development.md) (the module this extends);
  - [0059 — The assistant covers the whole system](./0059-assistant-covers-the-whole-system.md)
    (employee certifications, which certificates become);
  - [0074 — Employees join programs; sessions with attendance](./0074-training-self-enrolment-sessions-and-attendance.md)
    (built alongside);
  - module doc: [Training & Development](../modules/training.md).

## Context

Completing a program left a score and a date, and nothing a person could show for it.
Certifications existed — on the employee profile, typed in by hand, with an expiry
date nobody was told about. And training had no money in it at all: no budget, no
costs, and a docblock in `TrainingController::withCounts()` that still promised
"cost figures".

## Decision

### A certificate is an employee certification, tied to its enrollment

A program can **issue certificates** (`training_programs.issues_certificate`) valid
for **N months** (`certificate_validity_months`; empty means it never expires).

The certificate is a row in the existing `employee_certifications`, with three new
columns: `training_enrollment_id` (unique, cascade on delete), `certificate_no`, and
`expiry_notified_at`. We chose this over a new table so that there is one register of
what a person holds: the profile's Certifications tab, the assistant's
`find_certifications`, the data export and this module all read the same rows.

`TrainingCertificates` keeps a certificate in step with its enrollment:

- marking someone **completed** issues one — named after the program, issued by its
  provider (or the organisation), dated the day they completed on the organisation's
  clock, expiring the validity later, numbered `TC-<year>-<id>`;
- undoing the completion, or taking them off the roster, **withdraws** it;
- turning certificates on for a program issues one to everyone who already completed
  (`backfill()`); the toast says how many;
- changing a program later never changes a certificate already issued.

The profile cannot remove a training certificate, on the screen or in chat
(`EmployeeCertifications::refusal()`): it is withdrawn in Training, by changing the
enrollment.

### Its standing is derived

Never stored: **expired** after its expiry date; **expiring** within 60 days of it
(`EXPIRING_DAYS`); **valid** otherwise; and **renewed** when the same person later
earned a certificate of the same name — so a lapsed certificate that was renewed is not
a gap. Renewal is done the ordinary way: enroll the person in a program and complete
it. The register offers **Renew by enrolling again** on an expiring or expired one.

### Expiry is reminded once

`training:certificate-reminders` runs daily. Each training certificate expiring within
60 days is stamped (`expiry_notified_at`) and its holder is told once; whoever holds
`training.manage` gets one notice with the count. A renewed certificate is stamped
without a notice.

### A certificate prints

`/training/certificates/{id}` lays it out on a landscape page, printed with the app
around it hidden — no PDF library was added. HR (`training.view`) and the holder can
open it; anyone else, and any certification not from training, gets a 404.

### Money is recorded as costs and read against budgets

- `training_programs.budget` — what a program may spend.
- `training_costs` — what a program spent: a category (trainer fee, venue, materials,
  travel, meals, certification, other), an amount, the day it was incurred, and who
  recorded it.
- `training_budgets` — the organisation's training budget for a calendar year
  (unique per year).

Nothing spent is stored as a total (the derive-don't-store rule of ADR 0013).
`TrainingBudgets` adds the costs up:

- a **program**: its spend, what is left or how far over, spend per seat and per
  completion, and spend by category;
- a **year**: the costs incurred in it, archived programs included (money spent stays
  spent), by category, by month and by program, and what was given to programs that
  start in that year.

Money is in pesos, written the way the rest of the app writes it (`₱12,500.00`).

### Who sees it

The register, the schedule and the budget need `training.view`; recording costs and
setting budgets need `training.manage`. Participants see their own certificates and
never see costs or budgets.

## Consequences

- One register of certifications: a training certificate shows on the profile (marked
  as from training, with a link to print it for those who may) and in every place that
  already reads certifications.
- Reminders cover training certificates only. A licence typed in on the profile still
  shows its expiry, but nobody is told; extending the reminder to those is a small,
  separate decision.
- The assistant gains `find_training_certificates`, `get_training_budget`,
  `record_training_cost` and `set_training_budget`; program tools take the
  certificate and budget fields; `get_training_program` reads the money and the
  certificate; a person's brief lists their certificates.
- Not built: budgets per department, approval of spend, currencies other than the
  peso, and certificate templates per program.
