import { api } from '@/lib/api';
import { deviceId } from '@/lib/device-id';
import type { Coords } from '@/lib/location';
import type {
  AttendanceRecord,
  AttendanceSummary,
  Paginated,
  PunchType,
  QrScanResponse,
  TodayResponse,
} from '@/types/api';

export type PunchPayload = {
  type: PunchType;
  latitude?: number | null;
  longitude?: number | null;
  accuracy?: number | null;
  note?: string | null;
  photoUri?: string | null;
  /** The app's own id for the punch, so sending it again records it once (ADR 0040). */
  clientId?: string;
  /** When it was made, for a punch sent after being offline. */
  punchedAt?: string;
};

export const attendanceApi = {
  today: () => api.get<TodayResponse>('/attendance/today'),

  punch: async ({ type, latitude, longitude, accuracy, note, photoUri, clientId, punchedAt }: PunchPayload) => {
    const form = new FormData();
    form.append('type', type);
    // Which phone, so one phone clocking in two people shows (ADR 0103).
    form.append('device_id', await deviceId());
    if (clientId) form.append('client_id', clientId);
    if (punchedAt) {
      // The phone's clock now, beside the time it gave the punch — how the
      // server tells a wrong clock from a late send.
      form.append('punched_at', punchedAt);
      form.append('sent_at', new Date().toISOString());
    }
    if (latitude != null) form.append('latitude', String(latitude));
    if (longitude != null) form.append('longitude', String(longitude));
    if (accuracy != null) form.append('accuracy', String(accuracy));
    if (note) form.append('note', note);

    if (photoUri) {
      form.append('photo', {
        uri: photoUri,
        name: 'selfie.jpg',
        type: 'image/jpeg',
      } as unknown as Blob);
    }

    // A selfie on a weak signal takes longer to upload than a plain punch.
    return api.post<TodayResponse & { duplicate?: boolean }>('/attendance/punch', form, {
      timeoutMs: photoUri ? 60_000 : 30_000,
    });
  },

  /**
   * Scan a QR station's code (ADR 0103): proves the phone is at the screen, and
   * buys a two-minute ticket for the punch. Never queued offline: a code is only
   * good while the screen shows it.
   */
  qrScan: async (code: string, coords: Coords | null) =>
    api.post<QrScanResponse>('/attendance/qr/scan', {
      code,
      latitude: coords?.latitude ?? null,
      longitude: coords?.longitude ?? null,
      accuracy: coords?.accuracy ?? null,
      mocked: coords?.mocked ?? false,
      device_id: await deviceId(),
    }),

  /** Spend a scan's ticket on a punch, with the selfie a policy may ask for. */
  qrPunch: (ticket: string, type: PunchType, photoUri?: string | null) => {
    const form = new FormData();
    form.append('ticket', ticket);
    form.append('type', type);

    if (photoUri) {
      form.append('photo', {
        uri: photoUri,
        name: 'selfie.jpg',
        type: 'image/jpeg',
      } as unknown as Blob);
    }

    return api.post<TodayResponse>('/attendance/qr/punch', form, { timeoutMs: photoUri ? 60_000 : 30_000 });
  },

  records: (from: string, to: string) =>
    api.get<Paginated<AttendanceRecord>>(`/attendance/records?from=${from}&to=${to}&per_page=100`),

  summary: (from: string, to: string) =>
    api.get<AttendanceSummary>(`/attendance/summary?from=${from}&to=${to}`),
};
