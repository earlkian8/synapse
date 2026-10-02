import { useEffect } from 'react';
import { type DimensionValue, type StyleProp, type ViewStyle } from 'react-native';
import Animated, {
  Easing,
  useAnimatedStyle,
  useSharedValue,
  withRepeat,
  withTiming,
} from 'react-native-reanimated';

import { useTheme } from '@/theme/theme';

type SkeletonProps = {
  width?: DimensionValue;
  height?: number;
  radius?: number;
  style?: StyleProp<ViewStyle>;
};

/**
 * A placeholder in the shape of what is loading, breathing slowly: the redacted
 * look iOS uses while a widget fills in. Shaped like the real content, so nothing
 * jumps when it arrives.
 */
export function Skeleton({ width = '100%', height = 16, radius = 8, style }: SkeletonProps) {
  const { colors } = useTheme();
  const opacity = useSharedValue(1);

  useEffect(() => {
    opacity.set(withRepeat(withTiming(0.45, { duration: 900, easing: Easing.inOut(Easing.quad) }), -1, true));
  }, [opacity]);

  const animatedStyle = useAnimatedStyle(() => ({ opacity: opacity.get() }));

  return (
    <Animated.View
      accessibilityLabel="Loading"
      style={[
        { width, height, borderRadius: radius, borderCurve: 'continuous', backgroundColor: colors.fill },
        animatedStyle,
        style,
      ]}
    />
  );
}
