# Database: performance tables

The tables behind the [Performance module](../modules/performance.md), created by
`…_create_performance_tables` (ERD §8, plus the §2 config tables) and extended by
`…_create_appraisal_frameworks`. A configuration layer (`rating_scales`,
`kpi_criteria`, `review_templates` + `review_template_items`,
`evaluation_periods`) and the appraisals (`performance_evaluations` +
`performance_scores`) — see
[ADR 0028](../decisions/0028-appraisal-frameworks-and-tenant-rating-models.md)
and [ADR 0012](../decisions/0012-performance-management.md).

`…_create_performance_self_service_tables` adds what the people around an
appraisal take part with
([ADR 0072](../decisions/0072-appraisal-reviews-and-acknowledgement-by-the-employee.md),
[ADR 0073](../decisions/0073-goals-with-check-ins-and-calibration-sessions.md)):
the reviews (`appraisal_reviews` + `appraisal_review_scores`), goals and their
check-ins (`performance_goals` + `goal_check_ins`), the goal library
(`goal_templates`), calibration sessions (`calibration_sessions`,
`calibration_participants`, `calibration_adjustments`), and the sharing,
acknowledgement and calibration columns on `performance_evaluations`.

All are tenant-scoped (`organization_id`), except the `calibration_participants`
pivot, which is confined through its session.

## `rating_scales`

A reusable measurement instrument. Managed at `/setup/kpi`.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Addressed by hashid in URLs. |
| `organization_id` | FK → organizations | Tenant. |
| `name` | string | e.g. "Competency level". |
| `description` | text, nullable | What the scale is for. |
| `type` | string | `numeric \| percentage \| levels`. |
| `min` / `max` | decimal(8,2) | The bounds. A `levels` scale derives them from its own levels; a `percentage` scale is always 0–100. |
| `step` | decimal(6,2) | Granularity of a numeric scale (1 = whole points). |
| `levels` | json, nullable | Ordered `[{value, label, description}]` — the behavioural anchors. |
| `is_default` | boolean | The scale offered first. One per tenant (promoting demotes the rest). |
| timestamps + soft deletes | | A scale still in use cannot be permanently deleted. |

**Indexes:** `(organization_id, is_default)`.

## `kpi_criteria`

The Company-Setup **catalogue** of dimensions performance is measured on.
Managed at `/setup/kpi`.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Addressed by hashid in URLs. |
| `organization_id` | FK → organizations | Tenant. |
| `name` | string | e.g. "Quality of work". |
| `description` | text, nullable | Shown to the evaluator on the scorecard. |
| `weight` | decimal(6,2) | The **default** weight a framework starts it at. |
| `rating_scale_id` | FK → rating_scales, nullable | `nullOnDelete`; how it is rated. |
| `is_active` | boolean | Inactive criteria are not offered when building a framework. |
| `sort_order` | unsigned int | The tenant's catalogue ordering. |
| timestamps + soft deletes | | A criterion used by a framework or an appraisal cannot be permanently deleted. |

## `review_templates`

An **appraisal framework**: how one population is reviewed. Managed at
`/setup/kpi`.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Addressed by hashid in URLs. |
| `organization_id` | FK → organizations | Tenant. |
| `name` / `description` | string / text | e.g. "Individual Contributor Review". |
| `rating_scale_id` | FK → rating_scales, nullable | The scale an item falls back to. |
| `sections` | json | Ordered `[{key, name, description, weight}]` — weighted against each other. |
| `bands` | json | The **rating model**: ordered `[{key, label, min_percent, description, tone}]`, read top-down. |
| `result_display` | string | `band \| percent \| points` — what the scorecard leads with. |
| `applies_to` | string | `all \| department \| position \| employment_type`. |
| `applies_to_values` | json, nullable | The ids / values the rule names (strings throughout). |
| `is_default` | boolean | Used when nothing narrower matches. One per tenant. |
| `is_active` | boolean | Offered for new appraisals. |
| timestamps + soft deletes | | A framework used for appraisals cannot be permanently deleted. |

**Indexes:** `(organization_id, is_active)`.

## `review_template_items`

One weighted line of a framework. `weight` is its share **of its section**.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `organization_id` | FK → organizations | Tenant. |
| `review_template_id` | FK → review_templates | Cascade on delete. Items are replaced wholesale on save. |
| `kpi_criterion_id` | FK → kpi_criteria, nullable | `nullOnDelete`; lineage to the catalogue. Null for a one-off item. |
| `rating_scale_id` | FK → rating_scales, nullable | Overrides the framework default. |
| `section_key` | string | Must match a key in the framework's `sections`. |
| `name` / `description` | string / text | What is measured, and what it means. |
| `weight` | decimal(6,2) | Share of its section. |
| `sort_order` | unsigned int | Reading order within the section. |
| timestamps | | |

**Indexes:** `(review_template_id, sort_order)`.

## `evaluation_periods`

The Company-Setup review cycles appraisals are conducted within. Managed at
`/setup/kpi`.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Addressed by hashid in URLs. |
| `organization_id` | FK → organizations | Tenant. |
| `name` | string | e.g. "H1 2026 Review". |
| `start_date` / `end_date` | date | The cycle window (`end ≥ start`). |
| `status` | string | `draft \| open \| closed`. Indexed. Appraisals open only while `open`. |
| timestamps + soft deletes | | A period with appraisals cannot be permanently deleted. |

**Indexes:** `status`, `start_date`.

## `performance_evaluations`

One employee's appraisal for a cycle, conducted against a framework. Addressed by
hashid; managed at `/performance/{evaluation}`.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `organization_id` | FK → organizations | Tenant. |
| `employee_id` | FK → employees | Cascade on delete. |
| `evaluation_period_id` | FK → evaluation_periods | Cascade on delete. |
| `review_template_id` | FK → review_templates, nullable | `nullOnDelete`; lineage only — the snapshot below is what decides the result. |
| `template_name` | string, nullable | **Snapshot** of the framework's name. |
| `template_sections` | json, nullable | **Snapshot** of its weighted sections. |
| `template_bands` | json, nullable | **Snapshot** of its rating model. |
| `result_display` | string | **Snapshot** of what the scorecard leads with. |
| `evaluator_id` | FK → users, nullable | Who conducted it; `nullOnDelete`. |
| `overall_percent` | decimal(5,2), nullable | **Derived** attainment on 0–100 — the canonical figure. |
| `result_band` / `result_label` | string, nullable | **Derived** band key + the company's own word for it — or, once calibrated, the band the calibration moved it to. |
| `scored_band` / `scored_label` | string, nullable | What the scorecard gave, kept while a calibration has moved the rating; null otherwise. |
| `calibrated_at` | timestamp, nullable | When the rating was last moved; null when it stands as scored. |
| `overall_score` | decimal(5,2), nullable | **Derived** 1–5 projection of `overall_percent`, read by the ML pipelines. |
| `status` | string | `draft \| submitted \| acknowledged`. Indexed. |
| `submitted_at` | timestamp, nullable | Set on submit (locks the card). |
| `shared_at` | timestamp, nullable | When the employee could first read the result. Set on submit, or when the calibration session holding it ends. Back-filled from `submitted_at` for appraisals submitted before it existed. |
| `acknowledged_at` | timestamp, nullable | Set on sign-off. |
| `acknowledged_by` | FK → users, nullable | Who acknowledged it — the employee themselves, or HR recording a sign-off on their behalf. `nullOnDelete`. |
| `employee_comment` | text, nullable | What the employee said when acknowledging (up to 2,000 characters). |
| `remarks` | text, nullable | Overall summary. |
| `ai_insights` | json, nullable | The persisted LLM performance read. |
| timestamps | | |

**Indexes:** unique `(employee_id, evaluation_period_id)` — one appraisal per
employee per cycle; `status`.

## `performance_scores`

The per-criterion breakdown a result is built from — one line per framework item
at the time the appraisal was opened. **Every measurement fact is a snapshot**,
so the scorer can rebuild the result with no configuration present.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `organization_id` | FK → organizations | Tenant. |
| `performance_evaluation_id` | FK → performance_evaluations | Cascade on delete. |
| `kpi_criterion_id` | FK → kpi_criteria, nullable | `nullOnDelete`; lineage to the catalogue. |
| `review_template_item_id` | FK → review_template_items, nullable | `nullOnDelete`; lineage to the framework item. |
| `label` / `description` | string / text | **Snapshot** of what is measured and what it means. |
| `section_key` / `section_name` | string | **Snapshot** of the section this line was measured in. |
| `section_weight` | decimal(6,2) | **Snapshot** of the section's weight in the appraisal. |
| `weight` | decimal(6,2) | **Snapshot** of the line's weight within its section. |
| `scale_type` | string | **Snapshot**: `numeric \| percentage \| levels`. |
| `scale_name` | string, nullable | **Snapshot** of the scale's name, shown on the scorecard. |
| `scale_min` / `scale_max` | decimal(6,2) | **Snapshot** of the bounds the rating is read against. |
| `scale_levels` | json, nullable | **Snapshot** of the named levels + anchors. |
| `score` | decimal(5,2), nullable | The raw rating **on its own scale**; null until rated. |
| `remarks` | text, nullable | Evidence for the rating. |
| `sort_order` | unsigned int | Reading order across the whole scorecard. |
| timestamps | | |

**Indexes:** `kpi_criterion_id` (the other FKs are indexed by their constraints).

> The result is derived from these lines by
> `App\Support\Performance\PerformanceScorer` — each line read on its own scale,
> weighted within its section, sections weighted against each other, giving
> attainment on 0–100 and the band the snapshot rating model puts it in.
> Recomputed on every save and on submit, never stored from the client.

## `appraisal_reviews`

One request for one person's view of one appraisal (ADR 0072). Answered at
`/performance/reviews/{review}`; addressed by hashid.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Addressed by hashid in URLs. |
| `organization_id` | FK → organizations | Tenant. |
| `performance_evaluation_id` | FK → performance_evaluations | Cascade on delete. |
| `reviewer_id` | FK → employees | Who is asked. Cascade on delete. |
| `relationship` | string(16) | `self \| manager \| peer \| direct_report` — **derived** from `employees.manager_id` when asked, never typed. |
| `status` | string(16) | `pending \| submitted \| declined \| cancelled`. |
| `requested_by` | FK → users, nullable | Who asked. `nullOnDelete`. |
| `due_on` | date, nullable | Defaults to the cycle's end. |
| `strengths` / `improvements` | text, nullable | *What went well* and *What you'd like to develop*. |
| `decline_reason` | text, nullable | Given when declined. |
| `submitted_at` / `declined_at` | timestamp, nullable | When it was handed in or declined. |
| `reminded_at` | timestamp, nullable | The last reminder; at most one per 24 hours. |
| timestamps | | |

**Indexes:** unique `(performance_evaluation_id, reviewer_id)` — one request per
person per appraisal (a declined or cancelled one is re-opened when asked again);
`(reviewer_id, status)`.

## `appraisal_review_scores`

A reviewer's answer on one line of the appraisal's scorecard, on that line's own
frozen scale.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `organization_id` | FK → organizations | Tenant. |
| `appraisal_review_id` | FK → appraisal_reviews | Cascade on delete. |
| `performance_score_id` | FK → performance_scores | The line answered. Cascade on delete. |
| `score` | decimal(8,2), nullable | The rating on the line's own scale; null is "can't judge". |
| `remarks` | text, nullable | A note on the rating. |
| timestamps | | |

**Indexes:** unique `(appraisal_review_id, performance_score_id)`.

## `goal_templates`

The **goal library** (ADR 0073), managed at `/setup/kpi` on the Goal library tab.
Setting a goal from an entry copies it onto the goal.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Addressed by hashid in URLs. |
| `organization_id` | FK → organizations | Tenant. |
| `name` / `description` | string / text | The goal's wording and what success looks like. |
| `measure` | string(16) | `percent` (progress to 100) or `number` (a start to a target). |
| `start_value` / `target_value` | decimal(14,2) | 0 → 100 for `percent`; the target differs from the start for `number`. |
| `unit` | string(40), nullable | e.g. "tickets". |
| `is_active` | boolean | Offered when setting a goal. |
| timestamps + soft deletes | | |

**Indexes:** `(organization_id, is_active)`.

## `performance_goals`

One goal for one employee in one review cycle. Listed at `/performance/goals` and
`/performance/me/goals`; addressed by hashid.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Addressed by hashid in URLs. |
| `organization_id` | FK → organizations | Tenant. |
| `employee_id` | FK → employees | Whose goal. Cascade on delete. |
| `evaluation_period_id` | FK → evaluation_periods | The cycle. Cascade on delete. |
| `goal_template_id` | FK → goal_templates, nullable | Lineage to the library entry it was copied from. `nullOnDelete`. |
| `title` / `description` | string / text | **Copied** from the library entry, or written out. |
| `measure` | string(16) | `percent \| number`. |
| `start_value` / `target_value` | decimal(14,2) | The span progress is read across (signed, so a falling target works). |
| `current_value` | decimal(14,2) | Where it stands — the latest check-in's value. |
| `unit` | string(40), nullable | |
| `weight` | decimal(6,2) | Relative weight in the person's attainment (default 1). |
| `due_on` | date, nullable | |
| `status` | string(16) | `active \| achieved \| missed \| dropped`. |
| `health` | string(16), nullable | `on_track \| at_risk \| off_track`, from the latest check-in. |
| `last_check_in_at` | timestamp, nullable | Stale after 30 days without one. |
| `created_by` | FK → users, nullable | Who set it — the employee themselves for their own goal. `nullOnDelete`. |
| `closed_at` | timestamp, nullable | When it was achieved, missed or dropped. |
| timestamps | | |

**Indexes:** `(employee_id, evaluation_period_id)`, `(evaluation_period_id, status)`.

> Progress is `(current − start) ÷ (target − start)` clamped to 0–100, by
> `App\Support\Performance\GoalProgress`; attainment is the weight-averaged
> progress of the goals not dropped, an achieved one counting as 100.

## `goal_check_ins`

The append-only history of a goal.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `organization_id` | FK → organizations | Tenant. |
| `performance_goal_id` | FK → performance_goals | Cascade on delete. |
| `author_id` | FK → users, nullable | The owner or HR. `nullOnDelete`. |
| `value` | decimal(14,2) | The value at the check-in. |
| `health` | string(16) | `on_track \| at_risk \| off_track`. |
| `note` | text, nullable | What changed. |
| timestamps | | |

**Indexes:** `(performance_goal_id, created_at)`.

## `calibration_sessions`

A meeting in which a cycle's submitted ratings are compared and moved (ADR 0073).
Run at `/performance/calibration/{session}`; addressed by hashid.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Addressed by hashid in URLs. |
| `organization_id` | FK → organizations | Tenant. |
| `evaluation_period_id` | FK → evaluation_periods | The cycle. Cascade on delete. |
| `name` | string | e.g. "H1 2026 calibration". |
| `scheduled_for` | date, nullable | When it meets. |
| `department_ids` | json, nullable | The departments it covers; null for the whole cycle. Two open sessions in a cycle never overlap. |
| `status` | string(16) | `open \| completed \| cancelled`. While open, it holds back the appraisals it covers. |
| `notes` | text, nullable | |
| `facilitator_id` | FK → users, nullable | Who runs it. `nullOnDelete`. |
| `completed_at` | timestamp, nullable | |
| timestamps | | |

**Indexes:** `(evaluation_period_id, status)`.

## `calibration_participants`

Who takes part in a session. A pivot without `organization_id`: it is confined
through its session.

| Column | Type | Notes |
| --- | --- | --- |
| `calibration_session_id` | FK → calibration_sessions | Cascade on delete. |
| `user_id` | FK → users | Cascade on delete. |

**Primary key:** `(calibration_session_id, user_id)`.

## `calibration_adjustments`

Every rating moved in a session, with why — the history behind
`performance_evaluations.calibrated_at`.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `organization_id` | FK → organizations | Tenant. |
| `calibration_session_id` | FK → calibration_sessions | Cascade on delete. |
| `performance_evaluation_id` | FK → performance_evaluations | Cascade on delete. |
| `from_band` / `from_label` | string, nullable | The rating before the move. |
| `to_band` / `to_label` | string | The rating after it — a band of the appraisal's own rating model. |
| `reason` | text | Required. |
| `adjusted_by` | FK → users, nullable | `nullOnDelete`. |
| timestamps | | |

**Indexes:** `(performance_evaluation_id, id)`.
