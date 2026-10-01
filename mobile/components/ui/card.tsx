import { Pressable, View, type ViewProps, type ViewStyle } from 'react-native';

import { useTheme } from '@/theme/theme';

type CardProps = ViewProps & {
  padded?: boolean;
  onPress?: () => void;
  elevated?: boolean;
  style?: ViewStyle | ViewStyle[];
};

/**
 * Rounded, hairline-bordered surface — the app's default container. Page and card are
 * both white (as in the ERP), so the edge is what separates them; `elevated` adds a
 * soft neutral shadow for the one card on a screen that should sit above the rest.
 */
export function Card({ padded = true, onPress, elevated, style, children, ...rest }: CardProps) {
  const { colors, radius, spacing } = useTheme();

  const cardStyle: ViewStyle = {
    backgroundColor: colors.card,
    borderRadius: radius.lg,
    borderWidth: 1,
    borderColor: colors.border,
    padding: padded ? spacing.lg : 0,
    ...(elevated
      ? {
          shadowColor: colors.shadow,
          shadowOpacity: 0.1,
          shadowRadius: 20,
          shadowOffset: { width: 0, height: 10 },
          elevation: 3,
        }
      : {}),
  };

  if (onPress) {
    return (
      <Pressable
        onPress={onPress}
        style={({ pressed }) => [cardStyle, pressed && { opacity: 0.9 }, style]}
      >
        {children}
      </Pressable>
    );
  }

  return (
    <View style={[cardStyle, style]} {...rest}>
      {children}
    </View>
  );
}
