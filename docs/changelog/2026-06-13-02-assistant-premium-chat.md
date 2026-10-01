# 2026-06-13 — Premium assistant: conversations, streaming & markdown

The floating assistant was rebuilt from an MVP chat box into a polished,
multi-conversation AI surface — the kind of experience users expect from
ChatGPT / Claude / Gemini — while keeping the same server-side, permission-gated
HR agent underneath.

## Highlights

- **Persistent, multi-conversation history** (server-side, per user, tenant-
  scoped). Threads survive across sessions and devices, auto-titled from the
  first message, grouped in the history drawer by **Pinned / Today / Yesterday /
  Previous 7 days / Older**, with search, rename, pin, delete and clear-all.
- **Markdown replies** — GitHub-flavoured (tables, lists, links, code, quotes),
  styled for the chat and theme-aware (light/dark).
- **Simulated streaming** — finished replies reveal word-by-word with a caret and
  a **Stop** button, so it feels live without reworking the agent loop.
- **Message controls** — copy, regenerate (last answer), edit a previous message
  (truncate & re-run), retry failed turns, hover timestamps.
- **Composer** — auto-growing textarea, drag-and-drop and paste-to-attach,
  multi-file chips with previews, Enter to send / Shift+Enter for newline, and
  per-conversation **draft preservation**.
- **Quality of life** — suggested prompts on an empty thread, scroll-to-bottom
  button, loading skeletons, smooth reveal animations, an **expand/shrink** panel,
  AI + result-card avatars, and the existing live toasts + directory row-flash on
  HR mutations.

## Backend

- **Migration** `create_assistant_conversation_tables`:
  - `assistant_conversations` (organization_id, user_id, title, pinned,
    last_activity_at).
  - `assistant_messages` (conversation_id, role, body, steps, actions,
    attachments, failed) — assistant turns persist their agent timeline + result
    cards so a reopened thread re-renders faithfully.
- **Models** `AssistantConversation` / `AssistantMessage` (tenant-scoped via
  `BelongsToOrganization`), with `forUser` scope, a `latestMessage` preview
  relation, `deriveTitle()` (a cheap title from the first message — **no extra AI
  call**, to protect the request budget), and `present()` API shapers.
- **Controllers**:
  - `AssistantController@send` now persists the user message, runs the agent with
    **server-derived history**, persists the reply, auto-titles the thread, and
    supports editing (`replace_message_id` truncates and re-runs).
  - `AssistantController@regenerate` re-runs the last user turn (reorder fix so it
    targets the newest, not oldest, message — history is preserved).
  - `AssistantConversationController` — index / show / update (rename + pin) /
    destroy / clear, each scoped to the signed-in user.
  - Busy/limit replies are returned as a **retryable** (failed) turn rather than a
    hard error, so the UI shows a Retry button.
- **Routes** under `/assistant/*` (turn, conversations CRUD, regenerate).

## Frontend

- New deps: `react-markdown` + `remark-gfm`.
- `features/assistant/` restructured: `use-assistant` (state/orchestration),
  `api` (conversation CRUD + turn + regenerate), and components `assistant`
  (shell), `conversation-list` (history drawer), `message-list`, `message-item`
  (markdown + streaming + controls), `markdown`, `composer`. `agent-activity`
  (result cards) is reused unchanged.

## Notes

- The agent, modules, permissions and cost optimizations are unchanged — this is
  a UI/UX + persistence layer on top. Free-tier Gemini limits still apply; titles
  and confirmations are generated without extra API calls to conserve them.
