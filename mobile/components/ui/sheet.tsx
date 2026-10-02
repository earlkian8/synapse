import { useEffect, useState } from 'react';
import { Modal, Pressable, StyleSheet, useWindowDimensions, View } from 'react-native';
import { Gesture, GestureDetector, GestureHandlerRootView } from 'react-native-gesture-handler';
import Animated, {
  Extrapolation,
  interpolate,
  useAnimatedStyle,
  useSharedValue,
  withSpring,
  withTiming,
} from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { scheduleOnRN } from 'react-native-worklets';

import { AppText } from '@/components/ui/text';
import { keyboardEasing, useKeyboardFrame } from '@/lib/keyboard';
import { easeOut, springs } from '@/lib/motion';
import { useTheme } from '@/theme/theme';

type SheetProps = {
  visible: boolean;
  onClose: () => void;
  title?: string;
  /** A line under the title. */
  message?: string;
  /** False while something is in flight: no drag, tap-out or back to close it. */
  dismissible?: boolean;
  children: React.ReactNode;
};

/**
 * A bottom sheet, built to feel like the system's: it rises on a critically damped
 * spring, the page behind dims in step with it, and it follows a finger down and
 * lets go past a third of its height or on a quick flick. Dragged upward it resists.
 * It stays clear of the keyboard and the home indicator.
 */
export function Sheet({ visible, onClose, title, message, dismissible = true, children }: SheetProps) {
  const { colors, radius, spacing, squircle, scheme } = useTheme();
  const insets = useSafeAreaInsets();
  const { height: screen } = useWindowDimensions();

  // Kept mounted through the exit, so the sheet can leave as it came.
  const [mounted, setMounted] = useState(visible);
  if (visible && !mounted) {
    setMounted(true);
  }

  const offset = useSharedValue(screen);
  const panel = useSharedValue(screen);

  // Lifted over the keyboard, on the keyboard's own timing, when a field in it is focused.
  const keyboard = useKeyboardFrame();
  const lift = useSharedValue(0);
  useEffect(() => {
    const height = keyboard.visible ? Math.max(0, screen - keyboard.top - insets.bottom) : 0;
    lift.set(withTiming(height, { duration: keyboard.duration, easing: keyboardEasing }));
  }, [keyboard.visible, keyboard.top, keyboard.duration, screen, insets.bottom, lift]);
  const liftStyle = useAnimatedStyle(() => ({ paddingBottom: lift.get() }));

  useEffect(() => {
    if (!mounted) return;

    if (visible) {
      offset.set(withSpring(0, springs.gentle));
    } else {
      offset.set(
        withTiming(panel.get() + 40, { duration: 240, easing: easeOut }, (finished) => {
          if (finished) scheduleOnRN(setMounted, false);
        }),
      );
    }
  }, [visible, mounted, offset, panel]);

  const drag = Gesture.Pan()
    .enabled(dismissible)
    .activeOffsetY([-8, 8])
    .onUpdate((event) => {
      // Down follows the finger; up gives a little and no more.
      offset.set(event.translationY > 0 ? event.translationY : -Math.sqrt(-event.translationY) * 2);
    })
    .onEnd((event) => {
      if (event.translationY > panel.get() / 3 || event.velocityY > 900) {
        offset.set(withTiming(panel.get() + 40, { duration: 200, easing: easeOut }));
        scheduleOnRN(onClose);
      } else {
        offset.set(withSpring(0, springs.gentle));
      }
    });

  const panelStyle = useAnimatedStyle(() => ({ transform: [{ translateY: offset.get() }] }));

  const backdropStyle = useAnimatedStyle(() => ({
    opacity: interpolate(offset.get(), [0, panel.get()], [1, 0], Extrapolation.CLAMP),
  }));

  return (
    <Modal
      visible={mounted}
      transparent
      animationType="none"
      statusBarTranslucent
      navigationBarTranslucent
      onRequestClose={() => dismissible && onClose()}
    >
      <GestureHandlerRootView style={styles.fill}>
        <Animated.View style={[StyleSheet.absoluteFill, { backgroundColor: colors.overlay }, backdropStyle]}>
          <Pressable
            style={styles.fill}
            onPress={() => dismissible && onClose()}
            accessibilityRole="button"
            accessibilityLabel="Close"
          />
        </Animated.View>

        <Animated.View style={[styles.dock, liftStyle]} pointerEvents="box-none">
          <GestureDetector gesture={drag}>
            <Animated.View
              accessibilityViewIsModal
              onLayout={(event) => panel.set(event.nativeEvent.layout.height)}
              style={[
                styles.panel,
                squircle,
                {
                  // The grouped ground by day; after dark, the card step, with lists raised above it.
                  backgroundColor: scheme === 'dark' ? colors.card : colors.background,
                  borderTopLeftRadius: radius.xl,
                  borderTopRightRadius: radius.xl,
                  paddingHorizontal: spacing.xl,
                  paddingBottom: insets.bottom + spacing.lg,
                },
                panelStyle,
              ]}
            >
              <View style={[styles.grabber, { backgroundColor: colors.fillStrong }]} />
              {(title || message) && (
                <View style={styles.heading}>
                  {title && (
                    <AppText variant="title3" center accessibilityRole="header">
                      {title}
                    </AppText>
                  )}
                  {message && (
                    <AppText variant="subheadline" tone="secondary" center>
                      {message}
                    </AppText>
                  )}
                </View>
              )}
              {children}
            </Animated.View>
          </GestureDetector>
        </Animated.View>
      </GestureHandlerRootView>
    </Modal>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1 },
  dock: { flex: 1, justifyContent: 'flex-end' },
  panel: { paddingTop: 8, boxShadow: '0 -8px 30px rgba(0, 0, 0, 0.12)' },
  grabber: { alignSelf: 'center', width: 36, height: 5, borderRadius: 3, marginBottom: 14 },
  heading: { gap: 4, marginBottom: 20, paddingHorizontal: 8 },
});
