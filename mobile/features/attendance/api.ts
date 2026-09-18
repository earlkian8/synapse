import { api } from '@/lib/api';
import type {
  AttendanceRecord,
  AttendanceRequest,
  AttendanceRequestType,
  AttendanceSummary,
  Paginated,
  PunchType,
  TodayResponse,
} from '@/types/api';

export type PunchPayload = {
  type: PunchType;
  latitude?: number | null;
  longitude?: number | null;
  accuracy?: number | null;
  note?: string | null;
  photoUri?: string | null;
};

export type FileRequestPayload = {
  type: AttendanceRequestType;
  start_date: string;
  end_date?: string;
  reason: string;
  time_in?: string;
  break_start?: string;
  break_end?: string;
  time_out?: string;
  minutes?: number;
  start_time?: string;
  end_time?: string;
  location?: string;
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

  // My requests (ADR 0039) — decided in the ERP.
  requests: () => api.get<Paginated<AttendanceRequest>>('/attendance/requests?per_page=50'),

  fileRequest: (payload: FileRequestPayload) =>
    api.post<{ data: AttendanceRequest; message: string }>('/attendance/requests', payload),

  cancelRequest: (id: number) =>
    api.patch<{ data: AttendanceRequest; message: string }>(`/attendance/requests/${id}/cancel`),
};
