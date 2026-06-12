# 2026-06-12 — Agentic employee assistant (Gemini)

A floating, conversational assistant can now **manage employees for you**:
describe what you want — or drop in a CV — and it creates, updates, finds or
archives employee records on your behalf, with live, animated feedback. Scope is
**employee management only** for now. See the
[Employees module](../modules/employees.md).

## Summary

- A **persistent floating chat** (bottom-right, mounted once in the app layout so
  its conversation survives navigation) lets you talk to the system instead of
  filling forms.
- It is **agentic**: powered by Gemini 2.5 Flash with **function-calling**. The
  model decides; the backend enforces. Every mutation flows through the **same
  validation rules, tenant scoping, permission gates and activity logging** as
  the manual UI — the assistant can never do more than the signed-in user can.
- **Multimodal**: attach a CV/resume (PDF, image or text) and the assistant
  extracts the details and creates the employee, filling sensible defaults
  (hire date = today, probationary, active) and telling you what it assumed.
- **Non-static feedback**: the agent's steps reveal one-by-one as an animated
  timeline, the created/updated employee pops in as a result card (avatar +
  number + position), a toast fires, and — if you're on the directory — the
  table reloads and the affected row **flashes** into view.

## Backend

- **`config/services.php`** — new `gemini` block (`key`, `model`) read from
  `GEMINI_API_KEY` / `GEMINI_MODEL`. **Server-side only**; the key is never
  exposed to the browser.
- **`App\Support\Ai\GeminiClient`** — a thin wrapper over the Gemini
  `generateContent` REST endpoint. Supports `function_declarations`, system
  instructions and multimodal `inline_data`, and transparently retries the
  transient `429/503` overload statuses with a short backoff. Registered as a
  singleton in `AppServiceProvider`.
- **`App\Services\Employee\EmployeeAgent`** — the brain. Builds a system prompt
  with the live department/position/schedule catalogs, exposes four tools
  (`find_employees`, `create_employee`, `update_employee`, `archive_employee`),
  and runs a **bounded function-calling loop** (max 6 round-trips). Each tool
  re-checks the matching permission (`employees.create/update/delete`), validates
  with the existing `StoreEmployeeRequest` rules, resolves
  department/position/manager/schedule by **name or id**, applies defaults,
  persists, and logs the activity. Returns a `reply`, a `steps` transcript and a
  list of executed `actions` for the UI to animate.
- **`App\Http\Controllers\Employee\EmployeeAssistantController`** — JSON endpoint
  (`POST /employees/assistant`, gated by `employees.view`). Accepts a `message`,
  prior `history` and an optional `file`, builds the multimodal part and returns
  the agent result. Degrades gracefully when the key is unset.
- **Route** registered in `routes/employees.php` **before** the `{employee}`
  wildcard so `assistant` is not swallowed by model binding.

## Frontend

- **`features/assistant/`** — a self-contained feature:
  - `types.ts`, `api.ts` (plain `fetch` with the `XSRF-TOKEN` → `X-XSRF-TOKEN`
    header so it passes CSRF without Inertia).
  - `components/employee-assistant.tsx` — launcher FAB, chat panel, composer
    (text + file attach, Enter to send), thinking state, and the success effects
    (toast + `router.reload({ only: ['employees', 'stats'] })` + a
    `nexo:employee-mutated` window event).
  - `components/agent-activity.tsx` — the staged-reveal step timeline and the
    animated result cards (reusing the shared `PersonAvatar`).
- Mounted once in **`layouts/app/app-sidebar-layout.tsx`** (authenticated shell),
  gated client-side on `employees.view`.
- **Employee directory** (`pages/employees/index.tsx` + `employees-table.tsx`)
  listens for `nexo:employee-mutated` and briefly flashes the affected row via a
  new `nexo-row-flash` keyframe in `app.css` (alongside the assistant's
  `assistant-pop` / `assistant-sheen` keyframes).

## Environment / setup

- `GEMINI_API_KEY` (and optional `GEMINI_MODEL`) added to `.env` and
  `.env.example`. The key is git-ignored and read server-side only.
- Outbound HTTPS from PHP requires a CA bundle. On this machine `php.ini` had no
  `curl.cainfo`; a Mozilla `cacert.pem` was installed and wired up so PHP can
  reach the Gemini API.
