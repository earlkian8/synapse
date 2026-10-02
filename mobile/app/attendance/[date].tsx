import { Image } from 'expo-image';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { StyleSheet, View } from 'react-native';
import Animated from 'react-native-reanimated';

import { Card } from '@/components/ui/card';
import { EmptyState, ErrorState } from '@/components/ui/empty-state';
import { ListSection } from '@/components/ui/list';
import { BarTextButton, Page } from '@/components/ui/page';
import { Pill } from '@/components/ui/pill';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { ToneWell } from '@/components/ui/tone-well';
import { attendanceApi } from '@/features/attendance/api';
import { PUNCH_META, PUNCH_TIMELINE_LABEL } from '@/features/attendance/punch-meta';
import { useAuth } from '@/lib/auth';
import { formatClock, formatDate, formatMinutes, formatTime, formatWeekday } from '@/lib/format';
import { enter } from '@/lib/motion';
import { attendanceMeta } from '@/lib/status';
import { useQuery } from '@/lib/use-query';
import { useTheme } from '@/theme/theme';
import type { AttendanceRecord, Paginated, Punch } from '@/types/api';

export default function AttendanceDayScreen() {
  const { colors } = useTheme();
  const { date } = useLocalSearchParams<{ date: string }>();
  const { organization } = useAuth();
  const router = useRouter();

  const { data, loading, error, reload } = useQuery<Paginated<AttendanceRecord>>(
    () => attendanceApi.records(date, date),
    [date],
  );

  const record = data?.data?.[0] ?? null;
  const meta = record ? attendanceMeta(record.status) : null;
  const punches = record?.punches ?? [];
  const selfie = punches.find((p) => p.photo)?.photo ?? null;

  const metrics = record
    ? [
        { label: 'Worked', value: record.worked_minutes },
        { label: 'Late', value: record.late_minutes },
        { label: 'Undertime', value: record.undertime_minutes },
        { label: 'Overtime', value: record.overtime_minutes },
      ]
    : [];

  return (
    <Page
      title={formatDate(date)}
      largeTitle={false}
      modal
      right={<BarTextButton label="Done" emphasized onPress={() => router.back()} />}
    >
      {loading ? (
        <>
          <Skeleton height={200} radius={20} />
          <Skeleton height={180} radius={20} />
        </>
      ) : error && !data ? (
        <ErrorState message={error} onRetry={reload} />
      ) : !record ? (
        <EmptyState icon="calendar" title="No record" message="Nothing was recorded for this day." />
      ) : (
        <>
          <Animated.View entering={enter(0)}>
            <Card style={styles.hero}>
              <View style={styles.heroTop}>
                <View style={styles.flex}>
                  <AppText variant="footnote" weight="semibold" tone="secondary">
                    {formatWeekday(date).toUpperCase()}
                  </AppText>
                  <AppText variant="title2">{formatDate(date)}</AppText>
                </View>
                {meta && <Pill label={meta.label} color={meta.color} dot />}
              </View>
              <AppText variant="subheadline" tone="secondary">
                {record.scheduled_start
                  ? `Shift ${formatClock(record.scheduled_start)} – ${formatClock(record.scheduled_end)}`
                  : 'No shift scheduled'}
              </AppText>

              <View style={styles.metrics}>
                {metrics.map((metric) => (
                  <View
                    key={metric.label}
                    style={[styles.metric, { backgroundColor: colors.fill }]}
                    accessible
                    accessibilityLabel={`${metric.label}: ${formatMinutes(metric.value)}`}
                  >
                    <AppText variant="caption" tone="secondary">
                      {metric.label}
                    </AppText>
                    <AppText variant="headline" numeric tone={metric.value > 0 ? 'primary' : 'secondary'}>
                      {formatMinutes(metric.value)}
                    </AppText>
                  </View>
                ))}
              </View>
            </Card>
          </Animated.View>

          {/* Punch timeline */}
          <Animated.View entering={enter(1)}>
            <ListSection header="Punches">
              {punches.length === 0 ? (
                <View style={styles.note}>
                  <AppText variant="body" tone="secondary">
                    No punches recorded for this day.
                  </AppText>
                </View>
              ) : (
                <View style={styles.timeline}>
                  {punches.map((punch, index) => (
                    <TimelineItem
                      key={punch.id}
                      punch={punch}
                      time={formatTime(punch.punched_at, organization?.timezone)}
                      last={index === punches.length - 1}
                    />
                  ))}
                </View>
              )}
            </ListSection>
          </Animated.View>

          {selfie && (
            <Animated.View entering={enter(2)}>
              <ListSection header="Verification photo">
                <Image
                  source={{ uri: selfie }}
                  style={styles.photo}
                  contentFit="cover"
                  transition={250}
                  accessibilityLabel="Verification selfie"
                />
              </ListSection>
            </Animated.View>
          )}

          {record.remarks && (
            <Animated.View entering={enter(3)}>
              <ListSection header="Remarks">
                <View style={styles.note}>
                  <AppText variant="body" selectable>
                    {record.remarks}
                  </AppText>
                </View>
              </ListSection>
            </Animated.View>
          )}
        </>
      )}
    </Page>
  );
}

/** One punch on the day's timeline: a node in the punch's colour, joined to the next. */
function TimelineItem({ punch, time, last }: { punch: Punch; time: string; last: boolean }) {
  const { colors } = useTheme();
  const detail = punchDetail(punch);

  return (
    <View style={styles.item} accessible accessibilityLabel={`${PUNCH_TIMELINE_LABEL[punch.type]} at ${time}. ${detail}`}>
      <View style={styles.rail}>
        <ToneWell icon={PUNCH_META[punch.type].icon} color={PUNCH_META[punch.type].color} size={32} />
        {!last && <View style={[styles.connector, { backgroundColor: colors.separator }]} />}
      </View>
      <View style={[styles.itemBody, !last && styles.itemGap]}>
        <View style={styles.itemTitle}>
          <AppText variant="headline" style={styles.flex}>
            {PUNCH_TIMELINE_LABEL[punch.type]}
          </AppText>
          <AppText variant="subheadline" weight="medium" tone="secondary" numeric>
            {time}
          </AppText>
        </View>
        {detail !== '' && (
          <AppText variant="footnote" tone="secondary" numberOfLines={2}>
            {detail}
          </AppText>
        )}
      </View>
    </View>
  );
}

/**
 * Where a punch was and how it arrived (ADR 0040), in a line: "On site at Makati
 * Office", "Off site: 1.8 km from Makati Office", "Sent after being offline".
 */
function punchDetail(punch: Punch): string {
  const meters = (value: number) => (value < 1000 ? `${value} m` : `${(value / 1000).toFixed(1)} km`);

  const place =
    punch.within_geofence === true
      ? `On site at ${punch.location?.name ?? 'a work location'}`
      : punch.within_geofence === false
        ? punch.location && punch.distance_meters != null
          ? `Off site: ${meters(punch.distance_meters)} from ${punch.location.name}`
          : 'Off site: no location shared'
        : punch.location
          ? `At ${punch.location.name}`
          : punch.latitude != null
            ? `${punch.latitude.toFixed(4)}, ${punch.longitude?.toFixed(4)}`
            : null;

  return [punch.note, place, punch.offline ? 'Sent after being offline' : null].filter(Boolean).join(' · ');
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  hero: { gap: 12 },
  heroTop: { flexDirection: 'row', alignItems: 'flex-start', gap: 12 },
  metrics: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 4 },
  metric: { width: '47%', flexGrow: 1, borderRadius: 14, borderCurve: 'continuous', padding: 12, gap: 2 },
  note: { padding: 16 },
  timeline: { paddingHorizontal: 16, paddingVertical: 14 },
  item: { flexDirection: 'row', gap: 12 },
  rail: { alignItems: 'center' },
  connector: { width: 2, flex: 1, borderRadius: 1, marginVertical: 3, minHeight: 14 },
  itemBody: { flex: 1, paddingTop: 5, gap: 2 },
  itemGap: { paddingBottom: 18 },
  itemTitle: { flexDirection: 'row', alignItems: 'baseline', gap: 8 },
  photo: { width: '100%', aspectRatio: 4 / 3 },
});
