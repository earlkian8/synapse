# Database: awards tables

The tables behind the [Awards & Recognition module](../modules/awards.md), created by
`…_create_awards_tables` and `2026_10_10_010000_create_recognition_tables` (ERD §9 + the §2 config table). A config layer (`award_types`)
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
| `points` | integer | Points the award credits (0–10,000; default 0). |
| `accepts_nominations` | boolean | Whether colleagues may nominate for it (default true). |
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

## Recognition ([ADR 0071](../decisions/0071-recognition-kudos-nominations-points-and-rewards.md))

`organizations` also gains `kudos_points` (smallint, default 10; 0 turns kudos points
off) and `kudos_monthly_limit` (smallint, default 5). Every table below is
tenant-scoped (`organization_id`, cascade).

### `award_nominations`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Addressed by numeric id. |
| `award_type_id` | FK → award_types | Cascade. |
| `employee_id` | FK → employees | The nominee; cascade. Indexed. |
| `nominated_by` | FK → users, nullable | `nullOnDelete`. |
| `nominator_employee_id` | FK → employees, nullable | `nullOnDelete`. |
| `reason` | text | 20–1,000 characters; becomes the citation. |
| `status` | string | `pending` (default), `approved`, `rejected`, `withdrawn`. |
| `reviewed_by` | FK → users, nullable | `nullOnDelete`. |
| `reviewed_at` | timestamp, nullable | |
| `review_note` | text, nullable | Shown to the nominator. |
| `employee_award_id` | FK → employee_awards, nullable | The award an approval gave; `nullOnDelete`. |
| timestamps | | |

**Indexes:** `(organization_id, status)`, `employee_id`.

### `kudos`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `from_employee_id` | FK → employees | Cascade. |
| `to_employee_id` | FK → employees | Cascade. Indexed. Never the sender. |
| `message` | text | Up to 500 characters. |
| `points` | smallint | As credited (0 past the monthly limit). |
| timestamps + soft deletes | | HR taking kudos down soft-deletes them and reverses their points. |

**Indexes:** `(organization_id, created_at)`, `(from_employee_id, created_at)` — the
monthly limit counts a sender's kudos with points, removed ones included.

### `point_transactions`

The points ledger. A balance is the sum of a person's lines; nothing is edited.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `employee_id` | FK → employees | Cascade. Indexed. |
| `amount` | integer | Signed. |
| `kind` | string | `award`, `kudos`, `redemption`, `refund`, `adjustment`. |
| `subject_type`, `subject_id` | morph, nullable | The award, kudos or redemption it is for. Indexed. |
| `note` | string, nullable | Required for an adjustment. |
| `created_by` | FK → users, nullable | `nullOnDelete`. |
| timestamps | | |

### `rewards`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Addressed by hashid. |
| `name` | string | |
| `description` | text, nullable | |
| `cost` | integer | Points, at least 1. |
| `stock` | integer, nullable | Null for unlimited. |
| `is_active` | boolean | Default true. |
| timestamps + soft deletes | | Archiving keeps past requests. |

### `reward_redemptions`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `reward_id` | FK → rewards | Cascade. |
| `employee_id` | FK → employees | Cascade. Indexed. |
| `cost` | integer | As charged. |
| `status` | string | `pending` (default), `fulfilled`, `declined`, `cancelled`. |
| `note` | text, nullable | The employee's. |
| `response_note` | text, nullable | HR's. |
| `handled_by` | FK → users, nullable | `nullOnDelete`. |
| `handled_at` | timestamp, nullable | |
| timestamps | | |

**Indexes:** `(organization_id, status)`, `employee_id`.
