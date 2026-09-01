# Company Profile

The organisation's own record — its identity, contact details and statutory employer
numbers. It lives under **Company Setup** at `/setup/company`. There is no separate
`company_profiles` table: the tenant's `organizations` row **is** the company profile
(ADR 0005), so this screen edits the current tenant. Data model is ERD §2
(`COMPANY_PROFILE`), realised on the `organizations` table.

> Status: **Active** · Route prefix: `/setup/company`
> Sidebar: Company Setup → Company Profile (gated by `setup.company.view`)

## Surface

A single, sectioned edit form (no list — there is exactly one profile per tenant):

- **Brand & identity** — the company **logo** (upload / replace / remove) with an
  initials fallback, the **display name** (required) and the **registered legal name**.
- **Contact details** — email, phone, address.
- **Government & statutory** — the employer registration numbers payroll remits against:
  **TIN**, **SSS**, **PhilHealth** and **Pag-IBIG** employer numbers.

Without `setup.company.manage` the form renders **read-only** (inputs disabled, no save
bar), so viewers can see the profile but not change it.

## Data model

No new table. The editable fields already exist on `organizations` (added with
multi-tenancy): `name`, `legal_name`, `logo`, `email`, `phone`, `address`, `tin`,
`sss_employer_no`, `philhealth_employer_no`, `pagibig_employer_no`. The `slug` (tenant
identity) is **not** editable here. `Organization::logo_url` resolves the stored logo
path to a public URL; `Organization::initials()` powers the avatar fallback.

## Backend

- **`Setup\CompanyProfileController`** — `edit` (renders `setup/company` with the current
  tenant as a `CompanyProfileResource` + the `manage` flag) and `update`. The
  organisation is resolved from `Tenancy` (you can only edit your own tenant — never an
  id from the request).
- **`UpdateCompanyProfileRequest`** — validates the identity / contact / statutory fields
  plus `logo` (`image`, `mimes:jpg,jpeg,png,webp,svg`, `max:2048`) and a `remove_logo`
  flag.
- **Logo handling** mirrors employee photos: stored on the `public` disk under
  `organization-logos`; the previous file is deleted on replace or removal.
- **`CompanyProfileResource`** exposes the fields + `logo_url` + `initials`.
- Routes in `routes/setup.php` (`setup.company.edit` / `setup.company.update`). The update
  is a `POST` so the logo can be sent as multipart. Mutations are activity-logged
  (`logName: 'company-setup'`, like the other Company Setup screens).

## Permissions

`setup.company.view` (see the profile) and `setup.company.manage` (edit it), added to
`PermissionRegistry` under **Company Setup**. Built-in **HR Manager** gets both; Super
Admin / Administrator get them via the all-permissions grant. The sidebar item is gated on
`setup.company.view`.

## Integrations

- **Multi-tenancy (ADR 0005)** — the profile *is* the tenant; the name/logo feed the app's
  branding surfaces.
- **Payroll & Benefits** — the statutory employer numbers are the company-side identifiers
  for SSS / PhilHealth / Pag-IBIG remittances.
- **Seeding** — `OrganizationSeeder` backfills the demo tenant's profile (legal name,
  contact, employer numbers) when unset, so the screen isn't empty; idempotent, and it
  never overwrites a profile edited in-app.

## Out of scope (this cut)

Multiple branches / locations, per-document letterheads, tax-filing exports, and editing
the tenant `slug` (it is the stable tenant identity).
