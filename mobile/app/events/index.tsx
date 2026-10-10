import { useRouter } from 'expo-router';
import { useMemo, useState } from 'react';
import { Linking, Share, StyleSheet, View } from 'react-native';
import Animated from 'react-native-reanimated';

import { Card } from '@/components/ui/card';
import { EmptyState, ErrorState } from '@/components/ui/empty-state';
import { Icon } from '@/components/ui/icon';
import { ListRow, ListSection } from '@/components/ui/list';
import { Page } from '@/components/ui/page';
import { Pill } from '@/components/ui/pill';
import { Segmented } from '@/components/ui/segmented';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { useToast } from '@/components/ui/toast';
import { eventsApi } from '@/features/events/api';
import { RESPONSE_COLOR, RESPONSE_LABEL } from '@/features/events/meta';
import { useAuth } from '@/lib/auth';
import { dayParts, formatTime } from '@/lib/format';
import { enter } from '@/lib/motion';
import { useQuery } from '@/lib/use-query';
import { useTheme } from '@/theme/theme';
import { palette } from '@/theme/tokens';
import type { MyInvitation } from '@/types/api';

type Tab = 'upcoming' | 'past';

/**
 * My events (ADR 0070): the person's invitations as an agenda, day by day. A
 * repeating event shows its next date, with how many more follow. A row opens
 * the event, where it is answered.
 */
export default function EventsScreen() {
  const { colors } = useTheme();
  const { organization } = useAuth();
  const router = useRouter();
  const toast = useToast();
  const timeZone = organization?.timezone;
  const [view, setView] = useState<Tab>('upcoming');

  const { data, loading, refreshing, refresh, error, reload } = useQuery(() => eventsApi.list(), []);
  const invitations = useMemo(() => data?.data ?? [], [data]);

  const { groups, later } = useMemo(() => {
    const list = invitations.filter((i) => (view === 'past' ? i.event.status === 'past' : i.event.status !== 'past'));
    const hidden = new Map<number, number>();
    const seen = new Set<number>();
    const kept = list.filter((i) => {
      const series = i.event.series?.id;
      if (view === 'past' || series === undefined || !seen.has(series)) {
        if (series !== undefined) seen.add(series);
        return true;
      }
      hidden.set(series, (hidden.get(series) ?? 0) + 1);
      return false;
    });

    const byDay: { key: string; parts: ReturnType<typeof dayParts>; items: MyInvitation[] }[] = [];
    for (const invitation of kept) {
      const parts = dayParts(invitation.event.starts_at, timeZone);
      const last = byDay.at(-1);
      if (last && last.key === parts.key) last.items.push(invitation);
      else byDay.push({ key: parts.key, parts, items: [invitation] });
    }

    return { groups: byDay, later: hidden };
  }, [invitations, view, timeZone]);

  /** Subscribe: the phone's calendar app takes webcal links; otherwise share the https one. */
  const subscribe = async () => {
    try {
      const links = await eventsApi.calendar();
      const opened = await Linking.openURL(links.webcal).then(() => true, () => false);
      if (!opened) {
        await Share.share({ message: links.https, url: links.https });
      }
    } catch {
      toast.show('Couldn’t get your calendar link. Try again.', 'error');
    }
  };

  return (
    <Page
      title="My Events"
      subtitle={data && data.pending > 0 ? `${data.pending} waiting for your answer` : undefined}
      back
      refreshing={refreshing}
      onRefresh={refresh}
      gap={16}
    >
      <Segmented
        options={[
          { value: 'upcoming', label: 'Coming up' },
          { value: 'past', label: 'Past' },
        ]}
        value={view}
        onChange={setView}
        accessibilityLabel="Which events"
      />

      {loading ? (
        <>
          <Skeleton height={96} radius={20} />
          <Skeleton height={96} radius={20} />
          <Skeleton height={96} radius={20} />
        </>
      ) : error && !data ? (
        <ErrorState message={error} onRetry={reload} />
      ) : groups.length === 0 ? (
        <EmptyState
          icon="calendar"
          title={view === 'upcoming' ? 'Nothing coming up' : 'Nothing in the last month'}
          message={
            view === 'upcoming'
              ? 'When you’re invited to an event or a meeting, it shows up here.'
              : 'Events you were invited to stay here for a month.'
          }
        />
      ) : (
        groups.map((group, index) => (
          <Animated.View key={group.key} entering={enter(index)} style={styles.day}>
            <View style={styles.dayHead}>
              <AppText variant="footnote" weight="semibold" color={group.parts.isToday ? colors.tintText : colors.textSecondary}>
                {group.parts.isToday ? 'Today' : group.parts.weekday}
              </AppText>
              <AppText variant="title2" numeric>
                {group.parts.day}
              </AppText>
              <AppText variant="footnote" tone="secondary">
                {group.parts.month}
              </AppText>
            </View>
            <View style={styles.cards}>
              {group.items.map((invitation) => (
                <InvitationRow
                  key={invitation.id}
                  invitation={invitation}
                  later={invitation.event.series ? (later.get(invitation.event.series.id) ?? 0) : 0}
                  timeZone={timeZone}
                  onPress={() => router.push({ pathname: '/events/[id]', params: { id: invitation.event.hashid } })}
                />
              ))}
            </View>
          </Animated.View>
        ))
      )}

      {data && (
        <ListSection footer="Your calendar app checks this private link on its own, so new invitations and changes show up there too.">
          <ListRow icon="calendarPlus" iconColor={palette.teal} title="Subscribe in your calendar" onPress={() => void subscribe()} />
        </ListSection>
      )}
    </Page>
  );
}

function InvitationRow({
  invitation,
  later,
  timeZone,
  onPress,
}: {
  invitation: MyInvitation;
  later: number;
  timeZone?: string | null;
  onPress: () => void;
}) {
  const { colors } = useTheme();
  const { event } = invitation;
  const where = event.room?.name ?? event.location;

  return (
    <Card onPress={onPress} accessibilityLabel={`${event.title}, ${formatTime(event.starts_at, timeZone)}, ${RESPONSE_LABEL[invitation.response]}`} style={styles.card}>
      <View style={styles.cardTop}>
        <AppText variant="headline" numberOfLines={2} style={styles.flex}>
          {event.title}
        </AppText>
        <Pill label={RESPONSE_LABEL[invitation.response]} color={RESPONSE_COLOR[invitation.response]} on={colors.card} dot />
      </View>
      <AppText variant="subheadline" tone="secondary" numeric>
        {formatTime(event.starts_at, timeZone)}
        {event.ends_at ? ` – ${formatTime(event.ends_at, timeZone)}` : ''}
        {where ? ` · ${where}` : ''}
      </AppText>
      {event.series && (
        <View style={styles.series}>
          <Icon name="retry" size={12} color={colors.textTertiary} />
          <AppText variant="footnote" tone="secondary">
            {event.series.summary}
            {later > 0 ? ` · ${later} more ${later === 1 ? 'date' : 'dates'}` : ''}
          </AppText>
        </View>
      )}
    </Card>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  day: { flexDirection: 'row', gap: 12 },
  dayHead: { width: 44, alignItems: 'center', paddingTop: 4 },
  cards: { flex: 1, gap: 10 },
  card: { gap: 6 },
  cardTop: { flexDirection: 'row', alignItems: 'flex-start', gap: 10 },
  series: { flexDirection: 'row', alignItems: 'center', gap: 5 },
});
