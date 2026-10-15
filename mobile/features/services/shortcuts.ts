/**
 * The services a person pinned to Home (ADR 0088), at most {@link MAX_SHORTCUTS}.
 *
 * Kept on the phone in AsyncStorage, one list per signed-in user and company (as
 * the punch queue is): it is a preference about this phone's Home, not an HR
 * record. Until someone edits it, Home shows a default chosen from what they may
 * open. A small store rather than React state, because Home and the Services tab
 * read and change the same list.
 */
import AsyncStorage from '@react-native-async-storage/async-storage';
import { useEffect, useSyncExternalStore } from 'react';

import { useAuth } from '@/lib/auth';

import { servicesFor, type Service, type ServiceKey } from './catalog';

/** Seven tiles and "All services" fill Home's two rows of four. */
export const MAX_SHORTCUTS = 7;

/**
 * What Home starts with: the things people open from the app most, after the
 * tabs. Clock and Attendance are tabs already, so they are not repeated here.
 */
const DEFAULTS: ServiceKey[] = [
  'leave',
  'fileLeave',
  'schedule',
  'events',
  'recognition',
  'holidays',
  'rewards',
  'awards',
  'record',
];

type Owner = { userId: number; organizationId: number };

let owner: Owner | null = null;
/** The pinned keys, or null while the person has never edited them. */
let pinned: ServiceKey[] | null = null;
const listeners = new Set<() => void>();

const storageKey = ({ userId, organizationId }: Owner) => `synapse.shortcuts.${userId}.${organizationId}`;

function emit() {
  listeners.forEach((listener) => listener());
}

const store = {
  subscribe(listener: () => void) {
    listeners.add(listener);
    return () => listeners.delete(listener);
  },
  get: () => pinned,
};

async function bind(next: Owner | null): Promise<void> {
  if (owner?.userId === next?.userId && owner?.organizationId === next?.organizationId) {
    return;
  }

  owner = next;
  pinned = null;
  emit();

  if (!next) return;

  try {
    const stored = await AsyncStorage.getItem(storageKey(next));
    // A switch while this was loading has moved on: don't paint the old list.
    if (owner !== next) return;
    pinned = stored ? (JSON.parse(stored) as ServiceKey[]) : null;
  } catch {
    pinned = null;
  }

  emit();
}

function save(keys: ServiceKey[]) {
  pinned = keys;
  emit();

  if (owner) {
    void AsyncStorage.setItem(storageKey(owner), JSON.stringify(keys)).catch(() => {});
  }
}

/**
 * The shortcuts on Home, and the means to change them. Only services the person
 * may still open are shown: one they lost access to drops out on its own.
 */
export function useShortcuts() {
  const { user, organization } = useAuth();
  const keys = useSyncExternalStore(store.subscribe, store.get, store.get);

  const userId = user?.id;
  const organizationId = organization?.id;

  useEffect(() => {
    void bind(userId !== undefined && organizationId !== undefined ? { userId, organizationId } : null);
  }, [userId, organizationId]);

  const available = servicesFor(user);
  const byKey = new Map(available.map((service) => [service.key, service]));
  const chosen = (keys ?? DEFAULTS).filter((key) => byKey.has(key)).slice(0, MAX_SHORTCUTS);
  const shortcuts = chosen.map((key) => byKey.get(key)).filter((service): service is Service => !!service);

  const isPinned = (key: ServiceKey) => chosen.includes(key);

  return {
    shortcuts,
    isPinned,
    full: chosen.length >= MAX_SHORTCUTS,
    /** Pin a service at the end of the row, or take it off Home. */
    toggle(key: ServiceKey) {
      if (isPinned(key)) {
        save(chosen.filter((k) => k !== key));
      } else if (chosen.length < MAX_SHORTCUTS) {
        save([...chosen, key]);
      }
    },
    /** Back to the default set. */
    reset() {
      pinned = null;
      emit();
      if (owner) void AsyncStorage.removeItem(storageKey(owner)).catch(() => {});
    },
    /** Whether the person has changed them from the default. */
    customized: keys !== null,
  };
}
