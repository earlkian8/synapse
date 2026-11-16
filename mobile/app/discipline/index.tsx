import { useRouter } from 'expo-router';
import { StyleSheet, View } from 'react-native';
import Animated from 'react-native-reanimated';

import { Card } from '@/components/ui/card';
import { EmptyState, ErrorState } from '@/components/ui/empty-state';
import { ListRow, ListSection } from '@/components/ui/list';
import { Page } from '@/components/ui/page';
import { Pill } from '@/components/ui/pill';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { disciplineApi, STATUS_WORDS } from '@/features/discipline/api';
import type { MyNotices, NoticeSummary, Standing } from '@/features/discipline/types';
import { formatDate } from '@/lib/format';
import { enter } from '@/lib/motion';
import { useQuery } from '@/lib/use-query';
import { useTheme } from '@/theme/theme';

/**
 * My notices (ADR 0105): the notices HR has served on you, what each asks of you
 * and by when, and where you stand. Opening one reads it in full.
 */
export default function NoticesScreen() {
  const router = useRouter();
  const { status } = useTheme();
  const { data, loading, refreshing, refresh, error, reload } = useQuery<{ data: MyNotices }>(() => disciplineApi.mine(), []);
  const mine = data?.data ?? null;

  return (
    <Page title="My notices" back refreshing={refreshing} onRefresh={refresh} gap={24}>
      {loading ? (
        <>
          <Skeleton height={92} radius={20} />
          <Skeleton height={180} radius={20} />
        </>
      ) : error && !mine ? (
        <ErrorState message={error} onRetry={reload} />
      ) : mine ? (
        <>
          <Animated.View entering={enter(0)}>
            <StandingCard standing={mine.record.standing} upheld={mine.record.upheld} open={mine.record.open} />
          </Animated.View>

          {mine.cases.length === 0 ? (
            <EmptyState
              icon="doc"
              title="No notices"
              message="If HR ever serves you a notice to explain, it arrives here and you’re told at once. You’ll have at least five days to answer."
            />
          ) : (
            <Animated.View entering={enter(1)}>
              <ListSection header="Notices">
                {mine.cases.map((item) => (
                  <ListRow
                    key={item.hashid}
                    title={item.offense?.title ?? 'Notice to explain'}
                    subtitle={line(item)}
                    value={item.reference}
                    onPress={() => router.push({ pathname: '/discipline/[id]', params: { id: item.hashid } })}
                    accessibilityLabel={`${item.offense?.title ?? 'Notice to explain'}, ${STATUS_WORDS[item.status]}. ${line(item)}`}
                  />
                ))}
              </ListSection>
              {mine.cases.some((item) => waiting(item)) && (
                <AppText variant="footnote" color={status.late} style={styles.note}>
                  Answer each notice in writing by its due date. You can ask for a hearing.
                </AppText>
              )}
            </Animated.View>
          )}
        </>
      ) : null}
    </Page>
  );
}

const waiting = (item: NoticeSummary) => item.status === 'notice_issued' && !item.explained && !item.reply_overdue;

function line(item: NoticeSummary): string {
  if (waiting(item)) return `Answer by ${formatDate(item.reply_due_on)}`;
  if (item.finding === 'upheld') return 'Decided: the charge is upheld';
  if (item.finding === 'cleared') return 'Decided: not upheld';
  return `${STATUS_WORDS[item.status]}, incident of ${formatDate(item.incident_date)}`;
}

function StandingCard({ standing, upheld, open }: { standing: Standing; upheld: number; open: number }) {
  const { status } = useTheme();
  const tone =
    standing.key === 'good' ? status.present : standing.key === 'under_review' ? status.holiday : standing.key === 'dismissed' || standing.key === 'suspended' ? status.absent : status.late;

  return (
    <Card style={styles.standing}>
      <View style={styles.flex}>
        <AppText variant="footnote" tone="secondary">
          Where you stand
        </AppText>
        <AppText variant="headline">{standing.label}</AppText>
        {standing.until && (
          <AppText variant="footnote" tone="secondary">
            Until {formatDate(standing.until)}
          </AppText>
        )}
      </View>
      <Pill label={`${upheld} upheld, ${open} open`} color={tone} dot />
    </Card>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1, gap: 2 },
  standing: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  note: { marginTop: 8, marginLeft: 16 },
});
