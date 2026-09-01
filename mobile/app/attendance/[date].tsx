import { Ionicons } from '@expo/vector-icons';
import { Image } from 'expo-image';
import { useLocalSearchParams } from 'expo-router';
import { ScrollView, View } from 'react-native';

import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Pill } from '@/components/ui/pill';
import { Screen, ScreenHeader } from '@/components/ui/screen';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { attendanceApi } from '@/features/attendance/api';
import { PUNCH_META, PUNCH_TIMELINE_LABEL } from '@/features/attendance/punch-meta';
import { formatClock, formatDate, formatMinutes, formatTime } from '@/lib/format';
import { attendanceMeta } from '@/lib/status';
import { useQuery } from '@/lib/use-query';
import { useTheme } from '@/theme/theme';
import type { Paginated, AttendanceRecord } from '@/types/api';

export default function AttendanceDayScreen() {
  const { colors, spacing } = useTheme();
  const { date } = useLocalSearchParams<{ date: string }>();

  const { data, loading } = useQuery<Paginated<AttendanceRecord>>(
    () => attendanceApi.records(date, date),
    [date],
  );

  const record = data?.data?.[0] ?? null;
  const meta = record ? attendanceMeta(record.status) : null;
  const punches = record?.punches ?? [];
  const selfie = punches.find((p) => p.photo)?.photo ?? null;

  return (
    <Screen edges={['top', 'bottom']}>
      <ScreenHeader title={formatDate(date)} subtitle="Daily time record" back />

      <ScrollView contentContainerStyle={{ padding: spacing.lg, gap: spacing.lg }} showsVerticalScrollIndicator={false}>
        {loading ? (
          <Skeleton height={120} radius={18} />
        ) : !record ? (
          <EmptyState icon="document-outline" title="No record" message="Nothing was recorded for this day." />
        ) : (
          <>
            <Card elevated>
              <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}>
                {meta && <Pill label={meta.label} color={meta.color} dot />}
                <AppText variant="caption" muted>
                  {record.scheduled_start ? `Shift ${formatClock(record.scheduled_start)}–${formatClock(record.scheduled_end)}` : 'No shift'}
                </AppText>
              </View>

              <View style={{ flexDirection: 'row', justifyContent: 'space-between', marginTop: spacing.lg }}>
                <Metric label="Worked" value={formatMinutes(record.worked_minutes)} />
                <Metric label="Late" value={formatMinutes(record.late_minutes)} />
                <Metric label="Undertime" value={formatMinutes(record.undertime_minutes)} />
                <Metric label="Overtime" value={formatMinutes(record.overtime_minutes)} />
              </View>
            </Card>

            {/* Punch timeline */}
            <View style={{ gap: spacing.sm }}>
              <AppText variant="overline" muted>
                Punches
              </AppText>
              {punches.length === 0 ? (
                <Card>
                  <AppText variant="body" muted>
                    No punches recorded for this day.
                  </AppText>
                </Card>
              ) : (
                <Card>
                  {punches.map((punch, index) => (
                    <View
                      key={punch.id}
                      style={{
                        flexDirection: 'row',
                        alignItems: 'center',
                        gap: spacing.md,
                        paddingVertical: 10,
                        borderTopWidth: index === 0 ? 0 : 1,
                        borderTopColor: colors.hairline,
                      }}
                    >
                      <View
                        style={{
                          width: 36,
                          height: 36,
                          borderRadius: 18,
                          backgroundColor: colors.accentSoft,
                          alignItems: 'center',
                          justifyContent: 'center',
                        }}
                      >
                        <Ionicons name={PUNCH_META[punch.type].icon} size={18} color={colors.accent} />
                      </View>
                      <View style={{ flex: 1 }}>
                        <AppText variant="label">{PUNCH_TIMELINE_LABEL[punch.type]}</AppText>
                        {(punch.latitude != null || punch.note) && (
                          <AppText variant="caption" faint numberOfLines={1}>
                            {punch.note ?? `${punch.latitude?.toFixed(4)}, ${punch.longitude?.toFixed(4)}`}
                          </AppText>
                        )}
                      </View>
                      <AppText variant="label" muted style={{ fontVariant: ['tabular-nums'] }}>
                        {formatTime(punch.punched_at)}
                      </AppText>
                    </View>
                  ))}
                </Card>
              )}
            </View>

            {selfie && (
              <View style={{ gap: spacing.sm }}>
                <AppText variant="overline" muted>
                  Verification photo
                </AppText>
                <Image source={{ uri: selfie }} style={{ width: '100%', height: 220, borderRadius: 18 }} contentFit="cover" />
              </View>
            )}

            {record.remarks && (
              <Card>
                <AppText variant="overline" muted>
                  Remarks
                </AppText>
                <AppText variant="body" style={{ marginTop: 4 }}>
                  {record.remarks}
                </AppText>
              </Card>
            )}
          </>
        )}
      </ScrollView>
    </Screen>
  );
}

function Metric({ label, value }: { label: string; value: string }) {
  return (
    <View style={{ alignItems: 'center', flex: 1 }}>
      <AppText variant="label">{value}</AppText>
      <AppText variant="caption" faint style={{ textAlign: 'center' }}>
        {label}
      </AppText>
    </View>
  );
}
