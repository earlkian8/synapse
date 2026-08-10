import { Stack, useRouter, useSegments } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { StatusBar } from 'expo-status-bar';
import { useEffect } from 'react';
import { GestureHandlerRootView } from 'react-native-gesture-handler';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import 'react-native-reanimated';

import { ToastProvider } from '@/components/ui/toast';
import { AuthProvider, useAuth } from '@/lib/auth';
import { ThemeProvider, useTheme } from '@/theme/theme';

void SplashScreen.preventAutoHideAsync();

export default function RootLayout() {
  return (
    <GestureHandlerRootView style={{ flex: 1 }}>
      <SafeAreaProvider>
        <ThemeProvider>
          <AuthProvider>
            <ToastProvider>
              <RootNavigator />
            </ToastProvider>
          </AuthProvider>
        </ThemeProvider>
      </SafeAreaProvider>
    </GestureHandlerRootView>
  );
}

/**
 * Routes the user between the auth stack and the app shell based on session
 * state, and keeps the native splash up until the stored session is restored.
 */
function RootNavigator() {
  const { isLoading, isAuthenticated, hasEnteredWorkspace, needsWorkspace, organizations } =
    useAuth();
  const { scheme, colors } = useTheme();
  const segments = useSegments();
  const router = useRouter();

  useEffect(() => {
    if (isLoading) {
      return;
    }

    void SplashScreen.hideAsync();

    const inAuthGroup = segments[0] === '(auth)';
    const onPicker = segments[0] === 'select-workspace';
    const onJoin = segments[0] === 'join';
    // A fresh login by someone in several companies must pick one first.
    const needsChoice = isAuthenticated && !hasEnteredWorkspace && organizations.length > 1;
    // Signed in but belonging nowhere is a valid state (ADR 0026) — it has its own
    // screen rather than being treated as a failed session.
    const unaffiliated = isAuthenticated && needsWorkspace;

    if (!isAuthenticated && !inAuthGroup) {
      router.replace('/(auth)/login');
    } else if (unaffiliated && !onJoin) {
      router.replace('/join');
    } else if (needsChoice && !onPicker) {
      router.replace('/select-workspace');
    } else if (isAuthenticated && !unaffiliated && !needsChoice && (inAuthGroup || onPicker || onJoin)) {
      router.replace('/(tabs)');
    }
  }, [
    isLoading,
    isAuthenticated,
    hasEnteredWorkspace,
    needsWorkspace,
    organizations.length,
    segments,
    router,
  ]);

  return (
    <>
      <StatusBar style={scheme === 'dark' ? 'light' : 'dark'} />
      <Stack
        screenOptions={{
          headerShown: false,
          contentStyle: { backgroundColor: colors.background },
          animation: 'slide_from_right',
        }}
      >
        <Stack.Screen name="index" />
        <Stack.Screen name="(auth)" />
        <Stack.Screen name="join" />
        <Stack.Screen name="select-workspace" />
        <Stack.Screen name="(tabs)" />
        <Stack.Screen name="leave/new" options={{ presentation: 'modal', animation: 'slide_from_bottom' }} />
        <Stack.Screen name="leave/[id]" />
        <Stack.Screen name="attendance/[date]" options={{ presentation: 'modal', animation: 'slide_from_bottom' }} />
        <Stack.Screen name="awards/index" />
      </Stack>
    </>
  );
}
