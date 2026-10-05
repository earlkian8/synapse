import * as Haptics from 'expo-haptics';
import { Image } from 'expo-image';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, StyleSheet, View } from 'react-native';
import Animated, { useAnimatedStyle, useSharedValue, withRepeat, withTiming } from 'react-native-reanimated';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { ErrorState } from '@/components/ui/empty-state';
import { ListRow, ListSection } from '@/components/ui/list';
import { Page } from '@/components/ui/page';
import { Pill } from '@/components/ui/pill';
import { Sheet } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { useToast } from '@/components/ui/toast';
import { ToneWell } from '@/components/ui/tone-well';
import { attendanceApi } from '@/features/attendance/api';
import { ClockRing } from '@/features/attendance/components/clock-ring';
import { PunchSuccess } from '@/features/attendance/components/punch-success';
import { PUNCH_META } from '@/features/attendance/punch-meta';
import { isOffline, newPunchId, punchQueue, useQueuedPunches } from '@/features/attendance/punch-queue';
import { dayHeadline, shiftMinutes } from '@/features/attendance/shift';
import { ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatClock, formatDayHeading, formatElapsed, formatMinutes, formatTime } from '@/lib/format';
import { getCurrentCoords, type Coords } from '@/lib/location';
import { enter } from '@/lib/motion';
import { captureSelfie } from '@/lib/selfie';
import { attendanceMeta } from '@/lib/status';
import { useQuery } from '@/lib/use-query';
import { useTheme } from '@/theme/theme';
import { palette } from '@/theme/tokens';
import type { PunchType, TodayResponse } from '@/types/api';

/** A shift to measure the ring against when the schedule doesn't give one. */
const DEFAULT_SHIFT = 8 * 60;

export default function ClockScreen() {
  const { colors, fonts, spacing, status, readable } = useTheme();
  const { user, organization } = useAuth();
  // Times read on the organisation's clock, whatever zone the phone is in.
  const timeZone = organization?.timezone;
  const toast = useToast();

  const today = useQuery<TodayResponse>(() => attendanceApi.today(), []);
  const [now, setNow] = useState(new Date());

  // Punch flow state.
  const [pendingType, setPendingType] = useState<PunchType | null>(null);
  const [coords, setCoords] = useState<Coords | null>(null);
  const [locating, setLocating] = useState(false);
  const [photoUri, setPhotoUri] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [justPunched, setJustPunched] = useState<{ type: PunchType; at: string; queued: boolean } | null>(null);

  // Live clock + worked counter.
  useEffect(() => {
    const id = setInterval(() => setNow(new Date()), 1000);
    return () => clearInterval(id);
  }, []);

  const record = today.data?.data;
  const schedule = user?.employee?.schedule;

  // Punches saved on this phone while it was offline (ADR 0040). Until they are
  // sent, the buttons follow them: somebody who clocked in offline is offered
  // "Clock out" next, not "Clock in" again.
  const queued = useQueuedPunches();
  const day = withQueued(today.data?.next_expected ?? null, !!record?.last_out_at, queued.map((punch) => punch.type));

  const isCompleted = day.completed;
  const onClock = !!record?.first_in_at && !record?.last_out_at;
  const onBreak = day.next === 'break_end';
  const primary = day.next;
  const secondary = day.allowed.filter((t) => t !== primary);

  // Once everything queued has gone, show the day as the server now has it.
  const queuedBefore = useRef(queued.length);
  useEffect(() => {
    if (queuedBefore.current > 0 && queued.length === 0) {
      void today.reload();
    }

    queuedBefore.current = queued.length;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [queued.length]);

  const beginPunch = useCallback(async (type: PunchType) => {
    setPendingType(type);
    setPhotoUri(null);
    setCoords(null);
    setLocating(true);
    const fix = await getCurrentCoords();
    setCoords(fix);
    setLocating(false);
  }, []);

  const addSelfie = useCallback(async () => {
    const uri = await captureSelfie();
    if (uri) setPhotoUri(uri);
  }, []);

  const submitPunch = useCallback(async () => {
    if (!pendingType) return;
    setSubmitting(true);

    const punch = {
      client_id: newPunchId(),
      type: pendingType,
      punched_at: new Date().toISOString(),
      latitude: coords?.latitude,
      longitude: coords?.longitude,
      accuracy: coords?.accuracy,
      photoUri,
    };

    const done = pendingType;
    const at = formatTime(punch.punched_at, timeZone);
    const recorded = (savedOffline: boolean) => {
      void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success);
      setPendingType(null);
      setJustPunched({ type: done, at, queued: savedOffline });
      setTimeout(() => setJustPunched(null), 1800);
    };

    // Behind anything still waiting, so the server gets them in the order made.
    if (punchQueue.items().length > 0) {
      await punchQueue.add(punch);
      recorded(true);
      toast.show(`${PUNCH_META[done].label} saved. It will be sent after the punches waiting before it.`, 'info');
      void punchQueue.flush();
      setSubmitting(false);

      return;
    }

    try {
      await attendanceApi.punch({
        type: punch.type,
        latitude: punch.latitude,
        longitude: punch.longitude,
        accuracy: punch.accuracy,
        photoUri,
        clientId: punch.client_id,
      });

      recorded(false);
      await today.reload();
    } catch (error) {
      if (isOffline(error)) {
        // No connection: keep it, with the time it was made, and send it later.
        await punchQueue.add(punch);
        recorded(true);
        toast.show(`No connection. ${PUNCH_META[done].label} at ${at} is saved on this phone and will be sent when you’re back online.`, 'info');
      } else {
        const message = error instanceof ApiError ? error.message : 'Could not record your punch.';
        toast.show(message, 'error');
      }
    } finally {
      setSubmitting(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pendingType, coords, photoUri, timeZone]);

  const statusMeta = record?.first_in_at ? attendanceMeta(record.status) : null;

  // The ring: time worked against the shift, live while on the clock.
  const target = shiftMinutes(schedule) ?? DEFAULT_SHIFT;
  const sinceIn = record?.first_in_at ? now.getTime() - new Date(record.first_in_at).getTime() : 0;
  const workedLive = onClock ? Math.max(0, sinceIn / 60000 - (record?.break_minutes ?? 0)) : (record?.worked_minutes ?? 0);
  const ringTone = isCompleted ? status.present : onBreak ? status.late : palette.teal;
  const ringColors = [ringTone, readable(ringTone, colors.card, 3)] as const;

  const [clockFace, period] = formatTime(now.toISOString(), timeZone).split(' ');

  return (
    <Page
      title="Clock"
      eyebrow={formatDayHeading(now, timeZone)}
      refreshing={today.refreshing}
      onRefresh={today.refresh}
      tabInset
    >
      {/* Punches waiting to be sent */}
      {queued.length > 0 && (
        <Animated.View entering={enter(0)}>
          <Card style={styles.queue}>
            <ToneWell icon="offline" color={status.late} />
            <View style={styles.flex}>
              <AppText variant="headline">
                {queued.length} {queued.length === 1 ? 'punch' : 'punches'} waiting to send
              </AppText>
              <AppText variant="footnote" tone="secondary">
                Saved on this phone with the time you made {queued.length === 1 ? 'it' : 'them'}, and sent as soon as
                there’s a connection.
              </AppText>
            </View>
            <Button label="Send" icon="send" variant="gray" size="sm" fullWidth={false} onPress={() => void punchQueue.flush()} />
          </Card>
        </Animated.View>
      )}

      {today.loading ? (
        <>
          <Skeleton height={470} radius={20} />
          <Skeleton height={86} radius={20} />
        </>
      ) : today.error && !today.data ? (
        <ErrorState message={today.error} onRetry={today.reload} />
      ) : (
        <>
          {/* The clock face and the next punch */}
          <Animated.View entering={enter(1)}>
            <Card style={styles.face}>
              <ClockRing progress={workedLive / target} colors={ringColors}>
                <View style={styles.time}>
                  <AppText
                    numeric
                    maxFontSizeMultiplier={1}
                    style={{ fontFamily: fonts.bold, fontSize: 54, lineHeight: 60, letterSpacing: -1.6 }}
                  >
                    {clockFace}
                  </AppText>
                  <AppText variant="headline" tone="secondary" maxFontSizeMultiplier={1} style={styles.period}>
                    {period}
                  </AppText>
                </View>

                {onClock && !onBreak && record?.first_in_at ? (
                  <View style={styles.live}>
                    <LiveDot color={readable(palette.teal)} />
                    <AppText variant="subheadline" weight="medium" tone="secondary" numeric maxFontSizeMultiplier={1.1}>
                      {formatElapsed(sinceIn)}
                    </AppText>
                  </View>
                ) : (
                  <AppText
                    variant="subheadline"
                    weight="medium"
                    color={onBreak ? readable(status.late) : colors.textSecondary}
                    maxFontSizeMultiplier={1.1}
                    style={styles.state}
                  >
                    {dayHeadline(day.next, onClock, isCompleted)}
                  </AppText>
                )}
              </ClockRing>

              {statusMeta && <Pill label={statusMeta.label} color={statusMeta.color} dot />}

              {isCompleted ? (
                <View style={styles.done}>
                  <AppText variant="headline" center>
                    All done for today
                  </AppText>
                  <AppText variant="subheadline" tone="secondary" center>
                    You worked {formatMinutes(record?.worked_minutes ?? 0)}. See you next shift.
                  </AppText>
                </View>
              ) : (
                <View style={styles.actions}>
                  {primary && (
                    <Button
                      label={PUNCH_META[primary].label}
                      icon={PUNCH_META[primary].icon}
                      onPress={() => beginPunch(primary)}
                      variant="tint"
                      size="lg"
                    />
                  )}
                  {secondary.length > 0 && (
                    <View style={styles.secondary}>
                      {secondary.map((type) => (
                        <Button
                          key={type}
                          label={PUNCH_META[type].label}
                          icon={PUNCH_META[type].icon}
                          onPress={() => beginPunch(type)}
                          variant="gray"
                          style={styles.flex}
                        />
                      ))}
                    </View>
                  )}
                </View>
              )}
            </Card>
          </Animated.View>

          {/* Today's punches, at a glance */}
          <Animated.View entering={enter(2)}>
            <Card style={styles.stats}>
              <Stat label="Time in" value={formatTime(record?.first_in_at, timeZone)} />
              <View style={[styles.rule, { backgroundColor: colors.separator }]} />
              <Stat label="Time out" value={formatTime(record?.last_out_at, timeZone)} />
              <View style={[styles.rule, { backgroundColor: colors.separator }]} />
              <Stat label="Worked" value={formatMinutes(onClock ? Math.floor(workedLive) : (record?.worked_minutes ?? 0))} />
            </Card>
            {!!record?.late_minutes && record.late_minutes > 0 && (
              <AppText variant="footnote" color={readable(status.late, colors.background)} style={styles.lateNote}>
                Flagged {record.late_minutes} min late today.
              </AppText>
            )}
          </Animated.View>

          {/* The shift */}
          <Animated.View entering={enter(3)}>
            <ListSection header="Shift" withIcons>
              <ListRow
                icon="time"
                iconColor={colors.primary}
                title={
                  schedule?.start_time
                    ? `${formatClock(schedule.start_time)} – ${formatClock(schedule.end_time)}`
                    : 'No shift scheduled'
                }
                subtitle={schedule?.name}
                value={schedule?.start_time ? formatMinutes(target) : undefined}
              />
            </ListSection>
          </Animated.View>
        </>
      )}

      {/* Confirmation sheet */}
      <Sheet
        visible={pendingType !== null}
        onClose={() => setPendingType(null)}
        dismissible={!submitting}
        title={pendingType ? `Confirm ${PUNCH_META[pendingType].label}` : ''}
        message={`${formatTime(now.toISOString(), timeZone)} · ${formatDayHeading(now, timeZone)}`}
      >
        {pendingType && (
          <View style={{ gap: spacing.xl }}>
            <ListSection raised withIcons>
              <ListRow
                icon={coords ? 'location' : 'locationSlash'}
                iconColor={coords ? status.present : colors.textTertiary}
                title="Location"
                subtitle={
                  locating
                    ? 'Finding where you are…'
                    : coords
                      ? `${coords.latitude.toFixed(4)}, ${coords.longitude.toFixed(4)}`
                      : 'Unavailable. The punch will be sent without it.'
                }
                accessory={locating ? <ActivityIndicator /> : undefined}
              />
              <ListRow
                icon={photoUri ? undefined : 'camera'}
                iconColor={colors.primary}
                leading={photoUri ? <Image source={{ uri: photoUri }} style={styles.selfie} /> : undefined}
                title={photoUri ? 'Selfie attached' : 'Verification selfie'}
                subtitle={photoUri ? 'Tap to retake' : 'Optional'}
                onPress={addSelfie}
              />
            </ListSection>

            <Button
              label={`Confirm ${PUNCH_META[pendingType].label}`}
              icon={PUNCH_META[pendingType].icon}
              onPress={submitPunch}
              loading={submitting}
              variant="tint"
              size="lg"
            />
          </View>
        )}
      </Sheet>

      {justPunched && <PunchSuccess punch={justPunched.type} time={justPunched.at} queued={justPunched.queued} />}
    </Page>
  );
}

/**
 * The day's next and allowed punches once the ones saved offline are counted —
 * the same order the server enforces, played forward from where it left off.
 */
function withQueued(
  nextExpected: PunchType | null,
  clockedOut: boolean,
  queued: PunchType[],
): { next: PunchType | null; allowed: PunchType[]; completed: boolean } {
  let onClock = nextExpected === 'clock_out' || nextExpected === 'break_end';
  let onBreak = nextExpected === 'break_end';
  let completed = nextExpected === null && clockedOut;

  for (const type of queued) {
    onClock = type === 'clock_in' || (type !== 'clock_out' && onClock);
    onBreak = type === 'break_start' || (type !== 'break_end' && type !== 'clock_out' && onBreak);
    completed = type === 'clock_out';
  }

  if (completed) {
    return { next: null, allowed: ['clock_in'], completed: true };
  }

  if (!onClock) {
    return { next: 'clock_in', allowed: ['clock_in'], completed: false };
  }

  return onBreak
    ? { next: 'break_end', allowed: ['break_end', 'clock_out'], completed: false }
    : { next: 'clock_out', allowed: ['break_start', 'clock_out'], completed: false };
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <View style={styles.stat} accessible accessibilityLabel={`${label}: ${value}`}>
      <AppText variant="footnote" tone="secondary">
        {label}
      </AppText>
      <AppText variant="headline" numeric numberOfLines={1} adjustsFontSizeToFit>
        {value}
      </AppText>
    </View>
  );
}

/** A small dot that breathes while the clock is running. */
function LiveDot({ color }: { color: string }) {
  const opacity = useSharedValue(1);

  useEffect(() => {
    opacity.set(withRepeat(withTiming(0.25, { duration: 900 }), -1, true));
  }, [opacity]);

  const style = useAnimatedStyle(() => ({ opacity: opacity.get() }));

  return <Animated.View style={[styles.dot, { backgroundColor: color }, style]} />;
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  queue: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  face: { alignItems: 'center', gap: 20, paddingTop: 28, paddingBottom: 20 },
  time: { flexDirection: 'row', alignItems: 'baseline', gap: 4 },
  period: { marginBottom: 2 },
  live: { flexDirection: 'row', alignItems: 'center', gap: 7, marginTop: 2 },
  state: { marginTop: 2 },
  dot: { width: 7, height: 7, borderRadius: 4 },
  done: { gap: 4, paddingHorizontal: 8 },
  actions: { alignSelf: 'stretch', gap: 10 },
  secondary: { flexDirection: 'row', gap: 10 },
  stats: { flexDirection: 'row', alignItems: 'center', paddingVertical: 14, paddingHorizontal: 8 },
  stat: { flex: 1, alignItems: 'center', gap: 3 },
  rule: { width: StyleSheet.hairlineWidth, alignSelf: 'stretch', marginVertical: 4 },
  lateNote: { marginTop: 8, marginLeft: 16 },
  selfie: { width: 30, height: 30, borderRadius: 8 },
});
