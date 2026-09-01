# The demo seed catches up with the schema, and ships one login instead of five

`php artisan migrate:fresh --seed` had been failing since configurable pipelines
landed: `RecruitmentSeeder` still wrote `job_applications.stage`, a free string
column that [ADR 0029](../decisions/0029-configurable-recruitment-pipelines.md)
replaced with a foreign key to a pipeline's stages. Nothing in the suite ran the
seeders, so the break was invisible until somebody actually seeded a database —
which is exactly what an alpha tester does first.

This fixes that, rebuilds the recruitment seed around pipelines, and reduces the
seeded workspace to the **single account** alpha testers are given.

## The seed runs again, and now models pipelines

`RecruitmentSeeder` seeds two pipelines rather than one, deliberately: the default
`Standard Hiring` (Applied → Screening → Interview → Offer → Hired → Rejected) and
`Operations Hiring`, a shorter flow whose stages — `Trial Shift`,
`Supervisor Sign-off`, `Onboarded`, `Not Selected`, `Withdrew` — share no names
with it. One pipeline would have hidden the point of the feature. The seeder holds
itself to the same rule the module does: candidates are placed by an offset into a
pipeline's own `open` stages, or by `won` / `lost` **kind** — never by looking a
stage up by name.

Its five postings cover the shapes the module has to survive, not just the happy
path:

- the ranked office role (`Software Engineer`, min-experience + skills criteria);
- one that asks its own screening question (`Accountant` — "Are you a licensed CPA?");
- an `Operations Associate` on the ops pipeline that opted out of **both** the
  résumé requirement and automatic ranking, asking three plain yes/no questions
  instead — the generic case ADR 0029 exists for;
- a `filled` `Recruiter` posting whose hire is linked to a roster line, the state
  the recruitment → workforce bridge leaves behind;
- a `draft` `Marketing Specialist`, so the postings grid shows the whole lifecycle.

24 applicants spread across them, interviews on file for anyone past the entry
stage, and `screening_answers` recorded against each posting's own questions.
Pipelines are configuration rather than demo traffic, so they seed even into a
tenant that already has postings. `SYNAPSE Labs` — the second organisation that
makes the workspace switcher demoable — is left with no recruitment data on
purpose, so the "start from a template" empty state is demoable too.

## One account, not five

The seeded workspace now ships exactly one login: **`earlkian.dev@gmail.com` /
`password`**, Super Admin, a member of both organisations, and linked to a roster
line so the mobile self-service app resolves a self record on first sign-in.

`dev@synapse.com` and the four `mock.*@synapse.test` accounts are gone. Handing
alpha testers a set of credentials nobody owns was never worth what it bought:
User Management is demoable through the one account plus the invitation and
join-code flows ([ADR 0026](../decisions/0026-workspace-join-and-invitations.md)),
which is how real people get in anyway. The address lives in one place —
`DatabaseSeeder::ACCOUNT_EMAIL` — and `RolePermissionSeeder` reads it from there,
so the Super Admin grant can't drift from the account it's meant for.
`LeaveSeeder` stopped looking its approver up by hardcoded email and resolves the
workspace's account by identity instead.

`MockSeeder` was deleted. It targeted this same address, and it had been quietly
broken since identity was decoupled from tenant
([ADR 0023](../decisions/0023-identity-and-organization-membership.md)) — it
resolved its tenant through `users.organization_id`, a column that migration
dropped, so every run provisioned a *new* organisation instead of reusing one.
`DatabaseSeeder` now does everything it did, idempotently.

## The seed is tested now

`tests/Feature/DatabaseSeederTest.php` runs the whole seed and asserts what an
alpha tester would notice first: one login that owns the workspace, every posting
on a pipeline with a usable entry/won/lost stage, **every application sitting on a
stage of its own posting's pipeline** (the invariant the free-string column let the
seeder break), the coverage shapes above, and that the seeded — rather than
factory-built — data renders on the surfaces it is the sole source of data for.
Two tests, not a dataset: the seed is the expensive part.

`PageSmokeTest` gained `setup.recruitment-pipelines.index`, which the pipelines
change added as a page but never added to the walk.

## Notes

- Verified: `migrate:fresh --seed` green end to end on Postgres, then `db:seed`
  again over the result to prove idempotency (row counts unchanged, still one
  user). Pint `passed`. Full Pest suite against `staffa_test`: **640/641**, the one
  failure (`UserManagementTest::it_stores_an_uploaded_profile_photo`) is the
  pre-existing local GD gap, unrelated. Suite count moved 637 → 641: +2 seeder
  tests, +2 `PageSmokeTest` cases for the newly-walked page.
- No migration and no application-code change — this is seed data, tests and docs.
- Docs: a new **Seeding** section in `docs/modules/recruitment.md`; the account
  references in `docs/modules/{mobile-app,roles-permissions}.md`,
  `docs/modules/{work-schedule-holidays,offboarding}.md` (the `MockSeeder`
  mentions) and `mobile/README.md` updated. ADRs 0002 and 0020 still name
  `dev@synapse.com`; they are immutable by this folder's own convention and were
  left alone.
