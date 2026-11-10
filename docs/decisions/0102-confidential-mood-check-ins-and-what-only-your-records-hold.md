# 0102 — Confidential mood check-ins, and the signals only an organisation's own records hold

- **Status:** Accepted
- **Date:** 2026-11-10
- **Related:**
  - [0101 — The attrition model reads withdrawal](./0101-the-attrition-model-reads-withdrawal.md)
    (the behavioural indicators this adds to);
  - [0046 — Model graduation](./0046-model-graduation-trains-on-the-organisations-own-records.md)
    (the organisation's own model, which can learn what the survey never asked);
  - [0088 — The app opens its modules from Services](./0088-the-app-opens-its-modules-from-services.md);
  - module docs: [Check-ins](../modules/check-ins.md), [Attrition Risk](../modules/attrition-risk.md),
    [Model graduation](../modules/model-graduation.md).

## Context

ADR 0101 gave the attrition model one emotional factor, withdrawal, read from behaviour,
because the survey it learned from asked nothing about feelings. Behaviour indicates a
feeling; it doesn't measure one. The strongest published signs that someone will leave
are what they say: low engagement and job satisfaction, and above all how often they
think about leaving. No part of SYNAPSE asked, and the model-graduation panel listed
"Engagement and job satisfaction" as *not recorded anywhere*.

The system also already holds signals no survey asked about: when someone was last
recognised, and how much leave they took. The general model can never read them, since
it learned from that survey. An organisation's own model could.

Two things make asking hard. People answer honestly only if no one can trace the
answer to them. And the attrition score is read per person by HR, so feeding a
person's own answers into their own score would put those answers in front of HR.

## Decision

**A monthly mood check-in, confidential by construction** (`App\Support\CheckIns`,
`/check-ins`, the mobile app's *Check-in*). Six questions, each a published, tested
measure (`CheckInQuestions`):

| Key | Question | Scale | Source |
|---|---|---|---|
| `mood` | How have you felt at work this month? | Rough – Great (1–5) | — |
| `satisfaction` | Overall, how satisfied are you with your job? | 1–5 | single-item job satisfaction (Dolbier et al., 2005; Nagy, 2002) |
| `energy`, `enthusiasm`, `immersion` | the three UWES-3 statements | Never – Always (0–6) | Schaufeli, Shimazu, Hakanen, Salanova & De Witte (2019) |
| `leave_thoughts` | How often have you considered leaving your job? | Never – Always (1–5) | Turnover Intention Scale, item 1 (Roodt, 2004; Bothma & Roodt, 2013) |

A note is optional, for the person alone, and encrypted at rest. One check-in a month,
on the organisation's clock, changeable until the month ends. Reminders go out on the
1st and, to whoever hasn't answered, the 20th (`check-ins:remind`).

**Confidential, not anonymous.** The row names its employee, so the person can see their
own year and the system can keep one per month. Nobody else reads it:

- **HR sees groups, never people** (`CheckInResults`). A group is shown only with five or
  more answers (`MIN_GROUP`, not a setting). Departments under the bar are pooled into
  "Other teams". If the pool is non-empty but under five, the smallest shown department
  joins it until the pool clears the bar (complementary suppression). Without that, a
  single hidden department would be the company total minus the shown ones. Small
  departments aren't even listed by name with their answer counts.
- **Answering is not activity-logged.** "Jane answered on the 3rd" is something about
  Jane. Only turning check-ins on or off is logged.
- **No other door.** No assistant tool reads check-ins, the Data Export excludes
  `check_ins` (`DataExportCatalogue::EXCLUDED`), and the model hides `note` from
  serialisation. The results page's props carry counts and means only, and a test holds it.
- Permissions: `check-ins.respond` (everyone), `check-ins.view` (HR, department heads),
  `check-ins.manage` (HR; the on/off switch).

**The risk model gets the team's climate, never the person's answers.** Each attrition
score now also carries *signals*: inputs the general model can't read
(`AttritionRiskAssessor::SIGNAL_FEATURES`, `features.SIGNALS`):

- `team_mood`, `team_engagement`, `team_leave_thoughts`: the person's department's
  **latest shown** monthly result in the last three months (`CheckInResults::teamClimate`).
  It is exactly the figure the results page shows, under the same rules, so it tells no
  one anything new. An earlier design, the team average *without* the person, was
  rejected: comparing every member's "team minus me" figures gives back each person's
  exact answer.
- `months_since_recognition`: since the latest award or kudos received. For someone
  never recognised, it is their whole tenure, the same substitution as a promotion wait.
- `leave_days_365d`: approved leave days over the last year, for people here a year or more.

The general model ignores them without a warning (`Registry.unknown_inputs`). They're
stored with every score, which is what graduation trains on.

**An organisation's own model keeps them only if they help** (`synapse_ml.local.training`).
It is fitted with and without the signals out of fold. It keeps them only when the model
with them ranks better in at least 90% of resamples of the people, the same bar a local
model must clear to replace the general one. The HR reader gets a sentence either way.
The graduation panel lists the signals under a new state, *Kept for your own model*
(`learned`). Engagement is no longer listed as *not recorded anywhere*.

**Three more behavioural indicators** join ADR 0101's: team mood, recognition gap and rest.
They are read from the signals, with the theory behind each. Under the general model each
says it can only be learned by the organisation's own model. Under a local model that
left them out, it says that model tried them and they didn't help.

**Design.** Mood is a diverging scale from "feeling blue" to "sunny" (deep indigo, a
neutral grey, amber), chosen because the blue–amber axis survives colour blindness. Each
scheme has its own steps, checked with the palette validator (adjacent ΔE 17 normal / 16
deutan by day, 19 / 19 at night). The check-in's signature element is a drawn face whose
every feature follows the mood answer, easing between answers. It holds still under
reduced motion. **It moves only for the mood question.** A face that frowned at "I often
think about leaving" would push people toward answers that please it. The other scales
are drawn in ink, not mood colours. The person's year is twelve faces. HR's results are
100% stacked mood columns, three small single-scale line charts (no dual axis), and a
table sorted by name, never ranked. On mobile, check-ins are a new *How you're doing*
service family with its own drawn glyph. A Home card shows five faces while the month's
check-in waits, and tapping one starts the check-in with that mood.

## Consequences

- SYNAPSE can finally answer "how are people feeling", for groups, and an organisation's
  own model can learn whether it matters there. The general model is unchanged: the
  survey it learned from still has no such column.
- Team climate is a department-level figure, so two people in the same department get
  the same value. It can't explain one person; it can explain a team. With five or more
  answers needed, small teams carry no climate at all.
- Over time, a department's published month-on-month changes are as revealing as the
  month itself. That is inherent in publishing any recurring group figure, and it is
  why the threshold is not configurable.
- The recognition and leave signals exist from today for every score. Graduation needs a
  year of follow-up before any of it is learned.
- Check-ins are on by default for every workspace (HR can turn them off). This is safe
  to default because of the confidentiality above, and staying off would leave the
  feature unseen.
