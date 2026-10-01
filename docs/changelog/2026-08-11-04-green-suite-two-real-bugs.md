# The suite is green, and two of the failures were real bugs

`development` carried twelve standing test failures for long enough that they had
become scenery — the kind you learn to read past. Reading them properly turned up
two genuine defects, one of which meant a feature simply did not work in the
browser. The rest were tests that had been left behind by ADRs 0005, 0023 and
0026 and were asserting behaviour the app had deliberately stopped having.

**474 tests, 474 passing.** No standing failures.

## The two real bugs

- **Push notifications could be turned on but never off.**
  `DELETE /system/notifications/subscriptions` matched the `{notification}`
  wildcard declared above it, so the controller went looking for a notification
  with the id `subscriptions`. Comparing that string against a `uuid` column
  raises a Postgres error that **aborts the surrounding transaction**, so the
  request died and took anything after it down too. The frontend's *Disable*
  button had never worked. Fixed twice over: literal routes now precede the
  wildcard (the convention `routes/recruitment.php` already documents), and the
  wildcard is pinned with `whereUuid('notification')` so a future reorder cannot
  bring it back — and so junk never reaches the query layer at all.

- **Archiving a department never freed its code.** Two unique indexes covered
  `(organization_id, code)`: a **total** one from `add_multi_tenancy`, and the
  **partial** one (`WHERE deleted_at IS NULL`) that
  `make_department_code_unique_per_tenant` added later, which only dropped the
  older *global* index and left the total one in place. The total index quietly
  overrode the partial one's entire purpose: a replacement department could not
  reuse an archived code, and `DepartmentController::restore()`'s clash guard —
  which exists precisely because the code *should* be reusable while archived —
  could never be reached. A new migration drops the total index. Verified from
  zero: after `migrate:fresh`, `departments` carries the partial index and
  nothing else on that pair.

## The ten stale expectations

Each was checked against the code and the ADR that changed it, not just made to
pass:

| Test | Was asserting | Actually, by design |
| --- | --- | --- |
| login, 4× email verification | redirect to `/dashboard` | `fortify.home` is `/workspaces` — one identity, many companies (ADR 0023) |
| new users can register | no company name | registration provisions a tenant, so `organization_name` is required (ADR 0005) |
| delete account | the row is gone | `users` soft-deletes, so the Trash Bin can undo it |
| org provisioning | 4 built-in roles | 3: HR Manager, Department Head, Staff |
| duplicate email on create | a validation error | an existing identity is *linked into* the organisation (ADR 0023) |
| token bound to one org | 200 from a mobile endpoint | 403 — the endpoint is self-scoped and the test user had no roster line |

Where it was cheap, the corrected test now asserts the *intent* rather than the
mechanism, and a few gained a sibling for the other half of the behaviour:

- Login asserts the picker **and** that a single-membership user is dropped
  straight into their dashboard; a new test covers somebody in two companies
  actually seeing the picker.
- Account deletion asserts the account is soft-deleted, is gone from normal
  queries, **and can no longer be logged into** — a stronger claim than "the row
  vanished".
- Registration gained the negative case: no company name is rejected.
- The self-scoped mobile endpoint gained an explicit test that it answers 403,
  not an empty 200, when the caller has no employee record — so a client cannot
  mistake "not linked" for "no data".

## Regression cover for the two bugs

- The subscriptions route is asserted to resolve to the literal path, and a
  non-UUID notification id (`../../etc/passwd`, `1 OR 1=1`, plain junk) is
  asserted to 404 rather than reach the database. A third test pins that one
  user's delete never touches another user's notification.
- Departments gained: archiving frees the code for reuse; a department whose
  code is still free restores cleanly; one whose code was taken is refused with
  a warning toast. And, below the validation layer where the migration actually
  lives, **two live departments in one organisation still collide at the
  database** — the constraint that had to survive dropping the other index.

## Frontend

No frontend source changed. What was checked, because a route edit is exactly
the kind of change that breaks a client silently:

- Wayfinder's generated helpers were regenerated and the notification URLs
  compared: `/system/notifications`, `/read-all`, `/clear`, `/preferences`,
  `/{notification}/read`, `/{notification}` and `/subscriptions` are all
  byte-identical. Reordering and `whereUuid` change resolution, not URLs.
- The registration page already posts `organization_name`, so the corrected test
  now matches what the UI has been sending all along — the test was stale, the
  UI was right.
- `use-web-push.ts` unsubscribes with `router.delete(url, { data: { endpoint } })`,
  which is the exact shape the regression test exercises.
- `tsc`, ESLint and `npm run build` are green.

## Notes

- **A caution about regenerating Wayfinder:** `resources/js/{routes,actions,wayfinder}`
  are gitignored, so `git status` will *never* show drift there — an empty diff
  proves nothing. Generate with `php artisan wayfinder:generate --with-form`; the
  bare command drops the `.form` variants that the auth and settings pages use,
  and only `tsc` will tell you.
- `assertToast()` now lives in `tests/Pest.php`. Toasts are flashed via
  `Inertia::flash('toast')` and land under `inertia.flash_data`; asserting that
  key by hand in each test would spread the transport across the suite.
- **Not changed:** `UserController::store` reports whether an email already had an
  account, which is an existence oracle across the whole instance. The actor is
  an authenticated user holding `users.create`, and the message is what makes the
  outcome comprehensible to them, so the trade is deliberate — noted here rather
  than silently altered.
