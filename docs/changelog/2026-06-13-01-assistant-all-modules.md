# 2026-06-13 — Assistant goes org-wide (all HR modules)

The floating assistant is no longer employee-only. It is now **Synapse Assistant**,
an HR copilot that can act across **Employees, Leave, Onboarding and
Recruitment** — and it only ever exposes the modules the signed-in user is
permitted to use. The conversational, animated, multimodal experience is
unchanged; the reach is much wider.

## Summary

- **One assistant, every module.** Ask it to add or find employees, file/approve/
  cancel leave, start or advance onboarding, or run the recruitment pipeline
  (post a job, add a candidate, move/reject an application, schedule an interview,
  hire). **19 tools across 4 modules.**
- **Same guarantees, everywhere.** Every action re-checks the matching permission
  server-side, validates with the module's existing FormRequest rules, respects
  tenant scoping, and writes an activity-log entry — the assistant can never do
  more than the user can in the manual UI.
- **Still multi function-calling.** The model decides and chains tools across a
  bounded loop (it can look something up, then act on the result); the backend
  enforces. Multimodal CV upload (one employee per CV) is retained.

## Architecture — a module registry

The old single-purpose `EmployeeAgent` was refactored into a small, extensible
registry so each capability area is self-contained and the orchestrator is
generic:

- **`App\Services\Assistant\Assistant`** — the orchestrator. Aggregates the tools
  and prompt guidance of every module the user can access, runs the bounded
  Gemini function-calling loop, dispatches each `functionCall` to the owning
  module, and assembles the `reply` + `steps` + result `actions` for the UI.
- **`App\Services\Assistant\Contracts\AssistantModule`** — the contract:
  `key()`, `isAvailable(user)`, `handles(tool)`, `tools()` (Gemini function
  declarations), `guidance()` (prompt fragment + live catalogs), `run(...)`.
- **`App\Services\Assistant\Modules\Module`** — shared base: case-insensitive id
  resolution, **token-aware name matching** (so "Jane Doe" matches first *and*
  last name, not a single column), permission-denied results, and the
  module-agnostic **result-card** builder.
- **`EmployeeModule`, `LeaveModule`, `OnboardingModule`, `RecruitmentModule`** —
  the four capability areas. Each mirrors its controllers' validation, logging
  and notifications, and reuses the canonical support classes
  (`LeaveCalculator`, `OnboardingProvisioner`, and a new
  **`App\Support\ApplicantHirer`** extracted from `HireController` so the
  recruitment → workforce bridge has a single implementation).
- **`ToolResult`** — a uniform per-tool outcome (a timeline step + zero or more
  result cards) so every module animates identically.

## Backend changes

- **New** `App\Http\Controllers\AssistantController` at **`POST /assistant`**
  (any authenticated user; the agent exposes only the user's permitted modules).
- `Assistant` + the four modules registered in `AppServiceProvider`.
- `HireController` now delegates to `ApplicantHirer` (no logic change).
- **Removed** the employee-only `EmployeeAgent`, `EmployeeAssistantController` and
  the `POST /employees/assistant` route.

## Frontend changes

- `features/assistant/components/employee-assistant.tsx` → **`assistant.tsx`**
  (`<Assistant />`), gated on *any* of `employees.view` / `leave.view` /
  `onboarding.view` / `recruitment.view`, and posting to `/assistant`.
- **Module-agnostic result cards.** `types.ts` replaces the employee-only payload
  with a generic `AgentCard` (`module`, `kind`, `tone`, `badge`, `title`,
  `subtitle`, `meta`, optional `avatar`); `agent-activity.tsx` maps `kind` → icon
  and `tone` → colour, rendering a `PersonAvatar` when present or a kind icon
  otherwise.
- After any mutation the current page reloads so the relevant index updates live;
  the employee directory still flashes a freshly-touched row.

## Cost / quota

The function-calling loop is kept, but made much cheaper per turn:

- **No trailing round-trip for the wording.** When a turn's tool calls all
  succeed — a lookup we can read straight from, or a mutation we just performed —
  the reply is synthesized from the results instead of spending another request to
  have the model phrase it. Both "do X for Y" **and** "tell me about Y" now cost
  **1 request** (down from 2–3). Only errors / unknown tools go back to the model
  to recover. Net effect: every turn is a single Gemini request — the floor.
- **No redundant lookups.** The prompt tells the model to act on a named record
  directly (the backend resolves the name) rather than calling `find_*` first.
- **Honest limit messages.** A `503` (brief overload) and a `429` (quota) now read
  differently; the `429` message reflects that the free tier has a small
  request cap and that enabling billing raises it, and uses the server's
  suggested retry delay when present.

## Notes

- No new environment or dependencies — same `GEMINI_API_KEY` and Gemini 2.5
  Flash. The free tier caps requests (e.g. 20/day on `generate_content_free_tier`),
  so heavy testing can still exhaust it — that is the quota, not a bug.
