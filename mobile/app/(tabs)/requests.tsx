import { Ionicons } from '@expo/vector-icons';
import { useRouter } from 'expo-router';
import { useState } from 'react';
import { Pressable, RefreshControl, ScrollView, View } from 'react-native';
import Animated, { FadeIn } from 'react-native-reanimated';

import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Pill } from '@/components/ui/pill';
import { Screen, ScreenHeader } from '@/components/ui/screen';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { leaveApi } from '@/features/leave/api';
import { formatDate } from '@/lib/format';
import { leaveMeta } from '@/lib/status';
import { useQuery } from '@/lib/use-query';
import { useTheme } from '@/theme/theme';
import type { LeaveBalance, LeaveRequest, LeaveStatus } from '@/types/api';

type LeaveData = { balances: LeaveBalance[]; requests: LeaveRequest[] };
type Filter = 'all' | LeaveStatus;

const FILTERS: { value: Filter; label: string }[] = [
  { value: 'all', label: 'All' },
  { value: 'pending', label: 'Pending' },
  { value: 'approved', label: 'Approved' },
  { value: 'rejected', label: 'Rejected' },
];

export default function RequestsScreen() {
  const { colors, spacing } = useTheme();
  const router = useRouter();
  const [filter, setFilter] = useState<Filter>('all');

  const { data, loading, refreshing, refresh } = useQuery<LeaveData>(async () => {
    const [balances, requests] = await Promise.all([leaveApi.balances(), leaveApi.requests()]);
    return { balances: balances.data, requests: requests.data };
  }, []);

  const filtered = (data?.requests ?? []).filter((r) => filter === 'all' || r.status === filter);

  return (
    <Screen>
      <ScreenHeader
        title="Leave"
        subtitle="Balances & requests"
        right={
          <Pressable
            onPress={() => router.push('/leave/new')}
            style={{
              flexDirection: 'row',
              alignItems: 'center',
              gap: 4,
              backgroundColor: colors.accent,
              paddingHorizontal: 14,
              paddingVertical: 9,
              borderRadius: 999,
            }}
          >
            <Ionicons name="add" size={18} color={colors.onAccent} />
            <AppText variant="label" style={{ color: colors.onAccent }}>
              File
            </AppText>
          </Pressable>
        }
      />

      <ScrollView
        contentContainerStyle={{ padding: spacing.lg, paddingBottom: 120, gap: spacing.lg }}
        showsVerticalScrollIndicator={false}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.accent} />}
      >
        {/* Balances */}
        <View style={{ gap: spacing.sm }}>
          <AppText variant="overline" muted>
            Your Balances
          </AppText>
          {loading ? (
            <Skeleton height={96} radius={18} />
          ) : (
            <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: spacing.md }}>
              {(data?.balances ?? []).map((balance) => (
                <Card key={balance.leave_type_id} style={{ width: '47%', gap: 4 }}>
                  <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}>
                    <View style={{ width: 8, height: 8, borderRadius: 4, backgroundColor: balance.color ?? colors.accent }} />
                    <AppText variant="caption" muted numberOfLines={1} style={{ flex: 1 }}>
                      {balance.name}
                    </AppText>
                  </View>
                  <View style={{ flexDirection: 'row', alignItems: 'baseline', gap: 4 }}>
                    <AppText variant="title">{balance.remaining}</AppText>
                    <AppText variant="caption" faint>
                      / {balance.entitled} left
                    </AppText>
                  </View>
                  {balance.pending > 0 && (
                    <AppText variant="caption" style={{ color: '#F59E0B' }}>
                      {balance.pending} pending
                    </AppText>
                  )}
                </Card>
              ))}
            </View>
          )}
        </View>

        {/* History */}
        <View style={{ gap: spacing.sm }}>
          <AppText variant="overline" muted>
            History
          </AppText>

          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8 }}>
            {FILTERS.map((f) => {
              const active = f.value === filter;
              return (
                <Pressable
                  key={f.value}
                  onPress={() => setFilter(f.value)}
                  style={{
                    paddingHorizontal: 14,
                    paddingVertical: 7,
                    borderRadius: 999,
                    backgroundColor: active ? colors.accent : colors.card,
                    borderWidth: 1,
                    borderColor: active ? colors.accent : colors.border,
                  }}
                >
                  <AppText variant="caption" style={{ color: active ? colors.onAccent : colors.textMuted, fontWeight: '600' }}>
                    {f.label}
                  </AppText>
                </Pressable>
              );
            })}
          </ScrollView>

          {loading ? (
            <Skeleton height={140} radius={18} />
          ) : filtered.length === 0 ? (
            <EmptyState icon="document-text-outline" title="No requests" message="File a leave request to see it here." />
          ) : (
            <View style={{ gap: spacing.md }}>
              {filtered.map((request, index) => {
                const meta = leaveMeta(request.status);
                return (
                  <Animated.View key={request.id} entering={FadeIn.duration(300).delay(index * 40)}>
                    <Card
                      onPress={() => router.push({ pathname: '/leave/[id]', params: { id: String(request.id) } })}
                      style={{ flexDirection: 'row', alignItems: 'center', gap: spacing.md }}
                    >
                      <View
                        style={{
                          width: 4,
                          height: 44,
                          borderRadius: 2,
                          backgroundColor: request.type?.color ?? colors.accent,
                        }}
                      />
                      <View style={{ flex: 1 }}>
                        <AppText variant="label">{request.type?.name ?? 'Leave'}</AppText>
                        <AppText variant="caption" muted>
                          {formatDate(request.start_date)}
                          {request.start_date !== request.end_date ? ` – ${formatDate(request.end_date)}` : ''} · {request.days}{' '}
                          {request.days === 1 ? 'day' : 'days'}
                        </AppText>
                      </View>
                      <Pill label={meta.label} color={meta.color} />
                    </Card>
                  </Animated.View>
                );
              })}
            </View>
          )}
        </View>
      </ScrollView>
    </Screen>
  );
}
