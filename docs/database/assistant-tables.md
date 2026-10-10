# Database: assistant tables

The tables behind the [Assistant](../modules/assistant.md)'s conversation history,
created by `2026_06_13_000000_create_assistant_conversation_tables` (`usage` added by
`2026_10_05_120000_add_usage_to_assistant_messages`, ADR 0068). Both are
tenant-scoped (a non-null `organization_id`, ADR 0005), omitted below for brevity, so a
person's threads in one workspace are not visible from another.

The assistant has no tables for what it *does*. Its tools call the same support
classes as the screens, so a write lands in that module's own tables and in
`activity_logs`. A write held for confirmation lives in the cache, not here (see
[Held actions](#held-actions)).

## `assistant_conversations`

One chat thread, owned by one user.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `user_id` | FK → users | Cascade on delete. Every endpoint checks the thread belongs to the signed-in user and answers 404 otherwise. |
| `title` | string, nullable | Derived from the first user message (`AssistantConversation::deriveTitle()`: whitespace collapsed, cut at 48 characters). No model call is spent on it. Renamable. |
| `pinned` | boolean | Default false. Pinned threads sort first. |
| `last_activity_at` | timestamp, nullable | Touched on every turn, failed or not. |
| timestamps | | |

**Indexes:** `(user_id, pinned)`, `(user_id, last_activity_at)`.

`scopeForUser()` orders a list by `pinned`, then `last_activity_at`, then `id`, all
descending.

## `assistant_messages`

The turns of a thread.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `conversation_id` | FK → assistant_conversations | Cascade on delete. Indexed. |
| `role` | string | `user` or `assistant`. |
| `body` | text, nullable | The message, or the reply. A user turn with only files reads `[N files attached]`. Replies have already been through `ReplyGuard` (no images, no links off the app). |
| `steps` | json, nullable | Assistant turns: the timeline the chat animates — `{label, status, detail, kind}` per step, where `status` is `done`, `error` or `held` and `kind` is `read` or `action`. |
| `actions` | json, nullable | Assistant turns: the result cards (`module`, `kind`, `tone`, `badge`, `title`, `subtitle`, `meta`, `avatar`, `id`). A confirmation card also carries `confirmation: {token, state, expires_at}` and `plan: [{title, detail}]`, the steps it would run in order (ADR 0068). Once it is answered, the server sets `state` to `confirmed` or `cancelled` and removes the token. The chat shows an unanswered card as `expired` after `expires_at`. |
| `usage` | json, nullable | Assistant turns since ADR 0068: what the turn cost, summed over its model requests — `{requests, prompt_tokens, output_tokens, cached_tokens, thinking_tokens}`. |
| `attachments` | json, nullable | User turns: one `{name, mime, size, path}` per file (ADR 0068). The file itself is kept on the private `assistant` disk at `path` (`<organization_id>/<conversation_id>/<random>.<ext>`) for the life of the conversation, so a tool can file it on a record. Rows from before ADR 0068 hold bare names, and nothing was kept for them. |
| `failed` | boolean | Default false. In practice a failed turn is **not** saved: the user message is kept and the client gets a retryable notice with no row behind it. |
| timestamps | | |

The history replayed into a new turn is the last 20 messages with a body, each capped
at 4,000 characters.

## Editing, regenerating and clearing

- **Editing** a sent message (`replace_message_id`) deletes that message and every
  later one in the thread, then sends the new text.
- **Regenerating** deletes everything after the last user message and runs it again,
  with the files that message carried.
- **Deleting** a thread deletes its files from the `assistant` disk too.
- **Clearing** history deletes all of the user's threads in the current workspace, and
  their files.

## Held actions

A plan of writes the assistant holds for the user's OK
([ADR 0049](../decisions/0049-assistant-prompt-injection-defences.md),
[ADR 0068](../decisions/0068-the-assistant-finishes-the-job.md)) is stored in the
**cache** by `Services\Assistant\Security\PendingActions` as `{steps: [{tool, args,
title}]}`, under an unguessable token, for 15 minutes. An entry written before plans
(`{tool, args, summary}`) is read as a plan of one. The entry is bound to the user, the workspace and the conversation,
and it is claimed atomically when confirmed, so it can run only once. Nothing about
it is written to the database except the card in `assistant_messages.actions`.
