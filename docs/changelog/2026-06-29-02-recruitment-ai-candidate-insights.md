# Recruitment: AI candidate insights

Adds LLM decision support for a **single candidate** on top of the deterministic fit
score. From the candidate drawer, HR can ask the model to **read the applicant's actual
résumé and supporting documents** against the role and return a grounded read: a verdict,
strengths, concerns, what the documents reveal, sharp interview questions, and a
recommendation. Mirrors the Reports module's `ReportInsights` pattern. See the updated
[recruitment module doc](../modules/recruitment.md).

## Highlights

- **Reads the real documents.** `gemini-2.5-flash` ingests PDFs and images natively, so
  the résumé and supporting files are attached to the request directly — no parser
  dependency. The model grounds its assessment in what the candidate actually submitted,
  not just the headline.
- **Privacy by design.** **Government-ID documents are never sent** to the model, and only
  model-readable file types (PDF, PNG/JPG/WebP) are attached, bounded by a total size
  budget. Office files are named in the digest but not uploaded.
- **On demand + persisted.** Generated from the drawer's **AI Insights** panel and **saved
  on the application**, so reopening shows the last read instantly without spending another
  call; a **Regenerate** button re-runs it. Degrades gracefully (retryable) on a missing
  key, quota, or overload — exactly like the Reports insights.

## Backend

- **`App\Support\Recruitment\ApplicantInsights`** — compiles the candidate digest (profile,
  role + criteria, the rule-based fit breakdown, ratings, interview history), attaches the
  readable documents as Gemini inline data (skipping government IDs), and returns strict
  JSON; reuses `App\Support\Ai\GeminiClient`.
- **`JobApplicationController@insights`** (`POST /recruitment/applications/{application}/insights`,
  gated `recruitment.view`) scores, generates, persists, and activity-logs the read.
- **`job_applications.ai_insights`** JSON column (one additive migration); surfaced by
  `JobApplicationResource`.

## Frontend

- **`features/recruitment/components/applicant-insights.tsx`** — the drawer's AI Insights
  panel (intro / loading / result / retryable-unavailable states, Regenerate), seeded from
  the persisted insight and mounted per application id.
- **`features/recruitment/api.ts`** — `fetchApplicantInsights` (CSRF-aware `fetch`, mirrors
  the Reports `api.ts`); new insight types + the `applicationInsights` route.

## Notes

- One additive migration (one nullable JSON column); no ERD reshape. Verified: `php -l`,
  Pint, `tsc` / ESLint / Prettier / `npm run build` all green; the route is registered and
  the migration ran. New `tests/Feature/Recruitment/ApplicantInsightsTest.php` (Gemini
  faked) covers generation + persistence, the **government-ID exclusion**, and graceful
  degradation when unconfigured. The Pest suite still can't run locally (no `pdo_sqlite`).
- The résumé/documents are sent to Google Gemini when HR generates insights — an external
  PII egress that is intentional and gated behind an explicit action, with government IDs
  excluded. (Related: applicant files still live on the public disk — see the standing
  recommendation to move them behind an authorized download route.)
