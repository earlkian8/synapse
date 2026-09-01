import { Ionicons } from '@expo/vector-icons';
import { useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { ScrollView, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Pill } from '@/components/ui/pill';
import { Screen, ScreenHeader } from '@/components/ui/screen';
import { Sheet } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { useToast } from '@/components/ui/toast';
import { leaveApi } from '@/features/leave/api';
import { ApiError } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { leaveMeta } from '@/lib/status';
import { useQuery } from '@/lib/use-query';
import { useTheme } from '@/theme/theme';
import type { LeaveRequest, Paginated } from '@/types/api';

export default function LeaveDetailScreen() {
  const { colors, spacing } = useTheme();
  const { id } = useLocalSearchParams<{ id: string }>();
  const toast = useToast();

  const { data, loading, reload } = useQuery<Paginated<LeaveRequest>>(() => leaveApi.requests(), []);
  const request = data?.data.find((r) => String(r.id) === id) ?? null;

  const [confirmOpen, setConfirmOpen] = useState(false);
  const [cancelling, setCancelling] = useState(false);

  const canCancel = request && (request.status === 'pending' || request.status === 'approved');
  const meta = request ? leaveMeta(request.status) : null;

  const onCancel = async () => {
    if (!request) return;
    setCancelling(true);

    try {
      const result = await leaveApi.cancel(request.id);
      toast.show(result.message, 'success');
      setConfirmOpen(false);
      await reload();
    } catch (error) {
      const message = error instanceof ApiError ? error.message : 'Could not cancel the request.';
      toast.show(message, 'error');
    } finally {
      setCancelling(false);
    }
  };

  return (
    <Screen edges={['top', 'bottom']}>
      <ScreenHeader title="Leave Request" back />

      <ScrollView contentContainerStyle={{ padding: spacing.lg, gap: spacing.lg }} showsVerticalScrollIndicator={false}>
        {loading ? (
          <Skeleton height={200} radius={18} />
        ) : !request ? (
          <EmptyState icon="document-outline" title="Not found" message="This request is no longer available." />
        ) : (
          <>
            <Card elevated style={{ gap: spacing.md }}>
              <View style={{ flexDirection: 'row', alignItems: 'center', gap: spacing.md }}>
                <View style={{ width: 6, height: 44, borderRadius: 3, backgroundColor: request.type?.color ?? colors.accent }} />
                <View style={{ flex: 1 }}>
                  <AppText variant="heading">{request.type?.name ?? 'Leave'}</AppText>
                  <AppText variant="caption" muted>
                    Filed {request.created_human ?? ''}
                  </AppText>
                </View>
                {meta && <Pill label={meta.label} color={meta.color} dot />}
              </View>

              <View style={{ height: 1, backgroundColor: colors.hairline }} />

              <Row label="From" value={formatDate(request.start_date)} />
              <Row label="To" value={formatDate(request.end_date)} />
              <Row
                label="Duration"
                value={`${request.days} ${request.days === 1 ? 'day' : 'days'}${
                  request.is_half_day ? ` (${request.half_day_period})` : ''
                }`}
              />
              {request.type && <Row label="Paid" value={request.type.is_paid ? 'Yes' : 'No'} />}
            </Card>

            {request.reason && (
              <Card>
                <AppText variant="overline" muted>
                  Reason
                </AppText>
                <AppText variant="body" style={{ marginTop: 4 }}>
                  {request.reason}
                </AppText>
              </Card>
            )}

            {request.review_note && (
              <Card>
                <AppText variant="overline" muted>
                  Reviewer note
                </AppText>
                <AppText variant="body" style={{ marginTop: 4 }}>
                  {request.review_note}
                </AppText>
              </Card>
            )}

            {canCancel && (
              <Button
                label="Cancel request"
                variant="danger"
                onPress={() => setConfirmOpen(true)}
                icon={<Ionicons name="close-circle-outline" size={20} color="#fff" />}
              />
            )}
          </>
        )}
      </ScrollView>

      <Sheet visible={confirmOpen} onClose={() => (cancelling ? null : setConfirmOpen(false))} title="Cancel this request?">
        <AppText variant="body" muted style={{ marginBottom: spacing.lg }}>
          This will withdraw your leave request. This can’t be undone.
        </AppText>
        <View style={{ gap: spacing.sm }}>
          <Button label="Yes, cancel it" variant="danger" onPress={onCancel} loading={cancelling} />
          <Button label="Keep request" variant="ghost" onPress={() => setConfirmOpen(false)} />
        </View>
      </Sheet>
    </Screen>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
      <AppText variant="label" muted>
        {label}
      </AppText>
      <AppText variant="label">{value}</AppText>
    </View>
  );
}
