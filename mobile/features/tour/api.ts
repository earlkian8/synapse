import { api } from '@/lib/api';

export type TourOutcome = 'completed' | 'skipped';
export type TourRunMode = 'first' | 'replay';

/** The app's own tour (ADR 0080): its runs and its answer. */
export const tourApi = {
  start: (mode: TourRunMode, steps: string[]) => api.post<{ id: number }>('/tour/runs', { mode, steps }),
  reach: (run: number, index: number) => api.patch<void>(`/tour/runs/${run}`, { index }),
  finish: (outcome: TourOutcome, run: number | null, answers: boolean) =>
    api.post<void>('/tour/finish', { outcome, run, answers }),
};
