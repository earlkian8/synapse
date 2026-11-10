# Confidential mood check-ins, and what only your own records hold

Once a month, everyone in SYNAPSE now answers six quick questions about how work is going:
mood, job satisfaction, engagement and how often they've considered leaving. Answers are
confidential. Nobody at the company reads one person's; HR and department heads see
results for groups of five or more. A team's result, beside time since recognition and
leave taken, is kept with every attrition score, so that an organisation's own model
can learn from it. See
[ADR 0102](../decisions/0102-confidential-mood-check-ins-and-what-only-your-records-hold.md).

## Highlights

- **A check-in that takes a minute.** Six published, tested questions: mood, single-item
  job satisfaction, the three-item Utrecht Work Engagement Scale (UWES-3), and "How often
  have you considered leaving your job?" (the Turnover Intention Scale). They come one at
  a time. Answers can be read back and changed, and an optional note is kept for the
  person alone (encrypted). Answers can be changed until the month ends.
- **A face that wears your mood.** One drawing whose shape, brows, eyes, mouth and colour
  follow the mood from rough to great, easing between answers (and holding still under
  reduced motion). It moves for the mood question only, so it never nudges the other
  answers. Your year is twelve faces.
- **Results for groups, never people.** Team results show a month's tiles, mood over the
  year (100% stacked columns), satisfaction, engagement and "often think of leaving" as
  three small charts, and each team, sorted by name. Under five answers nothing is shown.
  Small teams are pooled, and the smallest shown team joins a pool that's still too
  small, so a hidden team can't be worked out by subtraction.
- **On the phone.** A new *How you're doing* service with its own drawn glyph. While the
  month's check-in waits, Home shows five faces; tap one and the rest of the questions
  follow. The Check-in screen shows this month on the hero card and your year as faces.
- **Emotional factors the general model can't learn, kept for your own.** Every attrition
  score now also stores team mood, engagement and thoughts of leaving (the department's
  latest shown result, never the person's answers), months since recognition and leave
  taken. The breakdown reads them as three more indicators: *team mood*, *recognition
  gap* and *rest*. An organisation's own model tries them and keeps them only if they
  win in 90% of re-checks; the graduation panel lists them as *Kept for your own model*.

## Backend (`server/`)

- `check_ins` table (unique per employee per month; `department_id` as it was when
  answered; `note` encrypted and hidden) and `organizations.check_ins_enabled`. New
  permissions: `check-ins.respond` (every role), `check-ins.view` (HR, department heads),
  `check-ins.manage` (HR).
- `App\Support\CheckIns`: `CheckInQuestions` (the questions, once), `CheckInPeriod` (the
  organisation's month), `CheckInWorkflow` (submit, overview, due; not activity-logged),
  `CheckInResults` (five-answer bar, pooling, complementary suppression, trend,
  `teamClimate`), `CheckInReminders` with `check-ins:remind` (1st and 20th of the month).
- Web `/check-ins` (landing, `me`, `results`, `settings`), API `/api/check-ins`, and the
  session's `can_check_in` / `check_in_due`.
- `AttritionRiskAssessor::SIGNAL_FEATURES`, loaded in bulk; `AttritionFeatureMapper` sends
  them; graduation trains on them; the graduation field list gains them under the new
  `learned` state ("Engagement and job satisfaction" is no longer *not recorded anywhere*).
- Help Center: *Mood check-ins*, *Check-in results*, and the app article; the assistant's
  system guide knows both screens (it reads no check-ins). The Data Export excludes
  `check_ins`. Demo data: a year of check-ins (`CheckInSeeder`).

## Model (`model/`)

- `features.SIGNALS` (with bands for evidence) and a `signals` branch in the preprocessor,
  one input each. The service no longer warns about them, and reads them as readings.
- Three indicators: team mood, recognition gap, rest. Each one's reason for being unread
  depends on whose model it is.
- `synapse_ml.local.training`: the organisation's model is fitted with and without its
  signals out of fold, and keeps them only when they win in at least 90% of resamples.
  `comparison.signals` records the outcome, and a finding says it in words.

## Frontend

- `features/check-ins/`: `MoodFace`, `CheckInFlow`, `MoodYear`, `WhoSeesThis`, mood charts
  and department results (cards on a phone); pages `check-ins/me` and `check-ins/results`;
  sidebar entry *Check-ins*. The mood palette lives in `app.css` (`--mood-1…5`, separate
  validated steps for dark).
- Mobile: `app/check-in/index.tsx`, `app/check-in/answer.tsx`, `features/check-ins/`, the
  Home prompt, the catalogue entry and the `mood` glyph.

## Notes

- Check-ins are on by default; HR can turn them off on Team results.
- Team mood is a department's figure: two people in one department carry the same value,
  and a department under five answers carries none.
- The general attrition model is unchanged by this: the survey it learned from never asked
  these questions.
- The Docker image was not built or run.
