# 0065 — Keyboard handling on React Native's own Keyboard API, not a native keyboard library

- **Status:** Accepted
- **Date:** 2026-10-02
- **Supersedes:** the keyboard part of
  [0064 — The mobile app is designed as an iOS app](./0064-the-mobile-app-is-designed-as-an-ios-app.md)
  (its use of `react-native-keyboard-controller`).
- **Related:** module doc: [Mobile app](../modules/mobile-app.md).

## Context

ADR 0064 handed keyboard handling to `react-native-keyboard-controller`, which is in
Expo Go's SDK 57 bundle. A field was still covered by the keyboard in use. Three things
were behind that, and an Android emulator running Expo Go showed each of them:

1. **The library can fail without a sign.** It attaches its handlers through a native
   view tag that, on the new architecture, sometimes doesn't resolve ("Can not attach
   worklet handlers … view tag can not be resolved",
   [kirillzyusko/react-native-keyboard-controller#1411](https://github.com/kirillzyusko/react-native-keyboard-controller/issues/1411),
   open with no fix). When that happens, `KeyboardAwareScrollView`, the library's
   `KeyboardAvoidingView` and its hooks all stop moving content. Its provider has
   taken over Android's keyboard insets, so the system no longer helps either.
2. **It only scrolls when the focused field itself is covered**
   ([docs](https://kirillzyusko.github.io/react-native-keyboard-controller/docs/api/components/keyboard-aware-scroll-view)).
   On the sign-in screen the fields sit above the keyboard, so nothing moved while the
   Sign in button and the footer stayed under it. People expect the screen to rise.
3. **Android measures in two frames.** `measureInWindow` leaves out the status bar
   unless React Native's edge-to-edge flag is on (`RootViewUtil.getViewportOffset`),
   and it is off in Expo Go. The keyboard's `screenY` never leaves it out. Comparing
   the two directly misses by the status bar's height. Expo Go also resizes the window
   for the keyboard, which an edge-to-edge build does not.

## Decision

**The keyboard is read from React Native's own `Keyboard` events** (`lib/keyboard.ts`).
That means `keyboardWillShow`/`keyboardWillHide` on iOS, with the keyboard's duration,
and `keyboardDidShow`/`keyboardDidHide` on Android, which React Native derives from the
window's IME insets. These can't fail to attach. One subscription serves the app through
`useKeyboardFrame()`.

**`KeyboardScroll` keeps the focused field, and the form's button, above the keyboard**
(`components/ui/keyboard-scroll.tsx`). It is the scroll view of every `Page` and every
entry screen:

- When the keyboard rises, an invisible spacer as tall as the keyboard (plus headroom
  for a toolbar row) goes under the content, so there is always room to scroll that
  far. When the keyboard goes, the spacer shrinks over the keyboard's own duration and
  curve, and the page settles back with it.
- The page then scrolls just enough to put the focused field, plus a margin, above the
  keyboard. A form can name its main button as an `anchor` (sign-in, register), and the
  page rises until the button clears the keyboard too. The focused field is never
  pushed up under the top of the screen or the navigation bar (`topInset`).
- **Everything is measured relative to the scroll view**: the field and the scroll view
  both by `measureInWindow`, so any frame offset cancels. The keyboard is measured by
  how much of the screen it covers from the bottom. The visible area ends at the nearer
  of two bottoms: the scroll view's bottom now (a window the system resized), or its
  resting bottom less the keyboard (a window it didn't). That is correct on iOS, on
  Android in Expo Go, and in edge-to-edge builds.
- The reveal runs once the keyboard's room is laid out, and again when the keyboard has
  finished moving. Each pass does nothing if nothing needs to move. Focusing another
  field, or a multiline field growing as it is typed into, reveals again (`Input` asks
  through `useKeyboardReveal()`).
- `fill` keeps a footer at the bottom of a short form without it jumping when the
  keyboard's room is added below.

**Sheets** lift their panel by the keyboard's height on the keyboard's timing.
`react-native-keyboard-controller` is removed.

## Consequences

- Verified on an Android 14 emulator in Expo Go (SDK 57): sign-in with Email or
  Password focused (the Sign in button and the footer clear the keyboard), the next
  key moving between fields, register's last field, the leave form's multiline reason,
  and the page settling back when the keyboard closes. iOS uses the same code. Its
  keyboard events carry absolute coordinates and its window never resizes, but it has
  not been run here, as there is no iOS simulator on Linux.
- A new screen with fields gets this by being a `Page` or an `EntryScreen`. A form whose
  button should rise with the keyboard passes the button's wrapper as `anchor`
  (`keyboardAnchor` on `EntryScreen`).
- The keyboard no longer moves the page frame by frame on Android. Android sends no
  "will" events, so the page moves just after the keyboard. On iOS it moves together
  with it.
