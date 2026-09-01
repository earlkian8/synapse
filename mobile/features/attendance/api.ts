import { api } from '@/lib/api';
import type { AttendanceRecord, AttendanceSummary, Paginated, PunchType, TodayResponse } from '@/types/api';

export type PunchPayload = {
  type: PunchType;
  latitude?: number | null;
  longitude?: number | null;
  accuracy?: number | null;
  note?: string | null;
  photoUri?: string | null;
};

export const attendanceApi = {
  today: () => api.get<TodayResponse>('/attendance/today'),

  punch: ({ type, latitude, longitude, accuracy, note, photoUri }: PunchPayload) => {
    const form = new FormData();
    form.append('type', type);
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

    return api.post<TodayResponse>('/attendance/punch', form);
  },

  records: (from: string, to: string) =>
    api.get<Paginated<AttendanceRecord>>(`/attendance/records?from=${from}&to=${to}&per_page=100`),

  summary: (from: string, to: string) =>
    api.get<AttendanceSummary>(`/attendance/summary?from=${from}&to=${to}`),
};
