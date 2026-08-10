import { Ionicons } from '@expo/vector-icons';
import { Link } from 'expo-router';
import { useState } from 'react';
import { KeyboardAvoidingView, Platform, Pressable, ScrollView, View } from 'react-native';
import Animated, { FadeIn } from 'react-native-reanimated';
import { SafeAreaView } from 'react-native-safe-area-context';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { AppText } from '@/components/ui/text';
import { useToast } from '@/components/ui/toast';
import { ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { palette } from '@/theme/tokens';

type Errors = Partial<Record<'first_name' | 'last_name' | 'email' | 'password', string>>;

/**
 * Create a SYNAPSE account (ADR 0026).
 *
 * The account is the person's own — it belongs to them, not to an employer — so
 * the screen deliberately asks for nothing about work. Connecting to a company is
 * the *next* screen, and saying so here stops people hunting for a field where
 * their employer's name should go.
 */
export default function RegisterScreen() {
  const { register } = useAuth();
  const toast = useToast();

  const [firstName, setFirstName] = useState('');
  const [lastName, setLastName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [errors, setErrors] = useState<Errors>({});

  const complete =
    firstName.trim() !== '' &&
    lastName.trim() !== '' &&
    email.trim() !== '' &&
    password !== '' &&
    confirmation !== '';

  const onSubmit = async () => {
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
    <View style={{ flex: 1, backgroundColor: palette.navy }}>
      <SafeAreaView style={{ flex: 1 }}>
        <KeyboardAvoidingView
          style={{ flex: 1 }}
          behavior={Platform.OS === 'ios' ? 'padding' : undefined}
        >
          <ScrollView
            contentContainerStyle={{
              flexGrow: 1,
              justifyContent: 'center',
              padding: 24,
            }}
            keyboardShouldPersistTaps="handled"
          >
            <Animated.View
              entering={FadeIn.duration(500)}
              style={{ alignItems: 'center', marginBottom: 28 }}
            >
              <View
                style={{
                  width: 64,
                  height: 64,
                  borderRadius: 20,
                  backgroundColor: palette.teal,
                  alignItems: 'center',
                  justifyContent: 'center',
                  marginBottom: 16,
                }}
              >
                <Ionicons name="person-add" size={32} color={palette.navyDeep} />
              </View>
              <AppText variant="display" style={{ color: palette.white, letterSpacing: 2 }}>
                SYNAPSE
              </AppText>
            </Animated.View>

            <Animated.View
              entering={FadeIn.duration(500).delay(150)}
              style={{
                backgroundColor: palette.white,
                borderRadius: 24,
                padding: 22,
                gap: 16,
              }}
            >
              <View style={{ gap: 4 }}>
                <AppText variant="heading" style={{ color: palette.navy }}>
                  Create your account
                </AppText>
                <AppText variant="caption" style={{ color: '#64748B' }}>
                  This account is yours. You&apos;ll connect it to your company next.
                </AppText>
              </View>

              <View style={{ flexDirection: 'row', gap: 12 }}>
                <View style={{ flex: 1 }}>
                  <Input
                    label="First name"
                    placeholder="Juan"
                    autoCapitalize="words"
                    autoComplete="given-name"
                    value={firstName}
                    onChangeText={setFirstName}
                    error={errors.first_name}
                    editable={!submitting}
                  />
                </View>
                <View style={{ flex: 1 }}>
                  <Input
                    label="Last name"
                    placeholder="dela Cruz"
                    autoCapitalize="words"
                    autoComplete="family-name"
                    value={lastName}
                    onChangeText={setLastName}
                    error={errors.last_name}
                    editable={!submitting}
                  />
                </View>
              </View>

              <Input
                label="Email"
                placeholder="you@example.com"
                hint="Use any address you check — it doesn't have to be a work one."
                autoCapitalize="none"
                keyboardType="email-address"
                autoComplete="email"
                value={email}
                onChangeText={setEmail}
                error={errors.email}
                editable={!submitting}
              />

              <View style={{ position: 'relative' }}>
                <Input
                  label="Password"
                  placeholder="At least 8 characters"
                  secureTextEntry={!showPassword}
                  autoComplete="new-password"
                  value={password}
                  onChangeText={setPassword}
                  error={errors.password}
                  editable={!submitting}
                />
                <Pressable
                  onPress={() => setShowPassword((v) => !v)}
                  hitSlop={10}
                  style={{ position: 'absolute', right: 14, top: 38 }}
                >
                  <Ionicons name={showPassword ? 'eye-off' : 'eye'} size={20} color="#94A3B8" />
                </Pressable>
              </View>

              <Input
                label="Confirm password"
                placeholder="Type it again"
                secureTextEntry={!showPassword}
                value={confirmation}
                onChangeText={setConfirmation}
                editable={!submitting}
                onSubmitEditing={onSubmit}
                returnKeyType="go"
              />

              <Button
                label="Create account"
                onPress={onSubmit}
                loading={submitting}
                disabled={!complete}
                size="lg"
              />
            </Animated.View>

            <View
              style={{
                flexDirection: 'row',
                justifyContent: 'center',
                alignItems: 'center',
                gap: 6,
                marginTop: 24,
              }}
            >
              <AppText variant="caption" style={{ color: 'rgba(255,255,255,0.55)' }}>
                Already have an account?
              </AppText>
              <Link href="/(auth)/login" replace asChild>
                <Pressable hitSlop={8}>
                  <AppText variant="caption" style={{ color: palette.teal, fontWeight: '600' }}>
                    Sign in
                  </AppText>
                </Pressable>
              </Link>
            </View>
          </ScrollView>
        </KeyboardAvoidingView>
      </SafeAreaView>
    </View>
  );
}
