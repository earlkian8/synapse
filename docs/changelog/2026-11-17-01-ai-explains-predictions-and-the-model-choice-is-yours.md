# AI explains each prediction, and the model choice is yours

The three predictive screens can now explain a score in plain words. An LLM reads the
breakdown and the record the viewer may see, and says why the system scored the
person this way. A model trained on the organisation's own records that doesn't pass
its check can still be chosen, knowingly, or set aside with a recorded "keep the
current model". The breakdown dialog no longer links to the person's profile. See
[ADR 0106](../decisions/0106-ai-explains-each-prediction-in-plain-words.md) and
[ADR 0107](../decisions/0107-a-model-that-did-not-pass-can-still-be-chosen.md).

## Highlights

- **In plain words.** Each breakdown's Summary tab has an *Explain this score*
  (*forecast*) button. It writes a headline, a summary, the reasons, how the record reads
  beside the score, what to confirm before acting, and what to do. The reasons' labels and points
  come from the stored result; the AI only writes the sentences.
- **Written for HR, not for developers.** The reading never explains how the system was
  built. It ends with *Before you act* (what to confirm about the person) and one line:
  "AI can make mistakes. Check important details before acting."
- **What this check means.** Each model check in *Your own model* can be explained the
  same way, with each part shown as met or not from the stored comparison.
- **Use it anyway, or keep the current model.** A model that doesn't pass is kept. The
  panel offers *Keep the general model* or *Use this model anyway*; the second needs a
  ticked acknowledgement. A model that passed also gets *Keep the general model*.
- **No profile link** in the breakdown dialog's header.

## Backend

- `App\Support\Ml\Interpretation\PredictionDigest`, `PredictionInterpreter`,
  `ModelCheckInterpreter`: one Gemini call each, strict JSON, saved in a new
  `interpretation` column (three score tables, `local_models`).
- Privacy: no name or employee number is sent. Pay never goes, and neither does
  anything built from it. Leave goes as days only. The record is gated by the viewer's
  permissions. A saved reading carries `needs`, so the score resources show it only to
  viewers who could see all it read.
- `InterpretationController`: `POST analytics/<surface>/scores/{score}/interpretation`
  and `…/graduation/{localModel}/interpretation`. They need the view permission and the
  new `interpretations` rate limit (10/min, 150/day per person), and are activity-logged.
  Both are added to the JSON-error paths in `bootstrap/app.php`.
- `App\Support\Ai\GeminiReply`: shared reading of a one-shot JSON answer and failure
  wording.
- `local_models.passed` (backfilled) is now separate from `status`. `ModelGraduation`
  keeps a stored model whatever the verdict. `activate()` needs `despite_check` for one
  that didn't pass (`ActivateLocalModelRequest`). The new `dismiss()` is behind
  `POST …/graduation/{localModel}/dismiss`.
- `PredictionContext::RECORD_GATES` names the context's permission gates once, and
  `employment.profile_url` is gone.
- Assistant: the switch tool offers the newest kept model. Its confirmation says plainly
  when that model didn't pass, and training reports a kept-but-failed model.
- Migration `2026_11_17_000000_add_interpretations_and_model_choice`.

## Model service

- `synapse_ml.local.training.train` fits the model on every example after the check,
  whatever the verdict. `/train` stores it with `verdict` in `metrics.json`. A model
  that can't be fitted at all is still not stored.

## Frontend

- `prediction-insights/components/ai-interpretation.tsx`: the reading, set as an
  annotation beside the numbers, with raise/lower points in the ledger's colours.
- The graduation panel: a card for the model that didn't pass, keep/use choices, the
  acknowledgement in the confirmation (`ConfirmDialog` gains `confirmDisabled`), and
  copy that no longer says the model in use "proved more accurate" when it didn't.

## Notes

- The tests make no real Gemini request: every one fakes the API at the HTTP boundary.
- The model sometimes cites the digest's tags ("[R1]") in its prose; every written field is
  stripped of them (`PredictionInterpreter::prose`), and the prompt says not to.
- Help articles (Attrition Risk, Promotion Readiness, Performance Forecast, Model
  graduation) and the module and database docs are updated.
