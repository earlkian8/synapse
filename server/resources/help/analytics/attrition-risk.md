**Analytics & AI** → **Attrition Risk** estimates how likely each active employee is to resign, and shows what drives each estimate — so a conversation about how someone is doing can happen before a resignation, not after.

> [!IMPORTANT]
> A risk score is a reason to check in with someone supportively. It is never grounds for discipline, dismissal or holding someone back, and it isn't something to tell the person.

## Running an assessment

Choose **Run assessment**. SYNAPSE scores every active employee and keeps the result as a snapshot you can come back to. Past assessments stay in the history selector; delete one you no longer need.

If the prediction service is unavailable, you'll see a message to try again shortly. Nothing is recorded, and earlier assessments stay visible.

## Reading the page

The tiles count who was assessed, how many are **High risk** and **At watch**, and the average risk. Below them, the **ranked roster** lists every active employee by risk score, with their tier and the strongest thing pushing their risk up. Search, or filter by tier.

| Tier          | Score          |
| ------------- | -------------- |
| **Stable**    | Under 33       |
| **At watch**  | 33 to under 66 |
| **High risk** | 66 and over    |

For the whole workforce — how risk is spread, what raises it for the most people, and risk by department — choose **Workforce summary**. It opens the **Attrition Risk** report, which you can filter, print and export.

## One person's breakdown

Click someone to open their breakdown. It has four tabs:

- **Summary** — their score and how it moved since the last assessment; a plain read of how urgent it is, taking their performance forecast and promotion readiness into account; **suggested next steps**, each with the reason behind it and, for a stay conversation, questions worth asking; their other predictions; and how they compare with everyone, their department and their position.
- **Reasons** — how their score adds up from a typical employee's, reason by reason; their **emotional factors** (below); what the survey data shows for each reason; and every input with its recorded value, or _Not on record_ where it had to be estimated.
- **What would lower it** — the score the model would give with a promotion now, regular employment or a raise into the next pay band. Some changes don't lower it, and the page says so. These show what the model weighs, not what will happen.
- **Record** — their risk in every assessment, attendance by month, appraisals, leave, training and awards. You only see the parts your role lets you open elsewhere.

## What it looks at

Eight things, taken from your records: employment type, length of service, salary, whether and how long ago they were last promoted, and — over the last 90 days — absences, late arrivals and overtime hours. Nothing about who the person is (age, gender and the like) is ever used.

Absences and late arrivals are read together, as **withdrawal**, so they make one reason rather than two. Overtime is recorded but the score doesn't use it: in the survey the model learned from, people left at about the same rate whatever their overtime.

Someone with no attendance recorded in the window is assessed without those inputs, and their confidence is lower — SYNAPSE doesn't pretend an empty record is a perfect one.

## Emotional factors

SYNAPSE never asks anyone how they feel, and the model never sees it. What it can see is behaviour that research reads as a sign of how someone feels about their work. The **Reasons** tab shows four of these **behavioural indicators** for each person, each with its level and how often people at that level left in the survey data:

| Indicator | Read from | A sign of | In the score? |
| --- | --- | --- | --- |
| **Withdrawal** | Absences and late arrivals, last 90 days | Disengagement — people pulling away from a job often show it in lateness and absence before they resign | Yes |
| **Career stagnation** | Whether and how long ago they were promoted | Career frustration | Yes, through the promotion record |
| **Workload strain** | Overtime, last 90 days | Exhaustion (burnout) | No — it didn't help predict who left |
| **Effort–reward imbalance** | Overtime against pay and time since a promotion | Feeling under-rewarded | No — it didn't help predict who left |

An indicator **indicates** a feeling; it doesn't measure it. A high withdrawal level is a reason to ask how someone is doing, not a conclusion about them — there are many ordinary reasons to be late or absent. Indicators the score doesn't use are still shown, because heavy overtime is worth noticing whatever it does to a risk score.

## How much to trust it

The score is **relative**: a 70 means this person's profile looks much more like people who left than people who stayed. It doesn't mean a 70% chance.

Until your company has enough history of its own, the model has learned from survey data about workers at other employers. It does better than chance, but modestly — treat it as a prompt to look, never as a prediction about a particular person. How your company graduates to a model trained on its own departures is explained in [Training a model on your own records](/help/analytics/model-graduation).
