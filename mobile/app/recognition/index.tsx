import { useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useRef } from 'react';
import { StyleSheet, View } from 'react-native';
import Animated from 'react-native-reanimated';

import { Avatar } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState, ErrorState } from '@/components/ui/empty-state';
import { ListRow, ListSection } from '@/components/ui/list';
import { BarButton, Page } from '@/components/ui/page';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { ToneWell } from '@/components/ui/tone-well';
import { recognitionApi } from '@/features/recognition/api';
import { useAuth } from '@/lib/auth';
import { formatAgo } from '@/lib/format';
import { enter } from '@/lib/motion';
import { useQuery } from '@/lib/use-query';
import { useTheme } from '@/theme/theme';
import { palette } from '@/theme/tokens';
import type { FeedItem, RecognitionMe } from '@/types/api';

/**
 * Recognition (ADR 0071): the person's points and the kudos they can still give
 * with points this month, then the wall — kudos colleagues sent each other and
 * the awards given, newest first.
 */
export default function RecognitionScreen() {
  const { status } = useTheme();
  const { organization } = useAuth();
  const router = useRouter();

  const { data, loading, refreshing, refresh, error, reload } = useQuery(() => recognitionApi.wall(), []);

  // Back from sending kudos or a nomination: show it. The first focus is the
  // mount, which loads anyway.
  const focusedBefore = useRef(false);
  useFocusEffect(
    useCallback(() => {
      if (focusedBefore.current) void reload();
      focusedBefore.current = true;
    }, [reload]),
  );

  return (
    <Page
      title="Recognition"
      back
      refreshing={refreshing}
      onRefresh={refresh}
      gap={20}
      right={data?.me.has_employee ? <BarButton icon="plus" label="Send kudos" onPress={() => router.push('/recognition/kudos')} prominent /> : undefined}
    >
      {loading ? (
        <>
          <Skeleton height={150} radius={22} />
          <Skeleton height={88} radius={20} />
          <Skeleton height={88} radius={20} />
        </>
      ) : error && !data ? (
        <ErrorState message={error} onRetry={reload} />
      ) : data ? (
        <>
          {data.me.has_employee && (
            <Animated.View entering={enter(0)}>
              <PointsCard me={data.me} onKudos={() => router.push('/recognition/kudos')} onNominate={() => router.push('/recognition/nominate')} />
            </Animated.View>
          )}

          <Animated.View entering={enter(1)}>
            <ListSection withIcons>
              <ListRow icon="reward" iconColor={status.late} title="Points & rewards" onPress={() => router.push('/rewards')} />
              <ListRow icon="rosette" iconColor="#5856D6" title="My nominations" onPress={() => router.push('/recognition/nominations')} />
              <ListRow icon="trophy" iconColor={palette.teal} title="My awards" onPress={() => router.push('/awards')} />
            </ListSection>
          </Animated.View>

          <View style={styles.feed}>
            <AppText variant="title3" style={styles.heading}>
              Lately
            </AppText>
            {data.data.length === 0 ? (
              <EmptyState icon="heart" title="Nothing on the wall yet" message="Be the first: thank someone for something they did this week." />
            ) : (
              data.data.map((item, index) => (
                <Animated.View key={`${item.kind}-${item.id}`} entering={enter(Math.min(index + 2, 8))}>
                  <FeedCard item={item} timeZone={organization?.timezone} />
                </Animated.View>
              ))
            )}
          </View>
        </>
      ) : null}
      {data && !data.me.has_employee && (
        <AppText variant="footnote" tone="secondary" center>
          Your account isn’t linked to an employee record, so you can read the wall but not take part. Ask HR to link it.
        </AppText>
      )}
    </Page>
  );
}

/** Points, and a meter of the kudos with points still to give this month. */
function PointsCard({ me, onKudos, onNominate }: { me: RecognitionMe; onKudos: () => void; onNominate: () => void }) {
  const { colors } = useTheme();
  const used = Math.max(0, me.kudos_monthly_limit - me.kudos_left);

  return (
    <Card style={styles.points}>
      <View style={styles.pointsTop}>
        <View>
          <AppText variant="footnote" tone="secondary">
            Your points
          </AppText>
          <AppText variant="largeTitle" numeric>
            {me.balance.toLocaleString()}
          </AppText>
        </View>
        <ToneWell icon="sparkles" color={palette.teal} size={44} />
      </View>

      {me.kudos_points > 0 && me.kudos_monthly_limit > 0 && (
        <View style={styles.meterWrap}>
          <View style={styles.meter} accessibilityElementsHidden>
            {Array.from({ length: me.kudos_monthly_limit }, (_, i) => (
              <View key={i} style={[styles.tick, { backgroundColor: i < used ? colors.fillStrong : palette.teal }]} />
            ))}
          </View>
          <AppText variant="footnote" tone="secondary">
            {me.kudos_left > 0
              ? `${me.kudos_left} of ${me.kudos_monthly_limit} kudos this month still give ${me.kudos_points} points.`
              : 'Kudos still go out this month — without points.'}
          </AppText>
        </View>
      )}

      <View style={styles.actions}>
        <View style={styles.flex}>
          <Button label="Send kudos" icon="heart" size="sm" onPress={onKudos} />
        </View>
        <View style={styles.flex}>
          <Button label="Nominate" icon="rosette" variant="gray" size="sm" onPress={onNominate} />
        </View>
      </View>
    </Card>
  );
}

function FeedCard({ item, timeZone }: { item: FeedItem; timeZone?: string | null }) {
  const { colors } = useTheme();
  const tint = item.award_type?.color ?? palette.teal;

  return (
    <Card style={styles.item}>
      <View style={styles.itemHead}>
        {item.kind === 'kudos' && item.from ? (
          <Avatar uri={item.from.photo} initials={item.from.initials} size={36} />
        ) : (
          <ToneWell icon="rosette" color={tint} size={36} />
        )}
        <View style={styles.flex}>
          <AppText variant="subheadline" numberOfLines={2}>
            {item.kind === 'kudos' ? (
              <>
                <AppText variant="subheadline" weight="semibold">
                  {item.from?.name ?? 'A former colleague'}
                </AppText>
                {' → '}
                <AppText variant="subheadline" weight="semibold">
                  {item.to?.name ?? 'a former colleague'}
                </AppText>
              </>
            ) : (
              <>
                <AppText variant="subheadline" weight="semibold">
                  {item.to?.name ?? 'A former colleague'}
                </AppText>
                {' received '}
                <AppText variant="subheadline" weight="semibold">
                  {item.award_type?.name ?? 'an award'}
                </AppText>
              </>
            )}
          </AppText>
          <AppText variant="caption" tone="secondary">
            {formatAgo(item.at, timeZone)}
          </AppText>
        </View>
        {item.points > 0 && (
          <View style={[styles.plus, { backgroundColor: colors.tintSoft }]}>
            <AppText variant="caption" weight="semibold" color={colors.tintText} numeric>
              +{item.points}
            </AppText>
          </View>
        )}
      </View>
      {item.message && (
        <AppText variant={item.kind === 'kudos' ? 'body' : 'callout'} tone={item.kind === 'kudos' ? 'primary' : 'secondary'} selectable>
          {item.message}
        </AppText>
      )}
    </Card>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  feed: { gap: 10 },
  heading: { paddingHorizontal: 4 },
  points: { gap: 14 },
  pointsTop: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  meterWrap: { gap: 6 },
  meter: { flexDirection: 'row', gap: 4 },
  tick: { flex: 1, height: 5, borderRadius: 3 },
  actions: { flexDirection: 'row', gap: 10 },
  item: { gap: 10 },
  itemHead: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  plus: { paddingHorizontal: 8, paddingVertical: 3, borderRadius: 10 },
});
