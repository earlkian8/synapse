import { StyleSheet } from 'react-native';

import { Icon } from '@/components/ui/icon';
import { Touchable } from '@/components/ui/touchable';
import { useTheme } from '@/theme/theme';

type StepButtonProps = {
  icon: 'chevronLeft' | 'chevronRight';
  /** Read aloud: "Previous month", "Next week". */
  label: string;
  onPress: () => void;
  disabled?: boolean;
};

/**
 * A small round chevron that steps a period back or forward — the month on
 * Attendance, the week on My schedule. 32pt across, a soft fill, a teal glyph.
 */
export function StepButton({ icon, label, onPress, disabled }: StepButtonProps) {
  const { colors } = useTheme();

  return (
    <Touchable
      onPress={onPress}
      disabled={disabled}
      haptic="selection"
      scaleTo={0.88}
      hitSlop={6}
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityState={{ disabled: !!disabled }}
      style={[styles.step, { backgroundColor: colors.fill }]}
    >
      <Icon name={icon} size={14} color={disabled ? colors.textTertiary : colors.tintText} weight="bold" />
    </Touchable>
  );
}

const styles = StyleSheet.create({
  step: { width: 32, height: 32, borderRadius: 16, alignItems: 'center', justifyContent: 'center' },
});
