import { Link, useLocalSearchParams, useRouter } from 'expo-router';
import { useEffect, useRef, useState } from 'react';
import { StyleSheet, View } from 'react-native';
import Animated from 'react-native-reanimated';

import { Button } from '@/components/ui/button';
import { BrandLockup, EntryScreen, entryColors } from '@/components/ui/entry-screen';
import { Input } from '@/components/ui/input';
import { AppText } from '@/components/ui/text';
import { ToneWell } from '@/components/ui/tone-well';
import { Touchable } from '@/components/ui/touchable';
import { useToast } from '@/components/ui/toast';
import { accountApi } from '@/features/account/api';
import { ApiError } from '@/lib/api';
import { enter } from '@/lib/motion';

/** The server sends one link a minute at most, so the button waits as long. */
const RESEND_SECONDS = 60;

/**
 * Forgot password? (ADR 0082) — the address one signs in with, and a link mailed
 * to it. The link opens the web's own reset page on this phone; the new password
 * then signs in here, and signs the app out wherever the old one was still in.
 *
 * The screen says the same thing whatever the address: the server never tells a
 * stranger whether an account uses it, so neither does this.
 */
export default function ForgotPasswordScreen() {
  const toast = useToast();
  const router = useRouter();
  const params = useLocalSearchParams<{ email?: string }>();
  // The form's button: it rises above the keyboard with the field.
  const submitRef = useRef<View>(null);

  const [email, setEmail] = useState(params.email ?? '');
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<string | undefined>();
  const [sent, setSent] = useState<{ to: string; minutes: number } | null>(null);
  const [wait, setWait] = useState(0);

  // Count down to when another link may be asked for.
  useEffect(() => {
    if (wait <= 0) return;
    const timer = setTimeout(() => setWait((seconds) => seconds - 1), 1000);
    return () => clearTimeout(timer);
  }, [wait]);

  const ready = email.trim() !== '';

  const send = async () => {
    if (!ready || sending) return;

    setError(undefined);
    setSending(true);

    try {
      const to = email.trim();
      const result = await accountApi.forgotPassword(to);
      setSent({ to, minutes: result.expires_minutes });
      setWait(RESEND_SECONDS);
    } catch (e) {
      if (e instanceof ApiError && e.fieldErrors.email) {
        setError(e.fieldErrors.email);
      } else if (e instanceof ApiError && e.status === 429) {
        toast.show('Too many tries. Wait a minute, then send it again.', 'error');
      } else if (e instanceof ApiError && e.status > 0) {
        toast.show(e.message, 'error');
      } else {
        toast.show('Could not reach the server. Check your connection.', 'error');
      }
    } finally {
      setSending(false);
    }
  };

  return (
    <EntryScreen keyboardAnchor={submitRef}>
      <Animated.View entering={enter(0)} style={styles.brand}>
        <BrandLockup markWidth={88} />
      </Animated.View>

      {sent ? (
        <Animated.View key="sent" entering={enter(0)} style={styles.sent}>
          <ToneWell icon="mail" color={entryColors.tint} size={56} />
          <View style={styles.heading}>
            <AppText variant="title1" center>
              Check your email
            </AppText>
            <AppText variant="body" tone="secondary" center>
              If an account uses {sent.to}, a link to reset its password is on its way. It works for {sent.minutes}{' '}
              minutes.
            </AppText>
          </View>

          <View style={[styles.steps, { backgroundColor: entryColors.background }]}>
            <Step n={1} text="Open the link on this phone." />
            <Step n={2} text="Choose a new password." />
            <Step n={3} text="Come back here and sign in with it." />
          </View>

          <View style={styles.actions}>
            <Button label="Back to sign in" size="lg" onPress={() => router.replace('/(auth)/login')} />
            <Button
              label={wait > 0 ? `Send it again in ${wait}s` : 'Send it again'}
              variant="plain"
              onPress={send}
              loading={sending}
              disabled={wait > 0}
            />
          </View>
          <AppText variant="footnote" tone="secondary" center>
            Nothing yet? Check your spam folder, and that this is the address you sign in with.
          </AppText>
        </Animated.View>
      ) : (
        <>
          <Animated.View entering={enter(1)} style={styles.heading}>
            <AppText variant="title1" center>
              Reset your password
            </AppText>
            <AppText variant="body" tone="secondary" center>
              Enter the email you sign in with. We’ll send you a link to choose a new password.
            </AppText>
          </Animated.View>

          <Animated.View entering={enter(2)} style={styles.form}>
            <Input
              label="Email"
              icon="mail"
              placeholder="you@company.com"
              autoCapitalize="none"
              autoCorrect={false}
              keyboardType="email-address"
              autoComplete="email"
              textContentType="username"
              returnKeyType="send"
              onSubmitEditing={send}
              value={email}
              onChangeText={setEmail}
              error={error}
              editable={!sending}
              autoFocus={!params.email}
            />

            <View ref={submitRef} collapsable={false}>
              <Button
                label="Email me a link"
                onPress={send}
                loading={sending}
                disabled={!ready}
                size="lg"
                style={styles.submit}
              />
            </View>
          </Animated.View>
        </>
      )}

      <View style={styles.spacer} />

      {!sent && (
        <Animated.View entering={enter(3)} style={styles.footer}>
          <AppText variant="subheadline" tone="secondary">
            Remembered it?
          </AppText>
          <Link href="/(auth)/login" replace asChild>
            <Touchable feedback="opacity" hitSlop={10} accessibilityRole="link">
              <AppText variant="subheadline" weight="semibold" color={entryColors.brandText}>
                Sign in
              </AppText>
            </Touchable>
          </Link>
        </Animated.View>
      )}
    </EntryScreen>
  );
}

/** One step of what to do next: a numbered disc and a line. */
function Step({ n, text }: { n: number; text: string }) {
  return (
    <View style={styles.step}>
      <View style={[styles.stepNumber, { backgroundColor: entryColors.tintSoft }]}>
        <AppText variant="footnote" weight="semibold" color={entryColors.tintText} numeric>
          {n}
        </AppText>
      </View>
      <AppText variant="subheadline" style={styles.flex}>
        {text}
      </AppText>
    </View>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  brand: { alignItems: 'center', marginTop: 12, marginBottom: 28 },
  heading: { gap: 6, marginBottom: 28 },
  form: { gap: 16 },
  submit: { marginTop: 8 },
  sent: { alignItems: 'center' },
  steps: { alignSelf: 'stretch', gap: 12, padding: 16, borderRadius: 16, borderCurve: 'continuous', marginBottom: 24 },
  step: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  stepNumber: { width: 26, height: 26, borderRadius: 13, alignItems: 'center', justifyContent: 'center' },
  actions: { alignSelf: 'stretch', gap: 8, marginBottom: 16 },
  spacer: { flexGrow: 1, minHeight: 32 },
  footer: { flexDirection: 'row', justifyContent: 'center', alignItems: 'center', gap: 6 },
});
