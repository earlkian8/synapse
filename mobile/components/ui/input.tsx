import { useState, type Ref } from 'react';
import { StyleSheet, TextInput, View, type TextInputProps } from 'react-native';
import Animated, {
  FadeIn,
  FadeOut,
  interpolateColor,
  useAnimatedStyle,
  useSharedValue,
  withTiming,
} from 'react-native-reanimated';

import { Icon, type IconName } from '@/components/ui/icon';
import { AppText } from '@/components/ui/text';
import { Touchable } from '@/components/ui/touchable';
import { useTheme } from '@/theme/theme';

type InputProps = TextInputProps & {
  label?: string;
  error?: string;
  hint?: string;
  /** A glyph at the field's leading edge. */
  icon?: IconName;
  /** An eye at the trailing edge that shows and hides what was typed. */
  secureToggle?: boolean;
  ref?: Ref<TextInput>;
};

/**
 * A text field: a filled, borderless well, the iOS way. Focus draws a teal edge in over
 * 160ms; an error turns it red and says why underneath. The keyboard matches the
 * scheme of the surface the field is on (so a white sign-in screen gets a light
 * keyboard on a phone set to dark), and the caret and selection are the brand teal.
 */
export function Input({
  label,
  error,
  hint,
  icon,
  secureToggle,
  secureTextEntry,
  multiline,
  style,
  onFocus,
  onBlur,
  editable = true,
  ref,
  ...rest
}: InputProps) {
  const { colors, radius, squircle, scheme, typography } = useTheme();
  const [hidden, setHidden] = useState(true);
  const focus = useSharedValue(0);

  const edgeStyle = useAnimatedStyle(() => ({
    borderColor: error ? colors.danger : interpolateColor(focus.get(), [0, 1], ['transparent', colors.tint]),
  }));

  const secure = secureToggle ? hidden : secureTextEntry;

  return (
    <View style={styles.wrap}>
      {label && (
        <AppText variant="footnote" weight="medium" tone="secondary" style={styles.label}>
          {label}
        </AppText>
      )}

      <Animated.View
        style={[
          styles.field,
          squircle,
          {
            borderRadius: radius.md,
            backgroundColor: colors.fill,
            minHeight: multiline ? 104 : 50,
            alignItems: multiline ? 'flex-start' : 'center',
            opacity: editable ? 1 : 0.6,
          },
          edgeStyle,
        ]}
      >
        {icon && (
          <Icon name={icon} size={17} color={colors.textTertiary} style={multiline ? styles.iconTop : undefined} />
        )}
        <TextInput
          ref={ref}
          placeholderTextColor={colors.textTertiary}
          selectionColor={colors.tint}
          cursorColor={colors.tint}
          keyboardAppearance={scheme}
          editable={editable}
          multiline={multiline}
          secureTextEntry={secure}
          maxFontSizeMultiplier={1.4}
          onFocus={(event) => {
            focus.set(withTiming(1, { duration: 160 }));
            onFocus?.(event);
          }}
          onBlur={(event) => {
            focus.set(withTiming(0, { duration: 200 }));
            onBlur?.(event);
          }}
          // No lineHeight: on a TextInput it pushes the caret off the text's baseline on iOS.
          style={[
            {
              fontFamily: typography.body.fontFamily,
              fontSize: typography.body.fontSize,
              letterSpacing: typography.body.letterSpacing,
            },
            styles.input,
            { color: colors.text },
            multiline && styles.multiline,
            style,
          ]}
          {...rest}
        />
        {secureToggle && (
          <Touchable
            feedback="opacity"
            hitSlop={12}
            onPress={() => setHidden((value) => !value)}
            accessibilityRole="button"
            accessibilityLabel={hidden ? 'Show password' : 'Hide password'}
          >
            <Icon name={hidden ? 'eye' : 'eyeSlash'} size={18} color={colors.textSecondary} />
          </Touchable>
        )}
      </Animated.View>

      {error ? (
        <Animated.View entering={FadeIn.duration(180)} exiting={FadeOut.duration(120)} style={styles.message}>
          <Icon name="error" size={13} color={colors.danger} />
          <AppText variant="footnote" tone="danger" style={styles.messageText}>
            {error}
          </AppText>
        </Animated.View>
      ) : hint ? (
        <AppText variant="footnote" tone="secondary" style={styles.hint}>
          {hint}
        </AppText>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { gap: 7 },
  label: { marginLeft: 4 },
  field: {
    flexDirection: 'row',
    gap: 10,
    paddingHorizontal: 14,
    borderWidth: 1.5,
  },
  input: { flex: 1, paddingVertical: 13, includeFontPadding: false },
  multiline: { minHeight: 100, paddingTop: 13, textAlignVertical: 'top' },
  iconTop: { marginTop: 15 },
  message: { flexDirection: 'row', alignItems: 'center', gap: 5, marginLeft: 4 },
  messageText: { flex: 1 },
  hint: { marginLeft: 4 },
});
