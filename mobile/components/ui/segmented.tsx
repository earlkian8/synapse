import { Pressable, View } from 'react-native';

import { AppText } from '@/components/ui/text';
import { useTheme } from '@/theme/theme';

type Option<T extends string> = { value: T; label: string };

type SegmentedProps<T extends string> = {
  options: Option<T>[];
  value: T;
  onChange: (value: T) => void;
};

/** A simple segmented control / period switcher. */
export function Segmented<T extends string>({ options, value, onChange }: SegmentedProps<T>) {
  const { colors, radius } = useTheme();

  return (
    <View
      style={{
        flexDirection: 'row',
        backgroundColor: colors.cardAlt,
        borderRadius: radius.md,
        padding: 4,
        gap: 4,
      }}
    >
      {options.map((option) => {
        const active = option.value === value;

        return (
          <Pressable
            key={option.value}
            onPress={() => onChange(option.value)}
            style={{
              flex: 1,
              paddingVertical: 9,
              borderRadius: radius.sm,
              backgroundColor: active ? colors.card : 'transparent',
              alignItems: 'center',
              ...(active
                ? {
                    shadowColor: colors.shadow,
                    shadowOpacity: 0.06,
                    shadowRadius: 6,
                    shadowOffset: { width: 0, height: 2 },
                    elevation: 1,
                  }
                : {}),
            }}
          >
            <AppText variant="label" style={{ color: active ? colors.text : colors.textMuted }}>
              {option.label}
            </AppText>
          </Pressable>
        );
      })}
    </View>
  );
}
