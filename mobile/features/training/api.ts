import { api } from '@/lib/api';
import type { CatalogEntry, CertificateDetail, MyTraining, TrainingProgramDetail } from '@/types/api';

/** Training on the phone (ADR 0094): one's own, and the programs one may join. */
export const trainingApi = {
  mine: () => api.get<{ data: MyTraining }>('/training'),
  catalog: () => api.get<{ data: CatalogEntry[] }>('/training/catalog'),
  program: (hashid: string) => api.get<{ data: TrainingProgramDetail }>(`/training/programs/${hashid}`),
  /** Join an open program, or ask to join one HR approves. */
  join: (hashid: string) => api.post<{ data: TrainingProgramDetail; message: string }>(`/training/programs/${hashid}/join`),
  /** Withdraw a request, or leave before the program starts. `data` is null once the program is out of sight. */
  withdraw: (enrollmentId: number) =>
    api.delete<{ data: TrainingProgramDetail | null; message: string }>(`/training/enrollments/${enrollmentId}`),
  /** A link to one session as a calendar file, that works for a few minutes without a token. */
  calendarLink: (sessionId: number) =>
    api.post<{ data: { url: string; expires_at: string } }>(`/training/sessions/${sessionId}/calendar-link`),
  certificate: (id: number | string) => api.get<{ data: CertificateDetail }>(`/training/certificates/${id}`),
};
