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

/** A queued punch the server refused, and why — it needs a correction instead. */
export type RefusedPunch = { punch: QueuedPunch; message: string };

type Owner = { userId: number; organizationId: number };

let owner: Owner | null = null;
let queue: QueuedPunch[] = [];
let sending = false;
const listeners = new Set<() => void>();

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
   * drops and reports one it refused. Returns the refused ones.
   */
  async flush(): Promise<RefusedPunch[]> {
    if (sending || queue.length === 0) {
      return [];
    }

    sending = true;
    const refused: RefusedPunch[] = [];

    try {
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
        } catch (error) {
          if (isOffline(error)) {
            break;
          }

          refused.push({ punch, message: error instanceof ApiError ? error.message : 'It was refused.' });
        }

        queue = queue.slice(1);
        emit();
        await persist();
      }
    } finally {
      sending = false;
    }

    return refused;
  },

  subscribe(listener: () => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
  },
};

/** The punches waiting to be sent, kept current. */
export function useQueuedPunches(): QueuedPunch[] {
  return useSyncExternalStore(punchQueue.subscribe, punchQueue.items, punchQueue.items);
}
