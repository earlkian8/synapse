import { useRouter } from 'expo-router';
import { useRef, useState } from 'react';
import { StyleSheet, View, type TextInput } from 'react-native';
import Animated from 'react-native-reanimated';

import { ErrorState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { BarTextButton, Page } from '@/components/ui/page';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { useToast } from '@/components/ui/toast';
import { accountApi } from '@/features/account/api';
import { ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { enter, fadeIn } from '@/lib/motion';
import { useQuery } from '@/lib/use-query';
import type { Account } from '@/types/api';

type Field = 'first_name' | 'middle_name' | 'last_name' | 'email' | 'current_password';

/**
 * Name and email (ADR 0082), as a form sheet: the person's own sign-in account,
 * the same fields as the web's Settings → Profile. It changes how they appear and
 * sign in in every company, and nothing on the employee record HR keeps.
 *
 * A new email address asks for the current password first — an app session never
 * ends on its own, so the phone alone must not be enough to move the account — and
 * is confirmed again with a code mailed to it.
 */
export default function AccountDetailsScreen() {
  const router = useRouter();
  const toast = useToast();
  const { refresh } = useAuth();

  const { data, loading, error, reload } = useQuery<{ data: Account }>(() => accountApi.show(), []);
  const account = data?.data ?? null;

  // What has been typed; anything untouched shows the account's own value.
  const [draft, setDraft] = useState<Partial<Record<Field, string>>>({});
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState<Partial<Record<Field, string>>>({});

  const middleRef = useRef<TextInput>(null);
  const lastRef = useRef<TextInput>(null);
  const emailRef = useRef<TextInput>(null);
  const passwordRef = useRef<TextInput>(null);

  const value = (field: Exclude<Field, 'current_password'>): string => draft[field] ?? account?.[field] ?? '';
  const set = (field: Field) => (text: string) => setDraft((current) => ({ ...current, [field]: text }));

  const email = value('email').trim();
  const emailChanged = !!account && email.toLowerCase() !== account.email.toLowerCase();
  const changed =
    !!account &&
    (value('first_name').trim() !== account.first_name ||
      value('middle_name').trim() !== (account.middle_name ?? '') ||
      value('last_name').trim() !== account.last_name ||
      email !== account.email);
  const complete =
    value('first_name').trim() !== '' &&
    value('last_name').trim() !== '' &&
    email !== '' &&
    (!emailChanged || (draft.current_password ?? '') !== '');

  const save = async () => {
    if (!account || !changed || !complete || saving) return;

    setSaving(true);
    setErrors({});

    try {
      const result = await accountApi.update({
        first_name: value('first_name').trim(),
        middle_name: value('middle_name').trim() || null,
        last_name: value('last_name').trim(),
        email,
        ...(emailChanged ? { current_password: draft.current_password } : {}),
      });
      // The name on Home and in the session comes from /me.
      await refresh().catch(() => undefined);
      toast.show(result.message, 'success');
      router.back();
    } catch (e) {
      if (e instanceof ApiError && Object.keys(e.fieldErrors).length > 0) {
        setErrors(e.fieldErrors as Partial<Record<Field, string>>);
      } else {
        toast.show(e instanceof ApiError ? e.message : 'Couldn’t save. Check your connection and try again.', 'error');
      }
    } finally {
      setSaving(false);
    }
  };

  return (
    <Page
      title="Name and email"
      largeTitle={false}
      modal
      left={<BarTextButton label="Cancel" onPress={() => router.back()} disabled={saving} />}
      right={
        <BarTextButton label="Save" emphasized onPress={() => void save()} disabled={saving || !changed || !complete} />
      }
    >
      {loading ? (
        <View style={styles.form}>
          <Skeleton height={74} radius={14} />
          <Skeleton height={74} radius={14} />
          <Skeleton height={74} radius={14} />
        </View>
      ) : error && !account ? (
        <ErrorState message={error} onRetry={reload} />
      ) : account ? (
        <>
          <Animated.View entering={enter(0)} style={styles.form}>
            <Input
              label="First name"
              autoCapitalize="words"
              autoComplete="given-name"
              textContentType="givenName"
              returnKeyType="next"
              submitBehavior="submit"
              onSubmitEditing={() => middleRef.current?.focus()}
              value={value('first_name')}
              onChangeText={set('first_name')}
              error={errors.first_name}
              editable={!saving}
            />
            <Input
              ref={middleRef}
              label="Middle name (optional)"
              autoCapitalize="words"
              autoComplete="additional-name"
              textContentType="middleName"
              returnKeyType="next"
              submitBehavior="submit"
              onSubmitEditing={() => lastRef.current?.focus()}
              value={value('middle_name')}
              onChangeText={set('middle_name')}
              error={errors.middle_name}
              editable={!saving}
            />
            <Input
              ref={lastRef}
              label="Last name"
              autoCapitalize="words"
              autoComplete="family-name"
              textContentType="familyName"
              returnKeyType="next"
              submitBehavior="submit"
              onSubmitEditing={() => emailRef.current?.focus()}
              value={value('last_name')}
              onChangeText={set('last_name')}
              error={errors.last_name}
              editable={!saving}
            />
          </Animated.View>

          <Animated.View entering={enter(1)} style={styles.form}>
            <Input
              ref={emailRef}
              label="Email"
              icon="mail"
              hint="The address you sign in with, in every company you belong to. Your work email on your employee record stays as HR has it."
              autoCapitalize="none"
              autoCorrect={false}
              keyboardType="email-address"
              autoComplete="email"
              textContentType="emailAddress"
              returnKeyType={emailChanged ? 'next' : 'done'}
              submitBehavior={emailChanged ? 'submit' : 'blurAndSubmit'}
              onSubmitEditing={() => (emailChanged ? passwordRef.current?.focus() : void save())}
              value={value('email')}
              onChangeText={set('email')}
              error={errors.email}
              editable={!saving}
            />

            {emailChanged && (
              <Animated.View entering={fadeIn} style={styles.form}>
                <Input
                  ref={passwordRef}
                  label="Current password"
                  icon="lock"
                  hint="Needed to change the address you sign in with. We’ll send a code to the new address to confirm it."
                  secureToggle
                  autoComplete="current-password"
                  textContentType="password"
                  returnKeyType="done"
                  onSubmitEditing={() => void save()}
                  value={draft.current_password ?? ''}
                  onChangeText={set('current_password')}
                  error={errors.current_password}
                  editable={!saving}
                />
              </Animated.View>
            )}
          </Animated.View>

          <AppText variant="footnote" tone="secondary" style={styles.note}>
            This is your SYNAPSE account, and it is yours. Your employee record (position, department, government IDs
            and the rest) is kept by HR: ask them to correct anything there.
          </AppText>
        </>
      ) : null}
    </Page>
  );
}

const styles = StyleSheet.create({
  form: { gap: 16 },
  note: { paddingHorizontal: 4 },
});
