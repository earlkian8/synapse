# Recruitment: fit scoring, due dates & decision support

Sharpens the Recruitment ATS around the three things HR actually does on a pipeline:
**decide who's strong, decide what to do next, and not let postings drift past their
deadline.** Candidates are now ranked by an automatic, position-aware **fit score**;
the candidate drawer is a full profile with a guided **decision panel**; and a published
posting must carry a **closing date** that auto-closes when it passes. Builds on
[ADR 0006](../decisions/0006-recruitment-ats-and-hire-bridge.md); see the updated
[recruitment module doc](../modules/recruitment.md).

## Highlights

- **Automatic candidate ranking.** A new `ApplicantScorer` assigns every application a
  0–100 **fit score** with a transparent breakdown (recruiter rating, experience vs the
  role's minimum, skill-keyword match, interview outcome, document completeness). The
  pipeline now leads with the strongest still-in-the-running candidates and shows each
  one's score and **rank** ("#1 of 12"). It is position-aware but config-free: postings
  may set a *minimum experience* and *required skills*, and the score normalises over
  whatever signals exist, so ranking works the moment a candidate applies.
- **Decision support.** The candidate drawer surfaces a **recommended next step** —
  *Advance to screening · Schedule an interview · Move to offer · Hire · Consider
  rejecting* — derived from the stage, fit and interview verdict, with a one-click
  button that performs it. Sending a candidate forward, to interview, or to rejection is
  now a single, obvious action.
- **View the whole candidate.** The drawer is now a complete profile: contact, profile
  links, every supporting document, the fit breakdown, interview history, **and the
  candidate's other applications across postings**.
- **Due dates that mean something.** A posting must have a **closing date once it's
  open**; the form requires it, the board and pipeline show a **countdown** (and an
  *Expired* flag), the careers page refuses late applications, and a daily
  `recruitment:close-expired` job flips past-due postings to *closed*.

## Backend

- **`App\Support\Recruitment\ApplicantScorer`** — pure, deterministic scoring + the
  next-step recommendation; one source of truth reused by the pipeline and the detail
  endpoint.
- **`job_postings`** gains `min_years_experience` + `skills` (the optional screening
  criteria); `JobPosting` gets `isExpired()` / `daysToClose()` / an `expired` scope.
- **`StoreJobPostingRequest`** requires `closing_date` when `status = open` and (on
  create) forbids back-dating; **`JobPostingController@show`** scores, ranks and orders
  the pipeline; **`JobApplicationController@show`** attaches the fit, recommendation and
  the candidate's other applications.
- **`CloseExpiredPostings`** console command, scheduled daily; **`CareersController`**
  hides/refuses expired postings on the fly.

## Frontend

- **`features/recruitment/`** — new `fit-score.tsx` (`FitBadge` + `FitMeter`) and
  `posting-deadline.tsx`; types/constants for fit bands and recommendation tones.
- The pipeline **table** and **cards** show the fit score + rank; the **posting form**
  gains a required-when-open closing date and a *Screening criteria* section (minimum
  experience + a skills tag input); the **detail drawer** gains the decision panel, fit
  breakdown, and other-applications list; the board + pipeline header show the deadline
  countdown.

## Notes

- One additive migration (two nullable columns); no ERD reshape. Verified: `php -l`,
  Pint, web `tsc` / ESLint / Prettier / `npm run build` all green. The scorer and the
  resource serialization were smoke-tested in a booted app against the seeded tenant
  across a range of stages (strong → advance, weak → screen, low screening → reject); the
  migration and the `recruitment:close-expired` schedule were run successfully. The Pest
  suite still can't run locally (no `pdo_sqlite`).
