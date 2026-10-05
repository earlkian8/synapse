import { StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native';

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
  style?: StyleProp<ViewStyle>;
};

/** A status pill: a tint of the state's tone, with a label that stays legible on it. */
export function Pill({ label, color, dot, on, style }: PillProps) {
  const { colors, scheme, readable } = useTheme();

  const surface = on ?? colors.card;
  const alpha = scheme === 'dark' ? 0.22 : 0.13;
  const ink = readable(color, composite(color, alpha, surface));

  return (
    <View style={[styles.pill, { backgroundColor: withAlpha(color, alpha) }, style]}>
      {dot && <View style={[styles.dot, { backgroundColor: ink }]} />}
      <AppText variant="caption" weight="semibold" color={ink} numberOfLines={1} maxFontSizeMultiplier={1.2}>
        {label}
      </AppText>
    </View>
  );
}

const styles = StyleSheet.create({
  pill: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 5,
    alignSelf: 'flex-start',
    paddingHorizontal: 9,
    paddingVertical: 3,
    borderRadius: 999,
  },
  dot: { width: 6, height: 6, borderRadius: 3 },
});
