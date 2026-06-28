/**
 * Multi-workspace session storage. A person employed by more than one company
 * has a separate SYNAPSE account per company (one account ⇒ one organisation,
 * ADR 0005), so the app keeps several signed-in sessions at once and lets the
 * user switch between them without re-entering credentials.
 *
 * Each session carries a Sanctum token — which is itself a complete tenant
 * binding (token → user → organisation) — so holding N tokens means holding N
 * company contexts with no change to the server's tenancy model.
 *
 * Tokens (the secret) live in SecureStore; the cached user profiles (not
 * sensitive, and potentially larger than SecureStore's comfortable size) live
 * in AsyncStorage. The two are joined by the session id.
 */
import AsyncStorage from '@react-native-async-storage/async-storage';
import * as SecureStore from 'expo-secure-store';

import type { AuthUser } from '@/types/api';

/** A single signed-in workspace. `id` is the account's user id stringified —
 * globally unique, since one account maps to exactly one organisation. */
export type Session = {
  id: string;
  token: string;
  user: AuthUser;
};

const TOKENS_KEY = 'synapse.session-tokens'; // SecureStore: [{ id, token }]
const USERS_KEY = 'synapse.session-users'; //  AsyncStorage: [{ id, user }]
const ACTIVE_KEY = 'synapse.active-session'; // AsyncStorage: id
const LEGACY_TOKEN_KEY = 'synapse.token'; //    pre-multi-account single token

/** Derive a session's stable id from its account. */
export function sessionId(user: AuthUser): string {
  return String(user.id);
}

function parse<T>(raw: string | null, fallback: T): T {
  if (!raw) return fallback;
  try {
    return JSON.parse(raw) as T;
  } catch {
    return fallback;
  }
}

/** Restore every saved session plus the active id, joining tokens to users. */
export async function readSessions(): Promise<{ sessions: Session[]; activeId: string | null }> {
  const [tokensRaw, usersRaw, activeId] = await Promise.all([
    SecureStore.getItemAsync(TOKENS_KEY),
    AsyncStorage.getItem(USERS_KEY),
    AsyncStorage.getItem(ACTIVE_KEY),
  ]);

  const tokens = parse<{ id: string; token: string }[]>(tokensRaw, []);
  const users = parse<{ id: string; user: AuthUser }[]>(usersRaw, []);
  const userById = new Map(users.map((u) => [u.id, u.user]));

  // A token without a cached user is unusable until /me re-hydrates it, so we
  // keep only sessions we can render; order follows the (add-ordered) token list.
  const sessions = tokens
    .filter((t) => userById.has(t.id))
    .map((t) => ({ id: t.id, token: t.token, user: userById.get(t.id)! }));

  const validActive = activeId && sessions.some((s) => s.id === activeId) ? activeId : sessions[0]?.id ?? null;

  return { sessions, activeId: validActive };
}

/** Persist the full set of sessions and which one is active. */
export async function writeSessions(sessions: Session[], activeId: string | null): Promise<void> {
  const tokens = sessions.map((s) => ({ id: s.id, token: s.token }));
  const users = sessions.map((s) => ({ id: s.id, user: s.user }));

  await Promise.all([
    sessions.length
      ? SecureStore.setItemAsync(TOKENS_KEY, JSON.stringify(tokens))
      : SecureStore.deleteItemAsync(TOKENS_KEY),
    AsyncStorage.setItem(USERS_KEY, JSON.stringify(users)),
    activeId ? AsyncStorage.setItem(ACTIVE_KEY, activeId) : AsyncStorage.removeItem(ACTIVE_KEY),
  ]);
}

/**
 * Pull a pre-multi-account token written by the old single-session app, clearing
 * it so the migration runs once. The caller hydrates it into a real session via
 * `/me` (the old store kept no user profile alongside the token).
 */
export async function takeLegacyToken(): Promise<string | null> {
  const token = await SecureStore.getItemAsync(LEGACY_TOKEN_KEY);

  if (token) {
    await SecureStore.deleteItemAsync(LEGACY_TOKEN_KEY);
  }

  return token;
}
