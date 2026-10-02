/**
 * The post-login workspace picker — shown after a fresh sign-in when the employee
 * belongs to more than one company (ADR 0023). It mirrors the web app's "pick a
 * company" landing: choosing one binds the session to that workspace (a fresh
 * org-scoped token) and the root navigator drops into the app shell.
 *
 * Companies render as rounded squares here, the system-wide mark for an
 * organisation (people are always circles), in an inset grouped list on the
 * entry screens' light ground, with the workspace already open marked as current.
 */
import { useState } from 'react';
import { ActivityIndicator, StyleSheet, View } from 'react-native';
import Animated from 'react-native-reanimated';

import { EntryScreen, entryColors as colors } from '@/components/ui/entry-screen';
import { Icon } from '@/components/ui/icon';
import { ListRow, ListSection } from '@/components/ui/list';
import { AppText } from '@/components/ui/text';
import { Touchable } from '@/components/ui/touchable';
import { useToast } from '@/components/ui/toast';
import { CompanyLogo } from '@/features/workspaces/workspace-switcher';
import { useAuth } from '@/lib/auth';
import { enter } from '@/lib/motion';
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
    <EntryScreen grouped contentStyle={styles.content}>
      <Animated.View entering={enter(0)} style={styles.heading}>
        <View style={[styles.mark, { backgroundColor: colors.primary }]}>
          <Icon name="company" size={26} color={colors.onPrimary} />
        </View>
        <AppText variant="title1" center>
          Choose a workspace
        </AppText>
        <AppText variant="body" tone="secondary" center>
          Pick the company you’d like to work in. You can switch anytime from Home or your profile.
        </AppText>
      </Animated.View>

      <Animated.View entering={enter(1)}>
        <ListSection leadingWidth={40} footer={`${organizations.length} companies on this account`}>
          {organizations.map((org) => {
            const busy = entering === org.id;

            return (
              <ListRow
                key={org.id}
                title={org.name}
                subtitle={org.id === organization?.id ? 'Current workspace' : undefined}
                leading={<CompanyLogo uri={org.logo} initials={org.initials} size={40} />}
                onPress={entering === null ? () => onEnter(org) : undefined}
                chevron={!busy}
                accessory={busy ? <ActivityIndicator color={colors.textSecondary} /> : undefined}
                accessibilityLabel={`Open ${org.name}`}
              />
            );
          })}
        </ListSection>
      </Animated.View>

      <View style={styles.spacer} />

      <Touchable
        feedback="opacity"
        onPress={logout}
        hitSlop={10}
        disabled={entering !== null}
        accessibilityRole="button"
        style={styles.signOut}
      >
        <AppText variant="subheadline" weight="medium" tone="secondary">
          Sign out
        </AppText>
      </Touchable>
    </EntryScreen>
  );
}

const styles = StyleSheet.create({
  content: { paddingHorizontal: 16 },
  heading: { alignItems: 'center', gap: 6, marginTop: 32, marginBottom: 28, paddingHorizontal: 12 },
  mark: {
    width: 60,
    height: 60,
    borderRadius: 16,
    borderCurve: 'continuous',
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: 12,
  },
  spacer: { flexGrow: 1, minHeight: 32 },
  signOut: { alignSelf: 'center' },
});
