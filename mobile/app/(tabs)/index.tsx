import { LinearGradient } from 'expo-linear-gradient';
import { useRouter } from 'expo-router';
import { useState } from 'react';
import { ScrollView, StyleSheet, View } from 'react-native';
import Animated from 'react-native-reanimated';
import Svg, { Circle } from 'react-native-svg';

import { Avatar } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { ErrorState } from '@/components/ui/empty-state';
import { Icon, type IconName } from '@/components/ui/icon';
import { ListRow, ListSection } from '@/components/ui/list';
import { Page } from '@/components/ui/page';
import { Pill } from '@/components/ui/pill';
import { ProgressBar } from '@/components/ui/progress-bar';
import { Section } from '@/components/ui/section';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { ToneWell } from '@/components/ui/tone-well';
import { useToast } from '@/components/ui/toast';
import { Touchable } from '@/components/ui/touchable';
import { attendanceApi } from '@/features/attendance/api';
import { PUNCH_META } from '@/features/attendance/punch-meta';
import { dayHeadline, shiftMinutes } from '@/features/attendance/shift';
import { awardsApi } from '@/features/awards/api';
import { AnswerButtons } from '@/features/events/answer-buttons';
import { eventsApi } from '@/features/events/api';
import { RESPONSE_COLOR, RESPONSE_LABEL } from '@/features/events/meta';
import { recognitionApi } from '@/features/recognition/api';
import { leaveApi } from '@/features/leave/api';
import { WorkspaceChip, WorkspaceSwitcher } from '@/features/workspaces/workspace-switcher';
import { ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { dayParts, formatClock, formatDate, formatDayHeading, formatMinutes, formatShortDate, formatTime } from '@/lib/format';
import { enter } from '@/lib/motion';
import { attendanceMeta } from '@/lib/status';
import { useQuery } from '@/lib/use-query';
import { useTheme } from '@/theme/theme';
import { palette, status as statusTones } from '@/theme/tokens';
import type { Award, EventAnswer, LeaveBalance, LeaveRequest, MyInvitation, TodayResponse } from '@/types/api';

type HomeData = {
  today: TodayResponse;
  balances: LeaveBalance[];
  awards: Award[];
  pending: LeaveRequest[];
  /** My events (ADR 0070), when this person answers invitations. */
  events: { data: MyInvitation[]; pending: number } | null;
  /** Points (ADR 0071), when this person takes part in recognition. */
  points: number | null;
};

function greeting(): string {
  const h = new Date().getHours();
  if (h < 12) return 'Good morning';
  if (h < 18) return 'Good afternoon';
  return 'Good evening';
}

const BALANCE_WIDTH = 152;

export default function HomeScreen() {
  const { colors, spacing } = useTheme();
  const { user, organization } = useAuth();
  const router = useRouter();
  const [switcherOpen, setSwitcherOpen] = useState(false);

  const canEvents = !!user?.can_respond_events;
  const canRecognize = !!user?.can_recognize;

  const { data, loading, refreshing, refresh, error, reload, setData } = useQuery<HomeData>(async () => {
    const [today, balances, awards, pending, events, points] = await Promise.all([
      attendanceApi.today(),
      leaveApi.balances(),
      awardsApi.list(),
      leaveApi.requests('pending'),
      canEvents ? eventsApi.list() : Promise.resolve(null),
      canRecognize ? recognitionApi.points().then((p) => p.balance) : Promise.resolve(null),
    ]);
    return { today, balances: balances.data, awards: awards.data, pending: pending.data, events, points };
  }, [canEvents, canRecognize]);

  // The next thing on the calendar the person has not turned down.
  const upNext = data?.events?.data.find((i) => i.event.status !== 'past' && i.response !== 'declined') ?? null;

  const firstName = user?.employee?.full_name?.split(' ')[0] ?? user?.name?.split(' ')[0] ?? 'there';
  const latestAward = data?.awards[0];

  const shortcuts: { icon: IconName; label: string; detail: string; color: string; onPress: () => void }[] = [
    {
      icon: 'calendarPlus',
      label: 'File leave',
      detail: 'Time off',
      color: palette.teal,
      onPress: () => router.push('/leave/new'),
    },
    canEvents
      ? {
          icon: 'calendar',
          label: 'Events',
          detail: data?.events && data.events.pending > 0 ? `${data.events.pending} to answer` : 'Invitations',
          color: statusTones.leave,
          onPress: () => router.push('/events'),
        }
      : {
          icon: 'calendar',
          label: 'Records',
          detail: 'Your time log',
          color: statusTones.leave,
          onPress: () => router.push('/(tabs)/attendance'),
        },
    canRecognize
      ? {
          icon: 'heart',
          label: 'Recognition',
          detail: data?.points != null ? `${data.points.toLocaleString()} points` : 'Kudos & rewards',
          color: statusTones.late,
          onPress: () => router.push('/recognition'),
        }
      : {
          icon: 'trophy',
          label: 'Awards',
          detail: data ? `${data.awards.length} received` : 'Recognition',
          color: statusTones.late,
          onPress: () => router.push('/awards'),
        },
  ];

  return (
    <Page
      title={`${greeting()}, ${firstName}`}
      eyebrow={formatDayHeading(new Date(), organization?.timezone)}
      titleAccessory={
        <Touchable
          onPress={() => router.push('/(tabs)/profile')}
          scaleTo={0.92}
          haptic="light"
          accessibilityRole="button"
          accessibilityLabel="Your profile"
        >
          <Avatar uri={user?.employee?.photo} initials={firstName.slice(0, 2)} size={42} />
        </Touchable>
      }
      refreshing={refreshing}
      onRefresh={refresh}
      tabInset
    >
      {organization && (
        <View style={styles.chipRow}>
          <WorkspaceChip onPress={() => setSwitcherOpen(true)} />
        </View>
      )}

      {error && !data ? (
        <ErrorState message={error} onRetry={reload} />
      ) : (
        <>
          {/* Today */}
          {loading || !data ? (
            <Skeleton height={212} radius={24} />
          ) : (
            <Animated.View entering={enter(0)}>
              <TodayCard today={data.today} onOpen={() => router.push('/(tabs)/clock')} />
            </Animated.View>
          )}

          {/* Shortcuts */}
          <Animated.View entering={enter(1)} style={styles.shortcuts}>
            {shortcuts.map((shortcut) => (
              <Card
                key={shortcut.label}
                onPress={shortcut.onPress}
                accessibilityLabel={`${shortcut.label}, ${shortcut.detail}`}
                style={styles.shortcut}
              >
                <ToneWell icon={shortcut.icon} color={shortcut.color} />
                <View style={styles.shortcutText}>
                  <AppText variant="subheadline" weight="semibold" numberOfLines={1}>
                    {shortcut.label}
                  </AppText>
                  <AppText variant="caption" tone="secondary" numberOfLines={1}>
                    {shortcut.detail}
                  </AppText>
                </View>
              </Card>
            ))}
          </Animated.View>

          {/* Up next: the next invitation, answered in one tap */}
          {upNext && (
            <Animated.View entering={enter(2)}>
              <Section title="Up next" action={{ label: 'All Events', onPress: () => router.push('/events') }}>
                <UpNextCard
                  invitation={upNext}
                  onOpen={() => router.push({ pathname: '/events/[id]', params: { id: upNext.event.hashid } })}
                  onAnswered={(updated) =>
                    data?.events &&
                    setData({
                      ...data,
                      events: {
                        pending: data.events.data.filter((i) => (i.id === updated.id ? updated : i).response === 'invited').length,
                        data: data.events.data.map((i) => (i.id === updated.id ? updated : i)),
                      },
                    })
                  }
                />
              </Section>
            </Animated.View>
          )}

          {/* Awaiting approval */}
          {data && data.pending.length > 0 && (
            <Animated.View entering={enter(2)}>
              <Section
                title="Awaiting approval"
                action={data.pending.length > 3 ? { label: 'See All', onPress: () => router.push('/(tabs)/requests') } : undefined}
              >
                <ListSection leadingWidth={4}>
                  {data.pending.slice(0, 3).map((request) => (
                    <ListRow
                      key={request.id}
                      title={request.type?.name ?? 'Leave'}
                      subtitle={`${formatShortDate(request.start_date)}${
                        request.start_date !== request.end_date ? ` – ${formatShortDate(request.end_date)}` : ''
                      } · ${request.days} ${request.days === 1 ? 'day' : 'days'}`}
                      leading={<TypeBar color={request.type?.color ?? colors.tint} />}
                      onPress={() => router.push({ pathname: '/leave/[id]', params: { id: String(request.id) } })}
                    />
                  ))}
                </ListSection>
              </Section>
            </Animated.View>
          )}

          {/* Leave balances */}
          <Animated.View entering={enter(3)}>
            <Section title="Leave balance" action={{ label: 'See All', onPress: () => router.push('/(tabs)/requests') }}>
              {loading ? (
                <View style={styles.balanceSkeletons}>
                  <Skeleton width={BALANCE_WIDTH} height={124} radius={20} />
                  <Skeleton width={BALANCE_WIDTH} height={124} radius={20} />
                </View>
              ) : (data?.balances.length ?? 0) === 0 ? (
                <Card>
                  <AppText variant="subheadline" tone="secondary">
                    No leave types are set up for you yet.
                  </AppText>
                </Card>
              ) : (
                <ScrollView
                  horizontal
                  showsHorizontalScrollIndicator={false}
                  decelerationRate="fast"
                  snapToInterval={BALANCE_WIDTH + spacing.md}
                  snapToAlignment="start"
                  style={styles.bleed}
                  contentContainerStyle={styles.balanceRow}
                >
                  {data?.balances.map((balance) => (
                    <BalanceCard key={balance.leave_type_id} balance={balance} />
                  ))}
                </ScrollView>
              )}
            </Section>
          </Animated.View>

          {/* Latest recognition */}
          {latestAward && (
            <Animated.View entering={enter(4)}>
              <Section title="Latest recognition">
                <Card onPress={() => router.push('/awards')} style={styles.award}>
                  <ToneWell icon="rosette" color={latestAward.award_type?.color ?? statusTones.late} size={44} />
                  <View style={styles.flex}>
                    <AppText variant="headline" numberOfLines={1}>
                      {latestAward.award_type?.name ?? 'Award'}
                    </AppText>
                    <AppText variant="subheadline" tone="secondary" numberOfLines={2}>
                      {latestAward.reason ?? formatDate(latestAward.awarded_on)}
                    </AppText>
                  </View>
                  <Icon name="chevronRight" size={13} color={colors.textTertiary} weight="semibold" />
                </Card>
              </Section>
            </Animated.View>
          )}
        </>
      )}

      <WorkspaceSwitcher visible={switcherOpen} onClose={() => setSwitcherOpen(false)} />
    </Page>
  );
}

/**
 * Today, on the one navy surface on the screen: where the day stands, the hours
 * against the shift, and the next punch, one tap from the clock.
 */
function TodayCard({ today, onOpen }: { today: TodayResponse; onOpen: () => void }) {
  const { colors, radius, squircle } = useTheme();
  const { user, organization } = useAuth();
  const timeZone = organization?.timezone;

  const record = today.data;
  const schedule = user?.employee?.schedule;
  const clockedIn = !!record.first_in_at && !record.last_out_at;
  const completed = today.next_expected === null && !!record.last_out_at;
  const meta = record.first_in_at || record.status !== 'absent' ? attendanceMeta(record.status) : null;

  const target = shiftMinutes(schedule);
  const worked = record.worked_minutes ?? 0;
  const next = today.next_expected;

  const detail = completed
    ? `You worked ${formatMinutes(worked)} today.`
    : record.first_in_at
      ? `Since ${formatTime(record.first_in_at, timeZone)}`
      : schedule?.start_time
        ? `Your shift starts at ${formatClock(schedule.start_time)}`
        : 'No shift scheduled today';

  return (
    <Touchable
      onPress={onOpen}
      scaleTo={0.985}
      haptic="light"
      accessibilityRole="button"
      accessibilityLabel={`Today: ${dayHeadline(next, clockedIn, completed)}. ${detail}${
        next && !completed ? ` Next: ${PUNCH_META[next].label}.` : ''
      }`}
      accessibilityHint="Opens the clock"
    >
      <LinearGradient
        colors={colors.hero}
        start={{ x: 0, y: 0 }}
        end={{ x: 1, y: 1 }}
        style={[styles.hero, squircle, { borderRadius: radius.xl }]}
      >
        {/* Two faint rings in the corner: the clock face, suggested. */}
        <Svg width={220} height={220} style={styles.rings} pointerEvents="none">
          <Circle cx={160} cy={60} r={100} stroke="rgba(255,255,255,0.07)" strokeWidth={1.5} fill="none" />
          <Circle cx={160} cy={60} r={64} stroke="rgba(10,191,191,0.22)" strokeWidth={1.5} fill="none" />
        </Svg>

        <View style={styles.heroTop}>
          <AppText variant="caption2" weight="semibold" color={colors.onHeroSecondary} style={styles.heroEyebrow}>
            TODAY
          </AppText>
          {meta && <Pill label={meta.label} color={meta.color} on={palette.navy} dot />}
        </View>

        <AppText variant="title1" color={colors.onHero} style={styles.heroTitle}>
          {dayHeadline(next, clockedIn, completed)}
        </AppText>
        <AppText variant="subheadline" color={colors.onHeroSecondary}>
          {detail}
        </AppText>

        {(record.first_in_at || target) && (
          <View style={styles.heroProgress}>
            <ProgressBar
              value={target ? worked / target : completed ? 1 : 0}
              color={palette.teal}
              track="rgba(255,255,255,0.14)"
            />
            <View style={styles.heroNumbers}>
              <AppText variant="footnote" weight="semibold" color={colors.onHero} numeric>
                {formatMinutes(worked)} worked
              </AppText>
              {target && (
                <AppText variant="footnote" color={colors.onHeroSecondary} numeric>
                  of {formatMinutes(target)}
                </AppText>
              )}
            </View>
          </View>
        )}

        {next && !completed && (
          <View style={styles.heroAction}>
            <Button label={PUNCH_META[next].label} icon={PUNCH_META[next].icon} variant="tint" decorative />
          </View>
        )}
      </LinearGradient>
    </Touchable>
  );
}

/**
 * The next invitation: when, where, and — until answered — Going / Maybe / Not
 * going right here. Answered, it shows the answer; the card opens the event.
 */
function UpNextCard({
  invitation,
  onOpen,
  onAnswered,
}: {
  invitation: MyInvitation;
  onOpen: () => void;
  onAnswered: (updated: MyInvitation) => void;
}) {
  const { colors } = useTheme();
  const { organization } = useAuth();
  const toast = useToast();
  const [sending, setSending] = useState<EventAnswer | null>(null);
  const timeZone = organization?.timezone;
  const { event } = invitation;
  const day = dayParts(event.starts_at, timeZone);
  const where = event.room?.name ?? event.location;

  const answer = async (response: EventAnswer) => {
    setSending(response);
    try {
      const result = await eventsApi.respond(event.hashid, response);
      onAnswered(result.data);
    } catch (e) {
      toast.show(e instanceof ApiError ? e.message : 'Couldn’t send your answer. Try again.', 'error');
    } finally {
      setSending(null);
    }
  };

  return (
    <Card onPress={onOpen} accessibilityLabel={`Up next: ${event.title}`} style={styles.upNext}>
      <View style={styles.upNextTop}>
        <View style={[styles.dayBadge, { backgroundColor: colors.tintSoft }]}>
          <AppText variant="caption2" weight="semibold" color={colors.tintText}>
            {day.isToday ? 'Today' : day.weekday}
          </AppText>
          <AppText variant="title3" numeric>
            {day.day}
          </AppText>
        </View>
        <View style={styles.flex}>
          <AppText variant="headline" numberOfLines={2}>
            {event.title}
          </AppText>
          <AppText variant="subheadline" tone="secondary" numberOfLines={1} numeric>
            {formatTime(event.starts_at, timeZone)}
            {where ? ` · ${where}` : ''}
          </AppText>
        </View>
        {invitation.response !== 'invited' && (
          <Pill label={RESPONSE_LABEL[invitation.response]} color={RESPONSE_COLOR[invitation.response]} on={colors.card} dot />
        )}
      </View>
      {invitation.response === 'invited' && (
        <AnswerButtons value={invitation.response} onAnswer={(a) => void answer(a)} sending={sending} title={event.title} />
      )}
    </Card>
  );
}

/** One leave type: what is left, against what was given, as a bar in the type's colour. */
function BalanceCard({ balance }: { balance: LeaveBalance }) {
  const { colors, readable } = useTheme();
  const color = readable(balance.color ?? colors.tint, colors.card, 3);

  return (
    <Card style={styles.balance} accessibilityLabel={`${balance.name}: ${balance.remaining} of ${balance.entitled} days left`}>
      <View style={styles.balanceName}>
        <View style={[styles.dot, { backgroundColor: color }]} />
        <AppText variant="footnote" weight="medium" tone="secondary" numberOfLines={1} style={styles.flex}>
          {balance.name}
        </AppText>
      </View>
      <View style={styles.balanceFigure}>
        <AppText variant="title1" numeric>
          {balance.remaining}
        </AppText>
        <AppText variant="footnote" tone="secondary" numeric>
          / {balance.entitled} days
        </AppText>
      </View>
      <ProgressBar value={balance.entitled > 0 ? balance.remaining / balance.entitled : 0} color={color} height={5} />
    </Card>
  );
}

/** The leave type's colour as a slim bar at a row's leading edge. */
function TypeBar({ color }: { color: string }) {
  const { colors, readable } = useTheme();
  return <View style={[styles.typeBar, { backgroundColor: readable(color, colors.card, 3) }]} />;
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  chipRow: { flexDirection: 'row', marginTop: -10 },
  hero: { padding: 20, overflow: 'hidden' },
  rings: { position: 'absolute', top: -40, right: -60 },
  heroTop: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', minHeight: 22 },
  heroEyebrow: { letterSpacing: 1.2 },
  heroTitle: { marginTop: 10, marginBottom: 4 },
  heroProgress: { marginTop: 20, gap: 8 },
  heroNumbers: { flexDirection: 'row', justifyContent: 'space-between' },
  heroAction: { marginTop: 20 },
  shortcuts: { flexDirection: 'row', gap: 10 },
  shortcut: { flex: 1, padding: 14, gap: 12 },
  shortcutText: { gap: 1 },
  bleed: { marginHorizontal: -16 },
  balanceRow: { paddingHorizontal: 16, gap: 12 },
  balanceSkeletons: { flexDirection: 'row', gap: 12 },
  balance: { width: BALANCE_WIDTH, gap: 10 },
  balanceName: { flexDirection: 'row', alignItems: 'center', gap: 7 },
  balanceFigure: { flexDirection: 'row', alignItems: 'baseline', gap: 4 },
  dot: { width: 8, height: 8, borderRadius: 4 },
  award: { flexDirection: 'row', alignItems: 'center', gap: 14 },
  upNext: { gap: 14 },
  upNextTop: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  dayBadge: { width: 48, paddingVertical: 6, borderRadius: 12, alignItems: 'center' },
  typeBar: { width: 4, height: 34, borderRadius: 2 },
});
