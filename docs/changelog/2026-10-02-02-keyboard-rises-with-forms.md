# Forms rise with the keyboard, on iOS and Android

Fields were still covered by the keyboard after the redesign. The keyboard library the
app relied on can fail without any sign on the new architecture, and even when it worked
it only moved a field that was itself covered, so a form's button stayed hidden. Keyboard
handling is now built on React Native's own `Keyboard` events. The screen rises so the
focused field and the form's button stay in sight. It was tested in Expo Go on an Android
emulator. See [ADR 0065](../decisions/0065-keyboard-handling-on-the-core-keyboard-api.md).

## Highlights

- **The screen rises with the keyboard.** On sign-in and register, the focused field and
  the main button (Sign in, Create account) lift clear of the keyboard, footer included
  when it fits. The page settles back when the keyboard closes.
- **Every form, every screen.** `Page` and the entry screens share one keyboard-aware
  scroll view, so File Leave's reason field, Join's code field and any future form behave
  the same.
- **Right on both platforms.** Positions are measured relative to the scroll view, which
  holds whether or not the system resized the window. Android in Expo Go leaves the
  status bar out of `measureInWindow`, and this approach is unaffected by that.

## Mobile

- `lib/keyboard.ts`: one subscription to `keyboardWillShow`/`WillHide` (iOS) and
  `keyboardDidShow`/`DidHide` (Android), as `useKeyboardFrame()` and
  `currentKeyboardFrame()`, plus the iOS keyboard curve.
- `components/ui/keyboard-scroll.tsx`: `KeyboardScroll`. It adds a spacer the keyboard's
  height (with headroom) and reveals the focused field after layout and again once the
  keyboard settles. Props: `anchor` (a button to lift too), `topInset` (never hide the
  field under a bar), `fill` (a full-height form whose footer doesn't jump), `offset` (a
  shared scroll value for the collapsing header). It exports `useKeyboardReveal()`.
- `Page` and `EntryScreen` use it (`EntryScreen` takes `keyboardAnchor`). Sign-in and
  register anchor their buttons. `Input` reveals itself on focus and as a multiline
  field grows. `Sheet` lifts its panel by the keyboard's height.
- Removed `react-native-keyboard-controller` and its provider.
- `SplashScreen.setOptions` is skipped in Expo Go, which doesn't support it and showed a
  warning on every launch.

## Notes

- **Verified:** on an Android 14 emulator running Expo Go 57.0.9, against a local server on
  a scratch database (dropped afterwards). The checks covered sign-in with each field
  focused, the keyboard's next and go keys, register's last field, the leave form's
  reason field, switching fields with the keyboard up, and closing it. `tsc` and
  `expo lint` are clean, and `expo export` bundles both platforms.
- iOS runs the same code but wasn't run here: there is no iOS simulator on Linux.
- On Android the page moves just after the keyboard, since Android sends no "will"
  events. On iOS the two move together.
