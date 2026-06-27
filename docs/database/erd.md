# SYNAPSE — Entity Relationship Diagram

> **Status: DRAFT for review.** This is the proposed data model for the whole
> system, derived from the capstone proposal and the application navigation. Built
> so far: `users`, `activity_logs`, the RBAC tables, `notifications`, the
> **Employee core** + organisation lookups (`departments`, `positions`,
> `work_schedules`, `employees` + sub-records — see
> [Employees](../modules/employees.md) and [ADR 0004](../decisions/0004-employee-user-separation.md)),
> the **multi-tenancy** layer (`organizations` + `organization_id` everywhere — see
> [ADR 0005](../decisions/0005-multi-tenancy.md)), and **Recruitment**
> (`job_postings`, `applicants`, `job_applications`, `interviews`, with the hire →
> employee bridge — see [Recruitment](../modules/recruitment.md) and
> [ADR 0006](../decisions/0006-recruitment-ats-and-hire-bridge.md)), and **Onboarding**
> (`onboarding_programs`, `onboarding_program_tasks`, `onboarding_cases`,
> `onboarding_tasks`, auto-started by the hire bridge — see
> [Onboarding](../modules/onboarding.md) and
> [ADR 0007](../decisions/0007-onboarding-template-bridge.md)).
> Review the **Design decisions** and **Open questions** sections first — a few
> choices shape everything downstream.

---

## Conventions

- **Tables**: `snake_case`, plural. **Diagrams** below use `UPPER_SNAKE`, singular.
- **PK**: `id` (bigint). **FK**: `<singular>_id`.
- **Timestamps**: every table has `created_at` / `updated_at`.
- **Soft deletes**: `deleted_at` on archivable business records (employees, most
  operational records) — never hard-delete HR data by default.
- **Money**: `decimal(12,2)`. **Flexible/derived data**: `json`.
- **Enums** shown as `enum(...)` are stored as short strings.

### Actor vs. Subject FK convention

- **Actor columns** — *who performed an action* (`*_by`, `approver_id`,
  `evaluator_id`, `interviewer_id`, `organizer_id`) → reference **`users`** (you must
  be an authenticated account to act).
- **Subject / org-chart columns** — *the person being managed* (`employee_id`,
  `manager_id`, `head_id`) → reference **`employees`**.

---

## Design decisions (please confirm)

1. ~~**Single-tenant deployment.**~~ **Superseded — SYNAPSE is now multi-tenant**
   ([ADR 0005](../decisions/0005-multi-tenancy.md)). Every registration creates an
   `organizations` row (the tenant, and also the company profile — there is no separate
   `company_profiles` singleton). Tenant-owned tables carry a non-null `organization_id`
   and are isolated by a global query scope. One user belongs to one organisation.
2. **`Employee` is separate from `User`.** `employees.user_id` is a **nullable,
   unique** FK to `users`. An Employee is the HR record (a DTR-only field worker may
   have no login); a User is an auth account (an IT admin may not be an employee).
   *This is the pivotal choice — see Open questions.*
3. **RBAC** uses `roles` + `permissions` + pivots (shape mirrors
   `spatie/laravel-permission` so we can adopt the package later without a schema
   change).
4. **ML inference is persisted.** The FastAPI service writes results into
   `attrition_predictions`, `performance_forecasts`, and
   `promotion_readiness_assessments`; the Analytics dashboard only *reads* them. The
   Laravel app never runs models inline.
5. **Company Setup tables are the configuration layer** (leave types, deduction
   types, KPI criteria, schedules, holidays, award types) that the operational
   modules reference — not hard-coded.

---

## 1. System & Access Control

Auth accounts, roles/permissions, audit trail, and backups.

```mermaid
erDiagram
    USER ||--o{ ROLE_USER : has
    ROLE ||--o{ ROLE_USER : assigned
    ROLE ||--o{ PERMISSION_ROLE : grants
    PERMISSION ||--o{ PERMISSION_ROLE : in
    USER ||--o{ ACTIVITY_LOG : causes
    USER ||--o{ BACKUP : initiated

    USER {
        bigint id PK
        string first_name
        string middle_name
        string last_name
        string suffix
        string email UK
        string password
        string phone_number
        string profile_photo
        string employee_id "legacy label; HR link is employees.user_id"
        boolean is_active
        datetime email_verified_at
        datetime last_login_at
        datetime deleted_at
    }
    ROLE {
        bigint id PK
        string name UK
        string label
        string description
    }
    PERMISSION {
        bigint id PK
        string name UK "e.g. employees.view"
        string group
    }
    ROLE_USER {
        bigint role_id FK
        bigint user_id FK
    }
    PERMISSION_ROLE {
        bigint permission_id FK
        bigint role_id FK
    }
    ACTIVITY_LOG {
        bigint id PK
        string log_name
        string event
        text description
        bigint causer_id FK
        string subject_type
        bigint subject_id
        json properties
        string ip_address
    }
    BACKUP {
        bigint id PK
        string disk
        string path
        string type "database|files|full"
        bigint size_bytes
        bigint created_by FK
        datetime completed_at
    }
```

---

## 2. Organization & Company Setup

The configuration layer most modules read from.

```mermaid
erDiagram
    DEPARTMENT ||--o{ DEPARTMENT : parent_of
    DEPARTMENT ||--o{ POSITION : defines
    DEPARTMENT ||--o{ EMPLOYEE : employs
    WORK_SCHEDULE ||--o{ EMPLOYEE : assigned

    COMPANY_PROFILE {
        bigint id PK
        string name
        string legal_name
        string logo
        string email
        string phone
        text address
        string tin
        string sss_employer_no
        string philhealth_employer_no
        string pagibig_employer_no
    }
    DEPARTMENT {
        bigint id PK
        string name
        string code "unique per tenant"
        bigint parent_id FK "self"
        bigint head_id FK "employees"
        text description
        datetime deleted_at
    }
    POSITION {
        bigint id PK
        string title
        bigint department_id FK
        decimal salary_grade_min
        decimal salary_grade_max
        text description
    }
    WORK_SCHEDULE {
        bigint id PK
        string name
        time start_time
        time end_time
        json work_days "Mon..Sun"
        int grace_minutes
        decimal required_hours
    }
    HOLIDAY {
        bigint id PK
        string name
        date date
        enum type "regular|special_non_working|special_working"
        boolean is_recurring
    }
    LEAVE_TYPE {
        bigint id PK
        string name
        boolean is_paid
        decimal default_days
        boolean carries_over
    }
    AWARD_TYPE {
        bigint id PK
        string name
        text description
    }
    KPI_CRITERION {
        bigint id PK
        string name
        text description
        decimal weight
        boolean is_active
    }
    EVALUATION_PERIOD {
        bigint id PK
        string name
        date start_date
        date end_date
        enum status "draft|open|closed"
    }
    ALLOWANCE_TYPE {
        bigint id PK
        string name
        boolean is_taxable
    }
    DEDUCTION_TYPE {
        bigint id PK
        string name
        enum kind "sss|philhealth|pagibig|withholding_tax|loan|other"
        boolean is_mandatory
        json computation "table/rate config"
    }
    EMAIL_TEMPLATE {
        bigint id PK
        string key UK
        string subject
        text body
        boolean is_enabled
    }
```

> `COMPANY_PROFILE`, `HOLIDAY`, `LEAVE_TYPE`, `AWARD_TYPE`, `KPI_CRITERION`,
> `EVALUATION_PERIOD`, `ALLOWANCE_TYPE`, `DEDUCTION_TYPE`, `EMAIL_TEMPLATE` are
> standalone config tables referenced by the operational modules below.
>
> **Built:** `LEAVE_TYPE`, `KPI_CRITERION` +
> `EVALUATION_PERIOD` (managed at `/setup/kpi` — see
> [Performance](../modules/performance.md)), and `AWARD_TYPE` (managed at
> `/setup/award-types` — see [Awards](../modules/awards.md)).
> **`ALLOWANCE_TYPE` / `DEDUCTION_TYPE` were removed** with Payroll — see
> [ADR 0019](../decisions/0019-remove-payroll-and-benefits.md).
>
> **`COMPANY_PROFILE` is the `organizations` row itself** — not a separate table. Its
> fields (`legal_name`, `logo`, `email`, `phone`, `address`, `tin`, `*_employer_no`) live
> on `organizations` and are edited at `/setup/company` (ADR 0005 — the tenant doubles as
> the company profile). See [Company Profile](../modules/company-profile.md).
>
> **Built:** `WORK_SCHEDULE` + `HOLIDAY` (managed together at `/setup/schedule`; `holidays`
> is new, `work_schedules` gained soft deletes + a management UI, and a non-working holiday
> is no longer charged as a leave day) — see
> [Work Schedule & Holidays](../modules/work-schedule-holidays.md).

---

## 3. Employee Core (the hub)

Almost everything references `EMPLOYEE`.

```mermaid
erDiagram
    USER ||--o| EMPLOYEE : "logs in as"
    DEPARTMENT ||--o{ EMPLOYEE : in
    POSITION ||--o{ EMPLOYEE : holds
    EMPLOYEE ||--o{ EMPLOYEE : manages
    EMPLOYEE ||--o{ EMPLOYEE_PROMOTION : promoted
    EMPLOYEE ||--o{ EMPLOYEE_CERTIFICATION : earns
    EMPLOYEE ||--o{ EMPLOYEE_ALLOWANCE : receives
    EMPLOYEE ||--o{ EMPLOYEE_DEDUCTION : owes
    EMPLOYEE ||--o{ EMPLOYEE_DOCUMENT : owns

    EMPLOYEE {
        bigint id PK
        bigint user_id FK "nullable, unique"
        string employee_no UK
        string first_name
        string middle_name
        string last_name
        string suffix
        date birth_date
        enum gender
        enum civil_status
        string email
        string phone
        text address
        string photo
        bigint department_id FK
        bigint position_id FK
        bigint manager_id FK "self"
        bigint work_schedule_id FK
        enum employment_type "regular|probationary|contractual|part_time"
        enum employment_status "active|on_leave|suspended|resigned|terminated"
        date date_hired
        date date_regularized
        decimal basic_salary
        string bank_name
        string bank_account_no
        string tin
        string sss_no
        string philhealth_no
        string pagibig_no
        datetime deleted_at
    }
    EMPLOYEE_PROMOTION {
        bigint id PK
        bigint employee_id FK
        bigint from_position_id FK
        bigint to_position_id FK
        decimal from_salary
        decimal to_salary
        date effective_date
        text reason
        bigint approved_by FK "users"
    }
    EMPLOYEE_CERTIFICATION {
        bigint id PK
        bigint employee_id FK
        string name
        string issuer
        date issued_date
        date expiry_date
        string file
    }
    EMPLOYEE_ALLOWANCE {
        bigint id PK
        bigint employee_id FK
        bigint allowance_type_id FK "nullable"
        decimal amount
        boolean is_active
    }
    EMPLOYEE_DEDUCTION {
        bigint id PK
        bigint employee_id FK
        bigint deduction_type_id FK "nullable; e.g. a loan"
        decimal amount
        boolean is_active
    }
    EMPLOYEE_DOCUMENT {
        bigint id PK
        bigint employee_id FK
        string title
        string type "contract|cv|govt_id|other"
        string file
        bigint uploaded_by FK "users"
    }
```

---

## 4. Recruitment & Onboarding (Talent Acquisition)

```mermaid
erDiagram
    JOB_POSTING ||--o{ JOB_APPLICATION : receives
    APPLICANT ||--o{ JOB_APPLICATION : submits
    JOB_APPLICATION ||--o{ INTERVIEW : schedules
    JOB_APPLICATION ||--o| EMPLOYEE : "hired becomes"
    DEPARTMENT ||--o{ JOB_POSTING : for
    EMPLOYEE ||--o| ONBOARDING_CASE : onboards
    ONBOARDING_PROGRAM ||--o{ ONBOARDING_PROGRAM_TASK : blueprints
    ONBOARDING_PROGRAM ||--o{ ONBOARDING_CASE : seeds
    ONBOARDING_CASE ||--o{ ONBOARDING_TASK : checklist

    JOB_POSTING {
        bigint id PK
        string title
        bigint department_id FK
        bigint position_id FK
        text description
        text requirements
        enum employment_type
        int openings
        enum status "draft|open|closed|filled"
        date closing_date
        bigint posted_by FK "users"
    }
    APPLICANT {
        bigint id PK
        string first_name
        string last_name
        string email
        string phone
        string resume_file
        string source
    }
    JOB_APPLICATION {
        bigint id PK
        bigint job_posting_id FK
        bigint applicant_id FK
        enum stage "applied|screening|interview|offer|hired|rejected"
        bigint hired_employee_id FK "nullable"
        datetime applied_at
    }
    INTERVIEW {
        bigint id PK
        bigint job_application_id FK
        bigint interviewer_id FK "users"
        datetime scheduled_at
        enum mode "onsite|online|phone"
        text notes
        enum result "pending|passed|failed"
    }
    ONBOARDING_PROGRAM {
        bigint id PK
        string name
        bigint department_id FK "nullable"
        string employment_type "nullable"
        boolean is_default
        boolean is_active
    }
    ONBOARDING_PROGRAM_TASK {
        bigint id PK
        bigint onboarding_program_id FK
        string title
        string category
        int due_offset_days "days after start"
        int sort_order
    }
    ONBOARDING_CASE {
        bigint id PK
        bigint employee_id FK "unique"
        bigint onboarding_program_id FK "nullable"
        enum status "pending|in_progress|completed|cancelled"
        date start_date
        date target_end_date
        datetime completed_at
    }
    ONBOARDING_TASK {
        bigint id PK
        bigint onboarding_case_id FK
        string title
        string category
        bigint assigned_to FK "users"
        date due_date
        enum status "pending|in_progress|done|skipped"
        datetime completed_at
        bigint completed_by FK "users"
    }
```

---

## 5. Time & Attendance

DTR app + bulk-import fallback. Source of overtime/lateness features for ML.

```mermaid
erDiagram
    EMPLOYEE ||--o{ ATTENDANCE : records
    ATTENDANCE_IMPORT_BATCH ||--o{ ATTENDANCE : imports

    ATTENDANCE {
        bigint id PK
        bigint employee_id FK
        date work_date
        datetime time_in
        datetime time_out
        decimal hours_worked
        decimal overtime_hours
        decimal late_minutes
        decimal undertime_minutes
        enum status "present|late|absent|on_leave|holiday|rest_day"
        enum source "dtr|import|manual"
        decimal in_latitude
        decimal in_longitude
        text remarks
    }
    ATTENDANCE_IMPORT_BATCH {
        bigint id PK
        string file
        int row_count
        enum status "pending|processed|failed"
        bigint uploaded_by FK "users"
        datetime processed_at
    }
```

---

## 6. Leave Management

**Built** — leave types live in Company Setup; requests + balances in the Workforce
module. **Balances store only the entitlement**; used / pending / remaining are *derived*
from requests (never stored), so they cannot drift. See
[Leave](../modules/leave.md), [leave tables](../database/leave-tables.md) and
[ADR 0009](../decisions/0009-leave-management.md).

```mermaid
erDiagram
    EMPLOYEE ||--o{ LEAVE_REQUEST : files
    LEAVE_TYPE ||--o{ LEAVE_REQUEST : categorizes
    EMPLOYEE ||--o{ LEAVE_BALANCE : holds
    LEAVE_TYPE ||--o{ LEAVE_BALANCE : tracks

    LEAVE_TYPE {
        bigint id PK
        bigint organization_id FK
        string name
        string code "unique per tenant"
        string color
        decimal default_days
        boolean is_paid
        boolean allow_half_day
        boolean requires_approval
        boolean is_active
        datetime deleted_at "soft delete"
    }
    LEAVE_REQUEST {
        bigint id PK
        bigint organization_id FK
        bigint employee_id FK
        bigint leave_type_id FK "restrict on delete"
        date start_date
        date end_date
        decimal days "working days, server-computed"
        boolean is_half_day
        string half_day_period "morning|afternoon, nullable"
        text reason
        enum status "pending|approved|rejected|cancelled"
        bigint filed_by FK "users"
        bigint reviewed_by FK "users"
        datetime reviewed_at
        text review_note
    }
    LEAVE_BALANCE {
        bigint id PK
        bigint organization_id FK
        bigint employee_id FK
        bigint leave_type_id FK
        int year
        decimal entitled_days "entitlement only — used/remaining derived"
    }
```

---

## 7. Payroll & Benefits

> **Removed (2026-06-27).** The Payroll and Benefits modules were taken out as out of
> scope for HR management — see [ADR 0019](../decisions/0019-remove-payroll-and-benefits.md).
> All of `payroll_periods`, `payslips`, `payslip_earnings`, `payslip_deductions`,
> `allowance_types`, `deduction_types`, `employee_allowances`, `employee_deductions`,
> `benefit_plans`, `benefit_enrollments` and `benefit_contributions` and their code/UI
> are gone. The employee's own compensation fields (`employees.basic_salary`, bank
> details, government-ID numbers) **stay** on the employee record (§3) as HR master data
> and feed the ML `salary` feature. The original proposed shape is kept below for
> reference only.

```mermaid
erDiagram
    PAYROLL_PERIOD ||--o{ PAYSLIP : contains
    EMPLOYEE ||--o{ PAYSLIP : paid
    PAYSLIP ||--o{ PAYSLIP_EARNING : has
    PAYSLIP ||--o{ PAYSLIP_DEDUCTION : has
    ALLOWANCE_TYPE ||--o{ PAYSLIP_EARNING : typed
    DEDUCTION_TYPE ||--o{ PAYSLIP_DEDUCTION : typed
    BENEFIT_PLAN ||--o{ BENEFIT_ENROLLMENT : offers
    EMPLOYEE ||--o{ BENEFIT_ENROLLMENT : enrolled
    EMPLOYEE ||--o{ BENEFIT_CONTRIBUTION : contributes
    PAYROLL_PERIOD ||--o{ BENEFIT_CONTRIBUTION : sources

    PAYROLL_PERIOD {
        bigint id PK
        string name
        date start_date
        date end_date
        date pay_date
        enum status "draft|processing|finalized|paid"
        bigint processed_by FK "users"
    }
    PAYSLIP {
        bigint id PK
        bigint payroll_period_id FK
        bigint employee_id FK
        decimal basic_pay
        decimal overtime_pay
        decimal gross_pay
        decimal total_earnings
        decimal total_deductions
        decimal net_pay
        decimal days_worked
        enum status "draft|released"
        boolean is_adjusted "lines hand-edited; re-process skips it"
    }
    PAYSLIP_EARNING {
        bigint id PK
        bigint payslip_id FK
        bigint allowance_type_id FK "nullable"
        string label
        decimal amount
    }
    PAYSLIP_DEDUCTION {
        bigint id PK
        bigint payslip_id FK
        bigint deduction_type_id FK "nullable"
        string label
        decimal amount
    }
    BENEFIT_PLAN {
        bigint id PK
        string name
        enum category "hmo|insurance|retirement|wellness|other"
        string provider "nullable"
        text description "nullable"
        decimal employee_cost
        decimal employer_cost
        enum frequency "monthly|quarterly|annual|one_time"
        boolean is_active
        datetime deleted_at "soft delete"
    }
    BENEFIT_CONTRIBUTION {
        bigint id PK
        bigint employee_id FK
        bigint payroll_period_id FK "nullable"
        string period "YYYY-MM"
        enum benefit "sss|philhealth|pagibig"
        decimal employee_share
        decimal employer_share
        decimal total
    }
    BENEFIT_ENROLLMENT {
        bigint id PK
        bigint benefit_plan_id FK
        bigint employee_id FK
        enum status "active|pending|waived|terminated"
        string reference_no "nullable; member/policy no"
        date enrolled_on
        date ended_on "nullable"
        text notes "nullable"
    }
```

---

## 8. Performance & Training

Primary ML feature sources (KPI scores, training participation).

**Built (Performance)** — `kpi_criteria` + `evaluation_periods` (Company-Setup config,
ERD §2) and `performance_evaluations` + `performance_scores`. Evaluations score an
employee against the weighted criteria within a period; the **`overall_score` is
derived** (weighted average, `App\Support\Performance\PerformanceScorer`) through a
`draft → submitted → acknowledged` lifecycle, and each score line **snapshots** the
criterion's label + weight so archiving a criterion never alters a past appraisal
(added `acknowledged_at`). See [Performance](../modules/performance.md),
[performance tables](../database/performance-tables.md) and
[ADR 0012](../decisions/0012-performance-management.md).

**Built (Training)** — `training_programs` + `training_enrollments`. Programs are
created in-module (no Company-Setup config); a program's lifecycle status
(`upcoming → ongoing → completed`) is **derived from its date window**, not stored, and
enrollments carry a status, a completion score and a server-managed `completed_at`
(`end_date` / `capacity` are nullable for open-ended / uncapped programs; added
`remarks`). See [Training](../modules/training.md),
[training tables](../database/training-tables.md) and
[ADR 0013](../decisions/0013-training-and-development.md).

```mermaid
erDiagram
    EMPLOYEE ||--o{ PERFORMANCE_EVALUATION : evaluated
    EVALUATION_PERIOD ||--o{ PERFORMANCE_EVALUATION : during
    PERFORMANCE_EVALUATION ||--o{ PERFORMANCE_SCORE : breaks_down
    KPI_CRITERION ||--o{ PERFORMANCE_SCORE : scored_on
    TRAINING_PROGRAM ||--o{ TRAINING_ENROLLMENT : enrolls
    EMPLOYEE ||--o{ TRAINING_ENROLLMENT : attends

    PERFORMANCE_EVALUATION {
        bigint id PK
        bigint employee_id FK
        bigint evaluation_period_id FK
        bigint evaluator_id FK "users"
        decimal overall_score
        enum status "draft|submitted|acknowledged"
        datetime submitted_at
        text remarks
    }
    PERFORMANCE_SCORE {
        bigint id PK
        bigint performance_evaluation_id FK
        bigint kpi_criterion_id FK
        decimal score
        decimal weight
        text remarks
    }
    TRAINING_PROGRAM {
        bigint id PK
        string name
        text description
        string provider
        date start_date
        date end_date
        int capacity
    }
    TRAINING_ENROLLMENT {
        bigint id PK
        bigint training_program_id FK
        bigint employee_id FK
        enum status "enrolled|completed|dropped"
        decimal score
        datetime completed_at
    }
```

---

## 9. Awards, Events & Offboarding

**Built (Awards)** — `award_types` (Company-Setup catalogue, ERD §2; added a `color`
+ `is_active`) and `employee_awards`. Recognitions are a flat feed at `/awards`; an
award's type relation is loaded `withTrashed` so archived types still render on past
awards. See [Awards](../modules/awards.md),
[awards tables](../database/awards-tables.md) and
[ADR 0014](../decisions/0014-awards-and-recognition.md).

**Built (Events)** — `events` + `event_attendees`. Events are created in-module (no
Company-Setup config); an event's lifecycle status (`upcoming → ongoing → past`) is
**derived from its date-time window**, not stored, and inviting an employee with a
linked account notifies them and stamps `notified_at` (added soft-deletes; `ends_at`
is nullable for a point-in-time entry). See [Events](../modules/events.md),
[events tables](../database/events-tables.md) and
[ADR 0015](../decisions/0015-events-and-meetings.md).

**Built (Offboarding)** — `offboarding_cases` + `clearance_items`. Mirrors Onboarding
(a per-employee case + a checklist), but the checklist is a **clearance grouped by
responsible department** and the case-level `clearance_status` is **derived** from the
items (`pending → in_progress → cleared`), not stored. A deliberate lifecycle
(`initiated → clearance → completed`, plus `cancelled`); completing the exit
**transitions the employee's `employment_status`** (added `completed_at`; `remarks` +
`sort_order` on items). See [Offboarding](../modules/offboarding.md),
[offboarding tables](../database/offboarding-tables.md) and
[ADR 0016](../decisions/0016-offboarding-and-clearance.md).

```mermaid
erDiagram
    EMPLOYEE ||--o{ EMPLOYEE_AWARD : receives
    AWARD_TYPE ||--o{ EMPLOYEE_AWARD : categorizes
    EVENT ||--o{ EVENT_ATTENDEE : invites
    EMPLOYEE ||--o{ EVENT_ATTENDEE : attends
    EMPLOYEE ||--o| OFFBOARDING_CASE : exits
    OFFBOARDING_CASE ||--o{ CLEARANCE_ITEM : requires

    EMPLOYEE_AWARD {
        bigint id PK
        bigint employee_id FK
        bigint award_type_id FK
        date awarded_on
        text reason
        bigint awarded_by FK "users"
    }
    EVENT {
        bigint id PK
        string title
        text description
        enum type "event|meeting"
        datetime starts_at
        datetime ends_at
        string location
        bigint organizer_id FK "users"
    }
    EVENT_ATTENDEE {
        bigint id PK
        bigint event_id FK
        bigint employee_id FK
        enum response "invited|accepted|declined|tentative"
        datetime notified_at
    }
    OFFBOARDING_CASE {
        bigint id PK
        bigint employee_id FK
        enum type "resignation|termination|retirement|end_of_contract"
        date notice_date
        date last_working_day
        text reason
        enum status "initiated|clearance|completed"
        enum clearance_status "pending|in_progress|cleared"
    }
    CLEARANCE_ITEM {
        bigint id PK
        bigint offboarding_case_id FK
        string item
        bigint department_id FK
        enum status "pending|cleared|flagged"
        bigint cleared_by FK "users"
        datetime cleared_at
    }
```

---

## 10. Analytics & AI — ML inference outputs

Written by the FastAPI ML service, read by the dashboard. Each row is one
prediction snapshot (kept historically so trends can be charted).

**Built (Promotion Readiness)** — a standalone **FastAPI inference service**
(`model/api`) the Laravel app calls server-side (`App\Support\Ml\MlClient`), plus
`promotion_readiness_runs` + `promotion_readiness_scores` (a header-plus-lines
batch). Scores are model-derived, never entered; the service is optional (graceful
degradation when offline). See [Promotion Readiness](../modules/promotion-readiness.md)
and [ADR 0017](../decisions/0017-predictive-analytics-and-ml-inference.md).

**Built (Performance Forecast)** — `performance_forecast_runs` +
`performance_forecasts`, the second surface on the same service. Maps the ERD's
`predicted_rating` / `confidence` / `features` / `target_period_id` onto the proven
header-plus-lines shape (recording the model as a `model_version` string in place of
`ml_model_id`, as Promotion Readiness does). The regressor's lack of factor
contributions is covered by a Laravel-derived **band** + **confidence** (data
coverage) and a **rating trajectory**. See
[Performance Forecast](../modules/performance-forecast.md) and
[ADR 0018](../decisions/0018-performance-forecasting.md).

**Built (Attrition Risk)** — `attrition_risk_runs` + `attrition_risk_scores`, the
third surface on the same service. Maps the ERD's `ATTRITION_PREDICTION`
(`risk_score` → `probability`/`score`, `risk_level` → `tier`, `features`) onto the
proven header-plus-lines shape (model recorded as a `model_version` string). Two
intended deviations: the model is a **Random Forest** that exposes no per-instance
contributions, so the ERD's `factors` is **dropped** in favour of a Laravel-derived
**confidence** (data coverage) + the grounded feature snapshot — matching the
Performance Forecast precedent; and it is trained on only the **ERP-servable** subset
of columns so its inputs match what the HR system can supply at inference. See
[Attrition Risk](../modules/attrition-risk.md) and
[ADR 0021](../decisions/0021-attrition-risk.md).

```mermaid
erDiagram
    EMPLOYEE ||--o{ ATTRITION_PREDICTION : scored
    EMPLOYEE ||--o{ PERFORMANCE_FORECAST : forecast
    EMPLOYEE ||--o{ PROMOTION_READINESS : assessed
    ML_MODEL ||--o{ ATTRITION_PREDICTION : produced
    ML_MODEL ||--o{ PERFORMANCE_FORECAST : produced
    ML_MODEL ||--o{ PROMOTION_READINESS : produced

    ML_MODEL {
        bigint id PK
        string name
        enum type "attrition|performance|promotion"
        string algorithm "random_forest|gradient_boosting|logistic_regression"
        string version
        json metrics "accuracy/precision/recall/f1"
        datetime trained_at
        boolean is_active
    }
    ATTRITION_PREDICTION {
        bigint id PK
        bigint employee_id FK
        bigint ml_model_id FK
        decimal risk_score "0..1"
        enum risk_level "low|medium|high"
        json factors "feature contributions"
        datetime predicted_at
    }
    PERFORMANCE_FORECAST {
        bigint id PK
        bigint employee_id FK
        bigint ml_model_id FK
        bigint target_period_id FK "evaluation_periods"
        decimal predicted_rating
        decimal confidence
        json features
        datetime predicted_at
    }
    PROMOTION_READINESS {
        bigint id PK
        bigint employee_id FK
        bigint ml_model_id FK
        decimal readiness_score "0..1"
        enum recommendation "not_ready|developing|ready"
        json factors
        datetime assessed_at
    }
    GENERATED_REPORT {
        bigint id PK
        string title
        string type
        json parameters
        string file
        bigint generated_by FK "users"
    }
```

---

## 11. Assistant — LLM conversations & document processing

```mermaid
erDiagram
    USER ||--o{ ASSISTANT_CONVERSATION : starts
    ASSISTANT_CONVERSATION ||--o{ ASSISTANT_MESSAGE : contains
    USER ||--o{ DOCUMENT_EXTRACTION : uploads

    ASSISTANT_CONVERSATION {
        bigint id PK
        bigint user_id FK
        string title
        datetime last_message_at
    }
    ASSISTANT_MESSAGE {
        bigint id PK
        bigint conversation_id FK
        enum role "user|assistant|tool"
        text content
        json tool_calls "function-calling payloads"
        json tool_result
    }
    DOCUMENT_EXTRACTION {
        bigint id PK
        bigint user_id FK
        string file
        string document_type "cv|form|other"
        enum status "pending|extracted|confirmed|discarded"
        json extracted_data
        string target_module "e.g. employees"
        bigint confirmed_record_id "nullable"
    }
```

> The LLM **function-calling layer does not get its own action tables** — it invokes
> the existing module controllers/actions (create employee, file leave, etc.) and
> those writes are captured in `activity_logs` like any other action. Conversations
> and document extractions are the only LLM-specific persistence.

---

## Central relationships at a glance

- **`EMPLOYEE`** is the hub: Department, Position, Manager (self), Work Schedule →
  and is referenced by Attendance, Leave, Payslip, Performance, Training, Awards,
  Events, Offboarding, Promotions, Certifications, and all three ML output tables.
- **`USER`** is the actor: it owns auth, roles/permissions, audit (`activity_logs`),
  assistant conversations, and is the FK for every `*_by` / approver / evaluator
  column. Linked 1:1 (optional) to an Employee.
- **Company Setup** tables are the lookups every operational module depends on.

---

## Build order implied by this ERD

1. **System**: User Management ✓, Activity Logs ✓ → **Roles & Permissions** (next in System).
2. **Foundation**: Company Profile, **Departments**, Positions, Work Schedules → **Employees** (+ the User↔Employee link).
3. **Operational** (generate ML features): **Leave ✓** (see [Leave](../modules/leave.md)), **Attendance/DTR ✓**, ~~Payroll~~ / ~~Benefits~~ (removed — [ADR 0019](../decisions/0019-remove-payroll-and-benefits.md)), **Performance ✓** (see [Performance](../modules/performance.md)), **Training ✓** (see [Training](../modules/training.md)), **Awards ✓** (see [Awards](../modules/awards.md)), **Events ✓** (see [Events](../modules/events.md)).
4. **Talent**: Recruitment ✓, Onboarding ✓, **Offboarding ✓** (see [Offboarding](../modules/offboarding.md)).
5. **Company Setup**: Departments & positions ✓ (org structure — see [Departments](../modules/departments.md)), **Leave Types ✓**; the rest of the config layer follows.
6. **Intelligence**: FastAPI ML service → prediction tables → Analytics dashboard.
7. **Assistant**: LLM conversations, function-calling, document processor.

---

## Open questions for your review

1. ~~**Employee ↔ User**~~ — **Resolved: separate**, linked 1:1 (optional) — see
   [ADR 0004](../decisions/0004-employee-user-separation.md).
2. ~~**Single-tenant** vs. multi-organization~~ — **Resolved: multi-tenant**, single
   database with row-level `organization_id` scoping — see
   [ADR 0005](../decisions/0005-multi-tenancy.md).
3. **`employees.employee_no`** is the canonical HR id; the existing
   `users.employee_id` string column becomes redundant — drop it once Employees exist?
4. **Approvers/evaluators as `users`** (current convention) vs. as `employees` — OK?
5. ~~**Benefit contributions**: standalone table vs. derived from payslip
   deductions~~ — **Resolved:** kept **`benefit_contributions`** but generated it
   from the run's statutory deductions (adding the **employer** share the payslips
   lack), and added **`benefit_plans` + `benefit_enrollments`** for benefit-program
   administration — see [ADR 0011](../decisions/0011-benefits-administration.md).
6. ~~**Recruitment applicants** are *not* users/employees until hired~~ — **Resolved:
   standalone `applicants` table** (a reusable candidate pool); a hire creates an
   employee via the bridge — see [ADR 0006](../decisions/0006-recruitment-ats-and-hire-bridge.md).
