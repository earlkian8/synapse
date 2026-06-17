# Database: employee & organisation tables

The tables behind the [Employees module](../modules/employees.md). Two migrations:
`…_create_organization_tables` (lookups) and `…_create_employees_tables` (the hub
+ sub-records, and the deferred `departments.head_id` FK).

## `departments`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `name` | string | |
| `code` | string | e.g. `HR`, `IT`. **Unique per tenant** via a partial index `(organization_id, code) WHERE deleted_at IS NULL` (see `…_make_department_code_unique_per_tenant`); archiving frees the code. |
| `parent_id` | FK → departments, nullable | Sub-department (self ref). `nullOnDelete`. Cannot form a cycle (see [Departments module](../modules/departments.md)). |
| `head_id` | FK → employees, nullable | Department head (FK added after `employees` exists). |
| `description` | text, nullable | |
| timestamps + `deleted_at` | | Soft-deletes. Managed via [Company Setup → Departments](../modules/departments.md). |

## `positions`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `title` | string | |
| `department_id` | FK → departments, nullable | `nullOnDelete`. |
| `salary_grade_min` / `salary_grade_max` | decimal(12,2), nullable | Salary band. |
| `description` | text, nullable | |

## `work_schedules`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `name` | string | e.g. Day Shift. |
| `start_time` / `end_time` | time, nullable | |
| `work_days` | json | `["Mon","Tue",…]`. |
| `grace_minutes` | smallint | Default 0. |
| `required_hours` | decimal(5,2) | Default 8. |

## `employees`

The hub. Soft-deletes.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `user_id` | FK → users, nullable, **unique** | Optional login link (ADR 0004). |
| `employee_no` | string, unique | Canonical HR id (`EMP-NNNNN`). |
| `first_name` / `middle_name` / `last_name` / `suffix` | string | `middle`/`suffix` nullable. |
| `birth_date` | date, nullable | |
| `gender` | string, nullable | male / female / other. |
| `civil_status` | string, nullable | single / married / widowed / separated / divorced. |
| `email` / `phone` / `address` / `photo` | string/text, nullable | |
| `department_id` / `position_id` / `work_schedule_id` | FK, nullable | `nullOnDelete`. |
| `manager_id` | FK → employees, nullable | Self ref (org chart). |
| `employment_type` | string | regular / probationary / contractual / part_time. |
| `employment_status` | string | active / on_leave / suspended / resigned / terminated. |
| `date_hired` | date | |
| `date_regularized` | date, nullable | |
| `basic_salary` | decimal(12,2), nullable | |
| `bank_name` / `bank_account_no` | string, nullable | |
| `tin` / `sss_no` / `philhealth_no` / `pagibig_no` | string, nullable | Government IDs. |
| timestamps + `deleted_at` | | Indexed on `employment_status`, `employment_type`. |

## `employee_documents`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `employee_id` | FK → employees | `cascadeOnDelete`. |
| `title` | string | |
| `type` | string | contract / cv / govt_id / other. |
| `file` | string | Stored on the `public` disk. |
| `uploaded_by` | FK → users, nullable | |

## `employee_certifications`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `employee_id` | FK → employees | `cascadeOnDelete`. |
| `name` | string | |
| `issuer` | string, nullable | |
| `issued_date` / `expiry_date` | date, nullable | Expiry surfaces an "Expired" badge. |
| `file` | string, nullable | |

## `employee_promotions`

Career history, **auto-recorded** on position/salary change.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `employee_id` | FK → employees | `cascadeOnDelete`. |
| `from_position_id` / `to_position_id` | FK → positions, nullable | |
| `from_salary` / `to_salary` | decimal(12,2), nullable | |
| `effective_date` | date | |
| `reason` | text, nullable | |
| `approved_by` | FK → users, nullable | |

## `employee_allowances` · `employee_deductions`

Recurring **per-employee pay items** (ERD §3 `EMPLOYEE_ALLOWANCE`, plus the
symmetric `EMPLOYEE_DEDUCTION`). They tie a Company-Setup allowance / deduction
type to an individual employee with a per-employee peso amount, so payroll config
actually drives payslips. Active items become the allowance earning lines and the
extra (e.g. loan) deduction lines when a run is processed — see
[Payroll](../modules/payroll.md) and `App\Support\Payroll\PayrollProcessor`.
Tenant-scoped (`organization_id`); managed from the Employee detail → **Compensation**.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `organization_id` | FK → organizations | Tenant. |
| `employee_id` | FK → employees | `cascadeOnDelete`. Indexed. |
| `allowance_type_id` / `deduction_type_id` | FK → allowance_types / deduction_types, nullable | `nullOnDelete`; an archived type resolves to null and is skipped by a run. |
| `amount` | decimal(12,2) | The per-employee peso amount per pay period. |
| `is_active` | boolean | Inactive items are ignored by a run. |
