import Constants from 'expo-constants';
import { useRouter } from 'expo-router';
import { useState } from 'react';
import { Alert, StyleSheet, View } from 'react-native';
import Animated from 'react-native-reanimated';

import { Avatar } from '@/components/ui/avatar';
import { ErrorState } from '@/components/ui/empty-state';
import { ListRow, ListSection } from '@/components/ui/list';
import { Page } from '@/components/ui/page';
import { Pill } from '@/components/ui/pill';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { useQueuedPunches } from '@/features/attendance/punch-queue';
import { profileApi } from '@/features/profile/api';
import { CompanyLogo, WorkspaceSwitcher } from '@/features/workspaces/workspace-switcher';
import { useAuth } from '@/lib/auth';
import { formatDate, humanize } from '@/lib/format';
import { enter } from '@/lib/motion';
import { useQuery } from '@/lib/use-query';
import { useTheme, type ThemeMode } from '@/theme/theme';
import { palette } from '@/theme/tokens';
import type { Profile } from '@/types/api';

const APPEARANCE: { mode: ThemeMode; label: string; icon: 'sun' | 'moon' | 'phoneDevice' }[] = [
  { mode: 'light', label: 'Light', icon: 'sun' },
  { mode: 'dark', label: 'Dark', icon: 'moon' },
  { mode: 'system', label: 'Match phone', icon: 'phoneDevice' },
];

export default function ProfileScreen() {
  const { colors, mode, setMode, status } = useTheme();
  const { logout, organization, organizations } = useAuth();
  const router = useRouter();
  const [switcherOpen, setSwitcherOpen] = useState(false);
  const waiting = useQueuedPunches().length;

  const { data, loading, refreshing, refresh, error, reload } = useQuery<{ data: Profile }>(() => profileApi.show(), []);
  const profile = data?.data ?? null;

  const confirmSignOut = () => {
    // Queued punches are kept per account and company (ADR 0040), so signing out loses none.
    const note =
      waiting > 0
        ? `${waiting} ${waiting === 1 ? 'punch is' : 'punches are'} still waiting to send. ${waiting === 1 ? 'It stays' : 'They stay'} on this phone and will be sent when you sign back in to this company.`
        : undefined;

    Alert.alert('Sign out of SYNAPSE?', note, [
      { text: 'Cancel', style: 'cancel' },
      { text: 'Sign out', style: 'destructive', onPress: () => void logout() },
    ]);
  };

  return (
    <Page title="Profile" refreshing={refreshing} onRefresh={refresh} tabInset gap={28}>
      {loading ? (
        <View style={styles.identity}>
          <Skeleton width={88} height={88} radius={44} />
          <Skeleton width={180} height={22} />
          <Skeleton width={140} height={16} />
        </View>
      ) : error && !profile ? (
        <ErrorState message={error} onRetry={reload} />
      ) : profile ? (
        <>
          {/* Identity */}
          <Animated.View entering={enter(0)} style={styles.identity}>
            <Avatar uri={profile.photo} initials={profile.initials} size={88} />
            <View style={styles.names}>
              <AppText variant="title2" center>
                {profile.full_name}
              </AppText>
              <AppText variant="subheadline" tone="secondary" center>
                {profile.position?.title ?? 'Employee'}
                {profile.department ? ` · ${profile.department.name}` : ''}
              </AppText>
            </View>
            <View style={styles.pills}>
              {profile.employee_no && <Pill label={profile.employee_no} color={palette.teal} on={colors.background} />}
              {profile.employment_status && (
                <Pill label={humanize(profile.employment_status)} color={status.present} on={colors.background} dot />
              )}
            </View>
          </Animated.View>

          <Animated.View entering={enter(1)}>
            <ListSection withIcons>
              <ListRow
                icon="trophy"
                iconColor={status.late}
                title="Awards & recognition"
                onPress={() => router.push('/awards')}
              />
              {organization && (
                <ListRow
                  leading={<CompanyLogo uri={organization.logo} initials={organization.initials} size={30} />}
                  title={organization.name}
                  subtitle={organizations.length > 1 ? `Switch · ${organizations.length} companies` : 'Your company'}
                  onPress={() => setSwitcherOpen(true)}
                  accessibilityLabel={`Workspace: ${organization.name}`}
                />
              )}
            </ListSection>
          </Animated.View>

          <Animated.View entering={enter(2)} style={styles.sections}>
            <ListSection header="Personal">
              <ListRow title="Birth date" value={formatDate(profile.birth_date)} />
              <ListRow title="Gender" value={humanize(profile.gender)} />
              <ListRow title="Civil status" value={humanize(profile.civil_status)} />
            </ListSection>

            <ListSection header="Contact">
              <ListRow title="Email" value={profile.email ?? '—'} />
              <ListRow title="Phone" value={profile.phone ?? '—'} />
              <ListRow title="Address" value={profile.address ?? '—'} wrap />
            </ListSection>

            <ListSection header="Employment">
              <ListRow title="Type" value={humanize(profile.employment_type)} />
              <ListRow title="Date hired" value={formatDate(profile.date_hired)} />
              <ListRow title="Regularized" value={formatDate(profile.date_regularized)} />
              <ListRow title="Manager" value={profile.manager?.full_name ?? '—'} />
            </ListSection>

            <ListSection header="Government IDs" footer="Shown masked. Your HR team holds the full numbers.">
              <ListRow title="TIN" value={profile.government_ids.tin ?? '—'} />
              <ListRow title="SSS" value={profile.government_ids.sss_no ?? '—'} />
              <ListRow title="PhilHealth" value={profile.government_ids.philhealth_no ?? '—'} />
              <ListRow title="Pag-IBIG" value={profile.government_ids.pagibig_no ?? '—'} />
            </ListSection>
          </Animated.View>
        </>
      ) : null}

      {/* Appearance */}
      <ListSection header="Appearance" withIcons>
        {APPEARANCE.map((option) => (
          <ListRow
            key={option.mode}
            icon={option.icon}
            iconColor={option.mode === 'dark' ? '#5E5CE6' : option.mode === 'light' ? status.late : '#8E8E93'}
            title={option.label}
            selected={mode === option.mode}
            onPress={() => setMode(option.mode)}
          />
        ))}
      </ListSection>

      <View style={styles.footer}>
        <ListSection style={styles.signOut}>
          <ListRow title="Sign out" destructive onPress={confirmSignOut} chevron={false} />
        </ListSection>
        <AppText variant="footnote" tone="secondary" center>
          SYNAPSE · Version {Constants.expoConfig?.version ?? '1.0.0'}
        </AppText>
      </View>

      <WorkspaceSwitcher visible={switcherOpen} onClose={() => setSwitcherOpen(false)} />
    </Page>
  );
}

const styles = StyleSheet.create({
  identity: { alignItems: 'center', gap: 12, marginTop: 4 },
  names: { gap: 3, alignItems: 'center', paddingHorizontal: 16 },
  pills: { flexDirection: 'row', gap: 8 },
  sections: { gap: 28 },
  footer: { gap: 16 },
  signOut: { alignSelf: 'stretch' },
});
