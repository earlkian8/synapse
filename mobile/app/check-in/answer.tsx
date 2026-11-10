import { useLocalSearchParams, useRouter } from 'expo-router';
import { useState } from 'react';
import { StyleSheet, View } from 'react-native';
import Animated, { FadeIn } from 'react-native-reanimated';

import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/empty-state';
import { Icon } from '@/components/ui/icon';
import { Input } from '@/components/ui/input';
import { ListRow, ListSection } from '@/components/ui/list';
import { BarTextButton, Page } from '@/components/ui/page';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { useToast } from '@/components/ui/toast';
import { Touchable } from '@/components/ui/touchable';
import { checkInsApi } from '@/features/check-ins/api';
import { MoodFace } from '@/features/check-ins/components/mood-face';
import { answerLabel, MOOD_WORDS } from '@/features/check-ins/meta';
import { ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useQuery } from '@/lib/use-query';
import { useTheme } from '@/theme/theme';
import type { CheckInAnswers, CheckInQuestion } from '@/types/api';

/** The most a private note may hold (CheckInQuestions::NOTE_MAX). */
const NOTE_MAX = 500;

/**
 * This month's check-in (ADR 0102), one question to a page. The face above the mood
 * question wears the answer; nothing else moves it. Choosing moves on by itself; the
 * last page reads every answer back, with a note only the person can read, before
 * anything is saved. `?edit=1` opens on the read-back with this month's answers.
 */
export default function AnswerCheckInScreen() {
  const params = useLocalSearchParams<{ edit?: string; mood?: string }>();
  const router = useRouter();
  const toast = useToast();
  const { colors, spacing } = useTheme();
  const { refresh } = useAuth();

  const { data, loading, error, reload } = useQuery(() => checkInsApi.overview(), []);
  const overview = data?.data ?? null;
  const questions = overview?.questions ?? [];

  const editing = params.edit === '1' && !!overview?.current;
  // Started from a face on Home: that mood is chosen, and the next question waits.
  const preset = Number(params.mood);
  const fromHome = !editing && preset >= 1 && preset <= 5;
  const [answers, setAnswers] = useState<Partial<CheckInAnswers> | null>(null);
  const [note, setNote] = useState<string | null>(null);
  const [step, setStep] = useState<number | null>(null);
  const [returning, setReturning] = useState(false);
  const [saving, setSaving] = useState(false);

  // Seeded from this month's answers once they arrive, when changing them.
  const current: Partial<CheckInAnswers> = answers ?? (editing ? overview!.current!.answers : fromHome ? { mood: preset } : {});
  const currentNote = note ?? (editing ? (overview?.current?.note ?? '') : '');
  const at = step ?? (editing ? questions.length : fromHome ? 1 : 0);
  const question: CheckInQuestion | null = at < questions.length ? questions[at] : null;

  const choose = (key: CheckInQuestion['key'], value: number) => {
    setAnswers({ ...current, [key]: value });
    const next = returning ? questions.length : at + 1;
    setReturning(false);
    setTimeout(() => setStep(next), 220);
  };

  const save = async () => {
    setSaving(true);
    try {
      const response = await checkInsApi.save(current as CheckInAnswers, currentNote.trim() || null);
      toast.show(response.message, 'success');
      void refresh();
      router.back();
    } catch (e) {
      toast.show(e instanceof ApiError ? e.message : 'Couldn’t save your check-in. Try again.', 'error');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Page
      title={overview ? overview.period_label : 'Check-in'}
      largeTitle={false}
      modal
      left={<BarTextButton label="Cancel" onPress={() => router.back()} />}
      gap={20}
    >
      {loading ? (
        <>
          <Skeleton height={8} radius={4} />
          <Skeleton height={320} radius={20} />
        </>
      ) : error && !overview ? (
        <ErrorState message={error} onRetry={reload} />
      ) : !overview ? null : (
        <>
          <View style={styles.progress} accessibilityLabel={question ? `Question ${at + 1} of ${questions.length}` : 'Read it back'}>
            {questions.map((q, i) => (
              <View
                key={q.key}
                style={[
                  styles.segment,
                  { backgroundColor: i === at ? colors.text : current[q.key] !== undefined ? colors.textTertiary : colors.fill },
                ]}
              />
            ))}
          </View>

          {question ? (
            <Animated.View key={question.key} entering={FadeIn.duration(220)} style={{ gap: spacing.xl }}>
              <View style={{ gap: spacing.xs }}>
                <AppText variant="footnote" tone="secondary">
                  {question.group === 'engagement'
                    ? `Question ${at + 1} of ${questions.length} · This month, how often did you feel this way?`
                    : `Question ${at + 1} of ${questions.length}`}
                </AppText>
                <AppText variant="title2">{question.prompt}</AppText>
              </View>

              {question.key === 'mood' ? (
                <MoodPicker value={current.mood} onChoose={(v) => choose('mood', v)} />
              ) : (
                <ListSection>
                  {question.options.map((option) => (
                    <ListRow
                      key={option.value}
                      title={option.label}
                      subtitle={option.hint ?? undefined}
                      selected={current[question.key] === option.value}
                      onPress={() => choose(question.key, option.value)}
                      chevron={false}
                    />
                  ))}
                </ListSection>
              )}

              <View style={styles.footer}>
                {at > 0 && <Button label="Back" variant="plain" size="sm" onPress={() => setStep(at - 1)} />}
                <View style={styles.flex} />
                {current[question.key] !== undefined && (
                  <Button label="Next" variant="gray" size="sm" onPress={() => setStep(at + 1)} />
                )}
              </View>
            </Animated.View>
          ) : (
            <Animated.View entering={FadeIn.duration(220)} style={{ gap: spacing.xl }}>
              <View style={styles.review}>
                <MoodFace mood={current.mood ?? null} size={88} />
                <View style={styles.flex}>
                  <AppText variant="title2">{current.mood ? MOOD_WORDS[current.mood - 1] : 'Your month'}</AppText>
                  <AppText variant="footnote" tone="secondary">
                    Read it back. Tap an answer to change it.
                  </AppText>
                </View>
              </View>

              <ListSection>
                {questions.map((q, i) => (
                  <ListRow
                    key={q.key}
                    title={answerLabel(q.options, current[q.key] ?? -1)}
                    subtitle={q.prompt}
                    wrap
                    onPress={() => {
                      setReturning(true);
                      setStep(i);
                    }}
                  />
                ))}
              </ListSection>

              <Input
                label="A note for yourself (optional)"
                hint="Only you can read it."
                value={currentNote}
                onChangeText={(text) => setNote(text.slice(0, NOTE_MAX))}
                placeholder="What made the month what it was?"
                multiline
                maxLength={NOTE_MAX}
              />

              <Button
                label={editing ? 'Save changes' : 'Save check-in'}
                onPress={() => void save()}
                loading={saving}
                disabled={saving || questions.some((q) => current[q.key] === undefined)}
                fullWidth
                size="lg"
              />
              <View style={styles.lock}>
                <Icon name="lock" size={13} color={colors.textSecondary} />
                <AppText variant="footnote" tone="secondary" style={styles.flex}>
                  Confidential. No one at your company sees your answers, only results for groups of five or more.
                </AppText>
              </View>
            </Animated.View>
          )}
        </>
      )}
    </Page>
  );
}

/** Five faces in a row, each mood's word under it, and the chosen one large above. */
function MoodPicker({ value, onChoose }: { value: number | undefined; onChoose: (value: number) => void }) {
  const { colors, spacing } = useTheme();

  return (
    <View style={{ gap: spacing.xxl }}>
      <View style={styles.hero}>
        <MoodFace mood={value ?? null} size={148} />
        <AppText variant="title1" accessibilityLiveRegion="polite">
          {value ? MOOD_WORDS[value - 1] : ' '}
        </AppText>
      </View>
      <View style={styles.moods} accessibilityRole="radiogroup">
        {MOOD_WORDS.map((word, i) => {
          const mood = i + 1;
          const on = value === mood;
          return (
            <Touchable
              key={word}
              onPress={() => onChoose(mood)}
              haptic="selection"
              scaleTo={0.92}
              accessibilityRole="radio"
              accessibilityState={{ checked: on }}
              accessibilityLabel={word}
              style={[styles.mood, on && { backgroundColor: colors.fill }]}
            >
              <View style={{ opacity: value !== undefined && !on ? 0.5 : 1 }}>
                <MoodFace mood={mood} size={50} label="" />
              </View>
              <AppText variant="caption" weight={on ? 'semibold' : 'regular'} tone={on ? undefined : 'secondary'}>
                {word}
              </AppText>
            </Touchable>
          );
        })}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  progress: { flexDirection: 'row', gap: 5 },
  segment: { flex: 1, height: 5, borderRadius: 3 },
  hero: { alignItems: 'center', gap: 6 },
  moods: { flexDirection: 'row', justifyContent: 'space-between' },
  mood: { alignItems: 'center', gap: 6, paddingVertical: 8, paddingHorizontal: 2, borderRadius: 14, flex: 1 },
  footer: { flexDirection: 'row', alignItems: 'center' },
  review: { flexDirection: 'row', alignItems: 'center', gap: 16 },
  lock: { flexDirection: 'row', alignItems: 'flex-start', gap: 6 },
});
