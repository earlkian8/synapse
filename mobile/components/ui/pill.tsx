import { View, type ViewStyle } from 'react-native';

import { AppText } from '@/components/ui/text';

type PillProps = {
  label: string;
  /** Accent colour; background is rendered at ~14% of it. */
  color: string;
  dot?: boolean;
  style?: ViewStyle;
};

/** A coloured status pill — translucent fill + solid text, used app-wide. */
export function Pill({ label, color, dot, style }: PillProps) {
  return (
    <View
      style={[
        {
          flexDirection: 'row',
          alignItems: 'center',
          gap: 6,
          alignSelf: 'flex-start',
          backgroundColor: withAlpha(color, 0.14),
          paddingHorizontal: 10,
          paddingVertical: 4,
          borderRadius: 999,
        },
        style,
      ]}
    >
      {dot && <View style={{ width: 7, height: 7, borderRadius: 4, backgroundColor: color }} />}
      <AppText variant="caption" style={{ color, fontWeight: '700' }}>
        {label}
      </AppText>
    </View>
  );
}

/** Apply an alpha to a #RRGGBB (or rgb/rgba) colour string. */
export function withAlpha(color: string, alpha: number): string {
  if (color.startsWith('#') && color.length === 7) {
    const r = parseInt(color.slice(1, 3), 16);
    const g = parseInt(color.slice(3, 5), 16);
    const b = parseInt(color.slice(5, 7), 16);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
  }
  return color;
}
