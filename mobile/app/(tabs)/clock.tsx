import { Ionicons } from '@expo/vector-icons';
import { Image } from 'expo-image';
import { useCallback, useEffect, useState } from 'react';
import { Pressable, ScrollView, View } from 'react-native';
import Animated, { FadeIn, FadeInDown, ZoomIn } from 'react-native-reanimated';
import * as Haptics from 'expo-haptics';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Pill, withAlpha } from '@/components/ui/pill';
import { Screen, ScreenHeader } from '@/components/ui/screen';
import { Skeleton } from '@/components/ui/skeleton';
import { Sheet } from '@/components/ui/sheet';
import { AppText } from '@/components/ui/text';
import { useToast } from '@/components/ui/toast';
import { attendanceApi } from '@/features/attendance/api';
import { PUNCH_META } from '@/features/attendance/punch-meta';
import { ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatClock, formatElapsed, formatLongDate, formatMinutes, formatTime } from '@/lib/format';
import { getCurrentCoords, type Coords } from '@/lib/location';
import { captureSelfie } from '@/lib/selfie';
import { attendanceMeta } from '@/lib/status';
import { useQuery } from '@/lib/use-query';
import { useTheme } from '@/theme/theme';
import type { PunchType, TodayResponse } from '@/types/api';

export default function ClockScreen() {
  const { colors, spacing, status } = useTheme();
  const { user } = useAuth();
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
  const allowed = today.data?.allowed ?? [];
  const nextExpected = today.data?.next_expected ?? null;
  const schedule = user?.employee?.schedule;

  const isCompleted = nextExpected === null && !!record?.last_out_at;
  const onClock = !!record?.first_in_at && !record?.last_out_at;
  const primary = nextExpected;
  const secondary = allowed.filter((t) => t !== primary);

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

    try {
      await attendanceApi.punch({
        type: pendingType,
        latitude: coords?.latitude,
        longitude: coords?.longitude,
        accuracy: coords?.accuracy,
        photoUri,
      });

      void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success);
      const done = pendingType;
      setPendingType(null);
      setJustPunched(done);
      setTimeout(() => setJustPunched(null), 1600);
      toast.show(`${PUNCH_META[done].label} recorded at ${formatTime(new Date().toISOString())}`, 'success');
      await today.reload();
    } catch (error) {
      const message = error instanceof ApiError ? error.message : 'Could not record your punch.';
      toast.show(message, 'error');
    } finally {
      setSubmitting(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pendingType, coords, photoUri]);

  const statusMeta = record ? attendanceMeta(record.status) : null;

  return (
    <Screen>
      <ScreenHeader title="Daily Time Record" subtitle="Clock in and out" />

      <ScrollView
        contentContainerStyle={{ padding: spacing.lg, paddingBottom: 120, gap: spacing.lg }}
        showsVerticalScrollIndicator={false}
      >
        {/* Live clock hero */}
        <Animated.View entering={FadeInDown.duration(400)}>
          <Card elevated style={{ alignItems: 'center', paddingVertical: spacing.xl }}>
            <AppText style={{ fontSize: 52, fontWeight: '800', letterSpacing: 1, color: colors.text, fontVariant: ['tabular-nums'] }}>
              {formatTime(now.toISOString())}
            </AppText>
            <AppText variant="label" muted style={{ marginTop: 4 }}>
              {formatLongDate(now)}
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
                  <Ionicons name="time-outline" size={22} color={colors.accent} />
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
                  <AppText style={{ fontSize: 30, fontWeight: '800', color: colors.accent, fontVariant: ['tabular-nums'] }}>
                    {formatElapsed(now.getTime() - new Date(record.first_in_at).getTime())}
                  </AppText>
                  <AppText variant="caption" faint>
                    since {formatTime(record.first_in_at)}
                  </AppText>
                </View>
              )}
            </Card>

            {/* Punch summary row */}
            {record && (record.first_in_at || record.last_out_at) && (
              <Card>
                <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                  <SummaryStat label="Time In" value={formatTime(record.first_in_at)} />
                  <SummaryStat label="Time Out" value={formatTime(record.last_out_at)} />
                  <SummaryStat label="Worked" value={formatMinutes(record.worked_minutes)} />
                </View>
                {record.late_minutes > 0 && (
                  <AppText variant="caption" style={{ color: status.late, marginTop: spacing.md }}>
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
                    backgroundColor: withAlpha(colors.accent, 0.14),
                    alignItems: 'center',
                    justifyContent: 'center',
                  }}
                >
                  <Ionicons name="checkmark-done" size={30} color={colors.accent} />
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
          entering={ZoomIn}
          pointerEvents="none"
          style={{
            position: 'absolute',
            top: '42%',
            alignSelf: 'center',
            width: 120,
            height: 120,
            borderRadius: 60,
            backgroundColor: PUNCH_META[justPunched].color,
            alignItems: 'center',
            justifyContent: 'center',
          }}
        >
          <Ionicons name="checkmark" size={64} color="#fff" />
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
            <ConfirmRow icon="time-outline" label="Time" value={formatTime(now.toISOString())} />
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
                  <Ionicons name="camera-outline" size={22} color={colors.accent} />
                </View>
              )}
              <AppText variant="label" style={{ flex: 1 }}>
                {photoUri ? 'Selfie attached — tap to retake' : 'Add a verification selfie (optional)'}
              </AppText>
              <Ionicons name="chevron-forward" size={18} color={colors.textFaint} />
            </Pressable>

            <Button label={`Confirm ${PUNCH_META[pendingType].label}`} onPress={submitPunch} loading={submitting} size="lg" />
          </Animated.View>
        )}
      </Sheet>
    </Screen>
  );
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
