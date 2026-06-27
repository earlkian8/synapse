import { Pressable, View, type ViewProps, type ViewStyle } from 'react-native';

import { useTheme } from '@/theme/theme';

type CardProps = ViewProps & {
  padded?: boolean;
  onPress?: () => void;
  elevated?: boolean;
  style?: ViewStyle | ViewStyle[];
};

/** Rounded, hairline-bordered surface — the app's default container. */
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
          shadowOpacity: 0.08,
          shadowRadius: 16,
          shadowOffset: { width: 0, height: 8 },
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
