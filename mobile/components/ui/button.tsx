import { ActivityIndicator, StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native';
import Animated from 'react-native-reanimated';

import { Icon, type IconName } from '@/components/ui/icon';
import { AppText } from '@/components/ui/text';
import { Touchable, type Haptic } from '@/components/ui/touchable';
import { fadeIn } from '@/lib/motion';
import { useTheme } from '@/theme/theme';

/**
 * The iOS button styles, in the brand's colours.
 *
 * - `primary`: the screen's main action. Navy by day, teal at night.
 * - `tint`: filled with the brand teal, for the one action this app exists for:
 *   punching in and out.
 * - `tinted`: a wash of teal with teal type, for a second action beside a primary one.
 * - `gray`: a neutral fill, for "Send now", "Retake", and other quiet actions.
 * - `plain`: just the tinted label, as in a navigation bar.
 * - `destructive`: a wash of red with red type. Red as a fill is kept for nothing,
 *   because nothing in this app is that loud.
 */
type Variant = 'primary' | 'tint' | 'tinted' | 'gray' | 'plain' | 'destructive';
type Size = 'sm' | 'md' | 'lg';

type ButtonProps = {
  label: string;
  onPress?: () => void;
  variant?: Variant;
  size?: Size;
  icon?: IconName;
  disabled?: boolean;
  loading?: boolean;
  /** A medium tick for the main action, light for the rest. `false` for none. */
  haptic?: Haptic;
  fullWidth?: boolean;
  style?: StyleProp<ViewStyle>;
  accessibilityHint?: string;
  /**
   * Drawn as a button but not one: for a button shape inside a surface that is itself
   * the pressable (the Today card on Home), where a real button would nest inside it.
   */
  decorative?: boolean;
};

const HEIGHT: Record<Size, number> = { sm: 34, md: 46, lg: 54 };

export function Button({
  label,
  onPress,
  variant = 'primary',
  size = 'md',
  icon,
  disabled,
  loading,
  haptic,
  fullWidth = true,
  style,
  accessibilityHint,
  decorative,
}: ButtonProps) {
  const { colors, radius, squircle, scheme } = useTheme();

  const dangerWash = scheme === 'dark' ? 'rgba(255, 69, 58, 0.18)' : 'rgba(215, 0, 21, 0.1)';

  const fill: Record<Variant, string> = {
    primary: colors.primary,
    tint: colors.tint,
    tinted: colors.tintSoft,
    gray: colors.fill,
    plain: 'transparent',
    destructive: dangerWash,
  };

  const ink: Record<Variant, string> = {
    primary: colors.onPrimary,
    tint: colors.onTint,
    tinted: colors.tintText,
    gray: colors.text,
    plain: colors.tintText,
    destructive: colors.danger,
  };

  const inert = disabled || loading;
  // A disabled control goes quiet rather than translucent: dropping the whole button
  // to half opacity takes its label down with it.
  const background = disabled && variant !== 'plain' ? colors.fill : fill[variant];
  const foreground = disabled ? colors.textTertiary : ink[variant];

  const shape: StyleProp<ViewStyle> = [
    styles.base,
    squircle,
    {
      height: HEIGHT[size],
      paddingHorizontal: size === 'sm' ? 14 : 20,
      borderRadius: size === 'sm' ? radius.pill : radius.md,
      backgroundColor: background,
      alignSelf: fullWidth ? 'stretch' : 'flex-start',
    },
    style,
  ];

  const content = loading ? (
    <Animated.View entering={fadeIn}>
      <ActivityIndicator color={foreground} />
    </Animated.View>
  ) : (
    <View style={styles.content}>
      {icon && <Icon name={icon} size={size === 'sm' ? 15 : 18} color={foreground} weight="semibold" />}
      <AppText
        variant={size === 'sm' ? 'subheadline' : 'headline'}
        weight="semibold"
        color={foreground}
        numberOfLines={1}
      >
        {label}
      </AppText>
    </View>
  );

  if (decorative) {
    return (
      <View style={shape} importantForAccessibility="no-hide-descendants" accessibilityElementsHidden>
        {content}
      </View>
    );
  }

  return (
    <Touchable
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityHint={accessibilityHint}
      accessibilityState={{ disabled: !!inert, busy: !!loading }}
      disabled={inert}
      onPress={onPress}
      haptic={haptic ?? (variant === 'primary' || variant === 'tint' ? 'medium' : 'light')}
      scaleTo={size === 'sm' ? 0.94 : 0.97}
      style={shape}
    >
      {content}
    </Touchable>
  );
}

const styles = StyleSheet.create({
  base: { alignItems: 'center', justifyContent: 'center' },
  content: { flexDirection: 'row', alignItems: 'center', gap: 8 },
});
