import { StyleSheet, View } from 'react-native';
import Animated from 'react-native-reanimated';

import { Card } from '@/components/ui/card';
import { EmptyState, ErrorState } from '@/components/ui/empty-state';
import { Page } from '@/components/ui/page';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { ToneWell } from '@/components/ui/tone-well';
import { awardsApi } from '@/features/awards/api';
import { formatDate } from '@/lib/format';
import { enter } from '@/lib/motion';
import { useQuery } from '@/lib/use-query';
import { useTheme } from '@/theme/theme';
import type { Award } from '@/types/api';

export default function AwardsScreen() {
  const { colors, status } = useTheme();

  const { data, loading, refreshing, refresh, error, reload } = useQuery<{ data: Award[] }>(() => awardsApi.list(), []);
  const awards = data?.data ?? [];

  return (
    <Page
      title="Awards"
      subtitle={
        awards.length > 0 ? `${awards.length} ${awards.length === 1 ? 'recognition' : 'recognitions'} received` : undefined
      }
      back
      refreshing={refreshing}
      onRefresh={refresh}
      gap={12}
    >
      {loading ? (
        <>
          <Skeleton height={120} radius={20} />
          <Skeleton height={120} radius={20} />
        </>
      ) : error && !data ? (
        <ErrorState message={error} onRetry={reload} />
      ) : awards.length === 0 ? (
        <EmptyState
          icon="trophy"
          title="No awards yet"
          message="Recognition you receive from your team will appear here."
        />
      ) : (
        awards.map((award, index) => {
          const tint = award.award_type?.color ?? status.late;

          return (
            <Animated.View key={award.id} entering={enter(index)}>
              <Card style={styles.card}>
                <View style={styles.head}>
                  <ToneWell icon="rosette" color={tint} size={46} />
                  <View style={styles.flex}>
                    <AppText variant="headline">{award.award_type?.name ?? 'Award'}</AppText>
                    <AppText variant="footnote" tone="secondary">
                      {formatDate(award.awarded_on)}
                      {award.granted_by ? ` · from ${award.granted_by.name}` : ''}
                    </AppText>
                  </View>
                </View>
                {award.reason && (
                  <View style={[styles.quote, { borderLeftColor: colors.separator }]}>
                    <AppText variant="callout" tone="secondary" selectable>
                      {award.reason}
                    </AppText>
                  </View>
                )}
              </Card>
            </Animated.View>
          );
        })
      )}
    </Page>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1, gap: 2 },
  card: { gap: 14 },
  head: { flexDirection: 'row', alignItems: 'center', gap: 14 },
  quote: { borderLeftWidth: 3, paddingLeft: 12, marginLeft: 2 },
});
