import { View, type StyleProp, type ViewProps, type ViewStyle } from 'react-native';

import { Touchable } from '@/components/ui/touchable';
import { useTheme } from '@/theme/theme';

type CardProps = Omit<ViewProps, 'style'> & {
  padded?: boolean;
  onPress?: () => void;
  style?: StyleProp<ViewStyle>;
  accessibilityLabel?: string;
};

/**
 * A white card on the grouped page, with continuous corners and no edge: the
 * difference in shade is what separates them, as it is in every iOS app. By day it
 * carries a shadow you feel more than see; at night the step from black to `#1C1C1E`
 * does that job and a shadow would only muddy it.
 */
export function Card({ padded = true, onPress, style, children, accessibilityLabel, ...rest }: CardProps) {
  const { colors, radius, spacing, squircle, scheme } = useTheme();

  const cardStyle: ViewStyle = {
    backgroundColor: colors.card,
    borderRadius: radius.lg,
    padding: padded ? spacing.lg : 0,
    ...squircle,
    ...(scheme === 'light'
      ? { boxShadow: '0 1px 2px rgba(0, 0, 0, 0.04), 0 6px 16px rgba(0, 0, 0, 0.035)' }
      : null),
  };

  if (onPress) {
    return (
      <Touchable
        onPress={onPress}
        scaleTo={0.98}
        haptic="light"
        accessibilityRole="button"
        accessibilityLabel={accessibilityLabel}
        style={[cardStyle, style]}
      >
        {children}
      </Touchable>
    );
  }

  return (
    <View style={[cardStyle, style]} accessibilityLabel={accessibilityLabel} {...rest}>
      {children}
    </View>
  );
}
