import { api } from '@/lib/api';

import type { MyNotices, Notice } from './types';

/** My notices on the phone (ADR 0105): read, acknowledge, answer. */
export const disciplineApi = {
  mine: () => api.get<{ data: MyNotices }>('/discipline'),
  one: (hashid: string) => api.get<{ data: Notice }>(`/discipline/${hashid}`),
  acknowledge: (hashid: string, notice: 'notice' | 'decision') =>
    api.patch<{ data: Notice }>(`/discipline/${hashid}/acknowledge`, { notice }),
  explain: (hashid: string, explanation: string, hearing: boolean) =>
    api.post<{ data: Notice }>(`/discipline/${hashid}/explanation`, { explanation, hearing }),
};

export const STATUS_WORDS: Record<Notice['status'], string> = {
  reported: 'Reported',
  notice_issued: 'Waiting for your answer',
  explained: 'Answered',
  hearing: 'Hearing',
  decided: 'Decided',
  closed: 'Closed',
  withdrawn: 'Withdrawn',
};
