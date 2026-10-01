import { useState } from 'react';
import { TextInput, View, type TextInputProps } from 'react-native';

import { AppText } from '@/components/ui/text';
import { useTheme } from '@/theme/theme';

type InputProps = TextInputProps & {
  label?: string;
  error?: string;
  hint?: string;
};

/** Labelled text field with focus ring and inline error. */
export function Input({ label, error, hint, style, ...rest }: InputProps) {
  const { colors, radius, spacing } = useTheme();
  const [focused, setFocused] = useState(false);

  return (
    <View style={{ gap: 6 }}>
      {label && (
        <AppText variant="label" muted>
          {label}
        </AppText>
      )}
      <TextInput
        placeholderTextColor={colors.textFaint}
        onFocus={() => setFocused(true)}
        onBlur={() => setFocused(false)}
        style={[
          {
            backgroundColor: colors.card,
            borderRadius: radius.md,
            borderWidth: 1.5,
            // The focus edge is teal darkened to 3:1 — the ERP's focus tint, at a
            // strength you can actually see against a white field.
            borderColor: error ? colors.danger : focused ? colors.accent : colors.border,
            paddingHorizontal: spacing.lg,
            paddingVertical: 14,
            fontSize: 15,
            color: colors.text,
          },
          style,
        ]}
        {...rest}
      />
      {error ? (
        <AppText variant="caption" style={{ color: colors.danger }}>
          {error}
        </AppText>
      ) : hint ? (
        <AppText variant="caption" faint>
          {hint}
        </AppText>
      ) : null}
    </View>
  );
}
