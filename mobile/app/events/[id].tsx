import { useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { StyleSheet, Switch, View } from 'react-native';
import Animated from 'react-native-reanimated';

import { Card } from '@/components/ui/card';
import { ErrorState } from '@/components/ui/empty-state';
import { ListRow, ListSection } from '@/components/ui/list';
import { Page } from '@/components/ui/page';
import { Pill } from '@/components/ui/pill';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { useToast } from '@/components/ui/toast';
import { eventsApi } from '@/features/events/api';
import { AnswerButtons } from '@/features/events/answer-buttons';
import { ANSWERS, reminderLabel } from '@/features/events/meta';
import { ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { dayParts, formatTime } from '@/lib/format';
import { enter } from '@/lib/motion';
import { useQuery } from '@/lib/use-query';
import { useTheme } from '@/theme/theme';
import { palette } from '@/theme/tokens';
import type { EventAnswer, MyInvitation } from '@/types/api';

/**
 * One invitation (ADR 0070): when and where, who runs it and who is coming,
 * and the person's answer — for this date, or every later date of a repeating
 * event too.
 */
export default function EventScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { colors, status } = useTheme();
  const { organization } = useAuth();
  const toast = useToast();
  const timeZone = organization?.timezone;

  const { data, loading, refreshing, refresh, error, reload, setData } = useQuery<{ data: MyInvitation }>(
    () => eventsApi.show(id),
    [id],
  );
  const [following, setFollowing] = useState(false);
  const [sending, setSending] = useState<EventAnswer | null>(null);

  const invitation = data?.data;
  const event = invitation?.event;

  const answer = async (response: EventAnswer) => {
    if (!event) return;
    setSending(response);

    try {
      const result = await eventsApi.respond(event.hashid, response, following ? 'following' : 'this');
      setData({ data: result.data });
      const label = ANSWERS.find((a) => a.value === response)?.label ?? 'Answered';
      toast.show(result.answered > 1 ? `${label} — for ${result.answered} dates.` : `${label}. The organiser can see it.`, 'success');
    } catch (e) {
      toast.show(e instanceof ApiError ? e.message : 'Couldn’t send your answer. Try again.', 'error');
    } finally {
      setSending(null);
    }
  };

  const day = event ? dayParts(event.starts_at, timeZone) : null;
  const closed = event?.status === 'past';
  const where = event ? [event.room?.name, event.room?.location ?? event.location].filter(Boolean).join(', ') : '';

  return (
    <Page title={event?.title ?? 'Event'} largeTitle={false} back refreshing={refreshing} onRefresh={refresh} gap={20}>
      {loading ? (
        <>
          <Skeleton height={160} radius={20} />
          <Skeleton height={56} radius={14} />
        </>
      ) : error && !invitation ? (
        <ErrorState message={error} onRetry={reload} />
      ) : event && day ? (
        <>
          <Animated.View entering={enter(0)}>
            <Card style={styles.hero}>
              <View style={styles.heroTop}>
                <View style={[styles.badge, { backgroundColor: colors.tintSoft }]}>
                  <AppText variant="caption" weight="semibold" color={colors.tintText}>
                    {day.isToday ? 'Today' : day.weekday}
                  </AppText>
                  <AppText variant="title1" numeric>
                    {day.day}
                  </AppText>
                  <AppText variant="caption" tone="secondary">
                    {day.month}
                  </AppText>
                </View>
                <View style={styles.flex}>
                  <AppText variant="title3">{event.title}</AppText>
                  <AppText variant="subheadline" tone="secondary" numeric>
                    {formatTime(event.starts_at, timeZone)}
                    {event.ends_at ? ` – ${formatTime(event.ends_at, timeZone)}` : ''}
                  </AppText>
                  {event.status === 'ongoing' && <Pill label="Happening now" color={status.present} on={colors.card} dot />}
                </View>
              </View>
              {event.description && (
                <AppText variant="callout" tone="secondary" selectable>
                  {event.description}
                </AppText>
              )}
            </Card>
          </Animated.View>

          <Animated.View entering={enter(1)} style={styles.gap}>
            <AppText variant="footnote" tone="secondary" style={styles.label}>
              {closed ? 'YOUR ANSWER' : 'ARE YOU GOING?'}
            </AppText>
            <AnswerButtons value={invitation.response} onAnswer={(a) => void answer(a)} disabled={closed} sending={sending} title={event.title} />
            {event.series && !closed && (
              <ListSection>
                <ListRow
                  title="Also for later dates"
                  subtitle={event.series.summary}
                  accessory={
                    <Switch
                      value={following}
                      onValueChange={setFollowing}
                      trackColor={{ true: colors.tint, false: colors.fillStrong }}
                      ios_backgroundColor={colors.fillStrong}
                      accessibilityLabel="Also for later dates"
                    />
                  }
                />
              </ListSection>
            )}
            {closed && (
              <AppText variant="footnote" tone="secondary" style={styles.label}>
                This event is over, so answers are closed.
              </AppText>
            )}
          </Animated.View>

          <Animated.View entering={enter(2)}>
            <ListSection withIcons>
              {where !== '' && <ListRow icon="pin" iconColor={palette.teal} title="Where" value={where} wrap />}
              {event.organizer && <ListRow icon="person" iconColor="#5856D6" title="Organiser" value={event.organizer} />}
              <ListRow icon="people" iconColor={status.present} title="Coming" value={`${event.attending_count} of ${event.attendees_count}`} />
              {event.series && <ListRow icon="retry" iconColor={status.late} title="Repeats" value={event.series.summary} wrap />}
              {reminderLabel(event.reminder_minutes) && !closed && (
                <ListRow icon="info" iconColor={status.holiday} title="Reminder" value={reminderLabel(event.reminder_minutes) ?? ''} />
              )}
            </ListSection>
          </Animated.View>
        </>
      ) : null}
    </Page>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1, gap: 4 },
  gap: { gap: 10 },
  hero: { gap: 14 },
  heroTop: { flexDirection: 'row', gap: 14, alignItems: 'flex-start' },
  badge: { width: 58, paddingVertical: 8, borderRadius: 14, alignItems: 'center' },
  label: { paddingHorizontal: 4, letterSpacing: 0.4 },
});
