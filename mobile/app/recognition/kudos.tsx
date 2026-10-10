import { useRouter } from 'expo-router';
import { useState } from 'react';
import { StyleSheet } from 'react-native';
import Animated from 'react-native-reanimated';

import { Input } from '@/components/ui/input';
import { BarTextButton, Page } from '@/components/ui/page';
import { AppText } from '@/components/ui/text';
import { useToast } from '@/components/ui/toast';
import { recognitionApi } from '@/features/recognition/api';
import { ColleagueSearch } from '@/features/recognition/colleague-search';
import { ApiError } from '@/lib/api';
import { enter } from '@/lib/motion';
import { useQuery } from '@/lib/use-query';
import type { Colleague } from '@/types/api';

const MAX = 500;

/**
 * Send kudos (ADR 0071), as a form sheet: who, and what they did. Says up front
 * whether these kudos still carry points this month.
 */
export default function KudosScreen() {
  const router = useRouter();
  const toast = useToast();
  const [to, setTo] = useState<Colleague | null>(null);
  const [message, setMessage] = useState('');
  const [sending, setSending] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const { data } = useQuery(() => recognitionApi.points(), []);
  const carries = data ? data.kudos_points > 0 && data.kudos_left > 0 : false;

  const send = async () => {
    if (!to) {
      setErrors({ to_employee_id: 'Choose who you’re thanking.' });
      return;
    }

    setSending(true);
    setErrors({});

    try {
      const result = await recognitionApi.sendKudos(to.id, message.trim());
      const first = to.name.split(' ')[0];
      toast.show(result.data.points > 0 ? `Kudos sent to ${first} — +${result.data.points} points.` : `Kudos sent to ${first}.`, 'success');
      router.back();
    } catch (e) {
      if (e instanceof ApiError && Object.keys(e.fieldErrors).length > 0) {
        setErrors(e.fieldErrors);
      } else {
        toast.show(e instanceof ApiError ? e.message : 'Couldn’t send your kudos. Try again.', 'error');
      }
    } finally {
      setSending(false);
    }
  };

  return (
    <Page
      title="Send Kudos"
      largeTitle={false}
      modal
      left={<BarTextButton label="Cancel" onPress={() => router.back()} disabled={sending} />}
      right={<BarTextButton label="Send" emphasized onPress={() => void send()} disabled={sending || !to || message.trim() === ''} />}
    >
      <Animated.View entering={enter(0)}>
        <ColleagueSearch selected={to} onSelect={setTo} error={errors.to_employee_id} />
      </Animated.View>

      <Animated.View entering={enter(1)} style={styles.gap}>
        <Input
          label="What did they do?"
          value={message}
          onChangeText={setMessage}
          placeholder="Be specific — it’s what they’ll remember."
          multiline
          maxLength={MAX}
          error={errors.message}
          hint={`${message.length}/${MAX}`}
          style={styles.message}
        />
        {data && (
          <AppText variant="footnote" tone="secondary" style={styles.note}>
            {carries
              ? `Gives them ${data.kudos_points} points. You have ${data.kudos_left} kudos with points left this month.`
              : 'Sent without points — your kudos with points for this month are used up.'}
          </AppText>
        )}
      </Animated.View>
    </Page>
  );
}

const styles = StyleSheet.create({
  gap: { gap: 8 },
  message: { minHeight: 110, textAlignVertical: 'top' },
  note: { paddingHorizontal: 4 },
});
