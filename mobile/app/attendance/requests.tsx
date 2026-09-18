import { Ionicons } from '@expo/vector-icons';
import { useRouter } from 'expo-router';
import { useState } from 'react';
import { Pressable, RefreshControl, ScrollView, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Pill } from '@/components/ui/pill';
import { Screen, ScreenHeader } from '@/components/ui/screen';
import { Sheet } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { useToast } from '@/components/ui/toast';
import { attendanceApi } from '@/features/attendance/api';
import { describeRequest, requestTypeLabel } from '@/features/attendance/requests';
import { ApiError } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { requestMeta } from '@/lib/status';
import { useQuery } from '@/lib/use-query';
import { useTheme } from '@/theme/theme';
import type { AttendanceRequest, Paginated } from '@/types/api';

/**
 * My attendance requests (ADR 0039): what I asked for, and what was decided.
 * Tapping one shows the whole ask and the reviewer's note; a pending one can be
 * withdrawn from there.
 */
export default function AttendanceRequestsScreen() {
  const { colors, spacing } = useTheme();
  const router = useRouter();
  const toast = useToast();

  const { data, loading, refreshing, refresh, reload } = useQuery<Paginated<AttendanceRequest>>(() => attendanceApi.requests(), []);
  const [open, setOpen] = useState<AttendanceRequest | null>(null);
  const [cancelling, setCancelling] = useState(false);

  const onCancel = async () => {
    if (!open) return;
    setCancelling(true);

    try {
      const result = await attendanceApi.cancelRequest(open.id);
      toast.show(result.message, 'success');
      setOpen(null);
      await reload();
    } catch (error) {
      toast.show(error instanceof ApiError ? error.message : 'Could not cancel the request.', 'error');
    } finally {
      setCancelling(false);
    }
  };

  const requests = data?.data ?? [];

  return (
    <Screen edges={['top', 'bottom']}>
      <ScreenHeader
        title="My requests"
        subtitle="Corrections, overtime and days away"
        back
        right={
          <Pressable
            onPress={() => router.push('/attendance/request')}
            accessibilityRole="button"
            accessibilityLabel="Ask for an attendance change"
            style={{ flexDirection: 'row', alignItems: 'center', gap: 4, backgroundColor: colors.primary, paddingHorizontal: 14, paddingVertical: 9, borderRadius: 999 }}
          >
            <Ionicons name="add" size={18} color={colors.onPrimary} />
            <AppText variant="label" style={{ color: colors.onPrimary }}>
              Ask
            </AppText>
          </Pressable>
        }
      />

      <ScrollView
        contentContainerStyle={{ padding: spacing.lg, paddingBottom: 60, gap: spacing.md }}
        showsVerticalScrollIndicator={false}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.accent} />}
      >
        {loading ? (
          <Skeleton height={160} radius={18} />
        ) : requests.length === 0 ? (
          <EmptyState icon="document-text-outline" title="Nothing asked yet" message="Missed a punch? Open the day in Attendance and request a correction." />
        ) : (
          requests.map((request) => {
            const meta = requestMeta(request.status);
            return (
              <Card key={request.id} onPress={() => setOpen(request)} style={{ flexDirection: 'row', alignItems: 'center', gap: spacing.md }}>
                <View style={{ flex: 1, gap: 2 }}>
                  <AppText variant="label">{requestTypeLabel(request.type)}</AppText>
                  <AppText variant="caption" muted>
                    {formatDate(request.start_date)}
                    {request.start_date !== request.end_date ? ` – ${formatDate(request.end_date)}` : ''}
                  </AppText>
                  <AppText variant="caption" faint numberOfLines={1}>
                    {describeRequest(request)}
                  </AppText>
                </View>
                <Pill label={meta.label} color={meta.color} />
              </Card>
            );
          })
        )}
      </ScrollView>

      <Sheet visible={open !== null} onClose={() => (cancelling ? null : setOpen(null))} title={open ? requestTypeLabel(open.type) : undefined}>
        {open && (
          <View style={{ gap: spacing.md }}>
            <AppText variant="body">{describeRequest(open)}</AppText>
            <AppText variant="caption" muted>
              {formatDate(open.start_date)}
              {open.start_date !== open.end_date ? ` – ${formatDate(open.end_date)}` : ''} · asked {open.created_human ?? ''}
            </AppText>
            <View style={{ gap: 2 }}>
              <AppText variant="overline" muted>
                Your reason
              </AppText>
              <AppText variant="body">{open.reason}</AppText>
            </View>
            {open.review_note && (
              <View style={{ gap: 2 }}>
                <AppText variant="overline" muted>
                  Reviewer note
                </AppText>
                <AppText variant="body">{open.review_note}</AppText>
              </View>
            )}
            {open.can.cancel ? (
              <Button label="Cancel this request" variant="danger" onPress={onCancel} loading={cancelling} />
            ) : (
              <Button label="Close" variant="ghost" onPress={() => setOpen(null)} />
            )}
          </View>
        )}
      </Sheet>
    </Screen>
  );
}
