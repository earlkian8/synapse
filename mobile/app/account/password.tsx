import { useRouter } from 'expo-router';
import { useRef, useState } from 'react';
import { Alert, StyleSheet, View, type TextInput } from 'react-native';
import Animated from 'react-native-reanimated';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { BarTextButton, Page } from '@/components/ui/page';
import { AppText } from '@/components/ui/text';
import { useToast } from '@/components/ui/toast';
import { accountApi } from '@/features/account/api';
import { ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { enter } from '@/lib/motion';

type Field = 'current_password' | 'password' | 'password_confirmation';

/**
 * Change password (ADR 0082), as a form sheet — the web's rules. The new
 * password signs the app out on every other phone; this one stays signed in. It
 * is the account's, so it is the password for the web and every company too.
 *
 * Someone who has forgotten the current one is offered the reset link from here,
 * sent to the address they sign in with.
 */
export default function ChangePasswordScreen() {
  const router = useRouter();
  const toast = useToast();
  const { user } = useAuth();

  const newRef = useRef<TextInput>(null);
  const confirmRef = useRef<TextInput>(null);

  const [current, setCurrent] = useState('');
  const [next, setNext] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [saving, setSaving] = useState(false);
  const [sendingLink, setSendingLink] = useState(false);
  const [errors, setErrors] = useState<Partial<Record<Field, string>>>({});

  const mismatch = confirmation !== '' && next !== confirmation;
  const complete = current !== '' && next !== '' && confirmation !== '' && !mismatch;

  const save = async () => {
    if (!complete || saving) return;

    setSaving(true);
    setErrors({});

    try {
      const result = await accountApi.changePassword({
        current_password: current,
        password: next,
        password_confirmation: confirmation,
      });
      toast.show(result.message, 'success');
      router.back();
    } catch (e) {
      if (e instanceof ApiError && Object.keys(e.fieldErrors).length > 0) {
        setErrors(e.fieldErrors as Partial<Record<Field, string>>);
      } else if (e instanceof ApiError && e.status === 429) {
        toast.show('Too many tries. Wait a minute, then try again.', 'error');
      } else {
        toast.show(e instanceof ApiError ? e.message : 'Couldn’t change it. Check your connection and try again.', 'error');
      }
    } finally {
      setSaving(false);
    }
  };

  const sendResetLink = () => {
    const email = user?.email;
    if (!email) return;

    Alert.alert('Email a reset link?', `We’ll send a link to ${email} to choose a new password.`, [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Send link',
        onPress: async () => {
          setSendingLink(true);
          try {
            const result = await accountApi.forgotPassword(email);
            toast.show(`Check your email. The link works for ${result.expires_minutes} minutes.`, 'success');
          } catch (e) {
            toast.show(
              e instanceof ApiError && e.status === 429
                ? 'Too many tries. Wait a minute, then send it again.'
                : e instanceof ApiError
                  ? e.message
                  : 'Couldn’t send it. Check your connection.',
              'error',
            );
          } finally {
            setSendingLink(false);
          }
        },
      },
    ]);
  };

  return (
    <Page
      title="Change password"
      largeTitle={false}
      modal
      left={<BarTextButton label="Cancel" onPress={() => router.back()} disabled={saving} />}
      right={<BarTextButton label="Save" emphasized onPress={() => void save()} disabled={saving || !complete} />}
    >
      <Animated.View entering={enter(0)} style={styles.form}>
        <Input
          label="Current password"
          icon="lock"
          secureToggle
          autoComplete="current-password"
          textContentType="password"
          returnKeyType="next"
          submitBehavior="submit"
          onSubmitEditing={() => newRef.current?.focus()}
          value={current}
          onChangeText={setCurrent}
          error={errors.current_password}
          editable={!saving}
          autoFocus
        />
      </Animated.View>

      <Animated.View entering={enter(1)} style={styles.form}>
        <Input
          ref={newRef}
          label="New password"
          icon="lock"
          hint="Use 12 or more characters, with upper and lower case letters, a number and a symbol."
          secureToggle
          autoComplete="new-password"
          textContentType="newPassword"
          returnKeyType="next"
          submitBehavior="submit"
          onSubmitEditing={() => confirmRef.current?.focus()}
          value={next}
          onChangeText={setNext}
          error={errors.password}
          editable={!saving}
        />
        <Input
          ref={confirmRef}
          label="Confirm new password"
          icon="lock"
          secureToggle
          autoComplete="new-password"
          textContentType="newPassword"
          returnKeyType="done"
          onSubmitEditing={() => void save()}
          value={confirmation}
          onChangeText={setConfirmation}
          error={mismatch ? 'The two don’t match yet.' : errors.password_confirmation}
          editable={!saving}
        />
      </Animated.View>

      <Animated.View entering={enter(2)} style={styles.after}>
        <AppText variant="footnote" tone="secondary" style={styles.note}>
          It’s the password for your SYNAPSE account, on the web and in every company. Saving it signs the app out on
          your other devices.
        </AppText>
        <View style={styles.forgot}>
          <Button
            label="Forgot your current password?"
            variant="plain"
            size="sm"
            fullWidth={false}
            onPress={sendResetLink}
            loading={sendingLink}
            disabled={saving}
          />
        </View>
      </Animated.View>
    </Page>
  );
}

const styles = StyleSheet.create({
  form: { gap: 16 },
  after: { gap: 8 },
  note: { paddingHorizontal: 4 },
  forgot: { alignItems: 'flex-start' },
});
