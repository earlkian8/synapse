import { Ionicons } from '@expo/vector-icons';
import type { BottomTabBarProps } from '@react-navigation/bottom-tabs';
import * as Haptics from 'expo-haptics';
import { Pressable, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { AppText } from '@/components/ui/text';
import { useQueuedPunches } from '@/features/attendance/punch-queue';
import { useTheme } from '@/theme/theme';

const ICONS: Record<string, { active: keyof typeof Ionicons.glyphMap; inactive: keyof typeof Ionicons.glyphMap; label: string }> = {
  index: { active: 'home', inactive: 'home-outline', label: 'Home' },
  attendance: { active: 'calendar', inactive: 'calendar-outline', label: 'Attendance' },
  clock: { active: 'finger-print', inactive: 'finger-print-outline', label: 'Clock' },
  requests: { active: 'document-text', inactive: 'document-text-outline', label: 'Leave' },
  profile: { active: 'person', inactive: 'person-outline', label: 'Profile' },
};

/**
 * The bottom tab bar. Teal marks the selected tab — the same job it does on the ERP's
 * sidebar — and fills the raised Clock button, which is the one place in the app where
 * the brand colour is the whole shape rather than a marker.
 */
export function TabBar({ state, navigation }: BottomTabBarProps) {
  const { colors, spacing, status } = useTheme();
  const insets = useSafeAreaInsets();
  // Punches saved while offline and not yet sent (ADR 0040).
  const waiting = useQueuedPunches().length;

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
        const tint = focused ? colors.accentText : colors.textFaint;

        const onPress = () => {
          void Haptics.selectionAsync();
          const event = navigation.emit({ type: 'tabPress', target: route.key, canPreventDefault: true });

          if (!focused && !event.defaultPrevented) {
            navigation.navigate(route.name);
          }
        };

        if (isClock) {
          return (
            <Pressable
              key={route.key}
              onPress={onPress}
              accessibilityRole="tab"
              accessibilityLabel={meta.label}
              accessibilityState={{ selected: focused }}
              style={{ flex: 1, alignItems: 'center' }}
            >
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
                  shadowColor: colors.shadow,
                  shadowOpacity: 0.18,
                  shadowRadius: 10,
                  shadowOffset: { width: 0, height: 5 },
                  elevation: 6,
                }}
              >
                <Ionicons name={meta.active} size={28} color={colors.onAccent} />
                {waiting > 0 && (
                  <View
                    accessibilityLabel={`${waiting} ${waiting === 1 ? 'punch' : 'punches'} waiting to send`}
                    style={{
                      position: 'absolute',
                      top: -4,
                      right: -4,
                      minWidth: 20,
                      height: 20,
                      borderRadius: 10,
                      paddingHorizontal: 5,
                      backgroundColor: status.late,
                      borderWidth: 2,
                      borderColor: colors.card,
                      alignItems: 'center',
                      justifyContent: 'center',
                    }}
                  >
                    <AppText style={{ color: colors.text, fontSize: 11, fontWeight: '800' }}>{waiting}</AppText>
                  </View>
                )}
              </View>
              <AppText variant="caption" style={{ color: tint, marginTop: 2, fontSize: 11, fontWeight: focused ? '700' : '500' }}>
                {meta.label}
              </AppText>
            </Pressable>
          );
        }

        return (
          <Pressable
            key={route.key}
            onPress={onPress}
            accessibilityRole="tab"
            accessibilityLabel={meta.label}
            accessibilityState={{ selected: focused }}
            style={{ flex: 1, alignItems: 'center', gap: 3, paddingVertical: 4 }}
          >
            <Ionicons name={focused ? meta.active : meta.inactive} size={23} color={tint} />
            <AppText
              variant="caption"
              style={{ color: tint, fontSize: 11, fontWeight: focused ? '700' : '500' }}
            >
              {meta.label}
            </AppText>
          </Pressable>
        );
      })}
    </View>
  );
}
