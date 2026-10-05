import { View } from 'react-native';

import { Icon, type IconName } from '@/components/ui/icon';
import { composite, withAlpha } from '@/theme/color';
import { useTheme } from '@/theme/theme';

/** A glyph on a soft wash of its own colour — whatever colour HR chose for it. */
export function ToneWell({ icon, color, size = 38 }: { icon: IconName; color: string; size?: number }) {
  const { colors, readable, scheme } = useTheme();
  const alpha = scheme === 'dark' ? 0.22 : 0.14;

  return (
    <View
      style={{
        width: size,
        height: size,
        borderRadius: size * 0.3,
        borderCurve: 'continuous',
        backgroundColor: withAlpha(color, alpha),
        alignItems: 'center',
        justifyContent: 'center',
      }}
    >
      <Icon name={icon} size={size * 0.48} color={readable(color, composite(color, alpha, colors.card), 3)} />
    </View>
  );
}
