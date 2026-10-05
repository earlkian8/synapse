import { useEffect } from 'react';
import { StyleSheet, View } from 'react-native';
import Animated, { useAnimatedProps, useSharedValue, withTiming } from 'react-native-reanimated';
import Svg, { Circle, Defs, LinearGradient, Stop } from 'react-native-svg';

import { easeOut } from '@/lib/motion';
import { useTheme } from '@/theme/theme';

const AnimatedCircle = Animated.createAnimatedComponent(Circle);

type ClockRingProps = {
  /** 0 to 1: how much of the shift is done. */
  progress: number;
  /** The arc's two ends, light to deep. */
  colors: readonly [string, string];
  size?: number;
  stroke?: number;
  children?: React.ReactNode;
};

/**
 * The clock face: a ring that fills clockwise from twelve as the shift is worked,
 * with whatever sits in the middle (the time, the state of the day). Each change
 * eases in over most of a second, so a ring updated every second sweeps instead of
 * ticking. Drawn in SVG, on the UI thread.
 */
export function ClockRing({ progress, colors: arc, size = 248, stroke = 14, children }: ClockRingProps) {
  const { colors } = useTheme();
  const radius = (size - stroke) / 2;
  const circumference = 2 * Math.PI * radius;

  const value = useSharedValue(0);

  useEffect(() => {
    value.set(withTiming(Math.max(0, Math.min(1, progress)), { duration: 900, easing: easeOut }));
  }, [progress, value]);

  // A round cap draws a dot even at zero length, so the arc stays hidden until it has length.
  const arcProps = useAnimatedProps(() => ({
    strokeDashoffset: circumference * (1 - value.get()),
    strokeOpacity: value.get() > 0.002 ? 1 : 0,
  }));

  return (
    <View
      style={{ width: size, height: size }}
      accessibilityRole="progressbar"
      accessibilityLabel="Shift progress"
      accessibilityValue={{ min: 0, max: 100, now: Math.round(Math.min(1, progress) * 100) }}
    >
      <Svg width={size} height={size} style={styles.turn}>
        <Defs>
          <LinearGradient id="arc" x1="0" y1="0" x2="1" y2="1">
            <Stop offset="0" stopColor={arc[0]} />
            <Stop offset="1" stopColor={arc[1]} />
          </LinearGradient>
        </Defs>
        <Circle cx={size / 2} cy={size / 2} r={radius} stroke={colors.fill} strokeWidth={stroke} fill="none" />
        <AnimatedCircle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          stroke="url(#arc)"
          strokeWidth={stroke}
          strokeLinecap="round"
          strokeDasharray={`${circumference} ${circumference}`}
          fill="none"
          animatedProps={arcProps}
        />
      </Svg>
      <View style={[StyleSheet.absoluteFill, styles.center]}>{children}</View>
    </View>
  );
}

const styles = StyleSheet.create({
  // SVG starts a circle at three o'clock; a clock starts at twelve.
  turn: { transform: [{ rotate: '-90deg' }] },
  center: { alignItems: 'center', justifyContent: 'center' },
});
