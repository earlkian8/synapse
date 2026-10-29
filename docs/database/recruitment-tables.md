# Database: recruitment tables

The tables behind the [Recruitment module](../modules/recruitment.md), created by the
`…_create_recruitment_tables` migration (and extended by
`…_extend_applicants_and_add_documents`, and by the pipeline-as-data migrations of
[ADR 0029](../decisions/0029-configurable-recruitment-pipelines.md)). Every table is
tenant-scoped — a non-null `organization_id` FK (ADR 0005), omitted from the columns
below for brevity.

## `recruitment_pipelines`

A tenant-defined hiring process — a named, ordered list of stages a job posting is
assigned to. See ADR 0029.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `name` | string | e.g. "Standard Hiring", "Warehouse Hiring". |
| `is_default` | boolean | Exactly one per tenant; used when a posting doesn't pick one. Indexed with `organization_id`. |
| timestamps | | |

## `recruitment_pipeline_stages`

One step in a pipeline. The name is free text; **`kind`** (not the name) is what all
business logic — open/terminal checks, "what's next," hiring, rejecting — keys off.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `recruitment_pipeline_id` | FK → recruitment_pipelines | `cascadeOnDelete`. |
| `name` | string | Arbitrary, tenant-chosen (e.g. "Physical Assessment"). |
| `kind` | string | `open` / `won` / `lost`. A pipeline needs exactly one `won` stage and at least one `lost` stage (enforced in the form request, not a DB constraint). |
| `position` | smallint | Stage order. Indexed with `recruitment_pipeline_id`. |
| timestamps | | Deleting a stage that still has applications on it is refused (`restrictOnDelete` from `job_applications`). |

## `job_posting_screening_questions`

A posting's own yes/no questions ("Valid driver's license?", "Available for night
shift?") — the generic complement to `min_years_experience` / `skills` for anything
those two don't cover.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `job_posting_id` | FK → job_postings | `cascadeOnDelete`. |
| `label` | string | The question text. |
| `position` | smallint | Display order. Indexed with `job_posting_id`. |
| timestamps | | |

## `job_postings`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `title` | string | |
| `recruitment_pipeline_id` | FK → recruitment_pipelines | `restrictOnDelete` — a posting always needs a pipeline to run its board on. |
| `department_id` | FK → departments, nullable | `nullOnDelete`. |
| `position_id` | FK → positions, nullable | `nullOnDelete`. |
| `description` / `requirements` | text, nullable | |
| `employment_type` | string | regular / probationary / contractual / part_time. |
| `openings` | smallint | Default 1. Posting auto-fills when this many are hired. |
| `salary_min` / `salary_max` | decimal(12,2), nullable | The pay range; either may be empty ("from …", "up to …"). ADR 0091. |
| `salary_period` | string(10) | Default `monthly`; monthly / annual / daily / hourly. The period the range and applicants' expected pay are in. |
| `show_salary` | boolean | Default `true`. When `false`, the careers page hides the range. |
| `work_arrangement` | string(10) | Default `onsite`; onsite / hybrid / remote. |
| `work_location_id` | FK → work_locations, nullable | The site the role is based at. `nullOnDelete`. |
| `min_years_experience` | decimal(4,1), nullable | Fit-scoring criterion, e.g. 1.5. |
| `skills` | json, nullable | The **must-have** skills, as HR typed them. Matched by meaning against the résumé and profile (ADR 0097). |
| `preferred_skills` | json, nullable | The **nice-to-have** skills; each weighs half a must-have in the fit score (ADR 0097). |
| `requires_resume` | boolean | Default `true`. When `false`, the public application form makes the résumé optional. |
| `asks_expected_salary` | boolean | Default `true`. When `false`, applicants aren't asked their expected pay. |
| `use_fit_scoring` | boolean | Default `true`. When `false`, the pipeline skips the automatic fit score/ranking; candidates sort by applied date instead. |
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
| `years_experience` | decimal(4,1), nullable | 0–60, one decimal place (ADR 0091). |
| `source` | string | website / referral / linkedin / agency / walk_in / other. |
| `resume` | string, nullable | The **primary CV**, stored on the private `uploads` disk; copied into the 201 file at hire. |
| `resume_text` | text, nullable | The words read out of the résumé (PDF, Word, or a scan by OCR), at most 60,000 characters, for skill matching (ADR 0097). Hidden from JSON; never sent to the browser. |
| `resume_read_status` | string(12), nullable | `pending` (waiting to be read), `read`, `unreadable`; null without a résumé. Reset to `pending` whenever the résumé changes. |
| `documents_text` | text, nullable | The read text of the supporting documents together (government IDs never included), so scoring never loads them. |
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
| `file` | string | Stored on the private `uploads` disk (ADR 0087). |
| `extracted_text` | text, nullable | The words read out of the file (ADR 0097). Hidden from JSON. |
| `read_status` | string(12), nullable | `pending`, `read`, `unreadable`, or `skipped` — a government ID, which is never read. |
| `uploaded_by` | FK → users, nullable | The recruiter; **null** for public submissions. `nullOnDelete`. |
| timestamps | | |

## `job_applications`

One applicant on one posting, moving through the pipeline.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `job_posting_id` | FK → job_postings | `cascadeOnDelete`. |
| `recruitment_pipeline_stage_id` | FK → recruitment_pipeline_stages | `restrictOnDelete` — replaces the old free-text `stage` column (ADR 0029). |
| `applicant_id` | FK → applicants | `cascadeOnDelete`. |
| `rating` | tinyint, nullable | 1–5. |
| `expected_salary` | decimal(12,2), nullable | |
| `expected_salary_period` | string(10), nullable | monthly / annual / daily / hourly. Filled from the posting when not given; null when there is no figure, and on answers from before ADR 0091. |
| `cover_note` | text, nullable | |
| `rejected_reason` | text, nullable | |
| `screening_answers` | json, nullable | `{question_id: bool}`, answers to the posting's `job_posting_screening_questions`. |
| `hired_employee_id` | FK → employees, nullable | Set by the hire bridge. `nullOnDelete`. |
| `applied_at` / `decided_at` | timestamp, nullable | |
| timestamps | | **Unique** `(job_posting_id, applicant_id)` — one application per posting. |

## `interviews`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `job_application_id` | FK → job_applications | `cascadeOnDelete`. |
| `interviewer_id` | FK → users, nullable | Who conducts it: someone `Interviewers` allows (ADR 0091), who sees it under My interviews. `nullOnDelete`. |
| `scheduled_at` | timestamp | Indexed. |
| `mode` | string | onsite / online / phone. |
| `location` | string, nullable | Venue or meeting link. |
| `notes` | text, nullable | |
| `result` | string | pending / passed / failed. |
| `feedback` | text, nullable | |

## Per-tenant uniqueness

`(organization_id)` scopes every table; the application uniqueness above is therefore
effectively unique within a tenant (posting and applicant both already belong to one).
