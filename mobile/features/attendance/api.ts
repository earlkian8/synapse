import { api } from '@/lib/api';
import type { AttendanceRecord, AttendanceSummary, Paginated, PunchType, TodayResponse } from '@/types/api';

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

  punch: ({ type, latitude, longitude, accuracy, note, photoUri, clientId, punchedAt }: PunchPayload) => {
    const form = new FormData();
    form.append('type', type);
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

    return api.post<TodayResponse & { duplicate?: boolean }>('/attendance/punch', form);
  },

  records: (from: string, to: string) =>
    api.get<Paginated<AttendanceRecord>>(`/attendance/records?from=${from}&to=${to}&per_page=100`),

  summary: (from: string, to: string) =>
    api.get<AttendanceSummary>(`/attendance/summary?from=${from}&to=${to}`),
};
