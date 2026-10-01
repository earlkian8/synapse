import { View, type ViewStyle } from 'react-native';

import { AppText } from '@/components/ui/text';
import { composite, withAlpha } from '@/theme/color';
import { useTheme } from '@/theme/theme';

type PillProps = {
  label: string;
  /** The state's tone. The fill is a tint of it; the label and dot are darkened (or
   *  lifted, after dark) from it until they clear 4.5:1 against that tint. */
  color: string;
  dot?: boolean;
  /** The surface the pill is sitting on, when it isn't a card. */
  on?: string;
  style?: ViewStyle;
};

/** A status pill — a tint of the state's tone, with a label that stays legible on it. */
export function Pill({ label, color, dot, on, style }: PillProps) {
  const { colors, scheme, readable } = useTheme();

  const surface = on ?? colors.card;
  const alpha = scheme === 'dark' ? 0.2 : 0.12;
  const ink = readable(color, composite(color, alpha, surface));

  return (
    <View
      style={[
        {
          flexDirection: 'row',
          alignItems: 'center',
          gap: 6,
          alignSelf: 'flex-start',
          backgroundColor: withAlpha(color, alpha),
          paddingHorizontal: 10,
          paddingVertical: 4,
          borderRadius: 999,
        },
        style,
      ]}
    >
      {dot && <View style={{ width: 7, height: 7, borderRadius: 4, backgroundColor: ink }} />}
      <AppText variant="caption" style={{ color: ink, fontWeight: '700' }}>
        {label}
      </AppText>
    </View>
  );
}
