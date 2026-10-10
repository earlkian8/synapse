# The assistant, redesigned: a panel from the top bar, and replies that show their work

The assistant was a round sparkle button fixed over the bottom-right corner of every
page. It sat on the footer's *Support* and *Docs* links and on whatever a page put in
that corner. It opened a floating window built from stock AI-chat parts: sparkles
everywhere, a ping that never stopped, a gradient header, bubbles with avatars, a sheen
on every card, and rotating "Almost there…" labels. Its button is now in the top bar.
It opens as a panel at the right edge that, on a wide screen, docks beside the page
instead of covering it. Its replies show what it read and what it changed as a trace.
See [ADR 0067](../decisions/0067-the-assistant-is-a-panel-opened-from-the-top-bar.md) and
the [module doc](../modules/assistant.md#frontend).

## Highlights

- **Nothing covers the page.** The *Assistant* button sits beside Help and the bell, and
  ⌘J / Ctrl+J toggles it from anywhere. At ≥ 1280 px the panel is a second inset card
  and the page narrows to make room, so the footer's links stay reachable. From 640 px
  it is a sheet over the right side, and on a phone it takes the screen. It is 420 px
  wide, or 640 px when widened, and the choice is remembered.
- **Replies show their work.** What the assistant read (a hollow node), changed (filled
  teal), held for your OK (amber) or failed at (red) is drawn as one connected trace.
  Past three steps it folds into a sentence ("Read 4 sources and made 1 change"). The
  reply is plain reading text with no bubble. What the turn produced is listed as
  receipts, then any Confirm cards. An answered card shrinks to one line.
- **Honest states.** *Working* shows the real elapsed time and no invented step names.
  The reply is no longer held back 520 ms per step, and it is written out in about a
  second whatever its length (instantly under reduced motion). A failed turn shows the
  server's reason, such as "not configured yet", instead of a bare status code.
- **A new conversation says what the assistant is for**, in two sentences, with starting
  points split into *Ask* and *Do*, each only if your role allows it. Picking one types
  it; it never sends.
- **Safer history.** Deleting a conversation, or all of them, asks once more, in place.
  Each row shows when it was last used, and previews are plain text.
- **Keyboard and screen readers.** Escape closes the history, then the panel, and focus
  returns to the button. Answering an in-place confirmation returns focus to the button
  that started it. Copy and Regenerate show on keyboard focus as well as hover, and on
  the latest reply always. *Working*'s ticking seconds are not read aloud.

## Frontend

- `features/assistant/launcher.ts`: the open state is now an external store
  (`useAssistantOpen`), with `open(prompt)`, `close()` and `toggle()`. The Help Center's
  *Ask the assistant* still works through `open(prompt)`, and the cursor lands after the
  typed question.
- New `components/assistant-button.tsx` (the top-bar button and shortcut) and
  `components/assistant-mark.tsx` (a hollow node over a filled one, in place of
  Sparkles).
- `components/assistant.tsx`: rewritten as the docked panel. The header lines up with
  the top bar, and the conversation's title opens the history. Files drop anywhere on
  the panel. Toasts move beside the panel while it is open. An action is toasted only
  if the panel was closed when its reply arrived, because the receipt is in the chat.
- `components/agent-activity.tsx`: `WorkTrace`, `Receipts` and `ConfirmCard` replace the
  timed `AgentActivity` reveal. The Confirm card's expiry timer no longer fires at once
  for an expiry past `setTimeout`'s 24.8-day limit.
- `message-item.tsx`, `message-list.tsx`, `composer.tsx`, `conversation-list.tsx`,
  `markdown.tsx`: restyled to the above. A conversation opened from history starts at
  its latest turn, and a *Latest* button returns there.
- `api.ts`: failures carry the server's `reply` or `error` text, and a rate-limit 429
  says to wait a minute.
- `resources/css/app.css`: `--assistant-ink`, `--assistant-signal` and
  `--assistant-signal-text` (light and dark), and the trace's keyframes, which respect
  reduced motion. The sheen keyframe is gone. `assistant-pop` stays for the tour's
  finish card.
- `components/app-sidebar-header.tsx`: hosts the button. The search field is sized by
  the bar's own width (a container query), so it gives way to the search icon when the
  docked panel narrows the bar, not only when the window is narrow.
- `components/ui/sonner.tsx`: reads `--app-toast-offset-right` (default 24 px, Sonner's
  own).
- `layouts/app/app-sidebar-layout.tsx`: the content area has `min-w-0`, so it can narrow
  beside the panel.
- `features/product-tour/steps.ts`: the assistant stop points at the top-bar button and
  mentions the shortcut.

## Backend

- `SystemGuide`: the assistant now tells people where it lives ("The Assistant button in
  the top bar, or ⌘J / Ctrl+J"), not "the sparkle button, bottom right".
- `HelpCenter` keywords for *Meet the assistant*: `shortcut` and `ctrl+j` replace
  `sparkle`. Docblock wording in `AssistantAccess`.

## Docs

- Help Center: *Meet the assistant* and *Finding your way around* describe the button,
  the shortcut, the trace, receipts and the history.
- ADR 0067; `modules/assistant.md` (intro, ADR list, Frontend); `modules/product-tour.md`;
  the docs index.

## Notes

- No server behaviour, endpoint or stored shape changed. Old turns whose steps carry no
  `kind` are drawn as changes, as before.
- The Confirm endpoint answers a malformed token (not 48 characters) with a validation
  redirect rather than JSON. Only a hand-made request can send one, so this change
  leaves it alone.
