/**
 * The post-login workspace picker — shown after a fresh sign-in when the employee
 * belongs to more than one company (ADR 0023). It mirrors the web app's "pick a
 * company" landing: choosing one binds the session to that workspace (a fresh
 * org-scoped token) and the root navigator drops into the app shell.
 *
 * Companies render as rounded squares here, the system-wide mark for an
 * organisation (people are always circles). Visual language matches the sign-in
 * screen: the white entry ground, navy second — here the workspace tile — and teal
 * marking the workspace already open, as teal marks the active thing everywhere.
 */
import { Ionicons } from '@expo/vector-icons';
import { useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, View } from 'react-native';
import Animated, { FadeIn } from 'react-native-reanimated';

import { EntryScreen, entryColors as colors } from '@/components/ui/entry-screen';
import { useToast } from '@/components/ui/toast';
import { AppText } from '@/components/ui/text';
import { CompanyLogo } from '@/features/workspaces/workspace-switcher';
import { useAuth } from '@/lib/auth';
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
    <EntryScreen>
      <ScrollView
        contentContainerStyle={{ flexGrow: 1, justifyContent: 'center', padding: 24 }}
      >
        <Animated.View entering={FadeIn.duration(450)} style={{ marginBottom: 28 }}>
          <View
            style={{
              width: 56,
              height: 56,
              borderRadius: 18,
              backgroundColor: colors.secondary,
              alignItems: 'center',
              justifyContent: 'center',
              marginBottom: 18,
            }}
          >
            <Ionicons name="grid" size={26} color={colors.onSecondary} />
          </View>
          <AppText variant="title">Choose a workspace</AppText>
          <AppText variant="caption" muted style={{ marginTop: 6 }}>
            Pick the company you’d like to work in. You can switch anytime from your
            profile.
          </AppText>
        </Animated.View>

        <Animated.View entering={FadeIn.duration(450).delay(120)} style={{ gap: 10 }}>
          {organizations.map((org) => {
            const active = org.id === organization?.id;
            const busy = entering === org.id;

            return (
              <Pressable
                key={org.id}
                onPress={() => onEnter(org)}
                disabled={entering !== null}
                accessibilityRole="button"
                accessibilityLabel={`Open ${org.name}`}
                style={({ pressed }) => ({
                  flexDirection: 'row',
                  alignItems: 'center',
                  gap: 14,
                  padding: 14,
                  borderRadius: 18,
                  // The ERP separates a card from the page by its edge, not a shade.
                  backgroundColor: pressed ? colors.cardAlt : colors.card,
                  borderWidth: 1,
                  borderColor: colors.border,
                  opacity: entering !== null && !busy ? 0.6 : 1,
                })}
              >
                <CompanyLogo uri={org.logo} initials={org.initials} active={active} />
                <View style={{ flex: 1 }}>
                  <AppText variant="label" numberOfLines={1}>
                    {org.name}
                  </AppText>
                  {active && (
                    <AppText variant="caption" color={colors.accentText} style={{ marginTop: 2 }}>
                      Current workspace
                    </AppText>
                  )}
                </View>
                {busy ? (
                  <ActivityIndicator color={colors.secondaryText} />
                ) : (
                  <Ionicons name="chevron-forward" size={20} color={colors.textFaint} />
                )}
              </Pressable>
            );
          })}
        </Animated.View>

        <Pressable
          onPress={logout}
          hitSlop={10}
          disabled={entering !== null}
          accessibilityRole="button"
          style={{ marginTop: 28, alignSelf: 'center' }}
        >
          <AppText variant="caption" muted style={{ fontWeight: '600' }}>
            Sign out
          </AppText>
        </Pressable>
      </ScrollView>
    </EntryScreen>
  );
}
