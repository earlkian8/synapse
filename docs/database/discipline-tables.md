# Database: discipline tables

The tables behind [Discipline](../modules/discipline.md), created by
`2026_11_16_020000_create_discipline_tables` (ERD §3) — see
[ADR 0105](../decisions/0105-discipline-taken-through-the-twin-notice-rule.md).
Tenant-scoped (`organization_id`). In Data Export, evidence files included, for
`discipline.view`.

## `conduct_offenses`

The company's code of conduct.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `organization_id` | FK → organizations | Tenant; cascade on delete. |
| `code` | string(20) | Unique per organisation among the live ones (validated). |
| `title` | string(160) | |
| `description` | text, nullable | |
| `gravity` | string(16) | `minor`, `less_serious`, `serious`, `grave`. |
| `ground` | string(32) | An Article 297 just cause (`serious_misconduct`, `willful_disobedience`, `gross_neglect`, `fraud`, `crime`, `analogous`) or `company_rule`, on which no ladder may reach dismissal. |
| `penalties` | json | The ladder: `[{action, days?}]`, action one of `verbal_warning`, `written_warning`, `final_warning`, `suspension` (with days), `dismissal`. The last step repeats. |
| `reckoning_months` | smallint, default 12 | How long an upheld case counts towards the next. |
| `is_active` | boolean, default true | |
| timestamps, `deleted_at` | | Soft-deleted, so cases keep naming it. |

**Indexes:** (`organization_id`, `deleted_at`).

## `disciplinary_cases`

One incident about one employee, through due process.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Addressed by hashid. |
| `organization_id` | FK → organizations | Tenant; cascade on delete. |
| `reference` | string(24) | `DC-<incident year>-<5 digits>`; unique per organisation. |
| `employee_id` | FK → employees | Cascade on delete. |
| `conduct_offense_id` | FK → conduct_offenses, nullable | Null on delete. |
| `status` | string(16), default `reported` | `reported`, `notice_issued`, `explained`, `hearing`, `decided`, `closed`, `withdrawn`. |
| `incident_date`, `incident_time` (HH:MM), `location` | | |
| `narrative` | text | The facts; printed on the notice. |
| `witnesses` | text, nullable | HR only; never shown to the employee or printed. |
| `reported_by` | FK → users, nullable | |
| `notice_grounds` | text, nullable | The specific charge. |
| `notice_issued_at` / `notice_issued_by` | timestamp / FK → users | The first notice. The employee sees the case from here on. |
| `reply_due_on` | date, nullable | At least five calendar days after. |
| `notice_received_at` | timestamp, nullable | The employee's acknowledgement: proof of service. |
| `preventive_from`, `preventive_to` | date, nullable | At most 30 days. |
| `explanation`, `explained_at` | text / timestamp, nullable | The written answer. |
| `explanation_recorded_by` | FK → users, nullable | Set when HR recorded a paper answer. |
| `hearing_requested` | boolean | Asked for in writing. |
| `hearing_at`, `hearing_place`, `hearing_held_at`, `hearing_notes`, `hearing_attended` | | The hearing or conference and its minutes. |
| `finding` | string(12), nullable | `upheld` or `cleared`. |
| `penalty` | string(20), nullable | As the ladder's actions. |
| `suggested_penalty` | string(20), nullable | The ladder's step at the time of the decision. |
| `occurrence` | smallint, nullable | The how-manieth upheld case of the offense within its period. |
| `suspension_days`, `penalty_from`, `penalty_to` | | A suspension's days and dates; a dismissal's effective date. |
| `decision` | text, nullable | The reasons. |
| `decided_at` / `decided_by` | timestamp / FK → users | The second notice. |
| `decision_received_at` | timestamp, nullable | Acknowledgement of the decision. |
| `withdrawn_reason`, `closed_at` | | |
| timestamps | | |

**Indexes:** unique (`organization_id`, `reference`); (`organization_id`, `status`);
`employee_id`.

## `disciplinary_case_attachments`

A case's evidence, on the private uploads disk under `discipline/` (ADR 0087), readable
with `discipline.view` only.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `organization_id` | FK → organizations | Tenant; cascade on delete. |
| `disciplinary_case_id` | FK → disciplinary_cases | Cascade on delete. |
| `path` | string | Storage path. |
| `name` | string(200) | As uploaded. |
| `size` | int | Bytes. |
| `uploaded_by` | FK → users, nullable | |
| timestamps | | |

**Indexes:** `organization_id`.
