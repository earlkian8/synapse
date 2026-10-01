import { Ionicons } from '@expo/vector-icons';
import { Link } from 'expo-router';
import { useState } from 'react';
import {
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  View,
} from 'react-native';
import Animated, { FadeIn } from 'react-native-reanimated';

import { Button } from '@/components/ui/button';
import { BrandLockup, EntryScreen, entryColors as colors } from '@/components/ui/entry-screen';
import { Input } from '@/components/ui/input';
import { AppText } from '@/components/ui/text';
import { useToast } from '@/components/ui/toast';
import { ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';

export default function LoginScreen() {
  const { login } = useAuth();
  const toast = useToast();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [errors, setErrors] = useState<{ email?: string; password?: string }>({});

  const onSubmit = async () => {
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
    <EntryScreen>
      <KeyboardAvoidingView
        style={{ flex: 1 }}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      >
        <ScrollView
          contentContainerStyle={{ flexGrow: 1, justifyContent: 'center', padding: 24 }}
          keyboardShouldPersistTaps="handled"
        >
          <Animated.View entering={FadeIn.duration(500)} style={{ marginBottom: 40 }}>
            <BrandLockup markWidth={168} tagline="Intelligent HR Management" />
          </Animated.View>

          <Animated.View entering={FadeIn.duration(500).delay(150)} style={{ gap: 16 }}>
            <View style={{ gap: 4 }}>
              <AppText variant="heading">Welcome back</AppText>
              <AppText variant="caption" muted>
                Sign in to your SYNAPSE account.
              </AppText>
            </View>

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
                accessibilityRole="button"
                accessibilityLabel={showPassword ? 'Hide password' : 'Show password'}
                style={{ position: 'absolute', right: 14, top: 38 }}
              >
                <Ionicons name={showPassword ? 'eye-off' : 'eye'} size={20} color={colors.textFaint} />
              </Pressable>
            </View>

            <Button
              label="Sign in"
              variant="secondary"
              onPress={onSubmit}
              loading={submitting}
              disabled={!email || !password}
              size="lg"
              style={{ marginTop: 4 }}
            />
          </Animated.View>

          <View
            style={{
              flexDirection: 'row',
              justifyContent: 'center',
              alignItems: 'center',
              gap: 6,
              marginTop: 28,
            }}
          >
            <AppText variant="caption" color={colors.textMuted}>
              New here?
            </AppText>
            <Link href="/(auth)/register" replace asChild>
              <Pressable hitSlop={8}>
                <AppText variant="caption" color={colors.secondaryText} style={{ fontWeight: '700' }}>
                  Create an account
                </AppText>
              </Pressable>
            </Link>
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </EntryScreen>
  );
}
