import { Ionicons } from '@expo/vector-icons';
import { useRouter } from 'expo-router';
import { Pressable, View, type ViewStyle } from 'react-native';
import { SafeAreaView, type Edge } from 'react-native-safe-area-context';

import { AppText } from '@/components/ui/text';
import { useTheme } from '@/theme/theme';

type ScreenProps = {
  children: React.ReactNode;
  edges?: Edge[];
  style?: ViewStyle;
};

/** Safe-area aware page background. */
export function Screen({ children, edges = ['top'], style }: ScreenProps) {
  const { colors } = useTheme();

  return (
    <SafeAreaView edges={edges} style={[{ flex: 1, backgroundColor: colors.background }, style]}>
      {children}
    </SafeAreaView>
  );
}

type HeaderProps = {
  title: string;
  subtitle?: string;
  back?: boolean;
  right?: React.ReactNode;
  large?: boolean;
};

/** Consistent header: optional back chevron, title, optional right action. */
export function ScreenHeader({ title, subtitle, back, right, large }: HeaderProps) {
  const { colors, spacing } = useTheme();
  const router = useRouter();

  return (
    <View
      style={{
        flexDirection: 'row',
        alignItems: 'center',
        gap: spacing.md,
        paddingHorizontal: spacing.lg,
        paddingTop: spacing.sm,
        paddingBottom: spacing.md,
      }}
    >
      {back && (
        <Pressable
          onPress={() => router.back()}
          hitSlop={10}
          style={{
            width: 40,
            height: 40,
            borderRadius: 20,
            backgroundColor: colors.card,
            borderWidth: 1,
            borderColor: colors.border,
            alignItems: 'center',
            justifyContent: 'center',
          }}
        >
          <Ionicons name="chevron-back" size={22} color={colors.text} />
        </Pressable>
      )}
      <View style={{ flex: 1 }}>
        <AppText variant={large ? 'title' : 'heading'}>{title}</AppText>
        {subtitle && (
          <AppText variant="caption" muted>
            {subtitle}
          </AppText>
        )}
      </View>
      {right}
    </View>
  );
}
