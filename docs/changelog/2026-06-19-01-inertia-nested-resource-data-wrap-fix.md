# Fix: nested API-resource collections double-wrapped in `data` on Inertia detail pages

Fixes a runtime crash on several detail pages — e.g. `TrainingShow` threw
`enrollments.map is not a function` at `/training/{id}`.

## Cause

When a controller renders an Inertia page with a pre-resolved resource
(`(new SomeResource($model))->resolve($request)`) that **nests** another resource
collection (`OtherResource::collection($this->whenLoaded('rel'))`), Inertia's
`PropsResolver` walks the prop tree and treats the nested collection as a
`Responsable` — calling `toResponse($request)->getData(true)`, which applies the
default API-resource **`data` wrapper**. The nested prop therefore arrived in the
browser as `{ data: [...] }` instead of `[...]`, so the page's `xs ?? []` guard
didn't trigger and `.map()` / `.length` blew up.

This only affects the **Inertia `->resolve()`** path; the JSON-fetch path used by the
Employee and Recruitment detail drawers serializes via `jsonSerialize()`, which does
not wrap nested collections — those were already correct.

## Fix

Resolve the nested collection to a plain array inside the resource, so the prop value
is a list (not a `Responsable`) and Inertia leaves it untouched:

```php
'enrollments' => $this->whenLoaded(
    'enrollments',
    fn () => TrainingEnrollmentResource::collection($this->enrollments)->resolve($request),
),
```

Applied to every resource that nests a collection consumed as a plain array on an
Inertia detail page:

- `TrainingProgramResource.enrollments` (the reported crash)
- `EventResource.attendees`
- `PerformanceEvaluationResource.scores`
- `BenefitPlanResource.enrollments`

`whenLoaded` still omits the key when the relation isn't loaded, so the frontend's
`?? []` fallback keeps working.

## Notes

- Verified via the real Inertia render path (`Controller@show` →
  `toResponse($request)`): the `enrollments` / `attendees` props now serialize as plain
  arrays (count 13 / 1) instead of `{ data: [...] }`. `php -l` + Pint green. No frontend
  changes were needed.
- Employee and Recruitment detail drawers were checked and are unaffected (JSON-fetch
  path; recruitment's Inertia listing uses `interviews_count`, not the array).
