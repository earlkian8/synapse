import { useRouter } from 'expo-router';
import { Alert, StyleSheet, View } from 'react-native';
import Animated from 'react-native-reanimated';

import { Avatar } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState, ErrorState } from '@/components/ui/empty-state';
import { BarButton, Page } from '@/components/ui/page';
import { Pill } from '@/components/ui/pill';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { useToast } from '@/components/ui/toast';
import { recognitionApi } from '@/features/recognition/api';
import { ApiError } from '@/lib/api';
import { formatAgo } from '@/lib/format';
import { enter } from '@/lib/motion';
import { useQuery } from '@/lib/use-query';
import { useTheme } from '@/theme/theme';
import type { Nomination } from '@/types/api';

const STATUS: Record<Nomination['status'], { label: string; tone: 'late' | 'present' | 'rest' }> = {
  pending: { label: 'Waiting for review', tone: 'late' },
  approved: { label: 'Approved', tone: 'present' },
  rejected: { label: 'Not approved', tone: 'rest' },
  withdrawn: { label: 'Withdrawn', tone: 'rest' },
};

/** Who the person nominated, for what, and what HR decided (ADR 0071). */
export default function NominationsScreen() {
  const { colors, status } = useTheme();
  const router = useRouter();
  const toast = useToast();
  const { data, loading, refreshing, refresh, error, reload } = useQuery(() => recognitionApi.nominations(), []);

  const withdraw = (nomination: Nomination) =>
    Alert.alert('Withdraw this nomination?', `${nomination.nominee?.name ?? 'They'} won’t be considered for ${nomination.award_type?.name ?? 'the award'} from it.`, [
      { text: 'Keep it', style: 'cancel' },
      {
        text: 'Withdraw',
        style: 'destructive',
        onPress: async () => {
          try {
            await recognitionApi.withdraw(nomination.id);
            toast.show('Nomination withdrawn.', 'success');
            void reload();
          } catch (e) {
            toast.show(e instanceof ApiError ? e.message : 'Couldn’t withdraw it. Try again.', 'error');
          }
        },
      },
    ]);

  return (
    <Page
      title="My Nominations"
      back
      refreshing={refreshing}
      onRefresh={refresh}
      gap={12}
      right={<BarButton icon="plus" label="Nominate" onPress={() => router.push('/recognition/nominate')} prominent />}
    >
      {loading ? (
        <Skeleton height={120} radius={20} />
      ) : error && !data ? (
        <ErrorState message={error} onRetry={reload} />
      ) : (data?.data ?? []).length === 0 ? (
        <EmptyState
          icon="rosette"
          title="No nominations yet"
          message="Know someone who went beyond? Nominate them — your reason becomes their citation."
          action={<Button label="Nominate a colleague" icon="rosette" fullWidth={false} onPress={() => router.push('/recognition/nominate')} />}
        />
      ) : (
        (data?.data ?? []).map((nomination, index) => (
          <Animated.View key={nomination.id} entering={enter(index)}>
            <Card style={styles.card}>
              <View style={styles.head}>
                <Avatar uri={nomination.nominee?.photo} initials={nomination.nominee?.initials} size={38} />
                <View style={styles.flex}>
                  <AppText variant="headline" numberOfLines={1}>
                    {nomination.nominee?.name ?? 'A former colleague'}
                  </AppText>
                  <AppText variant="footnote" tone="secondary">
                    {nomination.award_type?.name} · {formatAgo(nomination.created_at)}
                  </AppText>
                </View>
                <Pill label={STATUS[nomination.status].label} color={status[STATUS[nomination.status].tone]} on={colors.card} dot />
              </View>
              <AppText variant="callout" tone="secondary">
                {nomination.reason}
              </AppText>
              {nomination.review_note && (
                <AppText variant="footnote" tone="secondary">
                  HR: “{nomination.review_note}”
                </AppText>
              )}
              {nomination.status === 'pending' && (
                <Button label="Withdraw" variant="gray" size="sm" fullWidth={false} onPress={() => withdraw(nomination)} />
              )}
            </Card>
          </Animated.View>
        ))
      )}
    </Page>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  card: { gap: 10 },
  head: { flexDirection: 'row', alignItems: 'center', gap: 12 },
});
