import { Ionicons } from '@expo/vector-icons';
import type { BottomTabBarProps } from '@react-navigation/bottom-tabs';
import * as Haptics from 'expo-haptics';
import { Pressable, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { AppText } from '@/components/ui/text';
import { useTheme } from '@/theme/theme';

const ICONS: Record<string, { active: keyof typeof Ionicons.glyphMap; inactive: keyof typeof Ionicons.glyphMap; label: string }> = {
  index: { active: 'home', inactive: 'home-outline', label: 'Home' },
  attendance: { active: 'calendar', inactive: 'calendar-outline', label: 'Attendance' },
  clock: { active: 'finger-print', inactive: 'finger-print-outline', label: 'Clock' },
  requests: { active: 'document-text', inactive: 'document-text-outline', label: 'Leave' },
  profile: { active: 'person', inactive: 'person-outline', label: 'Profile' },
};

/** A custom bottom tab bar with a prominent, elevated centre Clock action. */
export function TabBar({ state, navigation }: BottomTabBarProps) {
  const { colors, spacing } = useTheme();
  const insets = useSafeAreaInsets();

  return (
    <View
      style={{
        flexDirection: 'row',
        backgroundColor: colors.card,
        borderTopWidth: 1,
        borderTopColor: colors.border,
        paddingBottom: insets.bottom > 0 ? insets.bottom : spacing.sm,
        paddingTop: spacing.sm,
        paddingHorizontal: spacing.sm,
      }}
    >
      {state.routes.map((route, index) => {
        const meta = ICONS[route.name];

        if (!meta) {
          return null;
        }

        const focused = state.index === index;
        const isClock = route.name === 'clock';

        const onPress = () => {
          void Haptics.selectionAsync();
          const event = navigation.emit({ type: 'tabPress', target: route.key, canPreventDefault: true });

          if (!focused && !event.defaultPrevented) {
            navigation.navigate(route.name);
          }
        };

        if (isClock) {
          return (
            <Pressable key={route.key} onPress={onPress} style={{ flex: 1, alignItems: 'center' }}>
              <View
                style={{
                  width: 60,
                  height: 60,
                  borderRadius: 30,
                  marginTop: -26,
                  backgroundColor: colors.accent,
                  alignItems: 'center',
                  justifyContent: 'center',
                  borderWidth: 4,
                  borderColor: colors.card,
                  shadowColor: colors.accent,
                  shadowOpacity: 0.45,
                  shadowRadius: 12,
                  shadowOffset: { width: 0, height: 6 },
                  elevation: 6,
                }}
              >
                <Ionicons name={meta.active} size={28} color={colors.onAccent} />
              </View>
              <AppText variant="caption" style={{ color: colors.textMuted, marginTop: 2, fontSize: 11 }}>
                {meta.label}
              </AppText>
            </Pressable>
          );
        }

        return (
          <Pressable key={route.key} onPress={onPress} style={{ flex: 1, alignItems: 'center', gap: 3, paddingVertical: 4 }}>
            <Ionicons
              name={focused ? meta.active : meta.inactive}
              size={23}
              color={focused ? colors.accent : colors.textFaint}
            />
            <AppText
              variant="caption"
              style={{ color: focused ? colors.accent : colors.textFaint, fontSize: 11, fontWeight: focused ? '700' : '500' }}
            >
              {meta.label}
            </AppText>
          </Pressable>
        );
      })}
    </View>
  );
}
