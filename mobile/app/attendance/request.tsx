import DateTimePicker from '@react-native-community/datetimepicker';
import { Ionicons } from '@expo/vector-icons';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { useState } from 'react';
import { Platform, Pressable, ScrollView, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Screen, ScreenHeader } from '@/components/ui/screen';
import { AppText } from '@/components/ui/text';
import { useToast } from '@/components/ui/toast';
import { attendanceApi, type FileRequestPayload } from '@/features/attendance/api';
import { REQUEST_TYPES } from '@/features/attendance/requests';
import { ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatClock, formatDate, parseDateOnly, todayDateKey } from '@/lib/format';
import { useTheme } from '@/theme/theme';
import type { AttendanceRequestType } from '@/types/api';

type Picker = { field: 'start' | 'end' } | { field: 'time'; key: TimeKey } | null;
type TimeKey = 'time_in' | 'break_start' | 'break_end' | 'time_out' | 'start_time' | 'end_time';

const CORRECTION: { key: TimeKey; label: string }[] = [
  { key: 'time_in', label: 'Time in' },
  { key: 'break_start', label: 'Break start' },
  { key: 'break_end', label: 'Break end' },
  { key: 'time_out', label: 'Time out' },
];

function toIso(date: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function toClock(date: Date): string {
  return `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
}

/**
 * Asking attendance for something the clock could not capture (ADR 0039) — the
 * same four kinds the ERP offers, decided there. Opened from a day ("Request a
 * correction", with the date set) or from My requests.
 */
export default function FileAttendanceRequestScreen() {
  const { colors, spacing, radius } = useTheme();
  const router = useRouter();
  const toast = useToast();
  const { organization } = useAuth();
  const params = useLocalSearchParams<{ type?: AttendanceRequestType; date?: string }>();
  const today = todayDateKey(organization?.timezone);

  const [type, setType] = useState<AttendanceRequestType>(params.type ?? 'correction');
  const [start, setStart] = useState(params.date ?? today);
  const [end, setEnd] = useState(params.date ?? today);
  const [times, setTimes] = useState<Partial<Record<TimeKey, string>>>({});
  const [hours, setHours] = useState('');
  const [minutes, setMinutes] = useState('');
  const [location, setLocation] = useState('');
  const [reason, setReason] = useState('');
  const [picker, setPicker] = useState<Picker>(null);
  const [submitting, setSubmitting] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const isRange = type === 'official_business' || type === 'remote_work';
  const asked = Number(hours || 0) * 60 + Number(minutes || 0);

  const onSubmit = async () => {
    const payload: FileRequestPayload = { type, start_date: start, reason: reason.trim() };

    if (isRange) {
      payload.end_date = end;
      if (times.start_time) payload.start_time = times.start_time;
      if (times.end_time) payload.end_time = times.end_time;
      if (location.trim()) payload.location = location.trim();
    }

    if (type === 'correction') {
      for (const { key } of CORRECTION) {
        if (times[key]) payload[key as 'time_in'] = times[key];
      }
    }

    if (type === 'overtime') payload.minutes = asked;

    setErrors({});
    setSubmitting(true);

    try {
      const result = await attendanceApi.fileRequest(payload);
      toast.show(result.message, 'success');
      router.back();
    } catch (error) {
      if (error instanceof ApiError && Object.keys(error.fieldErrors).length > 0) {
        setErrors(error.fieldErrors);
      } else {
        toast.show(error instanceof ApiError ? error.message : 'Could not send your request.', 'error');
      }
    } finally {
      setSubmitting(false);
    }
  };

  const setTime = (key: TimeKey, value: string) => setTimes((prev) => ({ ...prev, [key]: value }));

  return (
    <Screen edges={['top', 'bottom']}>
      <ScreenHeader title="Ask for a change" subtitle="Decided by a reviewer" back />

      <ScrollView
        contentContainerStyle={{ padding: spacing.lg, paddingBottom: 40, gap: spacing.lg }}
        showsVerticalScrollIndicator={false}
        keyboardShouldPersistTaps="handled"
      >
        <View style={{ gap: spacing.sm }}>
          <AppText variant="overline" muted>
            What do you need?
          </AppText>
          {REQUEST_TYPES.map((option) => {
            const active = option.value === type;
            return (
              <Pressable key={option.value} accessibilityRole="radio" accessibilityState={{ selected: active }} onPress={() => setType(option.value)}>
                <Card
                  style={{
                    flexDirection: 'row',
                    alignItems: 'center',
                    gap: spacing.md,
                    borderColor: active ? colors.accent : colors.border,
                    borderWidth: active ? 2 : 1,
                  }}
                >
                  <View style={{ flex: 1 }}>
                    <AppText variant="label">{option.label}</AppText>
                    <AppText variant="caption" faint>
                      {option.hint}
                    </AppText>
                  </View>
                  {active && <Ionicons name="checkmark-circle" size={22} color={colors.accentText} />}
                </Card>
              </Pressable>
            );
          })}
        </View>

        <View style={{ gap: spacing.sm }}>
          <AppText variant="overline" muted>
            {isRange ? 'Days' : 'Day'}
          </AppText>
          <View style={{ flexDirection: 'row', gap: spacing.md }}>
            <Field label={isRange ? 'From' : 'Day'} value={formatDate(start)} icon="calendar-outline" onPress={() => setPicker({ field: 'start' })} />
            {isRange && <Field label="To" value={formatDate(end)} icon="calendar-outline" onPress={() => setPicker({ field: 'end' })} />}
          </View>
          {(errors.start_date || errors.end_date) && (
            <AppText variant="caption" style={{ color: colors.danger }}>
              {errors.start_date ?? errors.end_date}
            </AppText>
          )}
        </View>

        {type === 'correction' && (
          <View style={{ gap: spacing.sm }}>
            <AppText variant="overline" muted>
              The times the day should show
            </AppText>
            <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: spacing.md }}>
              {CORRECTION.map(({ key, label }) => (
                <View key={key} style={{ width: '47%' }}>
                  <Field
                    label={label}
                    value={times[key] ? formatClock(times[key]) : 'Keep'}
                    icon="time-outline"
                    onPress={() => setPicker({ field: 'time', key })}
                  />
                </View>
              ))}
            </View>
            <AppText variant="caption" faint>
              Set only what should change. The rest keeps what the day already has.
            </AppText>
            {errors.time_in && (
              <AppText variant="caption" style={{ color: colors.danger }}>
                {errors.time_in}
              </AppText>
            )}
          </View>
        )}

        {type === 'overtime' && (
          <View style={{ gap: spacing.sm }}>
            <AppText variant="overline" muted>
              How much overtime
            </AppText>
            <View style={{ flexDirection: 'row', gap: spacing.md }}>
              <View style={{ flex: 1 }}>
                <Input label="Hours" keyboardType="number-pad" value={hours} onChangeText={setHours} placeholder="0" />
              </View>
              <View style={{ flex: 1 }}>
                <Input label="Minutes" keyboardType="number-pad" value={minutes} onChangeText={setMinutes} placeholder="0" />
              </View>
            </View>
            <AppText variant="caption" faint>
              {start > today ? 'Asked in advance: approved, it counts once the day is worked.' : 'Approved overtime is never more than you worked past your shift.'}
            </AppText>
            {errors.minutes && (
              <AppText variant="caption" style={{ color: colors.danger }}>
                {errors.minutes}
              </AppText>
            )}
          </View>
        )}

        {isRange && (
          <View style={{ gap: spacing.sm }}>
            <View style={{ flexDirection: 'row', gap: spacing.md }}>
              <Field label="From (optional)" value={times.start_time ? formatClock(times.start_time) : '—'} icon="time-outline" onPress={() => setPicker({ field: 'time', key: 'start_time' })} />
              <Field label="Until (optional)" value={times.end_time ? formatClock(times.end_time) : '—'} icon="time-outline" onPress={() => setPicker({ field: 'time', key: 'end_time' })} />
            </View>
            <Input label="Where (optional)" value={location} onChangeText={setLocation} placeholder={type === 'official_business' ? 'Client office, Makati' : 'Home'} error={errors.location} />
          </View>
        )}

        <Input
          label="Why"
          placeholder="What the reviewer needs to know"
          value={reason}
          onChangeText={setReason}
          multiline
          numberOfLines={3}
          style={{ minHeight: 88, textAlignVertical: 'top' }}
          error={errors.reason}
        />

        <Card style={{ backgroundColor: colors.cardAlt, borderRadius: radius.md }}>
          <AppText variant="caption" muted>
            A reviewer decides this in the ERP. You are told either way, and you can cancel it while it waits.
          </AppText>
        </Card>

        <Button
          label="Send for review"
          onPress={onSubmit}
          loading={submitting}
          size="lg"
          disabled={reason.trim().length < 3 || (type === 'overtime' && asked <= 0) || (type === 'correction' && !CORRECTION.some(({ key }) => times[key]))}
        />
      </ScrollView>

      {picker && (
        <DateTimePicker
          value={
            picker.field === 'time'
              ? new Date(`2000-01-01T${times[picker.key] ?? '08:00'}:00`)
              : parseDateOnly(picker.field === 'start' ? start : end)
          }
          mode={picker.field === 'time' ? 'time' : 'date'}
          display={Platform.OS === 'ios' ? (picker.field === 'time' ? 'spinner' : 'inline') : 'default'}
          maximumDate={picker.field !== 'time' && type === 'correction' ? parseDateOnly(today) : undefined}
          onChange={(event, date) => {
            const current = picker;
            setPicker(null);
            if (event.type === 'dismissed' || !date) return;

            if (current.field === 'time') {
              setTime(current.key, toClock(date));
              return;
            }

            const iso = toIso(date);
            if (current.field === 'start') {
              setStart(iso);
              if (end < iso || !isRange) setEnd(iso);
            } else {
              setEnd(iso);
            }
          }}
        />
      )}
    </Screen>
  );
}

function Field({ label, value, icon, onPress }: { label: string; value: string; icon: 'calendar-outline' | 'time-outline'; onPress: () => void }) {
  const { colors, radius, spacing } = useTheme();
  return (
    <Pressable onPress={onPress} style={{ flex: 1, gap: 6 }} accessibilityRole="button" accessibilityLabel={`${label}: ${value}`}>
      <AppText variant="label" muted>
        {label}
      </AppText>
      <View
        style={{
          flexDirection: 'row',
          alignItems: 'center',
          gap: spacing.sm,
          backgroundColor: colors.card,
          borderWidth: 1.5,
          borderColor: colors.border,
          borderRadius: radius.md,
          paddingHorizontal: spacing.md,
          paddingVertical: 13,
        }}
      >
        <Ionicons name={icon} size={18} color={colors.accentText} />
        <AppText variant="label">{value}</AppText>
      </View>
    </Pressable>
  );
}
