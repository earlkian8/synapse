# Database: awards tables

The tables behind the [Awards & Recognition module](../modules/awards.md), created by
`…_create_awards_tables` (ERD §9 + the §2 config table). A config layer (`award_types`)
+ the recognitions (`employee_awards`) — see
[ADR 0014](../decisions/0014-awards-and-recognition.md). Both are tenant-scoped
(`organization_id`).

## `award_types`

The Company-Setup catalogue of recognitions the organisation gives. Managed at
`/setup/award-types`.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Addressed by hashid in URLs. |
| `organization_id` | FK → organizations | Tenant. |
| `name` | string | e.g. "Employee of the Month". |
| `description` | text, nullable | What the recognition is for. |
| `color` | string, nullable | Accent colour (hex) for the recognition feed. |
| `is_active` | boolean | Inactive types are hidden from the give-award picker. |
| timestamps + soft deletes | | A type that has been given out cannot be permanently deleted. |

## `employee_awards`

One recognition given to an employee. Managed in the Awards module (`/awards`).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Awards are addressed by numeric id. |
| `organization_id` | FK → organizations | Tenant. |
| `employee_id` | FK → employees | Cascade on delete. Indexed. |
| `award_type_id` | FK → award_types | Cascade on delete. Indexed. The award's type relation is loaded **`withTrashed`**, so an archived type still renders on past awards. |
| `awarded_on` | date | When the recognition was given (`≤ today`). Indexed. |
| `reason` | text, nullable | Why it was given. |
| `awarded_by` | FK → users, nullable | Who granted it; `nullOnDelete`. |
| timestamps | | |

**Indexes:** `employee_id`, `award_type_id`, `awarded_on`.
