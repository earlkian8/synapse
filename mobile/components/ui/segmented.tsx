import { useEffect, useRef, useState } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';
import Animated, { useAnimatedStyle, useSharedValue, withSpring } from 'react-native-reanimated';

import { AppText } from '@/components/ui/text';
import { fireHaptic } from '@/components/ui/touchable';
import { springs } from '@/lib/motion';
import { useTheme } from '@/theme/theme';

type Option<T extends string> = { value: T; label: string };

type SegmentedProps<T extends string> = {
  options: Option<T>[];
  value: T;
  onChange: (value: T) => void;
  accessibilityLabel?: string;
};

const PAD = 2;

/**
 * The iOS segmented control: a recessed track with a raised thumb that slides to the
 * chosen segment on a spring, with a selection tick under the finger.
 */
export function Segmented<T extends string>({ options, value, onChange, accessibilityLabel }: SegmentedProps<T>) {
  const { colors, scheme } = useTheme();
  const [width, setWidth] = useState(0);
  const segment = width > 0 ? (width - PAD * 2) / options.length : 0;
  const index = Math.max(0, options.findIndex((option) => option.value === value));

  const x = useSharedValue(0);
  const placed = useRef(false);

  useEffect(() => {
    if (segment === 0) return;
    x.set(placed.current ? withSpring(index * segment, springs.snappy) : index * segment);
    placed.current = true;
  }, [index, segment, x]);

  const thumbStyle = useAnimatedStyle(() => ({ transform: [{ translateX: x.get() }] }));

  return (
    <View
      accessibilityRole="tablist"
      accessibilityLabel={accessibilityLabel}
      onLayout={(event) => setWidth(event.nativeEvent.layout.width)}
      style={[styles.track, { backgroundColor: colors.fill }]}
    >
      {segment > 0 && (
        <Animated.View
          style={[
            styles.thumb,
            {
              width: segment,
              backgroundColor: scheme === 'dark' ? '#636366' : colors.card,
              boxShadow: scheme === 'dark' ? undefined : '0 3px 8px rgba(0, 0, 0, 0.12), 0 1px 1px rgba(0, 0, 0, 0.04)',
            },
            thumbStyle,
          ]}
        />
      )}

      {options.map((option) => {
        const active = option.value === value;

        return (
          <Pressable
            key={option.value}
            onPress={() => {
              if (active) return;
              fireHaptic('selection');
              onChange(option.value);
            }}
            accessibilityRole="tab"
            accessibilityState={{ selected: active }}
            style={styles.segment}
          >
            <AppText
              variant="footnote"
              weight={active ? 'semibold' : 'medium'}
              tone={active ? 'primary' : 'secondary'}
              numberOfLines={1}
              maxFontSizeMultiplier={1.2}
            >
              {option.label}
            </AppText>
          </Pressable>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  track: { flexDirection: 'row', height: 34, borderRadius: 10, padding: PAD, borderCurve: 'continuous' },
  thumb: { position: 'absolute', top: PAD, bottom: PAD, left: PAD, borderRadius: 8, borderCurve: 'continuous' },
  segment: { flex: 1, alignItems: 'center', justifyContent: 'center' },
});
