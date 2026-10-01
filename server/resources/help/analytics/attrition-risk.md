**Analytics & AI** → **Attrition Risk** estimates how likely each active employee is to resign, and shows what drives each estimate — so a conversation about how someone is doing can happen before a resignation, not after.

> [!IMPORTANT]
> A risk score is a reason to check in with someone supportively. It is never grounds for discipline, dismissal or holding someone back, and it isn't something to tell the person.

## Running an assessment

Choose **Run assessment**. SYNAPSE scores every active employee and keeps the result as a snapshot you can come back to. Past assessments stay in the history selector; delete one you no longer need.

If the prediction service is unavailable, you'll see a message to try again shortly. Nothing is recorded, and earlier assessments stay visible.

## Reading the page

The tiles count who was assessed, how many are **High risk** and **At watch**, and the average risk. A bar shows how your workforce divides across the three tiers. Below it, the **ranked roster** lists every active employee by risk score, with their tier and the strongest thing pushing their risk up. Search, or filter by tier.

| Tier          | Score          |
| ------------- | -------------- |
| **Stable**    | Under 33       |
| **At watch**  | 33 to under 66 |
| **High risk** | 66 and over    |

Click someone to see their score, tier and **confidence**, **what moves this score** — each factor's effect, raising or lowering their risk — and **what it is based on**: every input, with their recorded value, or _Not on record_ where it had to be estimated.

## What it looks at

Eight things, taken from your records: employment type, length of service, salary, whether and how long ago they were last promoted, and — over the last 90 days — absences, late arrivals and overtime hours. Nothing about who the person is (age, gender and the like) is ever used.

Someone with no attendance recorded in the window is assessed without those inputs, and their confidence is lower — SYNAPSE doesn't pretend an empty record is a perfect one.

## How much to trust it

The score is **relative**: a 70 means this person's profile looks much more like people who left than people who stayed. It doesn't mean a 70% chance.

Until your company has enough history of its own, the model has learned from survey data about workers at other employers. It does better than chance, but modestly — treat it as a prompt to look, never as a prediction about a particular person. How your company graduates to a model trained on its own departures is explained in [Training a model on your own records](/help/analytics/model-graduation).
