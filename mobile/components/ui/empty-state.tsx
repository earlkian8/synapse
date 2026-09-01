import { Ionicons } from '@expo/vector-icons';
import { View } from 'react-native';

import { AppText } from '@/components/ui/text';
import { useTheme } from '@/theme/theme';

type EmptyStateProps = {
  icon?: keyof typeof Ionicons.glyphMap;
  title: string;
  message?: string;
};

/** Friendly empty state shown wherever a list has no rows. */
export function EmptyState({ icon = 'sparkles-outline', title, message }: EmptyStateProps) {
  const { colors, spacing } = useTheme();

  return (
    <View style={{ alignItems: 'center', paddingVertical: spacing.xxl, paddingHorizontal: spacing.xl, gap: 10 }}>
      <View
        style={{
          width: 64,
          height: 64,
          borderRadius: 32,
          backgroundColor: colors.accentSoft,
          alignItems: 'center',
          justifyContent: 'center',
        }}
      >
        <Ionicons name={icon} size={28} color={colors.accent} />
      </View>
      <AppText variant="heading" center>
        {title}
      </AppText>
      {message && (
        <AppText variant="body" muted center>
          {message}
        </AppText>
      )}
    </View>
  );
}
