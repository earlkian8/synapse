import DateTimePicker, { DateTimePickerAndroid } from '@react-native-community/datetimepicker';
import { useRouter } from 'expo-router';
import { useState } from 'react';
import { Platform, StyleSheet, View } from 'react-native';
import Animated from 'react-native-reanimated';

import { Icon } from '@/components/ui/icon';
import { Input } from '@/components/ui/input';
import { ListRow, ListSection } from '@/components/ui/list';
import { BarTextButton, Page } from '@/components/ui/page';
import { AppText } from '@/components/ui/text';
import { useToast } from '@/components/ui/toast';
import { offboardingApi } from '@/features/offboarding/api';
import { addDays, daysBetween } from '@/features/offboarding/meta';
import { ApiError } from '@/lib/api';
import { formatDate, parseDateOnly } from '@/lib/format';
import { enter } from '@/lib/motion';
import { useQuery } from '@/lib/use-query';
import { useTheme } from '@/theme/theme';
import type { MyExit } from '@/types/api';

const MAX = 2000;

function toIso(date: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/**
 * File one's resignation (ADR 0076), as a form sheet: the last day one plans to
 * work — a month out to start with, the notice the Labor Code asks for — and,
 * if one wants to say, why. A shorter notice is allowed, with a note that
 * agreeing to it is HR's call. Nothing changes until HR accepts it.
 */
export default function NewResignationScreen() {
  const { colors, scheme, status } = useTheme();
  const router = useRouter();
  const toast = useToast();

  // The company's today and its notice period, from the server.
  const { data } = useQuery<{ data: MyExit }>(() => offboardingApi.show(), []);
  const today = data?.data.today ?? toIso(new Date());
  const notice = data?.data.notice_days ?? 30;

  const [lastDay, setLastDay] = useState<string | null>(null);
  const [reason, setReason] = useState('');
  const [sending, setSending] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const chosen = lastDay ?? addDays(today, notice);
  const noticeGiven = daysBetween(today, chosen);
  const short = noticeGiven >= 0 && noticeGiven < notice;

  const openAndroidPicker = () =>
    DateTimePickerAndroid.open({
      value: parseDateOnly(chosen),
      mode: 'date',
      minimumDate: parseDateOnly(today),
      onValueChange: (_event, date) => setLastDay(toIso(date)),
    });

  const send = async () => {
    setSending(true);
    setErrors({});

    try {
      await offboardingApi.resign({ proposed_last_day: chosen, reason: reason.trim() || null });
      toast.show('Resignation sent to HR.', 'success');
      router.back();
    } catch (e) {
      if (e instanceof ApiError && Object.keys(e.fieldErrors).length > 0) {
        setErrors(e.fieldErrors);
      } else {
        toast.show(e instanceof ApiError ? e.message : 'Couldn’t send your resignation. Try again.', 'error');
      }
    } finally {
      setSending(false);
    }
  };

  return (
    <Page
      title="Resignation"
      largeTitle={false}
      modal
      left={<BarTextButton label="Cancel" onPress={() => router.back()} disabled={sending} />}
      right={<BarTextButton label="Send" emphasized onPress={() => void send()} disabled={sending || !data} />}
    >
      <Animated.View entering={enter(0)} style={styles.gap}>
        <ListSection
          header="Your last working day"
          footer={`${notice} days from today is ${formatDate(addDays(today, notice))}. HR agrees the final date with you.`}
        >
          <ListRow
            title="Last day"
            value={Platform.OS === 'ios' ? undefined : formatDate(chosen)}
            accessory={
              Platform.OS === 'ios' ? (
                <DateTimePicker
                  value={parseDateOnly(chosen)}
                  mode="date"
                  display="compact"
                  minimumDate={parseDateOnly(today)}
                  accentColor={colors.tint}
                  themeVariant={scheme}
                  onValueChange={(_event, date) => setLastDay(toIso(date))}
                />
              ) : undefined
            }
            onPress={Platform.OS === 'ios' ? undefined : openAndroidPicker}
            chevron={false}
          />
        </ListSection>
        {!!errors.proposed_last_day && (
          <AppText variant="footnote" tone="danger" style={styles.note}>
            {errors.proposed_last_day}
          </AppText>
        )}
        {short && (
          <View style={[styles.warning, { backgroundColor: colors.card }]}>
            <Icon name="warning" size={16} color={status.late} />
            <AppText variant="footnote" tone="secondary" style={styles.flex}>
              That is {noticeGiven} {noticeGiven === 1 ? 'day' : 'days'}’ notice. The Labor Code asks for {notice}; HR may
              agree to an earlier day, or ask you to stay longer.
            </AppText>
          </View>
        )}
      </Animated.View>

      <Animated.View entering={enter(1)}>
        <Input
          label="Why you are leaving"
          value={reason}
          onChangeText={setReason}
          placeholder="Optional. Only HR sees it."
          multiline
          maxLength={MAX}
          error={errors.reason}
          style={styles.reason}
        />
      </Animated.View>
    </Page>
  );
}

const styles = StyleSheet.create({
  gap: { gap: 8 },
  flex: { flex: 1 },
  note: { paddingHorizontal: 16 },
  warning: { flexDirection: 'row', gap: 10, alignItems: 'flex-start', padding: 12, borderRadius: 12 },
  reason: { minHeight: 120, textAlignVertical: 'top' },
});
