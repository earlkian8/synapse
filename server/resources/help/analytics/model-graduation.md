Every prediction in **Analytics & AI** starts from a **general model** — one that learned from other workplaces' data, because a new company has no history of its own yet. As your company records appraisals, promotions and departures, each prediction can **graduate** to its own model, trained on your records.

## The graduation strip

Under the header of **Attrition Risk**, **Performance Forecast** and **Promotion Readiness**, a one-line **Model graduation** strip says:

- whose model is scoring the page — **Using the general model** or **Using your own model**;
- how many of its requirements are met, with one mark per requirement;
- while the general model is in use, the one thing furthest from ready — for example "86 more promotions".

The strip turns into a call to action only when a decision is waiting. Its button opens the full picture.

## The four tabs

1. **Overview** — the next step to take, where this page is on the path (_General model_ → _Collecting your history_ → _Your own model_), and what graduation means in four short points.
2. **Requirements** — each requirement with its progress (for example "14 of 100") and what is still needed. Open one to see what you can do about it, when it might be met at your recent pace, why the number is what it is, and exactly what counts.
3. **Your own model** — the model in use, a model waiting for your decision (whether it passed its check or not), or a check that kept no model and why. **Explain this result** asks AI to say in plain words what each part of the check showed and what switching — or keeping the current model — would mean. Only the check's figures are sent, never anyone's record.
4. **Data used** — every piece of information a score draws on or could, with how many of your active employees' records carry it.

## What each prediction needs

| Prediction               | Its own model learns                                             | Requirements                                                                                                                                                                                                |
| ------------------------ | ---------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Promotion readiness**  | Whether a promotion followed each completed appraisal            | 100 promotions that followed an appraisal · 50 of them with two appraisals before · 100 appraisals not followed by a promotion · 80% of appraisal pairs on an unchanged form · the prediction service ready |
| **Performance forecast** | How each completed appraisal compared with the person's next one | 235 such comparisons · 2 review cycles with one before them to compare · 80% on an unchanged form · the prediction service ready                                                                            |
| **Attrition risk**       | Whether people resigned within a year of a risk score            | 100 resignations within a year of a score · 100 scores followed by a year of staying · 90% of departures with their type recorded · the prediction service ready                                            |

The numbers aren't arbitrary: they are the amounts of evidence needed to check a model of each kind reliably.

> [!TIP]
> The best thing you can do for attrition risk is to run every departure through [Offboarding](/help/offboarding/offboarding-an-employee), so its type is recorded — and to run assessments regularly, since each one is stored as history.

## Training and switching

Once every requirement is met, **Train on our records** (people who can run the prediction) fits a model on your company's records and checks it against the general model and against simply guessing, across a thousand re-samplings of your people. It **passes** if it wins in at least 90% of them.

- **If it passes**, it waits for you: nothing changes until someone chooses **Switch to this model** — or **Keep the general model**.
- **If it doesn't pass**, it is still kept, and the choice is still yours. The findings say why it fell short. Choose **Keep the general model**, or **Use this model anyway** — you'll be asked to tick that you understand it wasn't reliably more accurate for your people. Its scores can look just as confident while being less reliable, so read why first.
- **If no model could be built at all** from your records, nothing is kept, and the findings say why.

You can **Switch back** to the general model at any time. Every choice asks you to confirm, and each is recorded in the activity logs — including when a model was used despite not passing its check.
