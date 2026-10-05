import * as Haptics from 'expo-haptics';
import { Pressable, type PressableProps, type StyleProp, type ViewStyle } from 'react-native';
import Animated, {
  interpolateColor,
  useAnimatedStyle,
  useSharedValue,
  withSpring,
  withTiming,
} from 'react-native-reanimated';

import { springs } from '@/lib/motion';
import { useTheme } from '@/theme/theme';

const AnimatedPressable = Animated.createAnimatedComponent(Pressable);

export type Haptic = 'selection' | 'light' | 'medium' | false;

type TouchableProps = Omit<PressableProps, 'style' | 'children'> & {
  children: React.ReactNode;
  style?: StyleProp<ViewStyle>;
  /**
   * How a press shows. `scale` gives way under the finger (buttons, cards, tiles);
   * `highlight` greys the row like a UITableView cell; `opacity` dims, for bare
   * glyphs in a bar.
   */
  feedback?: 'scale' | 'highlight' | 'opacity';
  /** How far `scale` gives. Smaller surfaces can give more. */
  scaleTo?: number;
  /** The background a `highlight` row rests on. */
  restColor?: string;
  haptic?: Haptic;
};

export function fireHaptic(kind: Haptic) {
  if (kind === 'selection') void Haptics.selectionAsync();
  else if (kind === 'light') void Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light);
  else if (kind === 'medium') void Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Medium);
}

/**
 * Everything pressable goes through here, so every press in the app feels the same:
 * a spring that gives on touch-down and returns on release, an optional haptic tick,
 * and the pressed state drawn on the UI thread rather than by a re-render.
 */
export function Touchable({
  children,
  style,
  feedback = 'scale',
  scaleTo = 0.97,
  restColor = 'transparent',
  haptic = false,
  onPress,
  onPressIn,
  onPressOut,
  disabled,
  ...rest
}: TouchableProps) {
  const { colors } = useTheme();
  const pressed = useSharedValue(0);

  const animatedStyle = useAnimatedStyle(() => {
    const p = pressed.get();

    if (feedback === 'scale') {
      return { transform: [{ scale: 1 - (1 - scaleTo) * p }] };
    }

    if (feedback === 'highlight') {
      return { backgroundColor: interpolateColor(p, [0, 1], [restColor, colors.fillStrong]) };
    }

    return { opacity: 1 - 0.55 * p };
  });

  return (
    <AnimatedPressable
      {...rest}
      disabled={disabled}
      onPressIn={(event) => {
        pressed.set(
          feedback === 'scale' ? withSpring(1, springs.press) : withTiming(1, { duration: 60 }),
        );
        onPressIn?.(event);
      }}
      onPressOut={(event) => {
        pressed.set(
          feedback === 'scale' ? withSpring(0, springs.press) : withTiming(0, { duration: 220 }),
        );
        onPressOut?.(event);
      }}
      onPress={(event) => {
        fireHaptic(haptic);
        onPress?.(event);
      }}
      style={[style, animatedStyle]}
    >
      {children}
    </AnimatedPressable>
  );
}
