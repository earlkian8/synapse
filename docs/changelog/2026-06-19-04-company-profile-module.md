# Company Profile module

Adds the **Company Profile** screen under Company Setup (`/setup/company`): edit the
organisation's identity, contact details, logo and statutory employer numbers. There is
no new entity — the tenant's `organizations` row **is** the company profile (ADR 0005),
so this edits the current tenant. Wires the existing sidebar placeholder (which until now
404'd). See [module doc](../modules/company-profile.md) and
[ADR 0005](../decisions/0005-multi-tenancy.md).

## Highlights

- **One sectioned form.** Brand & identity (logo + display / legal name), contact details
  (email, phone, address) and government & statutory (TIN, SSS, PhilHealth, Pag-IBIG
  employer numbers) — there is exactly one profile per tenant, so no list/CRUD.
- **Logo upload.** Upload / replace / remove a company logo (initials fallback), stored on
  the `public` disk like employee photos; the old file is deleted on replace or removal.
- **Read-only without manage.** Without `setup.company.manage` the form is disabled and the
  save bar hidden — viewers can see the profile, not change it.

## Backend

- **Controller** `Setup\CompanyProfileController` (`edit` + `update`). The organisation is
  resolved from `Tenancy` (you only ever edit your own tenant), and `update` handles the
  logo (`organization-logos` on the public disk) before filling the rest. Activity-logged
  (`logName: 'company-setup'`).
- **Request** `UpdateCompanyProfileRequest` (identity / contact / statutory fields + `logo`
  image rules + `remove_logo`). **Resource** `CompanyProfileResource` (fields + `logo_url`
  + `initials`).
- **Routes** `setup.company.edit` (GET) / `setup.company.update` (POST, multipart-friendly)
  in `routes/setup.php`. Permissions `setup.company.view` / `setup.company.manage` added to
  `PermissionRegistry`; built-in HR Manager granted both.
- **No migration** — the editable columns (`legal_name`, `logo`, `email`, `phone`,
  `address`, `tin`, `*_employer_no`) already exist on `organizations` from the
  multi-tenancy migration; `slug` is intentionally not editable.
- **Seeder** `OrganizationSeeder` backfills the demo tenant's profile when unset
  (idempotent; never overwrites an in-app edit).

## Frontend

- **Page** `pages/setup/company.tsx` — a sectioned `useForm` (auto-multipart when a logo is
  picked) with logo preview / upload / remove, grouped Brand / Contact / Statutory cards, a
  disabled read-only mode, and a save bar gated on `manage`.
- **Feature** `features/company-profile` (types + routes).
- **Sidebar** Company Profile placeholder gated by `setup.company.view`.

## Notes

- Verified: `php -l`, Pint, `tsc`, ESLint (project-wide), Prettier and `vite build` all
  green (the `setup/company` chunk emitted). A tinker run confirmed `setup.company.view` /
  `setup.company.manage` synced and granted to HR Manager, the `OrganizationSeeder`
  profile backfill, and `CompanyProfileResource` serialization on Postgres. Pest was
  **not** run locally (no `pdo_sqlite`).
- Out of scope this cut: multiple branches / locations, per-document letterheads,
  tax-filing exports, and editing the tenant `slug`.
