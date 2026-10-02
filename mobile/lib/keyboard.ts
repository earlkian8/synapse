/**
 * Where the keyboard is, from React Native's own `Keyboard` events.
 *
 * This is deliberately built on the core API rather than on a native keyboard library.
 * `react-native-keyboard-controller` (tried first) attaches its handlers through a
 * native view tag. On the new architecture that tag sometimes doesn't resolve
 * (kirillzyusko/react-native-keyboard-controller#1411, open), and when it doesn't,
 * nothing moves and nothing says why. The core events can't fail that way.
 *
 * - iOS sends `keyboardWillShow`/`keyboardWillHide` before the keyboard animates, with
 *   its duration, so the page can move *with* it.
 * - Android sends only `keyboardDidShow`/`keyboardDidHide`. React Native derives them
 *   from the window's IME insets, so they arrive under edge-to-edge too, where the
 *   system no longer resizes the window for the keyboard (SDK 54+).
 *
 * `top` is the keyboard's top edge in window coordinates on both platforms
 * (`endCoordinates.screenY`), the same frame `measureInWindow` reports in. One
 * subscription serves the whole app.
 */
import { useSyncExternalStore } from 'react';
import { Easing } from 'react-native-reanimated';
import { Keyboard, Platform, type KeyboardEvent } from 'react-native';

export type KeyboardFrame = {
  visible: boolean;
  /** The keyboard's top edge, in window coordinates. */
  top: number;
  height: number;
  /** How long the keyboard takes to move, in ms (iOS reports it; Android doesn't). */
  duration: number;
};

let frame: KeyboardFrame = { visible: false, top: 0, height: 0, duration: 250 };
const listeners = new Set<() => void>();
let started = false;

function publish(next: KeyboardFrame) {
  frame = next;
  listeners.forEach((listener) => listener());
}

const toFrame = (event: KeyboardEvent, visible: boolean): KeyboardFrame => ({
  visible,
  top: event.endCoordinates.screenY,
  height: visible ? event.endCoordinates.height : 0,
  duration: event.duration || 250,
});

/** Listens once, for the life of the app: the keyboard's state is global anyway. */
function start() {
  if (started) return;
  started = true;

  const show = Platform.OS === 'ios' ? 'keyboardWillShow' : 'keyboardDidShow';
  const hide = Platform.OS === 'ios' ? 'keyboardWillHide' : 'keyboardDidHide';

  Keyboard.addListener(show, (event) => publish(toFrame(event, true)));
  Keyboard.addListener(hide, (event) => publish(toFrame(event, false)));
}

function subscribe(listener: () => void) {
  start();
  listeners.add(listener);
  return () => listeners.delete(listener);
}

const snapshot = () => frame;

/** The keyboard's current frame; re-renders when it shows, hides or changes height. */
export function useKeyboardFrame(): KeyboardFrame {
  return useSyncExternalStore(subscribe, snapshot, snapshot);
}

/** The keyboard's frame right now, for event handlers (no re-render). */
export function currentKeyboardFrame(): KeyboardFrame {
  start();
  return frame;
}

/** The curve iOS moves its keyboard on, near enough, so what follows it moves as one. */
export const keyboardEasing = Easing.bezier(0.17, 0.59, 0.4, 0.77);
