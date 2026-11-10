# Database: check-ins tables

The table behind [Check-ins](../modules/check-ins.md), created by
`2026_11_05_000000_create_check_ins` (ERD §9) — see
[ADR 0102](../decisions/0102-confidential-mood-check-ins-and-what-only-your-records-hold.md).
Tenant-scoped (`organization_id`). **Confidential:** no screen, API, assistant tool or
export reads a row for anyone but its owner; HR reads `CheckInResults` aggregates for
groups of five or more. Excluded from Data Export (`DataExportCatalogue::EXCLUDED`).

## `check_ins`

One employee's answers for one month.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `organization_id` | FK → organizations | Tenant; `cascadeOnDelete`. |
| `employee_id` | FK → employees | Whose answers; `cascadeOnDelete`. Read only to show the person their own months. |
| `department_id` | FK → departments, nullable | Where they worked when they answered, so a team's past months stay its own when someone moves; `nullOnDelete`. |
| `period` | date | The month's first day, on the organisation's clock (`CheckInPeriod`). |
| `mood` | tinyint | 1 (rough) – 5 (great). |
| `satisfaction` | tinyint | 1 (very dissatisfied) – 5 (very satisfied); single-item job satisfaction. |
| `energy`, `enthusiasm`, `immersion` | tinyint | UWES-3, each 0 (never) – 6 (always, every day). Engagement is their mean. |
| `leave_thoughts` | tinyint | 1 (never) – 5 (always): "How often have you considered leaving your job?" |
| `note` | text, nullable | For the person alone; **encrypted** (`encrypted` cast) and `$hidden` from serialisation. |
| `submitted_at` | timestamp | The last time it was saved; answering again in the month changes the row. |
| timestamps | | |

**Indexes:** unique (`employee_id`, `period`); (`organization_id`, `period`);
(`organization_id`, `department_id`, `period`).

Not activity-logged when answered: who answered is as confidential as what. Turning check-ins
on or off (`organizations.check_ins_enabled`) is logged under `check-ins`.
