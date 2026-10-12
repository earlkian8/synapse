import { api } from '@/lib/api';
import type { SearchGroup } from '@/types/api';

/** The shortest and longest search the server answers (GlobalSearchRequest). */
export const MIN_QUERY = 2;
export const MAX_QUERY = 80;

/** One's own records (ADR 0081). */
export const searchApi = {
  search: (q: string) => api.get<{ query: string; groups: SearchGroup[] }>(`/search?q=${encodeURIComponent(q)}`),
};
