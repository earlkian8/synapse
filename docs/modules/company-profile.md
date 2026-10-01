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
- **Time zone** (required) — a searchable picker of IANA zones with their offsets. It is
  the clock attendance is judged on — who was late, what "today" is, and which day a
  night shift belongs to ([ADR 0036](../decisions/0036-attendance-judged-in-local-time-on-shift-anchored-dates.md)) — and every attendance
  time on the web and the mobile app is shown on it. The browser's own zone is offered
  with one click when it differs.
- **Government & statutory** — the employer registration numbers payroll remits against:
  **TIN**, **SSS**, **PhilHealth** and **Pag-IBIG** employer numbers.

Without `setup.company.manage` the form renders **read-only** (inputs disabled, no save
bar), so viewers can see the profile but not change it.

## Data model

No new table. The editable fields already exist on `organizations` (added with
multi-tenancy): `name`, `legal_name`, `logo`, `email`, `phone`, `address`, `tin`,
`sss_employer_no`, `philhealth_employer_no`, `pagibig_employer_no` — plus `timezone`,
added by ADR 0036 (IANA identifier, existing organisations back-filled with
`Asia/Manila`). The `slug` (tenant
identity) is **not** editable here. `Organization::logo_url` resolves the stored logo
path to a public URL; `Organization::initials()` powers the avatar fallback.

## Backend

- **`Setup\CompanyProfileController`** — `edit` (renders `setup/company` with the current
  tenant as a `CompanyProfileResource` + the `manage` flag) and `update`. The
  organisation is resolved from `Tenancy` (you can only edit your own tenant — never an
  id from the request).
- **`UpdateCompanyProfileRequest`** — validates the identity / contact / statutory fields
  plus `logo` (`image`, `mimes:jpg,jpeg,png,webp,svg`, `max:2048`), a `remove_logo`
  flag, and a required `timezone` from `OrganizationClock::identifiers()` (PHP's
  canonical list, so a zone has one spelling). The same request backs step one of the
  setup wizard.
- **Logo handling** mirrors employee photos: stored on the `public` disk under
  `organization-logos`; the previous file is deleted on replace or removal.
- **`CompanyProfileResource`** exposes the fields + `logo_url` + `initials`; the page also
  receives `timezones` (`OrganizationClock::options()` — every zone with its current
  offset, west to east).
- Routes in `routes/setup.php` (`setup.company.edit` / `setup.company.update`). The update
  is a `POST` so the logo can be sent as multipart. Mutations are activity-logged
  (`logName: 'company-setup'`, like the other Company Setup screens).
- **`CompanyProfileWriter::save()`** applies an edit and records it. The screen and the
  assistant both call it; the wizard's first step calls `apply()` and records its own
  line. A changed time zone is written into the audit line, old → new ("Updated the
  company profile: time zone Asia/Manila → Europe/London").

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

## The assistant

`App\Services\Assistant\Modules\CompanyProfileModule` puts the profile in the chat
assistant ([ADR 0053](../decisions/0053-assistant-company-profile-schedules-and-attendance-policies.md)).

- **Reads** (`setup.company.view`): `get_company_profile` returns:
  - the names and contact details;
  - the time zone, with the time there now;
  - whether a logo is on file;
  - which statutory employer numbers are on file and which are missing;
  - for those who manage the profile, whether joining by code is on.
- **Writes** (`setup.company.manage`), through `CompanyProfileWriter::save()` and the
  request's own rules:
  - `update_company_profile`: display name, registered legal name, email, phone,
    address; `clear` empties one;
  - **`set_company_timezone` always waits for Confirm.** It takes a zone
    ("Asia/Manila") or a city only one zone has ("singapore"), never an offset. Its
    card shows the time there and on the current clock, and what moves.
- **Deliberately screen-only:**
  - **statutory numbers** are never read or written in chat. They are what payroll
    remits against;
  - the **join code** (a credential, ADR 0026) is never read, rotated or switched;
  - the **logo**.
- **Retrieval:** "what time zone are we on?", "what's our registered name?" carry the
  profile before the model is called.

## Out of scope (this cut)

Multiple branches / locations, per-document letterheads, tax-filing exports, and editing
the tenant `slug` (it is the stable tenant identity).
