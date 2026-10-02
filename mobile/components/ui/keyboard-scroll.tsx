import {
  createContext,
  useContext,
  useEffect,
  useEffectEvent,
  useRef,
  useState,
  type ReactNode,
  type RefObject,
} from 'react';
import {
  Dimensions,
  Platform,
  StyleSheet,
  TextInput,
  View,
  type ScrollViewProps,
  type StyleProp,
  type ViewStyle,
} from 'react-native';
import Animated, {
  scrollTo,
  useAnimatedRef,
  useAnimatedStyle,
  useScrollOffset,
  useSharedValue,
  withTiming,
  type SharedValue,
} from 'react-native-reanimated';
import { scheduleOnUI } from 'react-native-worklets';

import { currentKeyboardFrame, keyboardEasing, useKeyboardFrame } from '@/lib/keyboard';

/** Extra room under the content while the keyboard is up, for a keyboard that grows a
 *  suggestion or tool row as typing starts. Invisible: it sits beneath the keyboard. */
const KEYBOARD_HEADROOM = 120;
/** Long enough for the keyboard's room to be laid out before scrolling into it. */
const REVEAL_DELAY = 60;

/** Lets a field ask its scroll view to bring it above the keyboard (on focus, as it grows). */
const RevealContext = createContext<(() => void) | null>(null);

/** For `Input`: the reveal of the nearest keyboard-aware scroll view, if there is one. */
export function useKeyboardReveal(): (() => void) | null {
  return useContext(RevealContext);
}

type KeyboardScrollProps = Omit<ScrollViewProps, 'contentContainerStyle'> & {
  children: ReactNode;
  contentContainerStyle?: StyleProp<ViewStyle>;
  /**
   * How much room to keep between the focused field and the keyboard. Generous on a
   * sign-in form, so the field's own action comes up with it.
   */
  revealOffset?: number;
  /** A shared value to keep the scroll position in, for a header that reacts to it. */
  offset?: SharedValue<number>;
  /**
   * The content fills the screen at least, so a footer can sit at the bottom (`flexGrow`
   * a spacer above it). The keyboard's room is added *below* that, so the footer stays
   * where it is when the keyboard rises instead of jumping up the page.
   */
  fill?: boolean;
  /**
   * The form's main action. While a field in the form has focus, the page also lifts
   * this above the keyboard, so the screen rises to show the whole form down to its
   * button rather than only the field being typed in.
   */
  anchor?: RefObject<View | null>;
  /** Space the focused field must keep from the top: a navigation bar over the content. */
  topInset?: number;
};

/**
 * A scroll view that keeps the focused field in sight of the keyboard, on iOS and
 * Android alike.
 *
 * When the keyboard rises, an invisible spacer the keyboard's height goes under the
 * content (so there is always room to scroll that far), and the page scrolls just
 * enough to put the focused field, plus `revealOffset`, above the keyboard's top edge.
 * On iOS this starts on `keyboardWillShow`, so the page and the keyboard move together.
 * When the keyboard goes, the spacer shrinks over the keyboard's own duration and on
 * its curve, so the page settles back as the keyboard leaves rather than jumping.
 * Moving to another field while the keyboard is up reveals that one too.
 */
export function KeyboardScroll({
  children,
  revealOffset = 24,
  offset,
  fill,
  anchor,
  topInset = 0,
  contentContainerStyle,
  keyboardDismissMode,
  onLayout,
  ...rest
}: KeyboardScrollProps) {
  const ref = useAnimatedRef<Animated.ScrollView>();
  const scrollY = useScrollOffset(ref, offset);
  const keyboard = useKeyboardFrame();
  const spacer = useSharedValue(0);
  const container = useRef<View>(null);
  // The scroll view's height with the keyboard down: where its visible area ends at rest.
  const restHeight = useRef(0);
  const [viewport, setViewport] = useState(0);

  // The screen's height less the content's vertical padding: what `fill` stretches to.
  const pad = StyleSheet.flatten(contentContainerStyle) ?? {};
  const vertical = (edge: 'Top' | 'Bottom') =>
    Number(pad[`padding${edge}`] ?? pad.paddingVertical ?? pad.padding ?? 0) || 0;
  const fillHeight = Math.max(0, viewport - vertical('Top') - vertical('Bottom'));

  /**
   * Puts the focused field (and `revealOffset` below it) above the keyboard.
   *
   * Everything is measured relative to this scroll view, never in absolute terms:
   * Android's `measureInWindow` leaves out the status bar unless React Native's
   * edge-to-edge flag is on (it isn't in Expo Go), while the keyboard's `screenY` never
   * does, so comparing the two directly misses by the status bar's height. Measuring
   * the field and the scroll view the same way cancels any such offset.
   *
   * Where the visible area ends depends on whether the system resized the window for
   * the keyboard (Android with `adjustResize`, as in Expo Go) or not (iOS, and Android
   * edge-to-edge builds). Both are covered by taking the nearer of two bottoms: the
   * scroll view's own bottom now, and its resting bottom less what the keyboard covers.
   */
  const reveal = () => {
    const frame = currentKeyboardFrame();
    const input = TextInput.State.currentlyFocusedInput();
    const box = container.current;

    if (!frame.visible || !input || !box) return;

    box.measureInWindow((_boxX, boxY, _boxWidth, boxHeight) => {
      input.measureInWindow((_x, y, _width, height) => {
        const covered = Math.max(0, Dimensions.get('screen').height - frame.top);
        const keyboardTop = Math.min(boxHeight, (restHeight.current || boxHeight) - covered);
        const fieldTop = y - boxY;
        // A tall field (the multiline reason) needs its caret seen, not its whole height.
        const fieldBottom = fieldTop + Math.min(height, 160);

        const lift = (needed: number) => {
          // Never so far that the field itself slides up under the top of the screen.
          const overlap = Math.min(needed - keyboardTop, fieldTop - topInset - 12);

          if (overlap > 1) {
            const target = scrollY.get() + overlap;
            scheduleOnUI(() => {
              'worklet';
              scrollTo(ref, 0, target, true);
            });
          }
        };

        const target = anchor?.current;
        if (!target) {
          lift(fieldBottom + revealOffset);
          return;
        }

        target.measureInWindow((_ax, anchorY, _aw, anchorHeight) => {
          lift(Math.max(fieldBottom + revealOffset, anchorY - boxY + anchorHeight + 16));
        });
      });
    });
  };

  // The latest reveal, for the timers below, without restarting them on every render.
  const revealLater = useEffectEvent(() => reveal());

  // The keyboard rising, resizing or leaving.
  const settled = useRef(true);
  useEffect(() => {
    if (keyboard.visible) {
      // Room first, all at once (it sits under the keyboard, unseen), so the scroll
      // that follows is never clamped short: as much as the keyboard covers, plus
      // headroom for it growing a toolbar once typing starts.
      const covered = Math.max(Dimensions.get('screen').height - keyboard.top, keyboard.height, 0);
      spacer.set(covered + revealOffset + KEYBOARD_HEADROOM);
      settled.current = false;

      // Reveal once the room is laid out, and again once the keyboard has finished
      // moving: the second pass is a no-op unless something shifted in between.
      const first = setTimeout(revealLater, REVEAL_DELAY);
      const second = setTimeout(revealLater, keyboard.duration + REVEAL_DELAY);
      return () => {
        clearTimeout(first);
        clearTimeout(second);
      };
    }

    if (!settled.current) {
      spacer.set(withTiming(0, { duration: keyboard.duration, easing: keyboardEasing }));
      settled.current = true;
    }
  }, [keyboard.visible, keyboard.top, keyboard.height, keyboard.duration, revealOffset, spacer]);

  const spacerStyle = useAnimatedStyle(() => ({ height: spacer.get() }));

  return (
    <RevealContext.Provider value={reveal}>
      <View
        ref={container}
        style={styles.fill}
        collapsable={false}
        onLayout={(event) => {
          if (!currentKeyboardFrame().visible) restHeight.current = event.nativeEvent.layout.height;
        }}
      >
        <Animated.ScrollView
          ref={ref}
          keyboardShouldPersistTaps="handled"
          keyboardDismissMode={keyboardDismissMode ?? (Platform.OS === 'ios' ? 'interactive' : 'on-drag')}
          scrollEventThrottle={16}
          contentContainerStyle={contentContainerStyle}
          onLayout={(event) => {
            setViewport(event.nativeEvent.layout.height);
            onLayout?.(event);
          }}
          {...rest}
        >
          {fill ? <View style={{ minHeight: fillHeight }}>{children}</View> : children}
          {/* The keyboard's room. Collapses to nothing while the keyboard is down. */}
          <Animated.View pointerEvents="none" style={spacerStyle} />
        </Animated.ScrollView>
      </View>
    </RevealContext.Provider>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1 },
});
