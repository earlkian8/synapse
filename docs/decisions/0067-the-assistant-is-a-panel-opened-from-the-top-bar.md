# 0067 — The assistant is a panel opened from the top bar, and its replies show their work as a trace

- **Status:** Accepted
- **Date:** 2026-10-05
- **Related:** module doc: [Assistant](../modules/assistant.md);
  [0049 — Prompt-injection defences](./0049-assistant-prompt-injection-defences.md) (the
  Confirm card); [0060 — A first-run tour](./0060-a-first-run-tour-of-the-app.md) (the
  tour's last stop).

## Context

The assistant was a round button fixed 20 px from the bottom-right corner of every page,
which opened a floating 400 × 640 window over the same corner. Three problems came from
that, and the people using it raised two of them:

1. **It covered the page.** The app footer sits at the bottom of every page. Its right
   side holds *Privacy*, *Terms*, *Support* and *Docs*, and the button sat on *Support*
   and *Docs*. Tables put their pagination in that corner, and forms put their buttons
   there. The open window covered more of the same.
2. **It looked like every other chatbot.** Sparkles on the button, the header, every
   reply and the empty state; a ping animation that never stopped; a gradient header;
   speech bubbles with an avatar tile on each reply; a sheen sweeping across every
   result card; and rotating "Reading your request… Almost there…" labels that described
   nothing. It read as generic AI chrome, not as part of an HR system.
3. **It slowed the answer down.** A finished reply was revealed one step at a time, 520 ms
   per step, and the text only appeared after that. Then it was typed out at 3
   characters per frame. Five steps and a long reply meant several seconds of waiting
   for text that had already arrived.

## Decision

**The button is in the top bar.** It is labelled *Assistant*, sits beside Help and the
notifications bell, and shows whether the panel is open (`aria-expanded`). ⌘J / Ctrl+J
opens and closes it from anywhere. Nothing floats over the page. The panel's open state
lives in `features/assistant/launcher.ts`, a small external store
(`useSyncExternalStore`), so the button and the panel always agree, and the Help
Center's *Ask the assistant* keeps working through the same `open(prompt)`.

**The panel is at the right edge, full height.**

- **≥ 1280 px:** it docks beside the page as a second inset card, matching the inset
  sidebar layout, and the page narrows to make room. Nothing is covered, including the
  footer. Its header is the same height as the top bar, so the two line up.
- **640–1279 px:** a sheet over the page's right side. The page stays usable beside it.
- **< 640 px:** it takes the screen.

It is 420 px wide, or 640 px for long replies and tables. The choice is remembered
(`synapse.assistant.wide`). Escape closes the history first, then the panel, and focus
goes back to the button. While the panel is open, the app's toasts move beside it, or
above the composer on a phone, through `--app-toast-offset-right` and the existing
bottom offsets. An action the assistant took is no longer also toasted while the panel is
open, because its receipt is already in the chat. It is toasted only when the panel was
closed before the reply came back.

**A reply shows its work as a trace.** What the assistant read and what it changed are
drawn as a line of nodes. A *read* is a hollow node, a *change* a filled teal one, an
action *held for your OK* amber, and an *error* red. The mark on the button uses the
same vocabulary, a hollow node joined to a filled one, because the assistant finds things
out and does things. A trace longer than three steps folds into one sentence that says
what is in it ("Read 4 sources and made 1 change"). The reply follows as plain reading
text, without a bubble. What the turn produced follows as receipts in one ruled list,
each line saying in its own colour what happened. Then come any Confirm cards. Once
answered, a Confirm card shrinks to a line that says how it was answered.

**Motion only says what changed.** The trace's rows settle in with a 70 ms stagger, and
the reply is never held back behind them. The reply is written out in about 70 frames
(roughly a second) whatever its length, and not at all under reduced motion. While a turn
is in flight the panel shows one pulsing node, *Working*, and from the third second the
real elapsed time. The server does the whole turn in one request, so it has no live steps
to show, and the old rotating labels were invented.

**The colours are tokens.** `--assistant-ink` (the brand navy) carries what the person
wrote and the primary buttons. `--assistant-signal` (the brand teal) marks what the
assistant read or did. `--assistant-signal-text` is a deeper teal for links and outlines,
because `#0ABFBF` is about 2.3:1 on white and fails as text. Each has a dark-mode value.

**Smaller fixes.** *Delete* and *Delete all conversations* ask once more, in place.
History rows show when each thread was last used. Suggestions are split into *Ask*
(answered from the record) and *Do* (changes it, and may wait for Confirm). Picking one
types it into the composer and never sends it, since every turn spends quota. Files can
be dropped anywhere on the panel. Copy and Regenerate are always shown on the latest
reply, and on hover or keyboard focus for older ones.

## Consequences

- No page needs to keep its bottom-right corner clear for the assistant any more. The
  footer's links can always be reached.
- On a wide screen, opening the assistant narrows the page by 428 px, or 648 px when
  wide. Pages already reflow for the sidebar, and the sidebar layout's content area now
  has `min-w-0` so it can shrink below its content's width.
- The tour's assistant stop points at the top-bar button, with the other top-bar stops'
  placement.
- Two Help Center articles described "the sparkle button in the bottom-right corner".
  They now describe the button in the top bar and the shortcut.
- The server, the API and the stored shape of steps and cards are unchanged. Old turns
  whose steps carry no `kind` are drawn as changes, as before.
