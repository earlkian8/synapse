# Seven years of workforce history, so model graduation can be used

The demo company had about 25 people, 13 promotions and one appraisal cycle, so model
graduation could only ever say "roughly 13 years away". `WorkforceHistorySeeder` gives
it seven years of history, enough that **every requirement on all three surfaces is
met**. The history also has patterns of its own, so a model trained on it **passes its
check** against the general model on each. Training on it through the real stack also
exposed two small-sample weaknesses in the local models, which are fixed here. See
[Model graduation → Demo history](../modules/model-graduation.md#demo-history) and
[ADR 0046](../decisions/0046-model-graduation-trains-on-the-organisations-own-records.md).

## Highlights

- **A company with a past.** Simulated month by month from January 2019 to today:
  - about 120 people at a time, around 350 in all, with voluntary turnover of about a
    quarter a year;
  - FY 2019–FY 2025 appraisals as full scorecards, built and scored the way the app
    builds and scores them;
  - promotions decided on those appraisals;
  - about 230 departures through completed offboarding cases;
  - a risk assessment every March and September from September 2019 to September
    2025 (13 runs), storing each person's record as it stood that day.

  The current team is part of it: they stay, and their FY 2025 appraisals are kept.
- **Its own patterns**, which the general models miss:
  - promotion at about twice the general rate, driven by the level of an appraisal and
    how much it improved;
  - ratings that regress to the mean;
  - resignations driven by overtime, absences, lateness and a long wait for promotion.
    The survey behind the general model found overtime irrelevant.
- **Graduates on every surface.** On a fresh seed, training through the real inference
  service gives:

  | Surface | Examples | Your model | General model | Knowing nothing | Re-checks won |
  |---|---|---|---|---|---|
  | Promotion (prediction error) | 579 | 0.162 | 0.218 | 0.194 | 100% |
  | Performance (average miss) | 560 | 4.1 pts | 5.0 pts | 4.6 pts (repeat last rating) | 100% |
  | Attrition (tells leavers from stayers) | 834 | 69% | 57% | 50% | 100% |

  Performance's ranges also held 78% of the ratings that followed (80% promised).
- **Robust, not lucky.** The current team's hire dates are random on each seed, so the
  exact counts vary a little. Across twelve seeds every requirement cleared by at least
  30% (fewest: 130 resignations, 130 promotions, 503 comparisons). Across six seeds,
  every surface's model passed its check, winning 100% of re-checks each time.

## Model (`model/`)

- **The local forecast is a monotone line** (`MonotoneLine`, via
  `PerformanceForecastModel.for_sample`), not boosted steps. On the seeded history the
  boosted local model missed by 4.50 points, which lost to simply repeating the last
  rating (4.62). A line misses by 4.32. A negative slope is flattened to the mean.
- **Promotion calibration falls back to plain Platt scaling** when its quadratic bend
  turns back inside the scores. It used to refuse, failing a model with a real signal.
  A score that runs backwards is still refused.
- Neither change touches the reference models. Two new tests cover them: **136 passed**.

## Server

- **`WorkforceHistorySeeder`** (new): its own seeded generator, and idempotent
  (skipped once FY 2019 exists).
- **`DatabaseSeeder`** now runs `PerformanceSeeder` straight after the organisation,
  followed by the history, so the people still here get attendance, leave, training
  and the rest.
- **`EvaluationOpener::lines()`** (extracted from `open()`) returns the unrated
  scorecard lines, so the seeder builds scorecards exactly as the app does.
- **The other seeders stay on the current team.**
  - Attendance, leave, onboarding and recruitment use active employees only.
  - The offboarding demo exits are seeded unless an exit is already in flight.
  - The profile seeder no longer invents a promotion for someone with an appraisal
    history.
- **`DatabaseSeederTest`** asserts:
  - every graduation requirement is met on all three surfaces;
  - every departure went through a completed offboarding case;
  - about 120 people are on the roster;
  - the seeded appraisals rescore to exactly their stored result;
  - 13 assessments are stored, each holding the record;
  - the three analytics pages render.

  It seeds twice rather than three times, because the mobile sign-in check moved into
  the first test.

## Notes

- A full seed now takes about 45s instead of about 5s, almost all of it attendance for
  a 120-person team. `DatabaseSeederTest` takes about 67s.
- To use it, run `php artisan migrate:fresh --seed`, which resets the development
  database.
