# STAFFA — Entity Relationship Diagram

> **Status: DRAFT for review.** This is the proposed data model for the whole
> system, derived from the capstone proposal and the application navigation. Built
> so far: `users`, `activity_logs`, the RBAC tables, `notifications`, and the
> **Employee core** + organisation lookups (`departments`, `positions`,
> `work_schedules`, `employees` + sub-records — see
> [Employees](../modules/employees.md) and [ADR 0004](../decisions/0004-employee-user-separation.md)).
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

1. **Single-tenant deployment.** One organization per installation, so there is no
   `organization_id` scattered across tables; `company_profiles` is effectively a
   singleton. (If a multi-org SaaS is ever wanted, this is the expensive thing to
   retrofit — flag now if so.)
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
        string code UK
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
        bigint allowance_type_id FK
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
    EMPLOYEE ||--o{ ONBOARDING_TASK : assigned

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
    ONBOARDING_TASK {
        bigint id PK
        bigint employee_id FK
        string title
        text description
        bigint assigned_to FK "users"
        date due_date
        enum status "pending|in_progress|done"
        datetime completed_at
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

```mermaid
erDiagram
    EMPLOYEE ||--o{ LEAVE_APPLICATION : files
    LEAVE_TYPE ||--o{ LEAVE_APPLICATION : categorizes
    EMPLOYEE ||--o{ LEAVE_BALANCE : holds
    LEAVE_TYPE ||--o{ LEAVE_BALANCE : tracks

    LEAVE_APPLICATION {
        bigint id PK
        bigint employee_id FK
        bigint leave_type_id FK
        date start_date
        date end_date
        decimal days
        text reason
        enum status "pending|approved|disapproved|cancelled"
        bigint approved_by FK "users"
        datetime decided_at
        text decision_remarks
    }
    LEAVE_BALANCE {
        bigint id PK
        bigint employee_id FK
        bigint leave_type_id FK
        int year
        decimal entitled
        decimal used
        decimal remaining
    }
```

---

## 7. Payroll & Benefits

```mermaid
erDiagram
    PAYROLL_PERIOD ||--o{ PAYSLIP : contains
    EMPLOYEE ||--o{ PAYSLIP : paid
    PAYSLIP ||--o{ PAYSLIP_EARNING : has
    PAYSLIP ||--o{ PAYSLIP_DEDUCTION : has
    ALLOWANCE_TYPE ||--o{ PAYSLIP_EARNING : typed
    DEDUCTION_TYPE ||--o{ PAYSLIP_DEDUCTION : typed
    EMPLOYEE ||--o{ BENEFIT_CONTRIBUTION : accrues

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
    BENEFIT_CONTRIBUTION {
        bigint id PK
        bigint employee_id FK
        enum benefit "sss|philhealth|pagibig"
        string period "YYYY-MM"
        decimal employee_share
        decimal employer_share
        decimal total
    }
```

---

## 8. Performance & Training

Primary ML feature sources (KPI scores, training participation).

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
3. **Operational** (generate ML features): Attendance/DTR, Leave, Performance, Training, Payroll, Benefits, Awards, Events.
4. **Talent**: Recruitment, Onboarding; **Offboarding**.
5. **Intelligence**: FastAPI ML service → prediction tables → Analytics dashboard.
6. **Assistant**: LLM conversations, function-calling, document processor.

---

## Open questions for your review

1. **Employee ↔ User** — confirm the **separate** model (recommended) vs. collapsing
   Employee into User. Everything downstream depends on this.
2. **Single-tenant** vs. multi-organization — confirm single (recommended for the
   capstone scope).
3. **`employees.employee_no`** is the canonical HR id; the existing
   `users.employee_id` string column becomes redundant — drop it once Employees exist?
4. **Approvers/evaluators as `users`** (current convention) vs. as `employees` — OK?
5. **Benefit contributions**: standalone table (as drawn) vs. derived purely from
   payslip deductions — which do you prefer for reporting?
6. **Recruitment applicants** are *not* users/employees until hired — confirm a
   standalone `applicants` table is fine.
