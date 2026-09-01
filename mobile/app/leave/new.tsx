import { Ionicons } from '@expo/vector-icons';
import DateTimePicker from '@react-native-community/datetimepicker';
import { useRouter } from 'expo-router';
import { useMemo, useState } from 'react';
import { Platform, Pressable, ScrollView, Switch, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Screen, ScreenHeader } from '@/components/ui/screen';
import { Segmented } from '@/components/ui/segmented';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { useToast } from '@/components/ui/toast';
import { leaveApi } from '@/features/leave/api';
import { ApiError } from '@/lib/api';
import { formatDate, parseDateOnly } from '@/lib/format';
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

export default function NewLeaveScreen() {
  const { colors, spacing } = useTheme();
  const router = useRouter();
  const toast = useToast();

  const { data, loading } = useQuery<Options>(async () => {
    const [types, balances] = await Promise.all([leaveApi.types(), leaveApi.balances()]);
    return { types: types.data, balances: balances.data };
  }, []);

  const [typeId, setTypeId] = useState<number | null>(null);
  const [start, setStart] = useState(toIso(new Date()));
  const [end, setEnd] = useState(toIso(new Date()));
  const [isHalfDay, setIsHalfDay] = useState(false);
  const [period, setPeriod] = useState<'morning' | 'afternoon'>('morning');
  const [reason, setReason] = useState('');
  const [showPicker, setShowPicker] = useState<'start' | 'end' | null>(null);
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

  return (
    <Screen edges={['top', 'bottom']}>
      <ScreenHeader title="File Leave" subtitle="Request time off" back />

      <ScrollView
        contentContainerStyle={{ padding: spacing.lg, paddingBottom: 40, gap: spacing.lg }}
        showsVerticalScrollIndicator={false}
        keyboardShouldPersistTaps="handled"
      >
        {loading ? (
          <Skeleton height={300} radius={18} />
        ) : (
          <>
            {/* Type */}
            <View style={{ gap: spacing.sm }}>
              <AppText variant="overline" muted>
                Leave Type
              </AppText>
              <View style={{ gap: spacing.sm }}>
                {(data?.types ?? []).map((type) => {
                  const active = type.id === typeId;
                  const balance = data?.balances.find((b) => b.leave_type_id === type.id);
                  return (
                    <Pressable
                      key={type.id}
                      onPress={() => {
                        setTypeId(type.id);
                        if (!type.allow_half_day) setIsHalfDay(false);
                      }}
                    >
                      <Card
                        style={{
                          flexDirection: 'row',
                          alignItems: 'center',
                          gap: spacing.md,
                          borderColor: active ? colors.accent : colors.border,
                          borderWidth: active ? 2 : 1,
                        }}
                      >
                        <View style={{ width: 10, height: 10, borderRadius: 5, backgroundColor: type.color ?? colors.accent }} />
                        <View style={{ flex: 1 }}>
                          <AppText variant="label">{type.name}</AppText>
                          {balance && (
                            <AppText variant="caption" faint>
                              {balance.remaining} of {balance.entitled} days left
                            </AppText>
                          )}
                        </View>
                        {active && <Ionicons name="checkmark-circle" size={22} color={colors.accent} />}
                      </Card>
                    </Pressable>
                  );
                })}
              </View>
              {errors.leave_type_id && (
                <AppText variant="caption" style={{ color: '#F43F5E' }}>
                  {errors.leave_type_id}
                </AppText>
              )}
            </View>

            {/* Dates */}
            <View style={{ gap: spacing.sm }}>
              <AppText variant="overline" muted>
                Dates
              </AppText>
              <View style={{ flexDirection: 'row', gap: spacing.md }}>
                <DateField label="From" value={start} onPress={() => setShowPicker('start')} />
                <DateField label="To" value={end} onPress={() => setShowPicker('end')} />
              </View>
              {(errors.start_date || errors.end_date) && (
                <AppText variant="caption" style={{ color: '#F43F5E' }}>
                  {errors.start_date ?? errors.end_date}
                </AppText>
              )}
            </View>

            {/* Half day */}
            {canHalfDay && (
              <Card>
                <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }}>
                  <View style={{ flex: 1 }}>
                    <AppText variant="label">Half day</AppText>
                    <AppText variant="caption" faint>
                      File only half of this day
                    </AppText>
                  </View>
                  <Switch
                    value={isHalfDay}
                    onValueChange={setIsHalfDay}
                    trackColor={{ true: colors.accent, false: colors.border }}
                  />
                </View>
                {isHalfDay && (
                  <View style={{ marginTop: spacing.md }}>
                    <Segmented
                      options={[
                        { value: 'morning', label: 'Morning' },
                        { value: 'afternoon', label: 'Afternoon' },
                      ]}
                      value={period}
                      onChange={setPeriod}
                    />
                  </View>
                )}
              </Card>
            )}

            {/* Reason */}
            <Input
              label="Reason (optional)"
              placeholder="Add a short note for your approver"
              value={reason}
              onChangeText={setReason}
              multiline
              numberOfLines={3}
              style={{ minHeight: 88, textAlignVertical: 'top' }}
              error={errors.reason}
            />

            {/* Summary */}
            <Card style={{ backgroundColor: colors.accentSoft, borderColor: colors.accent, gap: 6 }}>
              <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                <AppText variant="label" muted>
                  Working days
                </AppText>
                <AppText variant="label" style={{ color: colors.accent }}>
                  {days} {days === 1 ? 'day' : 'days'}
                </AppText>
              </View>
              {selectedBalance && (
                <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                  <AppText variant="caption" muted>
                    Balance after approval
                  </AppText>
                  <AppText variant="caption" muted>
                    {Math.max(0, selectedBalance.remaining - days)} of {selectedBalance.entitled}
                  </AppText>
                </View>
              )}
            </Card>

            <Button label="Submit request" onPress={onSubmit} loading={submitting} size="lg" disabled={!typeId} />
          </>
        )}
      </ScrollView>

      {showPicker && (
        <DateTimePicker
          value={parseDateOnly(showPicker === 'start' ? start : end)}
          mode="date"
          display={Platform.OS === 'ios' ? 'inline' : 'default'}
          onChange={(event, date) => {
            setShowPicker(null);
            if (event.type === 'dismissed' || !date) return;
            const iso = toIso(date);
            if (showPicker === 'start') {
              setStart(iso);
              if (parseDateOnly(end) < date) setEnd(iso);
            } else {
              setEnd(iso);
            }
          }}
        />
      )}
    </Screen>
  );
}

function DateField({ label, value, onPress }: { label: string; value: string; onPress: () => void }) {
  const { colors, radius, spacing } = useTheme();
  return (
    <Pressable onPress={onPress} style={{ flex: 1, gap: 6 }}>
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
        <Ionicons name="calendar-outline" size={18} color={colors.accent} />
        <AppText variant="label">{formatDate(value)}</AppText>
      </View>
    </Pressable>
  );
}
