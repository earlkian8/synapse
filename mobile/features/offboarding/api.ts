import { api } from '@/lib/api';
import type { ExitInterview, ExitInterviewState, MyExit, MyResignation } from '@/types/api';

/** Leaving, from the phone (ADRs 0076, 0077). */
export const offboardingApi = {
  show: () => api.get<{ data: MyExit }>('/offboarding'),
  resign: (payload: { proposed_last_day: string; reason?: string | null }) =>
    api.post<{ data: MyResignation }>('/offboarding/resignation', payload),
  withdraw: (id: string) => api.patch<{ data: MyResignation }>(`/offboarding/resignation/${id}/withdraw`),
  interview: () => api.get<{ data: ExitInterview }>('/offboarding/exit-interview'),
  answer: (answers: Record<string, string | number>) =>
    api.post<{ data: ExitInterviewState }>('/offboarding/exit-interview', { answers }),
  /** A link to the Certificate of Employment that works for a few minutes, for the browser. */
  certificateLink: () => api.post<{ data: { url: string; expires_at: string } }>('/offboarding/certificate-link'),
};
