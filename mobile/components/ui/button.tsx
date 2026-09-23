import * as Haptics from 'expo-haptics';
import { ActivityIndicator, Pressable, StyleSheet, View, type ViewStyle } from 'react-native';
import Animated, { useAnimatedStyle, useSharedValue, withTiming } from 'react-native-reanimated';

import { AppText } from '@/components/ui/text';
import { useTheme } from '@/theme/theme';

/**
 * `primary` is the ERP's near-black button — the default for anything you press.
 * `secondary` is the brand navy, for the main action on the entry screens (sign in,
 * create an account), where the brand speaks before the app does.
 * `accent` is the brand teal, held for the one action this app exists for: punching in
 * and out. Spending it anywhere else is what made every screen read as teal.
 */
type Variant = 'primary' | 'secondary' | 'accent' | 'outline' | 'ghost' | 'danger';
type Size = 'sm' | 'md' | 'lg';

type ButtonProps = {
  label: string;
  onPress?: () => void;
  variant?: Variant;
  size?: Size;
  disabled?: boolean;
  loading?: boolean;
  haptic?: boolean;
  icon?: React.ReactNode;
  fullWidth?: boolean;
  style?: ViewStyle;
};

const AnimatedPressable = Animated.createAnimatedComponent(Pressable);

export function Button({
  label,
  onPress,
  variant = 'primary',
  size = 'md',
  disabled,
  loading,
  haptic = true,
  icon,
  fullWidth = true,
  style,
}: ButtonProps) {
  const { colors, radius } = useTheme();
  const scale = useSharedValue(1);

  const animatedStyle = useAnimatedStyle(() => ({ transform: [{ scale: scale.value }] }));

  const heights: Record<Size, number> = { sm: 40, md: 50, lg: 58 };

  const bg: Record<Variant, string> = {
    primary: colors.primary,
    secondary: colors.secondary,
    accent: colors.accent,
    outline: 'transparent',
    ghost: 'transparent',
    danger: colors.danger,
  };

  const fg: Record<Variant, string> = {
    primary: colors.onPrimary,
    secondary: colors.onSecondary,
    accent: colors.onAccent,
    outline: colors.text,
    ghost: colors.accentText,
    danger: colors.onDanger,
  };

  const bordered = variant === 'outline';
  const isDisabled = disabled || loading;

  // A disabled control goes quiet rather than translucent: dropping the whole button to
  // half opacity takes its label down with it, and a label you can't read isn't a label.
  const background = isDisabled && !bordered ? colors.cardAlt : bg[variant];
  const foreground = isDisabled ? colors.textMuted : fg[variant];

  return (
    <AnimatedPressable
      accessibilityRole="button"
      accessibilityState={{ disabled: !!isDisabled, busy: !!loading }}
      onPress={() => {
        if (isDisabled) return;
        if (haptic) void Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Medium);
        onPress?.();
      }}
      onPressIn={() => {
        scale.value = withTiming(0.97, { duration: 90 });
      }}
      onPressOut={() => {
        scale.value = withTiming(1, { duration: 120 });
      }}
      disabled={isDisabled}
      style={[
        styles.base,
        {
          height: heights[size],
          borderRadius: radius.md,
          backgroundColor: background,
          width: fullWidth ? '100%' : undefined,
          borderWidth: bordered || (isDisabled && variant !== 'ghost') ? 1.5 : 0,
          borderColor: colors.border,
        },
        animatedStyle,
        style,
      ]}
    >
      {loading ? (
        <ActivityIndicator color={foreground} />
      ) : (
        <View style={styles.content}>
          {icon}
          <AppText variant={size === 'lg' ? 'heading' : 'label'} style={{ color: foreground }}>
            {label}
          </AppText>
        </View>
      )}
    </AnimatedPressable>
  );
}

const styles = StyleSheet.create({
  base: { alignItems: 'center', justifyContent: 'center', paddingHorizontal: 18 },
  content: { flexDirection: 'row', alignItems: 'center', gap: 8 },
});
