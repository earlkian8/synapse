# The Assistant Finishes the Job — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The chat assistant completes multi-step requests (including filing an attached CV onto a candidate) at a fraction of today's tokens, with one Confirm per plan and a Manual / Balanced / Auto mode.

**Architecture:** `Assistant::handle()` becomes a real agent loop over a per-turn routed tool set (`Routing\ToolRouter` + a `load_tools` meta-tool). Held writes accumulate into one plan in `Security\PendingActions`. Uploads persist on a private disk and are resolved by number/name through `Attachments\ConversationAttachments`.

**Tech Stack:** Laravel 13 / PHP 8.3, Pest 4, Gemini REST (`generateContent`), React 19 + Inertia + TypeScript + Tailwind v4.

**Spec:** `docs/decisions/0068-the-assistant-finishes-the-job.md`

## Global Constraints

- No mutating git. Commit text goes to `docs/commit-messages/2026-10-05-03-assistant-finishes-the-job.txt`, and the changelog to `docs/changelog/2026-10-05-03-assistant-finishes-the-job.md`.
- No real Gemini calls. Every test binds a stub `GeminiClient`, and the key stays empty.
- Limits: `MAX_STEPS = 8`, `MAX_CALLS = 15`, `MAX_WRITES = 5`, router selects at most 6 modules, plan ≤ 5 steps, `PendingActions::TTL_MINUTES = 15`.
- Modes: `manual | balanced | auto`, default `balanced`. Consequential tools and question turns hold in every mode.
- Permission is the allow-list. Routing changes what is shown, never what may run.
- Chat uploads go on the private `assistant` disk, never `public`.
- Pest: run serially on `synapse_test` with the env overrides in memory. `php -l`, then Pint on the changed files.
- Frontend: tsc, ESLint, Prettier and `npm run build` must all be green.

## Review Focus

1. An attachment reference to another user's or another conversation's file must resolve to nothing. Test in Task 3.
2. A model that repeats the same write must not run it twice. Test in Task 5.
3. A plan whose step 1 fails must not run step 2, and the reply must say which steps never ran. Test in Task 6.
4. A `load_tools` call naming a module the user lacks must load nothing and reveal nothing. Test in Task 4.
5. A legacy `attachments` row (a list of plain names) must still present and replay. Test in Task 3.

---

### Task 1: Gemini client — configurable sampling, one retry, usage

**Files:** Modify `app/Support/Ai/GeminiClient.php`, `config/services.php`, `app/Providers/AppServiceProvider.php`. Test `tests/Unit/GeminiClientTest.php` (new).

**Produces:** `GeminiClient::__construct(?string $apiKey, string $model, string $baseUrl = …, ?float $temperature = null, ?string $thinkingLevel = null)`. `generate()` returns the decoded response unchanged, `usageMetadata` included.

- [ ] Tests (`Http::fake`):
  - `gemini-3*` sends no temperature; `gemini-2.5-flash` sends 0.2; `GEMINI_TEMPERATURE=0.7` sends 0.7.
  - Thinking level is sent as `generationConfig.thinkingConfig.thinkingLevel` only when set.
  - A `MALFORMED_FUNCTION_CALL` candidate, or no candidates, is retried once (2 requests).
- [ ] Implement, run, and pass.

### Task 2: Request intent

**Files:** Create `app/Services/Assistant/RequestIntent.php` (moves `isQuestion` out of `Assistant`). Test `tests/Unit/Assistant/RequestIntentTest.php`.

**Produces:** `RequestIntent::isQuestion(string $message): bool`.

- [ ] Tests:
  - "hey can you put this person in cybersecurity analyst" → false.
  - "please approve Maria's leave" → false.
  - "can you tell me who is on leave?" → true.
  - "what is Maria's phone?" → true.
  - "put him in Offer" → false.
  - "kumusta si Maria" → true.
  - "pls attach the cv" → false.
- [ ] Implement: strip leading greetings and politeness (`hey, hi, hello, please, pls, kindly, po, uh`) and commas. Then "can / could / would / will you" + an imperative counts as an instruction. Add the spec's verbs.

### Task 3: Attachments stored, numbered, resolvable

**Files:**
- Create `app/Services/Assistant/Attachments/ConversationAttachments.php` and `StoredAttachment.php`.
- Modify `config/filesystems.php` (`assistant` disk), `AssistantController`, `AssistantConversationController` (delete files), `AssistantMessage::present()`.
- Migration adding `usage` (json, nullable) to `assistant_messages`.
- Test `tests/Feature/Assistant/AttachmentsTest.php`.

**Produces:**
- `ConversationAttachments` is container-scoped:
  - `use(?AssistantConversation $c): void`;
  - `all(): list<StoredAttachment>` (numbered 1..n in upload order);
  - `resolve(mixed $ref): ?StoredAttachment` (a number, `"1"`, `"#1"` or a file name, case-insensitive);
  - `latestForReplay(): list<StoredAttachment>`;
  - `static store(AssistantConversation, UploadedFile): array{name,mime,size,path}`;
  - `static purge(AssistantConversation): void`.
- `StoredAttachment`: `int $number`, `string $name`, `string $mime`, `int $size`, `string $path`, `bool $current`. Methods: `contents(): string`, `extension(): string`, `copyTo(string $disk, string $directory): string`.

- [ ] Tests:
  - An upload is stored on the `assistant` disk and its row holds `{name,mime,size,path}`.
  - `present()` still returns names, for both new and legacy rows.
  - `resolve(1)` and `resolve('cv.pdf')` work.
  - A reference to another conversation's file → null, and another user's → null.
  - Deleting a conversation removes its files, and so does clearing all conversations.

### Task 4: Tool routing and `load_tools`

**Files:** Create `app/Services/Assistant/Routing/ToolRouter.php`. Test `tests/Feature/Assistant/RoutingTest.php`.

**Produces:**
- `ToolRouter::select(User $u, array $modules, string $message, array $recentModules, bool $hasAttachments, ?ContextBrief $brief): list<string>` returns module keys, ≤ 6, with `guide` always included.
- `ToolRouter::summary(string $key): string` is the catalogue line.
- `ToolRouter::ATTACHMENT_MODULES = ['recruitment','employee-records']`.

- [ ] Tests:
  - "put this cv in the cybersecurity analyst posting" selects `recruitment`.
  - "approve Maria's leave" selects `leave`.
  - The tools sent to the model are only those of the selected modules plus `load_tools`.
  - A model `load_tools(['training'])` → the next request carries the training tools and guidance.
  - `load_tools(['users'])` for a user without `users.view` → nothing loaded, and the response names no module.
  - A permitted but unshown tool still runs, while an unpermitted one is refused and logged as before.

### Task 5: The loop — continue, repeat guard, plans, modes, history, usage

**Files:** Modify `app/Services/Assistant/Assistant.php`, `TurnState.php`, `Security/PendingActions.php`. Tests: `tests/Feature/Assistant/AgentLoopTest.php` (new); update `AssistantGuardTest.php`.

**Produces:**
- `Assistant::handle(User, string, array $history, array $fileParts, ?int $conversationId, string $mode = 'balanced'): array{reply, steps, actions, usage}`.
- `PendingActions::hold(User, ?int, list<array{tool,args,title}> $steps): string` and `extend(string $token, array $step): void`; `take()` returns `steps`.
- History items may carry `steps` (labels) and `modules`.

- [ ] Tests:
  - Find → add → text: both calls run, and the reply is the model's text.
  - The same write repeated runs once.
  - With an attachment in balanced mode, `add_application` + `move_application` give one confirm card with 2 steps, and nothing runs.
  - Manual: a plain-instruction write is held.
  - Auto + attachment: the write runs.
  - Auto: a confirmTool is held, and a question-turn write is held.
  - The 6th write is refused.
  - `usage` sums `promptTokenCount` / `candidatesTokenCount` / `cachedContentTokenCount` / `thoughtsTokenCount` and counts requests.
  - A SAFETY finish gives a polite refusal.
  - History lines include "[Steps: …]".
- [ ] Update existing guard tests whose assumption was "stop after one successful step". Each change is stated in the changelog.

### Task 6: Confirming a plan

**Files:** Modify `AssistantActionController.php`, `Assistant::execute()` → `executePlan(User, list $steps): array`. Tests: extend `AgentLoopTest.php`.

- [ ] Tests:
  - Confirm runs the steps in order, gives one reply listing each, and the card is marked confirmed.
  - Step 1 fails → step 2 is not run, and the reply says "not run".
  - The attachment reference resolves against the plan's conversation at confirm time.
  - A legacy single-call payload (`tool`/`args`) still confirms.

### Task 7: Filing attachments in modules

**Files:** Modify `RecruitmentModule.php` (`resume_attachment`, `document_attachments` on `add_applicant` / `add_application` / `update_applicant`; `add_application.stage` optional), `EmployeeRecordsModule.php` (`add_employee_document`, confirmed, `employees.manage-documents`). Create `app/Support/Employees/EmployeeDocuments.php` (used by `EmployeeDocumentController::store` too). Tests: `tests/Feature/Recruitment/RecruitmentAttachmentAssistantTest.php`, and extend the employee records assistant test.

- [ ] Tests:
  - **The reported turn:** "put this person in cybersecurity analyst as an offer" with a PDF, in balanced mode → one card with 2 steps. Confirm → applicant created with `resume` on the public disk, application at the Offer stage, activity logged.
  - A `.txt` attachment as a résumé → a validation error naming the allowed types.
  - Auto mode with the same turn → done in the turn.
  - `add_employee_document` stores the file on the employee and is always held.

### Task 8: Frontend — mode switch, plan card, cost line

**Files:** `resources/js/features/assistant/{types.ts, api.ts, use-assistant.ts, components/composer.tsx, components/agent-activity.tsx, components/message-item.tsx}`.

- [ ] Add `AssistantMode = 'manual' | 'balanced' | 'auto'`, sent as `mode` on send and regenerate and stored in `localStorage` `synapse.assistant.mode` (wrapped in try/catch).
- [ ] The composer gets a compact mode menu with one-line descriptions; Auto's line states its trade-off.
- [ ] The confirm card lists `plan` steps (numbered) when present.
- [ ] The trace footer shows `usage` as "N model calls · X.Xk tokens".
- [ ] tsc, ESLint, Prettier and the build all pass. Check visually in a browser with a seeded conversation.

### Task 9: Docs, full suite, handoff

- [ ] Update `docs/modules/assistant.md` (turn flow, routing, plans, modes, attachments, configuration) and the `docs/database` assistant tables (`usage`, attachments shape).
- [ ] Add the changelog entry and the commit-message text. Update the memory notes (the DB is now local, and the 4 endpoint tests are now hermetic).
- [ ] Run the full Pest suite serially and report the real count.
