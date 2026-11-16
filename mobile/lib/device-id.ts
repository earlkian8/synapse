import * as SecureStore from 'expo-secure-store';

const KEY = 'synapse.device-id';

let cached: string | null = null;

/**
 * This install's own id (ADR 0103): made once, kept in the keychain, and sent
 * with every punch. The server keeps only its hash, to see one phone clocking
 * in two people. It names nobody and is not a secret — reinstalling the app
 * makes a new one.
 */
export async function deviceId(): Promise<string> {
  if (cached) return cached;

  try {
    const stored = await SecureStore.getItemAsync(KEY);

    if (stored) {
      cached = stored;
      return stored;
    }
  } catch {
    // No keychain (the web build): an id for this session only.
  }

  const made = Array.from({ length: 4 }, () => Math.random().toString(36).slice(2, 10).padEnd(8, '0')).join('');
  cached = `${Date.now().toString(36)}-${made}`;

  try {
    await SecureStore.setItemAsync(KEY, cached);
  } catch {
    // Kept in memory instead.
  }

  return cached;
}
