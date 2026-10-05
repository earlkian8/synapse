import { Stack } from 'expo-router';

export default function AuthLayout() {
  // Sign-in and register replace each other in place, so they cross-fade rather than push.
  return <Stack screenOptions={{ headerShown: false, animation: 'fade' }} />;
}
