import { useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { ActivityIndicator, Alert, StyleSheet, View } from 'react-native';
import Animated from 'react-native-reanimated';

import { Card } from '@/components/ui/card';
import { EmptyState, ErrorState } from '@/components/ui/empty-state';
import { ListRow, ListSection } from '@/components/ui/list';
import { Page } from '@/components/ui/page';
import { Pill } from '@/components/ui/pill';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { useToast } from '@/components/ui/toast';
import { ToneWell } from '@/components/ui/tone-well';
import { leaveApi } from '@/features/leave/api';
import { ApiError } from '@/lib/api';
import { formatDate, formatShortDate, humanize } from '@/lib/format';
import { enter } from '@/lib/motion';
import { leaveMeta } from '@/lib/status';
import { useQuery } from '@/lib/use-query';
import { useTheme } from '@/theme/theme';
import type { LeaveRequest, Paginated } from '@/types/api';

export default function LeaveDetailScreen() {
  const { colors } = useTheme();
  const { id } = useLocalSearchParams<{ id: string }>();
  const toast = useToast();

  const { data, loading, reload, error } = useQuery<Paginated<LeaveRequest>>(() => leaveApi.requests(), []);
  const request = data?.data.find((r) => String(r.id) === id) ?? null;

  const [cancelling, setCancelling] = useState(false);

  const canCancel = request && (request.status === 'pending' || request.status === 'approved');
  const meta = request ? leaveMeta(request.status) : null;

  const onCancel = async () => {
    if (!request) return;
    setCancelling(true);

    try {
      const result = await leaveApi.cancel(request.id);
      toast.show(result.message, 'success');
      await reload();
    } catch (error) {
      const message = error instanceof ApiError ? error.message : 'Could not cancel the request.';
      toast.show(message, 'error');
    } finally {
      setCancelling(false);
    }
  };

  const confirmCancel = () =>
    Alert.alert('Cancel this request?', 'This withdraws your leave request. It can’t be undone.', [
      { text: 'Keep request', style: 'cancel' },
      { text: 'Cancel request', style: 'destructive', onPress: () => void onCancel() },
    ]);

  const range = request
    ? request.start_date === request.end_date
      ? formatDate(request.start_date)
      : `${formatShortDate(request.start_date)} – ${formatDate(request.end_date)}`
    : '';

  return (
    <Page title="Leave Request" largeTitle={false} back>
      {loading ? (
        <>
          <Skeleton height={168} radius={20} />
          <Skeleton height={150} radius={20} />
        </>
      ) : error && !data ? (
        <ErrorState message={error} onRetry={reload} />
      ) : !request ? (
        <EmptyState icon="doc" title="Not found" message="This request is no longer available." />
      ) : (
        <>
          <Animated.View entering={enter(0)}>
            <Card style={styles.hero}>
              <View style={styles.heroTop}>
                <ToneWell icon="calendar" color={request.type?.color ?? colors.tint} size={44} />
                {meta && <Pill label={meta.label} color={meta.color} dot />}
              </View>
              <View style={styles.heroText}>
                <AppText variant="title2">{request.type?.name ?? 'Leave'}</AppText>
                <AppText variant="headline" tone="secondary" numeric>
                  {range}
                </AppText>
              </View>
              <AppText variant="footnote" tone="secondary">
                Filed {request.created_human ?? ''}
              </AppText>
            </Card>
          </Animated.View>

          <Animated.View entering={enter(1)}>
            <ListSection header="Details">
              <ListRow title="From" value={formatDate(request.start_date)} />
              <ListRow title="To" value={formatDate(request.end_date)} />
              <ListRow
                title="Duration"
                value={`${request.days} ${request.days === 1 ? 'day' : 'days'}${
                  request.is_half_day && request.half_day_period ? ` (${humanize(request.half_day_period)})` : ''
                }`}
              />
              {request.type ? <ListRow title="Paid" value={request.type.is_paid ? 'Yes' : 'No'} /> : null}
            </ListSection>
          </Animated.View>

          {request.reason && (
            <Animated.View entering={enter(2)}>
              <ListSection header="Reason">
                <View style={styles.note}>
                  <AppText variant="body" selectable>
                    {request.reason}
                  </AppText>
                </View>
              </ListSection>
            </Animated.View>
          )}

          {request.review_note && (
            <Animated.View entering={enter(3)}>
              <ListSection header="Reviewer note">
                <View style={styles.note}>
                  <AppText variant="body" selectable>
                    {request.review_note}
                  </AppText>
                </View>
              </ListSection>
            </Animated.View>
          )}

          {canCancel && (
            <Animated.View entering={enter(4)}>
              <ListSection
                footer={
                  request.status === 'approved'
                    ? 'Cancelling an approved request returns the days to your balance.'
                    : undefined
                }
              >
                <ListRow
                  title="Cancel request"
                  destructive
                  onPress={cancelling ? undefined : confirmCancel}
                  chevron={false}
                  accessory={cancelling ? <ActivityIndicator /> : undefined}
                />
              </ListSection>
            </Animated.View>
          )}
        </>
      )}
    </Page>
  );
}

const styles = StyleSheet.create({
  hero: { gap: 14 },
  heroTop: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  heroText: { gap: 2 },
  note: { padding: 16 },
});
