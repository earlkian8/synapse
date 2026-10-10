import { useRouter } from 'expo-router';
import { useState } from 'react';
import { StyleSheet, View } from 'react-native';
import Animated from 'react-native-reanimated';

import { ErrorState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { ListRow, ListSection } from '@/components/ui/list';
import { BarTextButton, Page } from '@/components/ui/page';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { useToast } from '@/components/ui/toast';
import { recognitionApi } from '@/features/recognition/api';
import { ColleagueSearch } from '@/features/recognition/colleague-search';
import { ApiError } from '@/lib/api';
import { enter } from '@/lib/motion';
import { useQuery } from '@/lib/use-query';
import { useTheme } from '@/theme/theme';
import type { Colleague } from '@/types/api';

const MIN = 20;

/**
 * Nominate a colleague for an award (ADR 0071). HR reviews it; if approved, the
 * reason becomes the award's citation.
 */
export default function NominateScreen() {
  const { colors, readable } = useTheme();
  const router = useRouter();
  const toast = useToast();
  const { data: types, loading, error, reload } = useQuery(() => recognitionApi.nominationTypes(), []);

  const [nominee, setNominee] = useState<Colleague | null>(null);
  const [typeId, setTypeId] = useState<number | null>(null);
  const [reason, setReason] = useState('');
  const [sending, setSending] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const chosenType = typeId ?? types?.data[0]?.id ?? null;
  const short = Math.max(0, MIN - reason.trim().length);

  const send = async () => {
    if (!nominee || !chosenType) {
      setErrors({ employee_id: nominee ? '' : 'Choose who you’re nominating.' });
      return;
    }

    setSending(true);
    setErrors({});

    try {
      await recognitionApi.nominate(nominee.id, chosenType, reason.trim());
      toast.show('Nomination sent — HR will review it.', 'success');
      router.back();
    } catch (e) {
      if (e instanceof ApiError && Object.keys(e.fieldErrors).length > 0) {
        setErrors(e.fieldErrors);
      } else {
        toast.show(e instanceof ApiError ? e.message : 'Couldn’t send the nomination. Try again.', 'error');
      }
    } finally {
      setSending(false);
    }
  };

  return (
    <Page
      title="Nominate"
      largeTitle={false}
      modal
      left={<BarTextButton label="Cancel" onPress={() => router.back()} disabled={sending} />}
      right={<BarTextButton label="Send" emphasized onPress={() => void send()} disabled={sending || !nominee || short > 0} />}
    >
      {loading ? (
        <Skeleton height={160} radius={14} />
      ) : error && !types ? (
        <ErrorState message={error} onRetry={reload} />
      ) : types && types.data.length === 0 ? (
        <AppText variant="body" tone="secondary" center>
          No award is open to nominations right now.
        </AppText>
      ) : (
        <>
          <Animated.View entering={enter(0)}>
            <ColleagueSearch selected={nominee} onSelect={setNominee} error={errors.employee_id || undefined} />
          </Animated.View>

          <Animated.View entering={enter(1)}>
            <ListSection header="For" leadingWidth={10}>
              {(types?.data ?? []).map((type) => (
                <ListRow
                  key={type.id}
                  leading={<View style={[styles.dot, { backgroundColor: readable(type.color ?? colors.tint, colors.card, 3) }]} />}
                  title={type.name}
                  subtitle={[type.points > 0 ? `${type.points} points` : null, type.description].filter(Boolean).join(' · ') || undefined}
                  selected={type.id === chosenType}
                  onPress={() => setTypeId(type.id)}
                />
              ))}
            </ListSection>
            {!!errors.award_type_id && (
              <AppText variant="footnote" tone="danger" style={styles.pad}>
                {errors.award_type_id}
              </AppText>
            )}
          </Animated.View>

          <Animated.View entering={enter(2)}>
            <Input
              label="Why"
              value={reason}
              onChangeText={setReason}
              placeholder="What did they do, and what difference did it make?"
              multiline
              maxLength={1000}
              error={errors.reason}
              hint={short > 0 ? `${short} more characters` : `${reason.length}/1000`}
              style={styles.reason}
            />
          </Animated.View>
        </>
      )}
    </Page>
  );
}

const styles = StyleSheet.create({
  dot: { width: 10, height: 10, borderRadius: 5 },
  pad: { paddingHorizontal: 4, marginTop: 6 },
  reason: { minHeight: 120, textAlignVertical: 'top' },
});
