import { Ionicons } from '@expo/vector-icons';
import { useState } from 'react';
import {
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  View,
} from 'react-native';
import Animated, { FadeIn } from 'react-native-reanimated';
import { SafeAreaView } from 'react-native-safe-area-context';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { AppText } from '@/components/ui/text';
import { useToast } from '@/components/ui/toast';
import { ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { palette } from '@/theme/tokens';

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
    <View style={{ flex: 1, backgroundColor: palette.navy }}>
      <SafeAreaView style={{ flex: 1 }}>
        <KeyboardAvoidingView
          style={{ flex: 1 }}
          behavior={Platform.OS === 'ios' ? 'padding' : undefined}
        >
          <ScrollView
            contentContainerStyle={{ flexGrow: 1, justifyContent: 'center', padding: 24 }}
            keyboardShouldPersistTaps="handled"
          >
            <Animated.View entering={FadeIn.duration(500)} style={{ alignItems: 'center', marginBottom: 36 }}>
              <View
                style={{
                  width: 72,
                  height: 72,
                  borderRadius: 22,
                  backgroundColor: palette.teal,
                  alignItems: 'center',
                  justifyContent: 'center',
                  marginBottom: 18,
                }}
              >
                <Ionicons name="finger-print" size={38} color={palette.navyDeep} />
              </View>
              <AppText variant="display" style={{ color: palette.white, letterSpacing: 2 }}>
                SYNAPSE
              </AppText>
              <AppText variant="body" style={{ color: 'rgba(255,255,255,0.7)', marginTop: 4 }}>
                Intelligent HR Management
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
                  Welcome back
                </AppText>
                <AppText variant="caption" style={{ color: '#64748B' }}>
                  Sign in with your employee credentials.
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
                  style={{ position: 'absolute', right: 14, top: 38 }}
                >
                  <Ionicons name={showPassword ? 'eye-off' : 'eye'} size={20} color="#94A3B8" />
                </Pressable>
              </View>

              <Button
                label="Sign in"
                onPress={onSubmit}
                loading={submitting}
                disabled={!email || !password}
                size="lg"
              />
            </Animated.View>

            <AppText
              variant="caption"
              center
              style={{ color: 'rgba(255,255,255,0.55)', marginTop: 24 }}
            >
              Your account is created by HR when you’re hired.
            </AppText>
          </ScrollView>
        </KeyboardAvoidingView>
      </SafeAreaView>
    </View>
  );
}
