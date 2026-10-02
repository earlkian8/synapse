/**
 * The app's motion vocabulary: a handful of springs and entrances, used everywhere
 * so the whole app moves the same way.
 *
 * The brief is iOS: things arrive quickly and settle without bouncing, a press gives
 * way under the finger, and nothing moves further than it needs to. Entrances travel
 * 12 points, not a screen's height. Reanimated follows the phone's Reduce Motion
 * setting by default (`ReduceMotion.System`), so each of these becomes a plain cut for
 * anyone who has asked for less movement.
 */
import { Platform } from 'react-native';
import { Easing, FadeIn, FadeInDown, type WithSpringConfig } from 'react-native-reanimated';

/** Springs, by job. Critically damped or close to it: settle, don't wobble. */
export const springs = {
  /** A press giving way, and coming back. */
  press: { damping: 22, stiffness: 420, mass: 0.6 },
  /** A control's thumb or indicator sliding to a new position. */
  snappy: { damping: 26, stiffness: 320, mass: 0.8 },
  /** A sheet, banner or overlay arriving. */
  gentle: { damping: 30, stiffness: 240, mass: 1 },
  /** A confirmation landing: the one place a little overshoot is earned. */
  pop: { damping: 14, stiffness: 260, mass: 0.7 },
} satisfies Record<string, WithSpringConfig>;

/** iOS's own ease for things that fade or slide on a clock rather than a spring. */
export const easeOut = Easing.bezier(0.22, 1, 0.36, 1);

/**
 * A block's entrance: fades in while rising 12pt. `index` staggers a list by 40ms a
 * row, capped so a long list never makes the last row wait.
 */
export function enter(index = 0) {
  const delay = Math.min(index, 8) * 40;

  // Reanimated's web layout animations don't support custom initial values: the block
  // is left positioned out of the flow. The web build (a development preview, not a
  // target) gets the fade without the rise.
  if (Platform.OS === 'web') {
    return FadeIn.duration(420).delay(delay);
  }

  return FadeInDown.duration(420)
    .delay(delay)
    .easing(easeOut)
    .withInitialValues({ opacity: 0, transform: [{ translateY: 12 }] });
}

/** A plain fade, for content swapping in place (a loaded value replacing a skeleton). */
export const fadeIn = FadeIn.duration(260).easing(easeOut);
