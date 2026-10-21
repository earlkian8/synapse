import { Platform } from 'react-native';
import Constants from 'expo-constants';

import { api } from '@/lib/api';
import type { ProblemReport } from '@/types/api';

export type NewProblemReport = {
  category: ProblemReport['category'];
  impact: ProblemReport['impact'];
  subject: string;
  description: string;
  /** The part of the app it happens in, as the sender picked it. */
  page: string | null;
  /** Local image URIs from the photo library. */
  screenshots: { uri: string; mimeType?: string | null; fileName?: string | null }[];
};

/** The phone's system, as plainly as it can be said: "iOS 18.1", "Android 15". */
function deviceLabel(): string {
  if (Platform.OS === 'ios') return `iOS ${Platform.Version}`;
  if (Platform.OS === 'android') {
    const release = (Platform.constants as { Release?: string }).Release;
    return release ? `Android ${release}` : `Android (API ${Platform.Version})`;
  }

  return Platform.OS;
}

/** Problems reported to the SYNAPSE team, from the phone (ADR 0096). */
export const problemReportsApi = {
  list: () => api.get<{ data: ProblemReport[] }>('/problem-reports'),
  show: (reference: string) => api.get<{ data: ProblemReport }>(`/problem-reports/${encodeURIComponent(reference)}`),
  send: (report: NewProblemReport) => {
    const form = new FormData();

    form.append('category', report.category);
    form.append('impact', report.impact);
    form.append('subject', report.subject.trim());
    form.append('description', report.description.trim());
    form.append('platform', Platform.OS === 'ios' ? 'ios' : 'android');
    if (report.page) form.append('page', report.page);
    form.append('app_version', Constants.expoConfig?.version ?? '');
    form.append('device', deviceLabel());

    report.screenshots.forEach((shot, index) => {
      const type = shot.mimeType ?? 'image/jpeg';
      const extension = type === 'image/png' ? 'png' : type === 'image/webp' ? 'webp' : 'jpg';

      form.append('screenshots[]', {
        uri: shot.uri,
        name: shot.fileName ?? `screenshot-${index + 1}.${extension}`,
        type,
      } as unknown as Blob);
    });

    // Photos on a weak signal take longer than a plain request.
    return api.post<{ data: ProblemReport; message: string }>('/problem-reports', form, {
      timeoutMs: report.screenshots.length > 0 ? 90_000 : 30_000,
    });
  },
};

export { deviceLabel };
