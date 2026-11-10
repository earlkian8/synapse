import { api } from '@/lib/api';
import type { CheckInAnswers, CheckInOverview, MyCheckIn } from '@/types/api';

/** Mood check-ins on the phone (ADR 0102): this month's questions, one's own months, and saving. */
export const checkInsApi = {
  overview: () => api.get<{ data: CheckInOverview }>('/check-ins'),
  save: (answers: CheckInAnswers, note: string | null) =>
    api.post<{ data: MyCheckIn; message: string }>('/check-ins', { ...answers, note }),
};
