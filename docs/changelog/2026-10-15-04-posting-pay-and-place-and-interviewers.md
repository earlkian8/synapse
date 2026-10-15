# Postings say what they pay and where, and anyone assigned can interview

A job posting's only money field was the applicant's *expected salary*: one number,
with no way to tell a month from a year. The posting had no pay range, no work
arrangement and no site, and experience could only be whole years. Interviewing
needed the full recruitment board, so a department head or staff member asked to sit
in on an interview couldn't record their verdict without being shown every
candidate's salary. The interviewer picker also listed users from every workspace.

See [ADR 0091](../decisions/0091-postings-say-what-they-pay-and-anyone-assigned-can-interview.md).

## Highlights

- **The posting form is rebuilt** in five parts: *Role*, *Pay & place*, *About the
  role*, *Applications*, *Publishing*.
- **Pay range with a period.** Lowest and highest pay (a range, "from", "up to", or
  nothing) **per month, year, day or hour**, and whether the careers page shows it.
- **Work arrangement and site.** On-site · Hybrid · Remote, and the site the role is
  based at (from Company Setup → Locations). Remote roles have no site.
- **Expected pay in the posting's terms.** Applicants are asked "Expected monthly pay"
  (or yearly, daily, hourly), and only if the posting asks. HR sees "₱30,000 a month",
  not a bare 30000.
- **Half years.** The minimum experience and an applicant's experience take one
  decimal place (1.5 years), and the fit score counts them.
- **Careers page.** Each card shows "Hybrid · Makati HQ", the employment type (now
  with a clock icon; it had a map pin), the pay when shown and the closing date. The
  job page adds the minimum experience and the site's address.
- **New permission: `recruitment.interview`**, "Interview the candidates you're
  assigned". Department Head and Staff have it by default.
- **Recruitment → My interviews.** An interviewer's own interviews, in two groups:
  *To record* and *Recorded*. Each opens with the time and join link, the candidate's
  résumé, links, cover note and answers, and a verdict (Not yet · Passed · Not a fit)
  with feedback. Expected pay, HR's rating, the fit score and other documents stay
  with HR.
- **The interviewer is notified** when booked ("You have an interview").
- **The interviewer picker lists only this workspace's people who can interview.** It
  used to list every user in the database.

## Backend

- Migration `2026_10_15_010000_add_pay_and_place_to_postings_and_interviewers`:
  - `job_postings` gains `salary_min`, `salary_max`, `salary_period` (default
    monthly), `show_salary`, `asks_expected_salary`, `work_arrangement` (default
    onsite) and `work_location_id`;
  - `min_years_experience` and `applicants.years_experience` become decimal(4,1);
  - `job_applications.expected_salary_period` is added;
  - `recruitment.interview` is granted to every company's Department Head and Staff.
    `down()` reverses all of it.
- `JobPosting::money()` / `payLabel()` and `JobApplication::expectedSalaryLabel()` are
  the one way pay is written out. A saving hook on `JobApplication` fills the period
  from the posting, or clears it when there is no figure.
- Requests validate the range (`salary_max ≥ salary_min`), the period and arrangement,
  a site from this workspace only, and experience as `decimal:0,1`. Public applications
  record expected pay only when the posting asks for it.
- `App\Support\Recruitment\Interviewers` decides who is an interviewer (`query`,
  `options`, `whyNot`, `mayRecord`, `notify`). The picker, `StoreInterviewRequest` and
  the assistant all use it, and `InterviewScheduler` notifies through it.
- `MyInterviewsController` + `Queries\MyInterviews` at `GET /recruitment/interviews`
  (`can:recruitment.interview`). `InterviewController@update` checks `mayRecord` and
  logs the verdict. `/recruitment` sends an interviewer-only user to My interviews.
- `UploadAccess`: an applicant's résumé is readable with `recruitment.view`, or with
  `recruitment.interview` by someone assigned an interview with that applicant. Other
  applicant documents still need `recruitment.view`.
- The assistant's posting tools take the pay range, period and arrangement. Experience
  is a number with one decimal place. Booking someone who can't interview is refused
  with the reason, and changing an interview's interviewer notifies them.
- The Help Center's *Interviews and hiring* article is readable with
  `recruitment.interview` and is the help for My interviews. The system guide has a
  *My interviews* entry.

## Frontend

- `posting-form-dialog.tsx` rewritten into sections, with `MoneyInput`, a `Segmented`
  control for the arrangement, and the site picker.
- `posting-detail-dialog.tsx` shows the arrangement and pay. `add-candidate-dialog.tsx`
  and `application-detail-dialog.tsx` take and show expected pay with its period.
- `components/recruitment-nav.tsx`: **My interviews** (with its count) ‖ **Job
  postings**, each by permission. `pages/recruitment/interviews.tsx` is new.
- Careers: `index.tsx`, `show.tsx` and `application-form.tsx` show the place, pay and
  experience, and ask for expected pay in the posting's period.
- `app-navigation.ts`: Recruitment shows for `recruitment.view` or
  `recruitment.interview`. `FormSelect` takes an `aria-label`.
- Help articles: *Job postings*, *Interviews and hiring*, *Your public careers page*.

## Notes

- Applications made before this change keep an expected salary without a period, and
  show the figure alone.
- A company that doesn't want staff to interview removes the permission from the Staff
  role under Roles & Permissions.
- Not built yet: My interviews in the mobile app, and the assistant for someone who can
  only interview.
- Checked in Chromium at 1440 and 390 px on a seeded scratch database:
  - HR created a hybrid posting at a site, ₱25,000–₱35,000 a month, 1.5 years;
  - the careers board and job page showed it, and an application stored 2.5 years
    and ₱30,000 a month;
  - the interviewer picker listed only the workspace's two people who can interview,
    and booking the staff member notified them;
  - signed in as staff, `/recruitment` opened My interviews and the board returned
    403, and *Passed* with feedback was saved.
