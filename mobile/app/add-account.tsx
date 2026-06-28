/**
 * Add another workspace: sign in to a second (or third) company you work for
 * without losing the session you already have. Presented as a modal from the
 * workspace switcher; on success the new account becomes active and we pop back
 * into the app, now in the new company's context.
 */
import { Ionicons } from '@expo/vector-icons';
import { useRouter } from 'expo-router';
import { useState } from 'react';
import { KeyboardAvoidingView, Platform, Pressable, ScrollView, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Screen, ScreenHeader } from '@/components/ui/screen';
import { AppText } from '@/components/ui/text';
import { useToast } from '@/components/ui/toast';
import { ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useTheme } from '@/theme/theme';

export default function AddAccountScreen() {
  const { signIn, sessions } = useAuth();
  const { colors, spacing, radius } = useTheme();
  const toast = useToast();
  const router = useRouter();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [errors, setErrors] = useState<{ email?: string; password?: string }>({});

  const onSubmit = async () => {
    setErrors({});

    const trimmed = email.trim().toLowerCase();
    if (sessions.some((s) => s.user.email.toLowerCase() === trimmed)) {
      setErrors({ email: 'That workspace is already added. Switch to it instead.' });
      return;
    }

    setSubmitting(true);

    try {
      await signIn(trimmed, password);
      toast.show('Workspace added', 'success');
      router.back();
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
    <Screen edges={['top', 'bottom']}>
      <ScreenHeader title="Add a company" back />

      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView
          contentContainerStyle={{ padding: spacing.lg, gap: spacing.lg }}
          keyboardShouldPersistTaps="handled"
        >
          <View
            style={{
              flexDirection: 'row',
              gap: spacing.md,
              padding: spacing.md,
              borderRadius: radius.lg,
              backgroundColor: colors.accentSoft,
            }}
          >
            <Ionicons name="business-outline" size={20} color={colors.accent} />
            <AppText variant="caption" style={{ flex: 1, color: colors.text }}>
              Work for more than one company? Sign in with the credentials for your other workspace.
              You can switch between them anytime.
            </AppText>
          </View>

          <View style={{ gap: spacing.md }}>
            <Input
              label="Email"
              placeholder="you@company.com"
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
                placeholder="••••••••"
                secureTextEntry={!showPassword}
                value={password}
                onChangeText={setPassword}
                error={errors.password}
                editable={!submitting}
                onSubmitEditing={onSubmit}
                returnKeyType="go"
              />
              <Pressable
                onPress={() => setShowPassword((v) => !v)}
                hitSlop={10}
                style={{ position: 'absolute', right: 14, top: 38 }}
              >
                <Ionicons name={showPassword ? 'eye-off' : 'eye'} size={20} color={colors.textFaint} />
              </Pressable>
            </View>
          </View>

          <Button
            label="Add workspace"
            onPress={onSubmit}
            loading={submitting}
            disabled={!email || !password}
            size="lg"
          />
        </ScrollView>
      </KeyboardAvoidingView>
    </Screen>
  );
}
