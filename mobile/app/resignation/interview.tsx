import { useRouter } from 'expo-router';
import { useState } from 'react';
import { StyleSheet, View } from 'react-native';
import Animated from 'react-native-reanimated';

import { Card } from '@/components/ui/card';
import { ErrorState } from '@/components/ui/empty-state';
import { Icon } from '@/components/ui/icon';
import { Input } from '@/components/ui/input';
import { ListRow, ListSection } from '@/components/ui/list';
import { BarTextButton, Page } from '@/components/ui/page';
import { Segmented } from '@/components/ui/segmented';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { useToast } from '@/components/ui/toast';
import { Touchable } from '@/components/ui/touchable';
import { offboardingApi } from '@/features/offboarding/api';
import { ApiError } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { enter } from '@/lib/motion';
import { useQuery } from '@/lib/use-query';
import { useTheme } from '@/theme/theme';
import type { ExitInterview, InterviewQuestion } from '@/types/api';

type Answers = Record<string, string | number>;

/**
 * The exit interview (ADR 0076), from the phone: why one is leaving, seven
 * parts of one's time here rated 1 to 5, whether one would recommend the
 * company or come back, and three open questions. Once sent, it shows what was
 * answered. Only the people who handle exits read it.
 */
export default function ExitInterviewScreen() {
  const { colors, spacing } = useTheme();
  const router = useRouter();
  const toast = useToast();

  const { data, loading, error, reload } = useQuery<{ data: ExitInterview }>(() => offboardingApi.interview(), []);
  const interview = data?.data ?? null;
  const readOnly = !interview?.can_answer;

  const [draft, setDraft] = useState<Answers>({});
  const [sending, setSending] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const answers: Answers = readOnly ? (interview?.answers ?? {}) : draft;
  const set = (key: string, value: string | number) => setDraft((current) => ({ ...current, [key]: value }));

  const questions = interview?.questions ?? [];
  const of = (type: InterviewQuestion['type']) => questions.filter((q) => q.type === type);
  const complete = questions.every((q) => !q.required || (answers[q.key] !== undefined && answers[q.key] !== ''));

  const send = async () => {
    setSending(true);
    setErrors({});

    try {
      await offboardingApi.answer(draft);
      toast.show('Thank you. Your answers went to HR.', 'success');
      router.back();
    } catch (e) {
      if (e instanceof ApiError && Object.keys(e.fieldErrors).length > 0) {
        setErrors(e.fieldErrors);
        toast.show('Answer every question marked as needed.', 'error');
      } else {
        toast.show(e instanceof ApiError ? e.message : 'Couldn’t send your answers. Try again.', 'error');
      }
    } finally {
      setSending(false);
    }
  };

  return (
    <Page
      title="Exit interview"
      largeTitle={false}
      back
      right={
        readOnly ? undefined : (
          <BarTextButton label="Send" emphasized onPress={() => void send()} disabled={sending || !complete} />
        )
      }
      gap={24}
    >
      {loading ? (
        <>
          <Skeleton height={60} radius={14} />
          <Skeleton height={300} radius={14} />
        </>
      ) : error && !interview ? (
        <ErrorState message={error} onRetry={reload} />
      ) : !interview ? null : (
        <>
          <Animated.View entering={enter(0)} style={styles.lock}>
            <Icon name="lock" size={14} color={colors.textSecondary} />
            <AppText variant="footnote" tone="secondary" style={styles.flex}>
              {interview.status === 'submitted'
                ? `You answered on ${formatDate(interview.submitted_on)}. Thank you.`
                : 'About five minutes. Only the people who handle exits in your company can read your answers.'}
            </AppText>
          </Animated.View>

          {of('choice').map((question) => (
            <Animated.View key={question.key} entering={enter(1)}>
              <ListSection header={question.label}>
                {Object.entries(question.options ?? {}).map(([value, label]) => (
                  <ListRow
                    key={value}
                    title={label}
                    selected={answers[question.key] === value}
                    onPress={readOnly ? undefined : () => set(question.key, value)}
                    chevron={false}
                  />
                ))}
              </ListSection>
              {!!errors[`answers.${question.key}`] && (
                <AppText variant="footnote" tone="danger" style={styles.error}>
                  Pick one.
                </AppText>
              )}
            </Animated.View>
          ))}

          {of('rating').length > 0 && (
            <Animated.View entering={enter(2)} style={{ gap: spacing.sm }}>
              <AppText variant="footnote" tone="secondary" style={styles.header}>
                {of('rating')[0].group ?? 'How would you rate…'} (1 very poor, 5 very good)
              </AppText>
              <Card style={styles.ratings}>
                {of('rating').map((question, index) => (
                  <View
                    key={question.key}
                    style={[
                      styles.rating,
                      index > 0 && { borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: colors.separator },
                    ]}
                  >
                    <AppText variant="callout" style={styles.flex}>
                      {question.label}
                    </AppText>
                    <View style={styles.scale} accessibilityRole="radiogroup" accessibilityLabel={question.label}>
                      {[1, 2, 3, 4, 5].map((score) => {
                        const on = Number(answers[question.key]) === score;

                        return (
                          <Touchable
                            key={score}
                            disabled={readOnly}
                            onPress={() => set(question.key, score)}
                            haptic="selection"
                            accessibilityRole="radio"
                            accessibilityState={{ checked: on, disabled: readOnly }}
                            accessibilityLabel={`${score} — ${interview.scale[score] ?? ''}`}
                            style={[
                              styles.dot,
                              { borderColor: on ? colors.tint : colors.separator },
                              on && { backgroundColor: colors.tint },
                            ]}
                          >
                            <AppText variant="footnote" weight="semibold" color={on ? '#FFFFFF' : colors.textSecondary}>
                              {score}
                            </AppText>
                          </Touchable>
                        );
                      })}
                    </View>
                  </View>
                ))}
              </Card>
            </Animated.View>
          )}

          {of('yes_no').map((question) => (
            <Animated.View key={question.key} entering={enter(3)} style={{ gap: spacing.sm }}>
              <AppText variant="footnote" tone="secondary" style={styles.header}>
                {question.label}
              </AppText>
              {readOnly ? (
                <Card>
                  <AppText variant="callout">
                    {question.options?.[String(answers[question.key])] ?? '—'}
                  </AppText>
                </Card>
              ) : (
                <Segmented
                  accessibilityLabel={question.label}
                  options={Object.entries(question.options ?? {}).map(([value, label]) => ({ value, label }))}
                  value={String(answers[question.key] ?? '')}
                  onChange={(value) => set(question.key, value)}
                />
              )}
            </Animated.View>
          ))}

          {of('text').map((question) => (
            <Animated.View key={question.key} entering={enter(4)}>
              {readOnly ? (
                <View style={{ gap: spacing.sm }}>
                  <AppText variant="footnote" tone="secondary" style={styles.header}>
                    {question.label}
                  </AppText>
                  <Card>
                    <AppText variant="callout" tone={answers[question.key] ? 'primary' : 'secondary'} selectable>
                      {answers[question.key] ? String(answers[question.key]) : 'No answer.'}
                    </AppText>
                  </Card>
                </View>
              ) : (
                <Input
                  label={question.label}
                  value={String(answers[question.key] ?? '')}
                  onChangeText={(text) => set(question.key, text)}
                  placeholder="Optional"
                  multiline
                  maxLength={2000}
                  style={styles.text}
                />
              )}
            </Animated.View>
          ))}
        </>
      )}
    </Page>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  lock: { flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: 4 },
  header: { marginLeft: 16, letterSpacing: 0.2 },
  error: { paddingHorizontal: 16, marginTop: 6 },
  ratings: { paddingVertical: 0 },
  rating: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 12 },
  scale: { flexDirection: 'row', gap: 6 },
  dot: { width: 32, height: 32, borderRadius: 16, borderWidth: 1.5, alignItems: 'center', justifyContent: 'center' },
  text: { minHeight: 90, textAlignVertical: 'top' },
});
