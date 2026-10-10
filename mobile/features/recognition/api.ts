import { api } from '@/lib/api';
import type {
  Colleague,
  FeedItem,
  LedgerLine,
  NominatableType,
  Nomination,
  RecognitionMe,
  Redemption,
  Reward,
} from '@/types/api';

/** Recognition (ADR 0071): the wall, kudos, nominations, points and rewards. */
export const recognitionApi = {
  wall: () => api.get<{ data: FeedItem[]; me: RecognitionMe }>('/recognition'),
  colleagues: (search: string) =>
    api.get<{ data: Colleague[] }>(`/colleagues?search=${encodeURIComponent(search)}`),
  sendKudos: (toEmployeeId: number, message: string) =>
    api.post<{ data: { id: number; points: number }; me: RecognitionMe }>('/kudos', {
      to_employee_id: toEmployeeId,
      message,
    }),
  nominations: () => api.get<{ data: Nomination[] }>('/nominations'),
  nominationTypes: () => api.get<{ data: NominatableType[] }>('/nominations/types'),
  nominate: (employeeId: number, awardTypeId: number, reason: string) =>
    api.post<{ data: Nomination }>('/nominations', { employee_id: employeeId, award_type_id: awardTypeId, reason }),
  withdraw: (id: number) => api.delete<{ data: Nomination }>(`/nominations/${id}`),
  points: () => api.get<RecognitionMe & { history: LedgerLine[] }>('/points'),
  rewards: () => api.get<{ balance: number; rewards: Reward[]; redemptions: Redemption[] }>('/rewards'),
  redeem: (hashid: string, note: string | null) =>
    api.post<{ data: Redemption; balance: number }>(`/rewards/${hashid}/redeem`, { note }),
  cancel: (id: number) => api.patch<{ data: Redemption; balance: number }>(`/redemptions/${id}/cancel`),
};
