import DateTimePicker, { DateTimePickerAndroid } from '@react-native-community/datetimepicker';
import { useRouter } from 'expo-router';
import { useMemo, useState } from 'react';
import { Platform, StyleSheet, Switch, View } from 'react-native';
import Animated, { FadeIn, FadeOut } from 'react-native-reanimated';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { ErrorState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { ListRow, ListSection } from '@/components/ui/list';
import { BarTextButton, Page } from '@/components/ui/page';
import { Segmented } from '@/components/ui/segmented';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { useToast } from '@/components/ui/toast';
import { leaveApi } from '@/features/leave/api';
import { ApiError } from '@/lib/api';
import { formatDate, parseDateOnly } from '@/lib/format';
import { enter } from '@/lib/motion';
import { useQuery } from '@/lib/use-query';
import { useTheme } from '@/theme/theme';
import type { LeaveBalance, LeaveType } from '@/types/api';

type Options = { types: LeaveType[]; balances: LeaveBalance[] };

function toIso(date: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/** Rough working-day count for the live preview; the server is authoritative
 * (it also excludes holidays). */
function workingDays(start: string, end: string): number {
  const s = parseDateOnly(start);
  const e = parseDateOnly(end);
  if (e < s) return 0;
  let count = 0;
  const cursor = new Date(s);
  while (cursor <= e) {
    const day = cursor.getDay();
    if (day !== 0 && day !== 6) count++;
    cursor.setDate(cursor.getDate() + 1);
  }
  return count;
}

/**
 * File a leave request, as an iOS form sheet: Cancel in the bar, the choices in
 * grouped lists, and the dates set with the platform's own picker (the compact
 * date button inline on iOS, the calendar dialog on Android). The reason field is
 * lifted clear of the keyboard as it opens.
 */
export default function NewLeaveScreen() {
  const { colors, readable, scheme, spacing } = useTheme();
  const router = useRouter();
  const toast = useToast();

  const { data, loading, error, reload } = useQuery<Options>(async () => {
    const [types, balances] = await Promise.all([leaveApi.types(), leaveApi.balances()]);
    return { types: types.data, balances: balances.data };
  }, []);

  const [typeId, setTypeId] = useState<number | null>(null);
  const [start, setStart] = useState(toIso(new Date()));
  const [end, setEnd] = useState(toIso(new Date()));
  const [isHalfDay, setIsHalfDay] = useState(false);
  const [period, setPeriod] = useState<'morning' | 'afternoon'>('morning');
  const [reason, setReason] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const selectedType = data?.types.find((t) => t.id === typeId) ?? null;
  const selectedBalance = data?.balances.find((b) => b.leave_type_id === typeId) ?? null;
  const sameDay = start === end;
  const canHalfDay = sameDay && !!selectedType?.allow_half_day;

  const days = useMemo(() => {
    if (isHalfDay && sameDay) return 0.5;
    return workingDays(start, end);
  }, [isHalfDay, sameDay, start, end]);

  const pickStart = (date: Date) => {
    const iso = toIso(date);
    setStart(iso);
    if (parseDateOnly(end) < date) setEnd(iso);
  };

  const pickEnd = (date: Date) => setEnd(toIso(date));

  /** Android has no inline picker: the row opens the system's calendar dialog. */
  const openAndroidPicker = (which: 'start' | 'end') =>
    DateTimePickerAndroid.open({
      value: parseDateOnly(which === 'start' ? start : end),
      mode: 'date',
      minimumDate: which === 'end' ? parseDateOnly(start) : undefined,
      onValueChange: (_event, date) => (which === 'start' ? pickStart(date) : pickEnd(date)),
    });

  const onSubmit = async () => {
    if (!typeId) {
      setErrors({ leave_type_id: 'Choose a leave type.' });
      return;
    }

    setErrors({});
    setSubmitting(true);

    try {
      const result = await leaveApi.file({
        leave_type_id: typeId,
        start_date: start,
        end_date: end,
        is_half_day: canHalfDay && isHalfDay,
        half_day_period: canHalfDay && isHalfDay ? period : null,
        reason: reason.trim() || null,
      });
      toast.show(result.message, 'success');
      router.back();
    } catch (error) {
      if (error instanceof ApiError) {
        if (Object.keys(error.fieldErrors).length > 0) {
          setErrors(error.fieldErrors);
        } else {
          toast.show(error.message, 'error');
        }
      } else {
        toast.show('Could not file your request.', 'error');
      }
    } finally {
      setSubmitting(false);
    }
  };

  const dateControl = (which: 'start' | 'end') =>
    Platform.OS === 'ios' ? (
      <DateTimePicker
        value={parseDateOnly(which === 'start' ? start : end)}
        mode="date"
        display="compact"
        minimumDate={which === 'end' ? parseDateOnly(start) : undefined}
        accentColor={colors.tint}
        themeVariant={scheme}
        onValueChange={(_event, date) => (which === 'start' ? pickStart(date) : pickEnd(date))}
      />
    ) : undefined;

  return (
    <Page
      title="New Leave Request"
      largeTitle={false}
      modal
      left={<BarTextButton label="Cancel" onPress={() => router.back()} disabled={submitting} />}
    >
      {loading ? (
        <>
          <Skeleton height={180} radius={20} />
          <Skeleton height={100} radius={20} />
          <Skeleton height={104} radius={14} />
        </>
      ) : error && !data ? (
        <ErrorState message={error} onRetry={reload} />
      ) : (
        <>
          {/* Type */}
          <Animated.View entering={enter(0)}>
            <ListSection header="Leave type" leadingWidth={10}>
              {(data?.types ?? []).map((type) => {
                const balance = data?.balances.find((b) => b.leave_type_id === type.id);
                return (
                  <ListRow
                    key={type.id}
                    leading={
                      <View
                        style={[styles.dot, { backgroundColor: readable(type.color ?? colors.tint, colors.card, 3) }]}
                      />
                    }
                    title={type.name}
                    subtitle={[
                      balance ? `${balance.remaining} of ${balance.entitled} days left` : null,
                      type.is_paid ? null : 'Unpaid',
                    ]
                      .filter(Boolean)
                      .join(' · ')}
                    selected={type.id === typeId}
                    onPress={() => {
                      setTypeId(type.id);
                      setErrors((current) => ({ ...current, leave_type_id: '' }));
                      if (!type.allow_half_day) setIsHalfDay(false);
                    }}
                  />
                );
              })}
            </ListSection>
            {!!errors.leave_type_id && (
              <AppText variant="footnote" tone="danger" style={styles.error}>
                {errors.leave_type_id}
              </AppText>
            )}
          </Animated.View>

          {/* Dates */}
          <Animated.View entering={enter(1)} style={{ gap: spacing.md }}>
            <ListSection header="Dates">
              <ListRow
                title="Starts"
                value={Platform.OS === 'ios' ? undefined : formatDate(start)}
                accessory={dateControl('start')}
                onPress={Platform.OS === 'ios' ? undefined : () => openAndroidPicker('start')}
                chevron={false}
              />
              <ListRow
                title="Ends"
                value={Platform.OS === 'ios' ? undefined : formatDate(end)}
                accessory={dateControl('end')}
                onPress={Platform.OS === 'ios' ? undefined : () => openAndroidPicker('end')}
                chevron={false}
              />
              {canHalfDay ? (
                <ListRow
                  title="Half day"
                  subtitle="File only half of this day"
                  accessory={
                    <Switch
                      value={isHalfDay}
                      onValueChange={setIsHalfDay}
                      trackColor={{ true: colors.tint, false: colors.fillStrong }}
                      ios_backgroundColor={colors.fillStrong}
                      accessibilityLabel="Half day"
                    />
                  }
                />
              ) : null}
            </ListSection>

            {canHalfDay && isHalfDay && (
              <Animated.View entering={FadeIn.duration(200)} exiting={FadeOut.duration(150)}>
                <Segmented
                  options={[
                    { value: 'morning', label: 'Morning' },
                    { value: 'afternoon', label: 'Afternoon' },
                  ]}
                  value={period}
                  onChange={setPeriod}
                  accessibilityLabel="Which half"
                />
              </Animated.View>
            )}

            {!!(errors.start_date || errors.end_date) && (
              <AppText variant="footnote" tone="danger" style={styles.error}>
                {errors.start_date ?? errors.end_date}
              </AppText>
            )}
          </Animated.View>

          {/* Reason */}
          <Animated.View entering={enter(2)}>
            <Input
              label="Reason"
              placeholder="A short note for your approver"
              hint="Optional"
              value={reason}
              onChangeText={setReason}
              multiline
              maxLength={1000}
              error={errors.reason}
              editable={!submitting}
            />
          </Animated.View>

          {/* Summary */}
          <Animated.View entering={enter(3)}>
            <Card style={styles.summary}>
              <View style={styles.summaryRow}>
                <View style={styles.flex}>
                  <AppText variant="subheadline" tone="secondary">
                    Working days
                  </AppText>
                  <AppText variant="caption" tone="secondary">
                    Holidays are taken off when you submit.
                  </AppText>
                </View>
                <AppText variant="title1" numeric>
                  {days}
                </AppText>
              </View>
              {selectedBalance && (
                <>
                  <View style={[styles.hairline, { backgroundColor: colors.separator }]} />
                  <View style={styles.summaryRow}>
                    <AppText variant="subheadline" tone="secondary" style={styles.flex}>
                      Balance after approval
                    </AppText>
                    <AppText variant="headline" numeric>
                      {Math.max(0, selectedBalance.remaining - days)} of {selectedBalance.entitled}
                    </AppText>
                  </View>
                </>
              )}
            </Card>
          </Animated.View>

          <Button label="Submit request" onPress={onSubmit} loading={submitting} size="lg" disabled={!typeId} />
        </>
      )}
    </Page>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  dot: { width: 10, height: 10, borderRadius: 5 },
  error: { marginTop: 7, marginLeft: 16 },
  summary: { gap: 14 },
  summaryRow: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  hairline: { height: StyleSheet.hairlineWidth },
});
