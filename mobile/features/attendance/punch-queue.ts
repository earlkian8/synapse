/**
 * The offline punch queue (ADR 0040). A punch the phone could not send — no
 * signal in a stairwell, a basement car park — is kept here with the time it was
 * made, and sent when the phone is back online. The server judges it at that
 * time, within the company's window, and flags it if the phone's clock was off.
 *
 * Kept in AsyncStorage, which has a web build (unlike SecureStore), one queue per
 * signed-in user and company. Every punch carries its own id, so sending it twice
 * — a response lost on a bad connection — records it once.
 *
 * A small store rather than React state, because the Clock screen, the tab bar's
 * badge and the background sender all read the same queue.
 */
import AsyncStorage from '@react-native-async-storage/async-storage';
import { useSyncExternalStore } from 'react';

import { ApiError } from '@/lib/api';
import type { PunchType } from '@/types/api';

import { attendanceApi } from './api';

export type QueuedPunch = {
  client_id: string;
  type: PunchType;
  /** When the punch was made, by the phone's clock. */
  punched_at: string;
  latitude?: number | null;
  longitude?: number | null;
  accuracy?: number | null;
  photoUri?: string | null;
};

/** A queued punch the server refused, and why — HR has to enter it instead. */
export type RefusedPunch = { punch: QueuedPunch; message: string };

/** What one attempt to send the queue came to. */
export type FlushResult = {
  /** Punches the server took (or already had). */
  sent: number;
  /** Punches it refused — dropped, and announced to {@link punchQueue.onRefused} listeners. */
  refused: RefusedPunch[];
  /** Punches still on the phone afterwards. */
  waiting: number;
  /** Whether it stopped because the server could not be reached. */
  offline: boolean;
};

type Owner = { userId: number; organizationId: number };

let owner: Owner | null = null;
let queue: QueuedPunch[] = [];
/**
 * The attempt under way, shared by everyone who asks to send meanwhile — the Send
 * button pressed during a background retry waits for that retry and hears how it
 * went, instead of returning at once with nothing done.
 */
let inFlight: Promise<FlushResult> | null = null;
const listeners = new Set<() => void>();
const refusalListeners = new Set<(refused: RefusedPunch[]) => void>();

const storageKey = ({ userId, organizationId }: Owner) => `synapse.punch-queue.${userId}.${organizationId}`;

function emit() {
  listeners.forEach((listener) => listener());
}

async function persist() {
  if (owner) {
    await AsyncStorage.setItem(storageKey(owner), JSON.stringify(queue));
  }
}

/** An id for a punch, unique enough to recognise it when it is sent again. */
export function newPunchId(): string {
  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

/**
 * Whether a failed request never reached the server (as opposed to the server
 * answering no). Only these are queued; a refusal is shown, not retried.
 */
export function isOffline(error: unknown): boolean {
  return !(error instanceof ApiError) || error.status === 0 || error.status >= 500;
}

export const punchQueue = {
  /** Load the queue of whoever is signed in, into whichever company. */
  async bind(next: Owner | null): Promise<void> {
    if (owner?.userId === next?.userId && owner?.organizationId === next?.organizationId) {
      return;
    }

    owner = next;
    queue = [];

    if (next) {
      try {
        const stored = await AsyncStorage.getItem(storageKey(next));
        queue = stored ? (JSON.parse(stored) as QueuedPunch[]) : [];
      } catch {
        queue = [];
      }
    }

    emit();
  },

  async add(punch: QueuedPunch): Promise<void> {
    queue = [...queue, punch];
    emit();
    await persist();
  },

  items(): QueuedPunch[] {
    return queue;
  },

  /**
   * Send what is queued, oldest first. Stops at the first punch that still
   * cannot reach the server; drops one the server has taken (or already had);
   * drops and reports one it refused. A call made while an attempt is under way
   * joins that attempt.
   */
  flush(): Promise<FlushResult> {
    if (inFlight) {
      return inFlight;
    }

    if (queue.length === 0) {
      return Promise.resolve({ sent: 0, refused: [], waiting: 0, offline: false });
    }

    inFlight = send().finally(() => {
      inFlight = null;
    });

    return inFlight;
  },

  /** Hear about punches the server refused, whoever asked to send them. */
  onRefused(listener: (refused: RefusedPunch[]) => void): () => void {
    refusalListeners.add(listener);

    return () => refusalListeners.delete(listener);
  },

  subscribe(listener: () => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
  },
};

async function send(): Promise<FlushResult> {
  const refused: RefusedPunch[] = [];
  const sendingFor = owner;
  let sent = 0;
  let offline = false;

  while (queue.length > 0) {
    const [punch] = queue;

    try {
      await attendanceApi.punch({
        type: punch.type,
        latitude: punch.latitude,
        longitude: punch.longitude,
        accuracy: punch.accuracy,
        photoUri: punch.photoUri,
        clientId: punch.client_id,
        punchedAt: punch.punched_at,
      });
      sent++;
    } catch (error) {
      if (isOffline(error)) {
        offline = true;
        break;
      }

      refused.push({ punch, message: error instanceof ApiError ? error.message : 'It was refused.' });
    }

    // Signed out, or into another company, while it was on its way: that queue is
    // somebody else's now, and this one is safe where `bind` left it.
    if (owner !== sendingFor) {
      break;
    }

    queue = queue.slice(1);
    emit();
    await persist();
  }

  if (refused.length > 0) {
    refusalListeners.forEach((listener) => listener(refused));
  }

  return { sent, refused, waiting: queue.length, offline };
}

/** The punches waiting to be sent, kept current. */
export function useQueuedPunches(): QueuedPunch[] {
  return useSyncExternalStore(punchQueue.subscribe, punchQueue.items, punchQueue.items);
}
