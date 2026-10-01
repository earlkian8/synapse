import { Ionicons } from '@expo/vector-icons';
import { Image } from 'expo-image';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Pressable, ScrollView, View } from 'react-native';
import Animated, { FadeIn } from 'react-native-reanimated';
import * as Haptics from 'expo-haptics';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Pill } from '@/components/ui/pill';
import { Screen, ScreenHeader } from '@/components/ui/screen';
import { Skeleton } from '@/components/ui/skeleton';
import { Sheet } from '@/components/ui/sheet';
import { AppText } from '@/components/ui/text';
import { useToast } from '@/components/ui/toast';
import { attendanceApi } from '@/features/attendance/api';
import { PUNCH_META } from '@/features/attendance/punch-meta';
import { isOffline, newPunchId, punchQueue, useQueuedPunches } from '@/features/attendance/punch-queue';
import { ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatClock, formatElapsed, formatLongDate, formatMinutes, formatTime } from '@/lib/format';
import { getCurrentCoords, type Coords } from '@/lib/location';
import { captureSelfie } from '@/lib/selfie';
import { attendanceMeta } from '@/lib/status';
import { useQuery } from '@/lib/use-query';
import { composite, onColor, withAlpha } from '@/theme/color';
import { useTheme } from '@/theme/theme';
import type { PunchType, TodayResponse } from '@/types/api';

export default function ClockScreen() {
  const { colors, spacing, status, readable } = useTheme();
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
  const [justPunched, setJustPunched] = useState<PunchType | null>(null);

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
    void Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Medium);
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
    const recorded = () => {
      void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success);
      setPendingType(null);
      setJustPunched(done);
      setTimeout(() => setJustPunched(null), 1600);
    };

    // Behind anything still waiting, so the server gets them in the order made.
    if (punchQueue.items().length > 0) {
      await punchQueue.add(punch);
      recorded();
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

      recorded();
      toast.show(`${PUNCH_META[done].label} recorded at ${formatTime(punch.punched_at, timeZone)}`, 'success');
      await today.reload();
    } catch (error) {
      if (isOffline(error)) {
        // No connection: keep it, with the time it was made, and send it later.
        await punchQueue.add(punch);
        recorded();
        toast.show(`No connection. ${PUNCH_META[done].label} at ${formatTime(punch.punched_at, timeZone)} is saved on this phone and will be sent when you’re back online.`, 'info');
      } else {
        const message = error instanceof ApiError ? error.message : 'Could not record your punch.';
        toast.show(message, 'error');
      }
    } finally {
      setSubmitting(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pendingType, coords, photoUri, timeZone]);

  const statusMeta = record ? attendanceMeta(record.status) : null;
  // The confirmation flash keeps the punch type's colour, pulled to a shade the tick
  // can sit on — a white tick on a raw 500-level green is 2.5:1.
  const burst = justPunched ? readable(PUNCH_META[justPunched].color, colors.background, 5) : colors.accent;

  return (
    <Screen>
      <ScreenHeader title="Daily Time Record" subtitle="Clock in and out" />

      <ScrollView
        contentContainerStyle={{ padding: spacing.lg, paddingBottom: 120, gap: spacing.lg }}
        showsVerticalScrollIndicator={false}
      >
        {/* Live clock hero */}
        <Animated.View entering={FadeIn.duration(400)}>
          <Card elevated style={{ alignItems: 'center', paddingVertical: spacing.xl }}>
            <AppText style={{ fontSize: 52, fontWeight: '800', letterSpacing: 1, color: colors.text, fontVariant: ['tabular-nums'] }}>
              {formatTime(now.toISOString(), timeZone)}
            </AppText>
            <AppText variant="label" muted style={{ marginTop: 4 }}>
              {formatLongDate(now, timeZone)}
            </AppText>

            {statusMeta && (
              <Pill label={statusMeta.label} color={statusMeta.color} dot style={{ marginTop: spacing.md }} />
            )}
          </Card>
        </Animated.View>

        {today.loading ? (
          <Skeleton height={180} radius={18} />
        ) : (
          <>
            {/* Punches waiting to be sent */}
            {queued.length > 0 && (
              <Card style={{ gap: spacing.md }}>
                <View style={{ flexDirection: 'row', alignItems: 'flex-start', gap: spacing.md }}>
                  <Ionicons name="cloud-offline-outline" size={22} color={readable(status.late)} />
                  <View style={{ flex: 1 }}>
                    <AppText variant="label">
                      {queued.length} {queued.length === 1 ? 'punch' : 'punches'} waiting to send
                    </AppText>
                    <AppText variant="caption" muted>
                      Saved on this phone with the time you made {queued.length === 1 ? 'it' : 'them'}, and sent as soon as there’s a connection.
                    </AppText>
                  </View>
                </View>
                <Button
                  label="Send now"
                  variant="outline"
                  fullWidth={false}
                  style={{ alignSelf: 'flex-start' }}
                  onPress={() => void punchQueue.flush()}
                />
              </Card>
            )}

            {/* Shift card */}
            <Card>
              <View style={{ flexDirection: 'row', alignItems: 'center', gap: spacing.md }}>
                <View
                  style={{
                    width: 44,
                    height: 44,
                    borderRadius: 14,
                    backgroundColor: colors.accentSoft,
                    alignItems: 'center',
                    justifyContent: 'center',
                  }}
                >
                  <Ionicons name="time-outline" size={22} color={colors.accentText} />
                </View>
                <View style={{ flex: 1 }}>
                  <AppText variant="overline" muted>
                    Today’s Shift
                  </AppText>
                  <AppText variant="heading">
                    {schedule?.start_time
                      ? `${formatClock(schedule.start_time)} – ${formatClock(schedule.end_time)}`
                      : 'No shift scheduled'}
                  </AppText>
                  {schedule?.name && (
                    <AppText variant="caption" faint>
                      {schedule.name}
                    </AppText>
                  )}
                </View>
              </View>

              {onClock && record?.first_in_at && (
                <View style={{ marginTop: spacing.lg, alignItems: 'center', gap: 2 }}>
                  <AppText variant="overline" muted>
                    On the clock
                  </AppText>
                  <AppText style={{ fontSize: 30, fontWeight: '800', color: colors.text, fontVariant: ['tabular-nums'] }}>
                    {formatElapsed(now.getTime() - new Date(record.first_in_at).getTime())}
                  </AppText>
                  <AppText variant="caption" faint>
                    since {formatTime(record.first_in_at, timeZone)}
                  </AppText>
                </View>
              )}
            </Card>

            {/* Punch summary row */}
            {record && (record.first_in_at || record.last_out_at) && (
              <Card>
                <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                  <SummaryStat label="Time In" value={formatTime(record.first_in_at, timeZone)} />
                  <SummaryStat label="Time Out" value={formatTime(record.last_out_at, timeZone)} />
                  <SummaryStat label="Worked" value={formatMinutes(record.worked_minutes)} />
                </View>
                {record.late_minutes > 0 && (
                  <AppText variant="caption" style={{ color: readable(status.late), marginTop: spacing.md }}>
                    Flagged {record.late_minutes} min late
                  </AppText>
                )}
              </Card>
            )}

            {/* Primary action */}
            {isCompleted ? (
              <Card style={{ alignItems: 'center', gap: 8, paddingVertical: spacing.xl }}>
                <View
                  style={{
                    width: 56,
                    height: 56,
                    borderRadius: 28,
                    backgroundColor: withAlpha(status.present, 0.14),
                    alignItems: 'center',
                    justifyContent: 'center',
                  }}
                >
                  <Ionicons
                    name="checkmark-done"
                    size={30}
                    color={readable(status.present, composite(status.present, 0.14, colors.card))}
                  />
                </View>
                <AppText variant="heading">All done for today</AppText>
                <AppText variant="caption" muted center>
                  You completed {formatMinutes(record?.worked_minutes ?? 0)} of work today.
                </AppText>
              </Card>
            ) : (
              <View style={{ gap: spacing.md }}>
                {primary && (
                  <Button
                    label={PUNCH_META[primary].label}
                    onPress={() => beginPunch(primary)}
                    variant="accent"
                    size="lg"
                    icon={<Ionicons name={PUNCH_META[primary].icon} size={22} color={colors.onAccent} />}
                  />
                )}
                {secondary.map((type) => (
                  <Button
                    key={type}
                    label={PUNCH_META[type].label}
                    onPress={() => beginPunch(type)}
                    variant="outline"
                    icon={<Ionicons name={PUNCH_META[type].icon} size={20} color={colors.text} />}
                  />
                ))}
              </View>
            )}
          </>
        )}
      </ScrollView>

      {/* Success burst */}
      {justPunched && (
        <Animated.View
          entering={FadeIn.duration(200)}
          pointerEvents="none"
          style={{
            position: 'absolute',
            top: '42%',
            alignSelf: 'center',
            width: 120,
            height: 120,
            borderRadius: 60,
            backgroundColor: burst,
            alignItems: 'center',
            justifyContent: 'center',
          }}
        >
          <Ionicons name="checkmark" size={64} color={onColor(burst)} />
        </Animated.View>
      )}

      {/* Confirmation sheet */}
      <Sheet
        visible={pendingType !== null}
        onClose={() => (submitting ? null : setPendingType(null))}
        title={pendingType ? `Confirm ${PUNCH_META[pendingType].label}` : ''}
      >
        {pendingType && (
          <Animated.View entering={FadeIn} style={{ gap: spacing.md }}>
            <ConfirmRow icon="time-outline" label="Time" value={formatTime(now.toISOString(), timeZone)} />
            <ConfirmRow
              icon="location-outline"
              label="Location"
              value={
                locating
                  ? 'Locating…'
                  : coords
                    ? `${coords.latitude.toFixed(4)}, ${coords.longitude.toFixed(4)}`
                    : 'Location unavailable'
              }
            />

            <Pressable
              onPress={addSelfie}
              style={{
                flexDirection: 'row',
                alignItems: 'center',
                gap: spacing.md,
                borderWidth: 1,
                borderColor: colors.border,
                borderRadius: 14,
                padding: spacing.md,
              }}
            >
              {photoUri ? (
                <Image source={{ uri: photoUri }} style={{ width: 44, height: 44, borderRadius: 10 }} />
              ) : (
                <View
                  style={{
                    width: 44,
                    height: 44,
                    borderRadius: 10,
                    backgroundColor: colors.accentSoft,
                    alignItems: 'center',
                    justifyContent: 'center',
                  }}
                >
                  <Ionicons name="camera-outline" size={22} color={colors.accentText} />
                </View>
              )}
              <AppText variant="label" style={{ flex: 1 }}>
                {photoUri ? 'Selfie attached — tap to retake' : 'Add a verification selfie (optional)'}
              </AppText>
              <Ionicons name="chevron-forward" size={18} color={colors.textFaint} />
            </Pressable>

            <Button
              label={`Confirm ${PUNCH_META[pendingType].label}`}
              onPress={submitPunch}
              loading={submitting}
              variant="accent"
              size="lg"
            />
          </Animated.View>
        )}
      </Sheet>
    </Screen>
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

function SummaryStat({ label, value }: { label: string; value: string }) {
  return (
    <View style={{ alignItems: 'center', flex: 1 }}>
      <AppText variant="overline" muted>
        {label}
      </AppText>
      <AppText variant="heading" style={{ marginTop: 2, fontVariant: ['tabular-nums'] }}>
        {value}
      </AppText>
    </View>
  );
}

function ConfirmRow({
  icon,
  label,
  value,
}: {
  icon: keyof typeof Ionicons.glyphMap;
  label: string;
  value: string;
}) {
  const { colors } = useTheme();
  return (
    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
      <Ionicons name={icon} size={20} color={colors.textMuted} />
      <AppText variant="label" muted style={{ width: 70 }}>
        {label}
      </AppText>
      <AppText variant="label" style={{ flex: 1 }}>
        {value}
      </AppText>
    </View>
  );
}
