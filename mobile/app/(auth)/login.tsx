import { Link } from 'expo-router';
import { useRef, useState } from 'react';
import { StyleSheet, View, type TextInput } from 'react-native';
import Animated from 'react-native-reanimated';

import { Button } from '@/components/ui/button';
import { BrandLockup, EntryScreen, entryColors } from '@/components/ui/entry-screen';
import { Icon } from '@/components/ui/icon';
import { Input } from '@/components/ui/input';
import { AppText } from '@/components/ui/text';
import { Touchable } from '@/components/ui/touchable';
import { useToast } from '@/components/ui/toast';
import { ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { enter } from '@/lib/motion';

export default function LoginScreen() {
  const { login, sessionEnded } = useAuth();
  const toast = useToast();
  // The form's button: it rises above the keyboard with whichever field has focus.
  const submitRef = useRef<View>(null);
  const passwordRef = useRef<TextInput>(null);

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [errors, setErrors] = useState<{ email?: string; password?: string }>({});

  const ready = email.trim() !== '' && password !== '';

  const onSubmit = async () => {
    if (!ready || submitting) return;

    setErrors({});
    setSubmitting(true);

    try {
      await login(email.trim(), password);
      // The root navigator redirects into the app on success.
    } catch (error) {
      if (error instanceof ApiError) {
        if (Object.keys(error.fieldErrors).length > 0) {
          setErrors(error.fieldErrors);
        } else {
          toast.show(error.message, 'error');
        }
      } else {
        toast.show('Could not reach the server. Check your connection.', 'error');
      }
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <EntryScreen keyboardAnchor={submitRef}>
      <Animated.View entering={enter(0)} style={styles.brand}>
        <BrandLockup markSize={85} />
      </Animated.View>

      <Animated.View entering={enter(1)} style={styles.heading}>
        <AppText variant="title1" center>
          Welcome back
        </AppText>
        <AppText variant="body" tone="secondary" center>
          Sign in to clock in, file leave and see your records.
        </AppText>
      </Animated.View>

      {/* The server ended this phone's session: a new password, set here or elsewhere (ADR 0082). */}
      {sessionEnded && (
        <Animated.View
          entering={enter(1)}
          style={[styles.notice, { backgroundColor: entryColors.background }]}
          accessibilityRole="alert"
        >
          <Icon name="info" size={17} color={entryColors.tintText} />
          <AppText variant="footnote" tone="secondary" style={styles.flex}>
            You were signed out on this phone, as happens when your password changes. Sign in again to carry on.
          </AppText>
        </Animated.View>
      )}

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
          returnKeyType="next"
          submitBehavior="submit"
          onSubmitEditing={() => passwordRef.current?.focus()}
          value={email}
          onChangeText={setEmail}
          error={errors.email}
          editable={!submitting}
        />

        <Input
          ref={passwordRef}
          label="Password"
          icon="lock"
          placeholder="Your password"
          secureToggle
          autoComplete="current-password"
          textContentType="password"
          returnKeyType="go"
          onSubmitEditing={onSubmit}
          value={password}
          onChangeText={setPassword}
          error={errors.password}
          editable={!submitting}
        />

        <View style={styles.forgot}>
          <Link href={{ pathname: '/(auth)/forgot-password', params: email.trim() ? { email: email.trim() } : {} }} replace asChild>
            <Touchable feedback="opacity" hitSlop={10} accessibilityRole="link">
              <AppText variant="subheadline" weight="medium" color={entryColors.brandText}>
                Forgot password?
              </AppText>
            </Touchable>
          </Link>
        </View>

        <View ref={submitRef} collapsable={false}>
          <Button
            label="Sign in"
            onPress={onSubmit}
            loading={submitting}
            disabled={!ready}
            size="lg"
            style={styles.submit}
          />
        </View>
      </Animated.View>

      <View style={styles.spacer} />

      <Animated.View entering={enter(3)} style={styles.footer}>
        <AppText variant="subheadline" tone="secondary">
          New to SYNAPSE?
        </AppText>
        <Link href="/(auth)/register" replace asChild>
          <Touchable feedback="opacity" hitSlop={10} accessibilityRole="link">
            <AppText variant="subheadline" weight="semibold" color={entryColors.brandText}>
              Create an account
            </AppText>
          </Touchable>
        </Link>
      </Animated.View>
    </EntryScreen>
  );
}

const styles = StyleSheet.create({
  brand: { alignItems: 'center', marginTop: 28, marginBottom: 36 },
  heading: { gap: 6, marginBottom: 28 },
  flex: { flex: 1 },
  notice: { flexDirection: 'row', alignItems: 'flex-start', gap: 10, padding: 12, borderRadius: 12, borderCurve: 'continuous', marginTop: -12, marginBottom: 24 },
  form: { gap: 16 },
  forgot: { flexDirection: 'row', justifyContent: 'flex-end', marginTop: -6 },
  submit: { marginTop: 0 },
  spacer: { flexGrow: 1, minHeight: 32 },
  footer: { flexDirection: 'row', justifyContent: 'center', alignItems: 'center', gap: 6 },
});
