import { useRouter } from 'expo-router';
import { useState } from 'react';
import { StyleSheet, useWindowDimensions, View } from 'react-native';
import Animated, { FadeIn } from 'react-native-reanimated';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState, ErrorState } from '@/components/ui/empty-state';
import { ListRow, ListSection } from '@/components/ui/list';
import { BarButton, Page } from '@/components/ui/page';
import { Pill } from '@/components/ui/pill';
import { ProgressBar } from '@/components/ui/progress-bar';
import { Section } from '@/components/ui/section';
import { Segmented } from '@/components/ui/segmented';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { leaveApi } from '@/features/leave/api';
import { formatShortDate } from '@/lib/format';
import { enter } from '@/lib/motion';
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
  const { colors, readable, spacing, status } = useTheme();
  const router = useRouter();
  // Two balances a row, at an exact half of the page less the gap between them.
  const half = { width: (useWindowDimensions().width - spacing.gutter * 2 - 12) / 2 };
  const [filter, setFilter] = useState<Filter>('all');

  const { data, loading, refreshing, refresh, error, reload } = useQuery<LeaveData>(async () => {
    const [balances, requests] = await Promise.all([leaveApi.balances(), leaveApi.requests()]);
    return { balances: balances.data, requests: requests.data };
  }, []);

  const filtered = (data?.requests ?? []).filter((r) => filter === 'all' || r.status === filter);
  const fileLeave = () => router.push('/leave/new');

  return (
    <Page
      title="Leave"
      right={<BarButton icon="plus" label="File a leave request" onPress={fileLeave} prominent />}
      refreshing={refreshing}
      onRefresh={refresh}
      tabInset
    >
      {error && !data ? (
        <ErrorState message={error} onRetry={reload} />
      ) : (
        <>
          {/* Balances */}
          <Section title="Balances">
            {loading ? (
              <View style={styles.grid}>
                <Skeleton height={128} radius={20} style={half} />
                <Skeleton height={128} radius={20} style={half} />
              </View>
            ) : (data?.balances.length ?? 0) === 0 ? (
              <Card>
                <AppText variant="subheadline" tone="secondary">
                  No leave types are set up for you yet.
                </AppText>
              </Card>
            ) : (
              <View style={styles.grid}>
                {data?.balances.map((balance, index) => {
                  const tone = readable(balance.color ?? colors.tint, colors.card, 3);
                  return (
                    <Animated.View key={balance.leave_type_id} entering={enter(index)} style={half}>
                      <Card
                        style={styles.balance}
                        accessibilityLabel={`${balance.name}: ${balance.remaining} of ${balance.entitled} days left${
                          balance.pending > 0 ? `, ${balance.pending} pending` : ''
                        }`}
                      >
                        <View style={styles.balanceName}>
                          <View style={[styles.dot, { backgroundColor: tone }]} />
                          <AppText variant="footnote" weight="medium" tone="secondary" numberOfLines={1} style={styles.flex}>
                            {balance.name}
                          </AppText>
                        </View>
                        <View style={styles.figure}>
                          <AppText variant="title1" numeric>
                            {balance.remaining}
                          </AppText>
                          <AppText variant="footnote" tone="secondary" numeric>
                            / {balance.entitled} left
                          </AppText>
                        </View>
                        <ProgressBar
                          value={balance.entitled > 0 ? balance.remaining / balance.entitled : 0}
                          color={tone}
                          height={5}
                        />
                        {balance.pending > 0 && (
                          <AppText variant="caption" weight="medium" color={readable(status.late)}>
                            {balance.pending} pending
                          </AppText>
                        )}
                      </Card>
                    </Animated.View>
                  );
                })}
              </View>
            )}
          </Section>

          {/* History */}
          <Section title="History" gap={12}>
            <Segmented options={FILTERS} value={filter} onChange={setFilter} accessibilityLabel="Filter requests" />

            {loading ? (
              <Skeleton height={180} radius={20} />
            ) : filtered.length === 0 ? (
              <EmptyState
                icon="doc"
                title={filter === 'all' ? 'No requests yet' : `No ${filter} requests`}
                message={filter === 'all' ? 'Requests you file will appear here with their status.' : undefined}
                action={
                  filter === 'all' ? (
                    <Button label="File leave" icon="plus" variant="tinted" size="sm" fullWidth={false} onPress={fileLeave} />
                  ) : undefined
                }
              />
            ) : (
              <Animated.View key={filter} entering={FadeIn.duration(220)}>
                <ListSection leadingWidth={4}>
                  {filtered.map((request) => {
                    const meta = leaveMeta(request.status);
                    return (
                      <ListRow
                        key={request.id}
                        leading={
                          <View
                            style={[
                              styles.typeBar,
                              { backgroundColor: readable(request.type?.color ?? colors.tint, colors.card, 3) },
                            ]}
                          />
                        }
                        title={request.type?.name ?? 'Leave'}
                        subtitle={`${formatShortDate(request.start_date)}${
                          request.start_date !== request.end_date ? ` – ${formatShortDate(request.end_date)}` : ''
                        } · ${request.days} ${request.days === 1 ? 'day' : 'days'}`}
                        accessory={<Pill label={meta.label} color={meta.color} />}
                        onPress={() => router.push({ pathname: '/leave/[id]', params: { id: String(request.id) } })}
                      />
                    );
                  })}
                </ListSection>
              </Animated.View>
            )}
          </Section>
        </>
      )}
    </Page>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: 12 },
  balance: { gap: 10 },
  balanceName: { flexDirection: 'row', alignItems: 'center', gap: 7 },
  figure: { flexDirection: 'row', alignItems: 'baseline', gap: 4 },
  dot: { width: 8, height: 8, borderRadius: 4 },
  typeBar: { width: 4, height: 34, borderRadius: 2 },
});
