# Database: recruitment tables

The tables behind the [Recruitment module](../modules/recruitment.md), created by the
`…_create_recruitment_tables` migration (and extended by
`…_extend_applicants_and_add_documents`). Every table is tenant-scoped — a non-null
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
| `current_location` | string, nullable | City / region. |
| `headline` | string, nullable | Current role / one-liner. |
| `linkedin_url` / `portfolio_url` | string, nullable | Professional links. |
| `years_experience` | tinyint, nullable | 0–60. |
| `source` | string | website / referral / linkedin / agency / walk_in / other. |
| `resume` | string, nullable | The **primary CV**, stored on the `public` disk; copied into the 201 file at hire. |
| `notes` | text, nullable | |

## `applicant_documents`

Supporting files an applicant attaches besides the primary résumé (cover letter,
certificate, transcript, portfolio, government ID, …). Mirrors `employee_documents`.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `applicant_id` | FK → applicants | `cascadeOnDelete`. Indexed. |
| `title` | string | Original file name. |
| `type` | string | cover_letter / certificate / transcript / portfolio / government_id / other. |
| `file` | string | Stored on the `public` disk. |
| `uploaded_by` | FK → users, nullable | The recruiter; **null** for public submissions. `nullOnDelete`. |
| timestamps | | |

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
