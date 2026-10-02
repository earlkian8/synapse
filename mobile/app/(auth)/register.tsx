import { Link } from 'expo-router';
import { useRef, useState } from 'react';
import { StyleSheet, View, type TextInput } from 'react-native';
import Animated from 'react-native-reanimated';

import { Button } from '@/components/ui/button';
import { BrandLockup, EntryScreen, entryColors } from '@/components/ui/entry-screen';
import { Input } from '@/components/ui/input';
import { AppText } from '@/components/ui/text';
import { Touchable } from '@/components/ui/touchable';
import { useToast } from '@/components/ui/toast';
import { ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { enter } from '@/lib/motion';

type Errors = Partial<Record<'first_name' | 'last_name' | 'email' | 'password', string>>;

/**
 * Create a SYNAPSE account (ADR 0026).
 *
 * The account is the person's own — it belongs to them, not to an employer — so
 * the screen deliberately asks for nothing about work. Connecting to a company is
 * the *next* screen, and saying so here stops people hunting for a field where
 * their employer's name should go.
 *
 * Return on each field moves to the next one, so the whole form can be filled
 * without leaving the keyboard.
 */
export default function RegisterScreen() {
  const { register } = useAuth();
  const toast = useToast();

  const lastNameRef = useRef<TextInput>(null);
  const emailRef = useRef<TextInput>(null);
  const passwordRef = useRef<TextInput>(null);
  const confirmationRef = useRef<TextInput>(null);

  const [firstName, setFirstName] = useState('');
  const [lastName, setLastName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [errors, setErrors] = useState<Errors>({});

  const complete =
    firstName.trim() !== '' &&
    lastName.trim() !== '' &&
    email.trim() !== '' &&
    password !== '' &&
    confirmation !== '';

  const onSubmit = async () => {
    if (!complete || submitting) return;

    setErrors({});
    setSubmitting(true);

    try {
      await register({
        first_name: firstName.trim(),
        last_name: lastName.trim(),
        email: email.trim(),
        password,
        password_confirmation: confirmation,
      });
      // The root navigator takes it from here — straight to the join screen.
    } catch (error) {
      if (error instanceof ApiError) {
        if (Object.keys(error.fieldErrors).length > 0) {
          setErrors(error.fieldErrors as Errors);
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
    <EntryScreen>
      <Animated.View entering={enter(0)} style={styles.brand}>
        <BrandLockup markWidth={88} />
      </Animated.View>

      <Animated.View entering={enter(1)} style={styles.heading}>
        <AppText variant="title1" center>
          Create your account
        </AppText>
        <AppText variant="body" tone="secondary" center>
          This account is yours. You&apos;ll connect it to your company next.
        </AppText>
      </Animated.View>

      <Animated.View entering={enter(2)} style={styles.form}>
        <View style={styles.row}>
          <View style={styles.half}>
            <Input
              label="First name"
              placeholder="Juan"
              autoCapitalize="words"
              autoComplete="given-name"
              textContentType="givenName"
              returnKeyType="next"
              submitBehavior="submit"
              onSubmitEditing={() => lastNameRef.current?.focus()}
              value={firstName}
              onChangeText={setFirstName}
              error={errors.first_name}
              editable={!submitting}
            />
          </View>
          <View style={styles.half}>
            <Input
              ref={lastNameRef}
              label="Last name"
              placeholder="dela Cruz"
              autoCapitalize="words"
              autoComplete="family-name"
              textContentType="familyName"
              returnKeyType="next"
              submitBehavior="submit"
              onSubmitEditing={() => emailRef.current?.focus()}
              value={lastName}
              onChangeText={setLastName}
              error={errors.last_name}
              editable={!submitting}
            />
          </View>
        </View>

        <Input
          ref={emailRef}
          label="Email"
          icon="mail"
          placeholder="you@example.com"
          hint="Any address you check. It doesn't have to be a work one."
          autoCapitalize="none"
          autoCorrect={false}
          keyboardType="email-address"
          autoComplete="email"
          textContentType="emailAddress"
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
          placeholder="At least 8 characters"
          secureToggle
          autoComplete="new-password"
          textContentType="newPassword"
          returnKeyType="next"
          submitBehavior="submit"
          onSubmitEditing={() => confirmationRef.current?.focus()}
          value={password}
          onChangeText={setPassword}
          error={errors.password}
          editable={!submitting}
        />

        <Input
          ref={confirmationRef}
          label="Confirm password"
          icon="lock"
          placeholder="Type it again"
          secureToggle
          autoComplete="new-password"
          textContentType="newPassword"
          returnKeyType="go"
          onSubmitEditing={onSubmit}
          value={confirmation}
          onChangeText={setConfirmation}
          editable={!submitting}
        />

        <Button
          label="Create account"
          onPress={onSubmit}
          loading={submitting}
          disabled={!complete}
          size="lg"
          style={styles.submit}
        />
      </Animated.View>

      <View style={styles.spacer} />

      <Animated.View entering={enter(3)} style={styles.footer}>
        <AppText variant="subheadline" tone="secondary">
          Already have an account?
        </AppText>
        <Link href="/(auth)/login" replace asChild>
          <Touchable feedback="opacity" hitSlop={10} accessibilityRole="link">
            <AppText variant="subheadline" weight="semibold" color={entryColors.brandText}>
              Sign in
            </AppText>
          </Touchable>
        </Link>
      </Animated.View>
    </EntryScreen>
  );
}

const styles = StyleSheet.create({
  brand: { alignItems: 'center', marginTop: 12, marginBottom: 28 },
  heading: { gap: 6, marginBottom: 28 },
  form: { gap: 16 },
  row: { flexDirection: 'row', gap: 12 },
  half: { flex: 1 },
  submit: { marginTop: 8 },
  spacer: { flexGrow: 1, minHeight: 32 },
  footer: { flexDirection: 'row', justifyContent: 'center', alignItems: 'center', gap: 6 },
});
