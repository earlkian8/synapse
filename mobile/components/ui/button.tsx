import * as Haptics from 'expo-haptics';
import { ActivityIndicator, Pressable, StyleSheet, View, type ViewStyle } from 'react-native';
import Animated, { useAnimatedStyle, useSharedValue, withTiming } from 'react-native-reanimated';

import { AppText } from '@/components/ui/text';
import { useTheme } from '@/theme/theme';

type Variant = 'primary' | 'secondary' | 'outline' | 'ghost' | 'danger';
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
    primary: colors.accent,
    secondary: colors.brand,
    outline: 'transparent',
    ghost: 'transparent',
    danger: '#F43F5E',
  };

  const fg: Record<Variant, string> = {
    primary: colors.onAccent,
    secondary: colors.brandText,
    outline: colors.text,
    ghost: colors.accent,
    danger: '#FFFFFF',
  };

  const isDisabled = disabled || loading;

  return (
    <AnimatedPressable
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
          backgroundColor: bg[variant],
          opacity: isDisabled ? 0.55 : 1,
          width: fullWidth ? '100%' : undefined,
          borderWidth: variant === 'outline' ? 1.5 : 0,
          borderColor: colors.border,
        },
        animatedStyle,
        style,
      ]}
    >
      {loading ? (
        <ActivityIndicator color={fg[variant]} />
      ) : (
        <View style={styles.content}>
          {icon}
          <AppText variant={size === 'lg' ? 'heading' : 'label'} style={{ color: fg[variant] }}>
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
