import { useEffect } from 'react';
import { StyleSheet, View } from 'react-native';
import Animated, { useAnimatedStyle, useSharedValue, withTiming } from 'react-native-reanimated';

import { easeOut } from '@/lib/motion';
import { useTheme } from '@/theme/theme';

type ProgressBarProps = {
  /** 0 to 1. */
  value: number;
  color: string;
  height?: number;
  /** The track; a fill of the surface by default. */
  track?: string;
};

/** A thin capsule that fills to `value`, easing in when it first appears and on change. */
export function ProgressBar({ value, color, height = 6, track }: ProgressBarProps) {
  const { colors } = useTheme();
  const progress = useSharedValue(0);

  useEffect(() => {
    progress.set(withTiming(Math.max(0, Math.min(1, value)), { duration: 700, easing: easeOut }));
  }, [value, progress]);

  const fillStyle = useAnimatedStyle(() => ({ width: `${progress.get() * 100}%` }));

  return (
    <View
      accessibilityRole="progressbar"
      accessibilityValue={{ min: 0, max: 100, now: Math.round(value * 100) }}
      style={[styles.track, { height, borderRadius: height / 2, backgroundColor: track ?? colors.fill }]}
    >
      <Animated.View style={[styles.fill, { borderRadius: height / 2, backgroundColor: color }, fillStyle]} />
    </View>
  );
}

const styles = StyleSheet.create({
  track: { width: '100%', overflow: 'hidden' },
  fill: { height: '100%' },
});
