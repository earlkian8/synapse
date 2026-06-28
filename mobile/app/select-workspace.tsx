/**
 * The post-login workspace picker — shown after a fresh sign-in when the employee
 * belongs to more than one company (ADR 0023). It mirrors the web app's "pick a
 * company" landing: choosing one binds the session to that workspace (a fresh
 * org-scoped token) and the root navigator drops into the app shell.
 *
 * Companies render as rounded squares here, the system-wide mark for an
 * organisation (people are always circles). Visual language matches the sign-in
 * screen: SYNAPSE's deep-navy field with white cards.
 */
import { Ionicons } from '@expo/vector-icons';
import { useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, View } from 'react-native';
import Animated, { FadeIn } from 'react-native-reanimated';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useToast } from '@/components/ui/toast';
import { AppText } from '@/components/ui/text';
import { CompanyLogo } from '@/features/workspaces/workspace-switcher';
import { useAuth } from '@/lib/auth';
import { palette } from '@/theme/tokens';
import type { AuthOrganization } from '@/types/api';

export default function SelectWorkspaceScreen() {
  const { organization, organizations, enterWorkspace, logout } = useAuth();
  const toast = useToast();
  const [entering, setEntering] = useState<number | null>(null);

  const onEnter = async (target: AuthOrganization) => {
    if (entering !== null) return;

    setEntering(target.id);

    try {
      // On success the root navigator routes into the app shell and unmounts this.
      await enterWorkspace(target.id);
    } catch {
      toast.show('Could not open that workspace. Try again.', 'error');
      setEntering(null);
    }
  };

  return (
    <View style={{ flex: 1, backgroundColor: palette.navy }}>
      <SafeAreaView style={{ flex: 1 }}>
        <ScrollView
          contentContainerStyle={{ flexGrow: 1, justifyContent: 'center', padding: 24 }}
        >
          <Animated.View entering={FadeIn.duration(450)} style={{ marginBottom: 28 }}>
            <View
              style={{
                width: 56,
                height: 56,
                borderRadius: 18,
                backgroundColor: palette.teal,
                alignItems: 'center',
                justifyContent: 'center',
                marginBottom: 18,
              }}
            >
              <Ionicons name="grid" size={28} color={palette.navyDeep} />
            </View>
            <AppText variant="title" style={{ color: palette.white }}>
              Choose a workspace
            </AppText>
            <AppText
              variant="caption"
              style={{ color: 'rgba(255,255,255,0.6)', marginTop: 6 }}
            >
              Pick the company you’d like to work in. You can switch anytime from your
              profile.
            </AppText>
          </Animated.View>

          <Animated.View
            entering={FadeIn.duration(450).delay(120)}
            style={{ gap: 10 }}
          >
            {organizations.map((org) => {
              const active = org.id === organization?.id;
              const busy = entering === org.id;

              return (
                <Pressable
                  key={org.id}
                  onPress={() => onEnter(org)}
                  disabled={entering !== null}
                  style={({ pressed }) => ({
                    flexDirection: 'row',
                    alignItems: 'center',
                    gap: 14,
                    padding: 14,
                    borderRadius: 18,
                    backgroundColor: palette.white,
                    opacity: pressed || (entering !== null && !busy) ? 0.85 : 1,
                  })}
                >
                  <CompanyLogo uri={org.logo} initials={org.initials} active={active} />
                  <View style={{ flex: 1 }}>
                    <AppText variant="label" style={{ color: palette.navy }} numberOfLines={1}>
                      {org.name}
                    </AppText>
                    {active && (
                      <AppText variant="caption" style={{ color: palette.teal, marginTop: 2 }}>
                        Current workspace
                      </AppText>
                    )}
                  </View>
                  {busy ? (
                    <ActivityIndicator color={palette.teal} />
                  ) : (
                    <Ionicons name="chevron-forward" size={20} color="#94A3B8" />
                  )}
                </Pressable>
              );
            })}
          </Animated.View>

          <Pressable
            onPress={logout}
            hitSlop={10}
            disabled={entering !== null}
            style={{ marginTop: 28, alignSelf: 'center' }}
          >
            <AppText variant="caption" style={{ color: 'rgba(255,255,255,0.55)' }}>
              Sign out
            </AppText>
          </Pressable>
        </ScrollView>
      </SafeAreaView>
    </View>
  );
}
