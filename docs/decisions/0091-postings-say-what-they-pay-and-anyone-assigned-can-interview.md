# 0091 — Postings say what they pay, and anyone assigned can interview

- **Status:** Accepted
- **Date:** 2026-10-15
- **Related:**
  - [0006 — Recruitment ATS and the hire bridge](./0006-recruitment-ats-and-hire-bridge.md);
  - [0029 — Configurable recruitment pipelines](./0029-configurable-recruitment-pipelines.md);
  - [0087 — Uploads are private to their workspace](./0087-uploads-are-private-to-their-workspace.md);
  - [Recruitment module](../modules/recruitment.md).

## Context

**A posting could not say what it paid.** The only money on it was the applicant's
*expected salary*: a single number with no period. "30000" could mean a month or a
year, and a role paid by the day or the hour had no way to say so. The posting itself
had no pay range, so candidates applied without knowing whether the role was worth
it, and HR compared their asks against nothing. Postings also had no place: no work
arrangement (on-site, hybrid, remote) and no site, although most Philippine job
boards show both first.

**Experience was whole years only.** `min_years_experience` and an applicant's
`years_experience` were small integers. "1.5 years" is a normal requirement and a
normal answer; the form rounded it away.

**Only HR could interview.** Recording an interview's verdict needed
`recruitment.schedule-interviews`, and seeing the candidate needed `recruitment.view`,
the whole board. A department head meeting candidates for their own team, or a staff
member asked to sit in, had to be given the entire recruitment board, salaries
included, or HR typed the verdict for them. The interviewer picker also listed every
user in the database, other workspaces included.

## Decision

### A posting carries its pay and its place

- **Pay range.** `salary_min` / `salary_max` (either may be empty: a range, "from",
  "up to", or nothing) and a **`salary_period`**: `monthly` (the default), `annual`,
  `daily` or `hourly`. Daily and hourly cover the rank-and-file roles that are paid
  that way. **`show_salary`** (default on) chooses whether the careers page shows it.
  An unpublished range is still useful to HR for judging asks.
- **One way to write money.** `JobPosting::money($min, $max, $period)` produces
  "₱25,000–₱35,000 a month", "From ₱600 a day", "Up to ₱480,000 a year" or "₱87.50 an
  hour". The careers page, the posting modal, the assistant and an application's
  expected pay all use it.
- **Expected pay is asked in the posting's period.** **`asks_expected_salary`**
  (default on) turns the question off for roles where it isn't asked. The careers form
  reads "Expected monthly pay" (or yearly, daily, hourly). The answer stores
  **`job_applications.expected_salary_period`**. A model hook fills it from the posting
  when a recruiter doesn't pick one, and clears it when there is no figure. Answers
  given before this change have no period; they were asked without one.
- **Place.** **`work_arrangement`** (`onsite` default, `hybrid`, `remote`) and an
  optional **`work_location_id`**, one of the company's sites (Company Setup →
  Locations). The site is hidden for remote roles. The careers page shows
  "Hybrid · Makati HQ" and the site's address.
- **Experience takes one decimal place.** `min_years_experience` and
  `applicants.years_experience` become `decimal(4,1)`, validated `decimal:0,1`. The fit
  scorer compares fractions ("1.5 yrs (needs 2)").

Nothing else was added. Benefits, education level, headcount approvals and multiple
locations per posting were considered and left out: the description holds the first
two, and the last two belong to a workflow this module doesn't have.

### Interviewing is its own permission

- **`recruitment.interview`**: "Interview the candidates you're assigned". It reaches
  only interviews whose `interviewer_id` is the person, never the board.
- **Built-in Department Head and Staff roles get it**, in new workspaces
  (`OrganizationProvisioner`) and in existing ones (the migration's
  `PermissionSyncer::grant`). Being assigned an interview is something HR does
  deliberately, so the grant opens nothing by itself. A company that doesn't want
  staff interviewing removes it under Roles & Permissions.
- **One rule decides who is an interviewer**: `App\Support\Recruitment\Interviewers`.
  - An active member of *this* workspace whose role holds `recruitment.interview` or
    `recruitment.schedule-interviews`, or who is HR Manager.
  - The picker lists exactly them. The booking request and the assistant refuse anyone
    else, with the reason: "… can't interview candidates. Give one of their roles the
    'Interview the candidates you're assigned' permission first".
  - This also **fixes the cross-workspace leak**: the picker used to list every user
    in the database.
- **Who may record a verdict** (`Interviewers::mayRecord`): someone with
  `schedule-interviews` may record any interview, and someone with `recruitment.interview`
  may record their own. `POST /recruitment/interviews/{interview}` checks this in the
  controller instead of the route middleware, since either permission may pass.
- **My interviews** (`/recruitment/interviews`, `MyInterviewsController`,
  `Queries\MyInterviews`) lists two groups: *To record* (pending, soonest first) and
  *Recorded* (latest 50). Each interview opens a modal with:
  - its time, mode and join link;
  - the candidate's experience, location, résumé, LinkedIn, portfolio, email and phone;
  - their cover note and answers to the posting's questions;
  - the verdict (Not yet · Passed · Not a fit) and feedback.
- **The interviewer sees what interviewing needs, nothing more.** Expected salary,
  HR's rating and notes, the fit score and the candidate's other documents
  (government ID among them) stay on the board. The résumé download follows the same
  line: `UploadAccess` allows an `applicant-resumes` file to holders of
  `recruitment.view`, or to holders of `recruitment.interview` who are assigned an
  interview with that applicant. Other applicant documents still need
  `recruitment.view`.
- **Navigation.** Recruitment shows in the sidebar for `recruitment.view` or
  `recruitment.interview`. Its header switches between **My interviews** (with a count
  to record) and **Job postings**, each shown by permission. Someone who can only
  interview who opens `/recruitment` is sent to My interviews.
- **The interviewer is told.** Booking an interview, or changing its interviewer in the
  assistant, sends them "You have an interview" (linking to My interviews), unless they
  booked it themselves.

## Consequences

- Candidates see the pay and the place before applying, and HR reads an expected
  salary with its period ("₱30,000 a month") instead of a bare number.
- Department heads and staff can interview without access to the board. HR no longer
  types up other people's verdicts.
- The interviewer picker shows only this workspace's people who can actually open the
  interview, so an interview can't be given to someone who would never see it.
- Old applications keep a period-less expected salary. They show the figure alone.
- Not built yet: My interviews in the mobile app, and the assistant for someone who can
  only interview. The Recruitment assistant module is still gated by
  `recruitment.view`.
