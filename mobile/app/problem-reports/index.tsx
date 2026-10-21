import { useRouter } from 'expo-router';
import Animated, { FadeIn } from 'react-native-reanimated';

import { Button } from '@/components/ui/button';
import { EmptyState, ErrorState } from '@/components/ui/empty-state';
import { ListRow, ListSection } from '@/components/ui/list';
import { BarButton, Page } from '@/components/ui/page';
import { Pill } from '@/components/ui/pill';
import { Skeleton } from '@/components/ui/skeleton';
import { problemReportsApi } from '@/features/problem-reports/api';
import { reportStatusColor } from '@/features/problem-reports/meta';
import { useReloadOnReturn } from '@/features/training/use-reload-on-return';
import { formatShortDate } from '@/lib/format';
import { useQuery } from '@/lib/use-query';
import type { ProblemReport } from '@/types/api';

/**
 * Your reports (ADR 0096): every problem one has reported to the SYNAPSE team,
 * from the app or the web, and where each stands. Opened from Profile → Help.
 */
export default function ProblemReportsScreen() {
  const router = useRouter();
  const { data, loading, refreshing, refresh, error, reload } = useQuery<{ data: ProblemReport[] }>(
    () => problemReportsApi.list(),
    [],
  );
  // Back from sending one, the list already has it.
  useReloadOnReturn(reload);

  const reports = data?.data ?? [];
  const report = () => router.push('/problem-reports/new');

  return (
    <Page
      title="Your reports"
      subtitle="Problems you’ve reported to the SYNAPSE team, and their replies."
      back
      right={<BarButton icon="plus" label="Report a problem" onPress={report} prominent />}
      refreshing={refreshing}
      onRefresh={refresh}
    >
      {loading ? (
        <Skeleton height={220} radius={20} />
      ) : error && !data ? (
        <ErrorState message={error} onRetry={reload} />
      ) : reports.length === 0 ? (
        <EmptyState
          icon="flag"
          title="No reports yet"
          message="When something in SYNAPSE doesn’t work as it should, tell the team. You can follow it here."
          action={<Button label="Report a problem" icon="plus" variant="tinted" size="sm" fullWidth={false} onPress={report} />}
        />
      ) : (
        <Animated.View entering={FadeIn.duration(220)}>
          <ListSection footer={`${reports.filter((r) => r.is_open).length} waiting on the team.`}>
            {reports.map((item) => (
              <ListRow
                key={item.reference}
                title={item.subject}
                subtitle={[item.reference, formatShortDate(item.created_at), item.reply ? 'Replied' : null]
                  .filter(Boolean)
                  .join(' · ')}
                accessory={<Pill label={item.status_label} color={reportStatusColor(item.status)} />}
                onPress={() => router.push({ pathname: '/problem-reports/[id]', params: { id: item.reference } })}
              />
            ))}
          </ListSection>
        </Animated.View>
      )}
    </Page>
  );
}
