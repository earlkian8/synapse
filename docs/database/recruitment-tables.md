# Database: recruitment tables

The tables behind the [Recruitment module](../modules/recruitment.md), created by the
`…_create_recruitment_tables` migration. Every table is tenant-scoped — a non-null
`organization_id` FK (ADR 0005), omitted from the columns below for brevity.

## `job_postings`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `title` | string | |
| `department_id` | FK → departments, nullable | `nullOnDelete`. |
| `position_id` | FK → positions, nullable | `nullOnDelete`. |
| `description` / `requirements` | text, nullable | |
| `employment_type` | string | regular / probationary / contractual / part_time. |
| `openings` | smallint | Default 1. Posting auto-fills when this many are hired. |
| `status` | string | draft / open / closed / filled. Indexed. |
| `closing_date` | date, nullable | |
| `posted_by` | FK → users, nullable | The recruiter (actor). |
| timestamps | | No soft-deletes — the status lifecycle is the archive. |

## `applicants`

The standalone candidate pool (not users or employees).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `first_name` / `last_name` | string | |
| `email` / `phone` | string, nullable | `email` indexed. |
| `headline` | string, nullable | Current role / one-liner. |
| `source` | string | website / referral / linkedin / agency / walk_in / other. |
| `resume` | string, nullable | Stored on the `public` disk. |
| `notes` | text, nullable | |

## `job_applications`

One applicant on one posting, moving through the pipeline.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `job_posting_id` | FK → job_postings | `cascadeOnDelete`. |
| `applicant_id` | FK → applicants | `cascadeOnDelete`. |
| `stage` | string | applied / screening / interview / offer / hired / rejected. Indexed. |
| `rating` | tinyint, nullable | 1–5. |
| `expected_salary` | decimal(12,2), nullable | |
| `cover_note` | text, nullable | |
| `rejected_reason` | text, nullable | |
| `hired_employee_id` | FK → employees, nullable | Set by the hire bridge. `nullOnDelete`. |
| `applied_at` / `decided_at` | timestamp, nullable | |
| timestamps | | **Unique** `(job_posting_id, applicant_id)` — one application per posting. |

## `interviews`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `job_application_id` | FK → job_applications | `cascadeOnDelete`. |
| `interviewer_id` | FK → users, nullable | The actor. `nullOnDelete`. |
| `scheduled_at` | timestamp | Indexed. |
| `mode` | string | onsite / online / phone. |
| `location` | string, nullable | Venue or meeting link. |
| `notes` | text, nullable | |
| `result` | string | pending / passed / failed. |
| `feedback` | text, nullable | |

## Per-tenant uniqueness

`(organization_id)` scopes every table; the application uniqueness above is therefore
effectively unique within a tenant (posting and applicant both already belong to one).
