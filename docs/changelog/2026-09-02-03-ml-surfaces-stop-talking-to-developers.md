# The predictive analytics screens stop talking to developers

Promotion Readiness and Performance Forecast were showing the people who use them
things only the person who trained the model could act on: a strip reading
**"Model service connected · HistGradientBoostingRegressor · R² 0.92"**, and, when
the service was down, an instruction to run `python -m api` from `model/`. This is
an HR product. Nobody signing in to see who is ready for promotion is going to open
a terminal, and none of them should have to know what a regressor is.

## What's gone

- **The connected strip.** `ServiceBanner` now renders **nothing** while the
  service is working — a healthy service isn't news. The algorithm name, the model
  version and the accuracy figure (`R²` on the forecast, `ROC-AUC` on the
  readiness assessment) are gone with it, along with the now-unused
  `modelAlgorithm()` helper in both features' `constants.ts`.
- **The start command.** The offline banner keeps its job — telling you why the
  Run button is disabled — in language aimed at whoever is reading it:
  "Assessments are temporarily unavailable… Try again shortly, or contact your
  system administrator if this continues."
- **The developer copy in the toast.** `MlClient` raised
  `'The prediction service is not running. Start it with: python -m api (in
  model/).'` and `"Prediction failed (503): …"` — both of which
  `PromotionReadinessRunController` / `PerformanceForecastRunController` flash
  straight into a toast. They now read "Predictions are temporarily unavailable.
  Please try again shortly." and "Couldn't complete the prediction just now.
  Please try again shortly." **The diagnostic detail was not thrown away** — the
  URL, the connection error, the status code and the service's response body are
  now `Log::warning`'d, where whoever operates the service will actually look for
  them.

## The model stops reaching the browser at all

Deleting the strip would have left the model's identity and metrics sitting in the
page props, one devtools panel away. So both analytics controllers now send
liveness and nothing else — `'service' => ['connected' => $health !== null]` — and
`ServiceInfo` in both feature `types.ts` narrows to match. Attrition Risk's
frontend-only run carried a `model_version` of `'Simulated · demo scoring'` that
nothing rendered; it's dropped from its type and its mock engine too.

## Notes

- No migration, no schema change, no change to how anything is scored — the
  models, the assessors and the run records are untouched. The runs still persist
  their own `model_version`; it just isn't sent to or shown in the UI.
- Verified: `tsc --noEmit` clean project-wide, ESLint clean, Prettier clean on
  every touched file, `npm run build` succeeds. Pint `passed`. Full Pest suite
  against `staffa_test`: **640/641** — the one failure
  (`UserManagementTest::it_stores_an_uploaded_profile_photo`) is the pre-existing
  local GD gap, unrelated. `PageSmokeTest` walks both analytics pages with the
  service offline, so the rewritten banner is on the walked path.
- Prettier reports pre-existing style drift in 13 unrelated files; none were
  touched here and none were reformatted.
- Docs: the "connectivity strip" in the Surfaces list of
  `docs/modules/{promotion-readiness,performance-forecast}.md` is now described as
  what it became, plus a short paragraph in each recording the rule — nothing
  about the model reaches the browser, and an `MlException`'s message is written
  for the person who clicked the button.
