import { Ionicons } from '@expo/vector-icons';
import { useRouter } from 'expo-router';
import { useState } from 'react';
import { Pressable, RefreshControl, ScrollView, View } from 'react-native';
import Animated, { FadeIn } from 'react-native-reanimated';

import { Avatar } from '@/components/ui/avatar';
import { Card } from '@/components/ui/card';
import { Pill, withAlpha } from '@/components/ui/pill';
import { Screen } from '@/components/ui/screen';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { attendanceApi } from '@/features/attendance/api';
import { awardsApi } from '@/features/awards/api';
import { leaveApi } from '@/features/leave/api';
import { WorkspaceChip, WorkspaceSwitcher } from '@/features/workspaces/workspace-switcher';
import { useAuth } from '@/lib/auth';
import { formatDate, formatLongDate, formatMinutes, formatTime } from '@/lib/format';
import { attendanceMeta } from '@/lib/status';
import { useQuery } from '@/lib/use-query';
import { useTheme } from '@/theme/theme';
import type { Award, LeaveBalance, LeaveRequest, TodayResponse } from '@/types/api';

type HomeData = {
  today: TodayResponse;
  balances: LeaveBalance[];
  awards: Award[];
  pending: LeaveRequest[];
};

function greeting(): string {
  const h = new Date().getHours();
  if (h < 12) return 'Good morning';
  if (h < 18) return 'Good afternoon';
  return 'Good evening';
}

export default function HomeScreen() {
  const { colors, spacing } = useTheme();
  const { user } = useAuth();
  const router = useRouter();
  const [switcherOpen, setSwitcherOpen] = useState(false);

  const { data, loading, refreshing, refresh } = useQuery<HomeData>(async () => {
    const [today, balances, awards, pending] = await Promise.all([
      attendanceApi.today(),
      leaveApi.balances(),
      awardsApi.list(),
      leaveApi.requests('pending'),
    ]);
    return { today, balances: balances.data, awards: awards.data, pending: pending.data };
  }, []);

  const record = data?.today.data;
  const statusMeta = record ? attendanceMeta(record.status) : null;
  const firstName = user?.employee?.full_name?.split(' ')[0] ?? user?.name?.split(' ')[0] ?? 'there';

  const actions = [
    { icon: 'finger-print', label: 'Clock', tint: colors.accent, onPress: () => router.push('/(tabs)/clock') },
    { icon: 'add-circle', label: 'File Leave', tint: '#6366F1', onPress: () => router.push('/leave/new') },
    { icon: 'calendar', label: 'Attendance', tint: '#10B981', onPress: () => router.push('/(tabs)/attendance') },
    { icon: 'trophy', label: 'Awards', tint: '#F59E0B', onPress: () => router.push('/awards') },
  ] as const;

  return (
    <Screen>
      <ScrollView
        contentContainerStyle={{ padding: spacing.lg, paddingBottom: 120, gap: spacing.lg }}
        showsVerticalScrollIndicator={false}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.accent} />}
      >
        {/* Active company — tap to switch workspaces */}
        <View style={{ flexDirection: 'row' }}>
          <WorkspaceChip onPress={() => setSwitcherOpen(true)} />
        </View>

        {/* Greeting */}
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: spacing.md }}>
          <Avatar uri={user?.employee?.photo} initials={firstName.slice(0, 2)} size={52} ring />
          <View style={{ flex: 1 }}>
            <AppText variant="caption" muted>
              {greeting()},
            </AppText>
            <AppText variant="title">{firstName}</AppText>
          </View>
          {data && data.pending.length > 0 && (
            <Pressable onPress={() => router.push('/(tabs)/requests')}>
              <View
                style={{
                  flexDirection: 'row',
                  alignItems: 'center',
                  gap: 6,
                  backgroundColor: withAlpha('#F59E0B', 0.14),
                  paddingHorizontal: 10,
                  paddingVertical: 7,
                  borderRadius: 999,
                }}
              >
                <Ionicons name="hourglass-outline" size={15} color="#F59E0B" />
                <AppText variant="caption" style={{ color: '#F59E0B', fontWeight: '700' }}>
                  {data.pending.length} pending
                </AppText>
              </View>
            </Pressable>
          )}
        </View>

        {/* Today hero */}
        <Animated.View entering={FadeIn.duration(400)}>
          <Card
            elevated
            onPress={() => router.push('/(tabs)/clock')}
            style={{ backgroundColor: colors.brand, borderColor: colors.brand }}
          >
            <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start' }}>
              <View style={{ gap: 2 }}>
                <AppText variant="overline" style={{ color: 'rgba(255,255,255,0.6)' }}>
                  {formatLongDate(new Date())}
                </AppText>
                <AppText variant="title" style={{ color: '#fff' }}>
                  {record?.first_in_at ? (record.last_out_at ? 'Day complete' : 'You’re clocked in') : 'Not clocked in'}
                </AppText>
              </View>
              <View
                style={{
                  width: 46,
                  height: 46,
                  borderRadius: 23,
                  backgroundColor: colors.accent,
                  alignItems: 'center',
                  justifyContent: 'center',
                }}
              >
                <Ionicons name="finger-print" size={24} color={colors.onAccent} />
              </View>
            </View>

            {loading ? (
              <Skeleton height={20} width="60%" style={{ marginTop: 16 }} />
            ) : (
              <View style={{ flexDirection: 'row', alignItems: 'center', gap: spacing.lg, marginTop: spacing.lg }}>
                <View>
                  <AppText variant="caption" style={{ color: 'rgba(255,255,255,0.6)' }}>
                    Time In
                  </AppText>
                  <AppText variant="heading" style={{ color: '#fff' }}>
                    {formatTime(record?.first_in_at)}
                  </AppText>
                </View>
                <View>
                  <AppText variant="caption" style={{ color: 'rgba(255,255,255,0.6)' }}>
                    Worked
                  </AppText>
                  <AppText variant="heading" style={{ color: '#fff' }}>
                    {formatMinutes(record?.worked_minutes ?? 0)}
                  </AppText>
                </View>
                {statusMeta && <Pill label={statusMeta.label} color={statusMeta.color} style={{ marginLeft: 'auto' }} />}
              </View>
            )}
          </Card>
        </Animated.View>

        {/* Quick actions */}
        <View style={{ flexDirection: 'row', gap: spacing.md }}>
          {actions.map((action) => (
            <Pressable key={action.label} onPress={action.onPress} style={{ flex: 1 }}>
              <Card padded={false} style={{ alignItems: 'center', paddingVertical: spacing.lg, gap: 8 }}>
                <View
                  style={{
                    width: 42,
                    height: 42,
                    borderRadius: 14,
                    backgroundColor: withAlpha(action.tint, 0.14),
                    alignItems: 'center',
                    justifyContent: 'center',
                  }}
                >
                  <Ionicons name={action.icon} size={21} color={action.tint} />
                </View>
                <AppText variant="caption" style={{ fontWeight: '600', fontSize: 11 }}>
                  {action.label}
                </AppText>
              </Card>
            </Pressable>
          ))}
        </View>

        {/* Leave balances */}
        <View style={{ gap: spacing.sm }}>
          <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}>
            <AppText variant="overline" muted>
              Leave Balances
            </AppText>
            <Pressable onPress={() => router.push('/(tabs)/requests')}>
              <AppText variant="caption" style={{ color: colors.accent, fontWeight: '700' }}>
                See all
              </AppText>
            </Pressable>
          </View>
          {loading ? (
            <Skeleton height={92} radius={18} />
          ) : (
            <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: spacing.md }}>
              {(data?.balances ?? []).slice(0, 5).map((balance) => (
                <Card key={balance.leave_type_id} style={{ width: 130, gap: 4 }}>
                  <View style={{ width: 8, height: 8, borderRadius: 4, backgroundColor: balance.color ?? colors.accent }} />
                  <AppText variant="caption" muted numberOfLines={1}>
                    {balance.name}
                  </AppText>
                  <AppText variant="title">{balance.remaining}</AppText>
                  <AppText variant="caption" faint>
                    of {balance.entitled} days
                  </AppText>
                </Card>
              ))}
            </ScrollView>
          )}
        </View>

        {/* Latest award */}
        {!loading && data && data.awards.length > 0 && (
          <View style={{ gap: spacing.sm }}>
            <AppText variant="overline" muted>
              Latest Recognition
            </AppText>
            <Card
              onPress={() => router.push('/awards')}
              style={{ flexDirection: 'row', alignItems: 'center', gap: spacing.md }}
            >
              <View
                style={{
                  width: 46,
                  height: 46,
                  borderRadius: 14,
                  backgroundColor: withAlpha(data.awards[0].award_type?.color ?? '#F59E0B', 0.16),
                  alignItems: 'center',
                  justifyContent: 'center',
                }}
              >
                <Ionicons name="trophy" size={22} color={data.awards[0].award_type?.color ?? '#F59E0B'} />
              </View>
              <View style={{ flex: 1 }}>
                <AppText variant="label">{data.awards[0].award_type?.name ?? 'Award'}</AppText>
                <AppText variant="caption" muted numberOfLines={1}>
                  {data.awards[0].reason ?? formatDate(data.awards[0].awarded_on)}
                </AppText>
              </View>
              <Ionicons name="chevron-forward" size={18} color={colors.textFaint} />
            </Card>
          </View>
        )}
      </ScrollView>

      <WorkspaceSwitcher visible={switcherOpen} onClose={() => setSwitcherOpen(false)} />
    </Screen>
  );
}
