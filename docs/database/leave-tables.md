# Database: leave tables

The tables behind the [Leave Management module](../modules/leave.md), created by
`…_create_leave_tables`. All are tenant-scoped (`organization_id`). See
[ADR 0009](../decisions/0009-leave-management.md).

## `leave_types`

The kinds of leave the organisation grants (configured under Company Setup).
Soft-deletes (archived, not destroyed, so requests keep a valid type).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `name` | string | e.g. Vacation Leave. |
| `code` | string | e.g. `VL`. **Unique per tenant** via a partial index `(organization_id, code) WHERE deleted_at IS NULL`; archiving frees the code. |
| `description` | text, nullable | |
| `color` | string(20) | Badge / calendar accent (hex). |
| `default_days` | decimal(5,1) | Annual entitlement used when no `leave_balances` row exists. |
| `is_paid` | boolean | Default true. |
| `allow_half_day` | boolean | Default true. Permits a single-day half (0.5). |
| `requires_approval` | boolean | Default true. When false, a filed request is auto-approved. |
| `is_active` | boolean | Default true. Inactive types can't be picked on new requests. |
| timestamps + `deleted_at` | | Soft-deletes. Indexed on `is_active`. |

## `leave_balances`

One employee's **entitlement** for a type in a year. **Only the entitlement is stored** —
used and pending days are derived from `leave_requests` (ADR 0009), so a balance can
never drift.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `employee_id` | FK → employees | `cascadeOnDelete`. |
| `leave_type_id` | FK → leave_types | `cascadeOnDelete`. |
| `year` | smallint | The entitlement year. |
| `entitled_days` | decimal(5,1) | Allocation for the year. |
| `note` | string, nullable | |
| | | **Unique** `(employee_id, leave_type_id, year)`. |

## `leave_requests`

A filed leave request and its approval lifecycle.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `employee_id` | FK → employees | `cascadeOnDelete`. The person the leave is for. |
| `leave_type_id` | FK → leave_types | **`restrictOnDelete`** — a type with history can't be hard-deleted (archive instead). |
| `start_date` / `end_date` | date | Inclusive range. |
| `days` | decimal(4,1) | **Working days, server-computed** (weekends excluded; 0.5 for a half day). Never trusted from the client. |
| `is_half_day` | boolean | Single-day half. |
| `half_day_period` | string, nullable | `morning` / `afternoon` (only when `is_half_day`). |
| `reason` | text, nullable | Employee's note. |
| `status` | string | `pending` / `approved` / `rejected` / `cancelled`. |
| `filed_by` | FK → users, nullable | Who filed it. `nullOnDelete`. |
| `reviewed_by` | FK → users, nullable | Who approved / rejected it. `nullOnDelete`. |
| `reviewed_at` | timestamp, nullable | |
| `review_note` | text, nullable | Approver's note. |
| timestamps | | Indexed on `status`, `start_date`, and `(employee_id, status)`. |

### Derivation

For an employee + type + year:

- **entitled** = `leave_balances.entitled_days` if a row exists, else `leave_types.default_days`
- **used** = Σ `days` of **approved** requests (by `start_date` year)
- **pending** = Σ `days` of **pending** requests
- **remaining** = entitled − used

computed by `App\Queries\LeaveBalanceService`.
