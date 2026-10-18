import { useRouter } from 'expo-router';
import { useMemo, useState } from 'react';
import { StyleSheet, View } from 'react-native';
import Animated from 'react-native-reanimated';

import { Card } from '@/components/ui/card';
import { EmptyState, ErrorState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Page } from '@/components/ui/page';
import { Pill } from '@/components/ui/pill';
import { Segmented } from '@/components/ui/segmented';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { trainingApi } from '@/features/training/api';
import { DateLeaf, SeatMeter } from '@/features/training/components/program-parts';
import { dateRange, ENROLLMENT_STATUS, MODE_LABEL, validityLabel } from '@/features/training/meta';
import { useReloadOnReturn } from '@/features/training/use-reload-on-return';
import { enter, fadeIn } from '@/lib/motion';
import { useQuery } from '@/lib/use-query';
import { useTheme } from '@/theme/theme';
import type { CatalogEntry, TrainingEnrollmentMode } from '@/types/api';

type Filter = 'all' | Exclude<TrainingEnrollmentMode, 'closed'>;

/**
 * Browse training (ADR 0094): the programs open to sign-ups that are not over,
 * as Browse on the web lists them — when each runs, its sessions, the seats
 * left and the certificate it earns, and where the person already stands. A
 * card opens the program, where it is joined.
 *
 * The search field sits in the page's keyboard-aware scroll view, so the list
 * under it stays reachable while the keyboard is up.
 */
export default function BrowseTrainingScreen() {
  const router = useRouter();
  const [query, setQuery] = useState('');
  const [filter, setFilter] = useState<Filter>('all');

  const { data, loading, refreshing, refresh, error, reload } = useQuery(() => trainingApi.catalog(), []);
  useReloadOnReturn(reload);

  const programs = useMemo(() => data?.data ?? [], [data]);
  const words = query.trim().toLowerCase().split(/\s+/).filter(Boolean);

  const shown = programs.filter(
    (program) =>
      (filter === 'all' || program.enrollment_mode === filter) &&
      words.every((word) => `${program.name} ${program.provider ?? ''} ${program.description ?? ''}`.toLowerCase().includes(word)),
  );

  // Only worth filtering when both kinds are on offer.
  const mixed = programs.some((p) => p.enrollment_mode === 'open') && programs.some((p) => p.enrollment_mode === 'approval');

  return (
    <Page
      title="Browse training"
      subtitle={data ? (programs.length === 0 ? undefined : `${programs.length} ${programs.length === 1 ? 'program' : 'programs'} open to you`) : undefined}
      back
      refreshing={refreshing}
      onRefresh={refresh}
      gap={16}
    >
      {programs.length > 3 && (
        <Input
          icon="search"
          value={query}
          onChangeText={setQuery}
          placeholder="Search programs or providers"
          autoCorrect={false}
          returnKeyType="search"
          clearButtonMode="while-editing"
          accessibilityLabel="Search programs"
        />
      )}

      {mixed && (
        <Segmented
          options={[
            { value: 'all', label: 'All' },
            { value: 'open', label: 'Open to join' },
            { value: 'approval', label: 'On approval' },
          ]}
          value={filter}
          onChange={setFilter}
          accessibilityLabel="Which programs"
        />
      )}

      {loading ? (
        <>
          <Skeleton height={168} radius={20} />
          <Skeleton height={168} radius={20} />
        </>
      ) : error && !data ? (
        <ErrorState message={error} onRetry={reload} />
      ) : programs.length === 0 ? (
        <EmptyState
          icon="training"
          title="Nothing open to join right now"
          message="When HR opens a program to sign-ups, it shows up here. HR can still enroll you in others."
        />
      ) : shown.length === 0 ? (
        <Animated.View entering={fadeIn}>
          <EmptyState icon="search" title={words.length ? `No programs match “${query.trim()}”` : 'None of these right now'} message="Try another word, or show all programs." />
        </Animated.View>
      ) : (
        shown.map((program, index) => (
          <Animated.View key={program.id} entering={enter(index)}>
            <ProgramCard program={program} onPress={() => router.push({ pathname: '/training/[id]', params: { id: program.hashid } })} />
          </Animated.View>
        ))
      )}
    </Page>
  );
}

/** One program on offer: when, how many sittings, the seats, the certificate, and where I stand. */
function ProgramCard({ program, onPress }: { program: CatalogEntry; onPress: () => void }) {
  const { colors } = useTheme();
  const mine = program.mine ? ENROLLMENT_STATUS[program.mine.status] : null;
  const sittings = program.sessions_count === 0 ? 'Sessions to be set' : `${program.sessions_count} ${program.sessions_count === 1 ? 'session' : 'sessions'}`;

  return (
    <Card onPress={onPress} accessibilityLabel={[program.name, program.provider, dateRange(program.start_date, program.end_date), mine?.label].filter(Boolean).join(', ')} style={styles.card}>
      <View style={styles.top}>
        <DateLeaf date={program.start_date} />
        <View style={styles.titles}>
          <AppText variant="headline" numberOfLines={2}>
            {program.name}
          </AppText>
          <AppText variant="subheadline" tone="secondary" numberOfLines={1}>
            {program.provider ?? 'In-house'}
          </AppText>
        </View>
        {mine && <Pill label={mine.label} color={mine.color} dot />}
      </View>

      {program.description && (
        <AppText variant="subheadline" tone="secondary" numberOfLines={2}>
          {program.description}
        </AppText>
      )}

      <View style={[styles.facts, { borderTopColor: colors.separator }]}>
        <AppText variant="footnote" numeric>
          {dateRange(program.start_date, program.end_date)}
          <AppText variant="footnote" tone="secondary">
            {`  ${sittings}`}
          </AppText>
        </AppText>
        {!program.mine && <SeatMeter capacity={program.capacity} left={program.seats_remaining} />}
        <View style={styles.tags}>
          <AppText variant="footnote" tone="tint" weight="medium">
            {MODE_LABEL[program.enrollment_mode]}
          </AppText>
          {program.issues_certificate && (
            <AppText variant="footnote" tone="secondary">
              {`Certificate, ${validityLabel(program.certificate_validity_months).toLowerCase()}`}
            </AppText>
          )}
        </View>
      </View>
    </Card>
  );
}

const styles = StyleSheet.create({
  card: { gap: 12 },
  top: { flexDirection: 'row', alignItems: 'flex-start', gap: 12 },
  titles: { flex: 1, gap: 2, paddingTop: 2 },
  facts: { gap: 10, paddingTop: 12, borderTopWidth: StyleSheet.hairlineWidth },
  tags: { flexDirection: 'row', flexWrap: 'wrap', columnGap: 14, rowGap: 4 },
});
