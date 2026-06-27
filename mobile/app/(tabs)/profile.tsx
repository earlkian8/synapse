import { Ionicons } from '@expo/vector-icons';
import { useRouter } from 'expo-router';
import { RefreshControl, ScrollView, View } from 'react-native';

import { Avatar } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Pill } from '@/components/ui/pill';
import { Screen, ScreenHeader } from '@/components/ui/screen';
import { Segmented } from '@/components/ui/segmented';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { profileApi } from '@/features/profile/api';
import { formatDate, humanize } from '@/lib/format';
import { useAuth } from '@/lib/auth';
import { useQuery } from '@/lib/use-query';
import { status as statusColors } from '@/theme/tokens';
import { useTheme, type ThemeMode } from '@/theme/theme';
import type { Profile } from '@/types/api';

export default function ProfileScreen() {
  const { colors, spacing, mode, setMode } = useTheme();
  const { logout } = useAuth();
  const router = useRouter();

  const { data, loading, refreshing, refresh } = useQuery<{ data: Profile }>(() => profileApi.show(), []);
  const profile = data?.data ?? null;

  return (
    <Screen>
      <ScreenHeader
        title="Profile"
        right={
          <Ionicons
            name="trophy-outline"
            size={24}
            color={colors.text}
            onPress={() => router.push('/awards')}
          />
        }
      />

      <ScrollView
        contentContainerStyle={{ padding: spacing.lg, paddingBottom: 120, gap: spacing.lg }}
        showsVerticalScrollIndicator={false}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.accent} />}
      >
        {loading ? (
          <Skeleton height={160} radius={18} />
        ) : profile ? (
          <>
            {/* Identity */}
            <Card elevated style={{ alignItems: 'center', gap: 6, paddingVertical: spacing.xl }}>
              <Avatar uri={profile.photo} initials={profile.initials} size={84} ring />
              <AppText variant="title" center style={{ marginTop: 6 }}>
                {profile.full_name}
              </AppText>
              <AppText variant="body" muted center>
                {profile.position?.title ?? 'Employee'}
                {profile.department ? ` · ${profile.department.name}` : ''}
              </AppText>
              <View style={{ flexDirection: 'row', gap: 8, marginTop: 6 }}>
                <Pill label={profile.employee_no ?? '—'} color={colors.accent} />
                {profile.employment_status && (
                  <Pill label={humanize(profile.employment_status)} color={statusColors.present} />
                )}
              </View>
            </Card>

            {/* Awards shortcut */}
            <Card onPress={() => router.push('/awards')} style={{ flexDirection: 'row', alignItems: 'center', gap: spacing.md }}>
              <View
                style={{
                  width: 42,
                  height: 42,
                  borderRadius: 14,
                  backgroundColor: 'rgba(245,158,11,0.16)',
                  alignItems: 'center',
                  justifyContent: 'center',
                }}
              >
                <Ionicons name="trophy" size={20} color="#F59E0B" />
              </View>
              <AppText variant="label" style={{ flex: 1 }}>
                My Awards & Recognition
              </AppText>
              <Ionicons name="chevron-forward" size={18} color={colors.textFaint} />
            </Card>

            <Section title="Personal">
              <InfoRow label="Birth date" value={formatDate(profile.birth_date)} />
              <InfoRow label="Gender" value={humanize(profile.gender)} />
              <InfoRow label="Civil status" value={humanize(profile.civil_status)} last />
            </Section>

            <Section title="Contact">
              <InfoRow label="Email" value={profile.email ?? '—'} />
              <InfoRow label="Phone" value={profile.phone ?? '—'} />
              <InfoRow label="Address" value={profile.address ?? '—'} last />
            </Section>

            <Section title="Employment">
              <InfoRow label="Type" value={humanize(profile.employment_type)} />
              <InfoRow label="Date hired" value={formatDate(profile.date_hired)} />
              <InfoRow label="Regularized" value={formatDate(profile.date_regularized)} />
              <InfoRow label="Manager" value={profile.manager?.full_name ?? '—'} last />
            </Section>

            <Section title="Government IDs">
              <InfoRow label="TIN" value={profile.government_ids.tin ?? '—'} />
              <InfoRow label="SSS" value={profile.government_ids.sss_no ?? '—'} />
              <InfoRow label="PhilHealth" value={profile.government_ids.philhealth_no ?? '—'} />
              <InfoRow label="Pag-IBIG" value={profile.government_ids.pagibig_no ?? '—'} last />
            </Section>
          </>
        ) : null}

        {/* Appearance */}
        <View style={{ gap: spacing.sm }}>
          <AppText variant="overline" muted>
            Appearance
          </AppText>
          <Segmented
            options={[
              { value: 'light', label: 'Light' },
              { value: 'dark', label: 'Dark' },
              { value: 'system', label: 'System' },
            ]}
            value={mode}
            onChange={(value) => setMode(value as ThemeMode)}
          />
        </View>

        <Button
          label="Sign out"
          variant="outline"
          onPress={logout}
          icon={<Ionicons name="log-out-outline" size={20} color={colors.text} />}
        />
      </ScrollView>
    </Screen>
  );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  const { spacing } = useTheme();
  return (
    <View style={{ gap: spacing.sm }}>
      <AppText variant="overline" muted>
        {title}
      </AppText>
      <Card>{children}</Card>
    </View>
  );
}

function InfoRow({ label, value, last }: { label: string; value: string; last?: boolean }) {
  const { colors } = useTheme();
  return (
    <View
      style={{
        flexDirection: 'row',
        justifyContent: 'space-between',
        alignItems: 'center',
        paddingVertical: 11,
        borderBottomWidth: last ? 0 : 1,
        borderBottomColor: colors.hairline,
        gap: 16,
      }}
    >
      <AppText variant="label" muted>
        {label}
      </AppText>
      <AppText variant="label" style={{ flex: 1, textAlign: 'right' }} numberOfLines={1}>
        {value}
      </AppText>
    </View>
  );
}
