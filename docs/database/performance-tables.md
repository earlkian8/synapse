# Database: performance tables

The tables behind the [Performance module](../modules/performance.md), created by
`…_create_performance_tables` (ERD §8, plus the §2 config tables) and extended by
`…_create_appraisal_frameworks`. A configuration layer (`rating_scales`,
`kpi_criteria`, `review_templates` + `review_template_items`,
`evaluation_periods`) and the appraisals (`performance_evaluations` +
`performance_scores`) — see
[ADR 0028](../decisions/0028-appraisal-frameworks-and-tenant-rating-models.md)
and [ADR 0012](../decisions/0012-performance-management.md). All are
tenant-scoped (`organization_id`).

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
| `result_band` / `result_label` | string, nullable | **Derived** band key + the company's own word for it. |
| `overall_score` | decimal(5,2), nullable | **Derived** 1–5 projection of `overall_percent`, read by the ML pipelines. |
| `status` | string | `draft \| submitted \| acknowledged`. Indexed. |
| `submitted_at` | timestamp, nullable | Set on submit (locks the card). |
| `acknowledged_at` | timestamp, nullable | Set on sign-off. |
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
