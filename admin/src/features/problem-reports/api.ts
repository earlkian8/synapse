import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api, query } from '@/lib/api';
import type { ListFilters, Option, Paginated } from '@/lib/types';

export type ReportStatus = 'new' | 'investigating' | 'resolved' | 'closed';
export type ReportImpact = 'blocked' | 'workaround' | 'minor';
export type ReportPlatform = 'web' | 'ios' | 'android';

/** A problem report as the list shows it (server: ProblemReportResource). */
export type ProblemReport = {
    id: number;
    reference: string;
    subject: string;
    category: string;
    category_label: string;
    impact: ReportImpact;
    impact_label: string;
    status: ReportStatus;
    status_label: string;
    platform: ReportPlatform;
    platform_label: string;
    reporter: { id: number; name: string; email: string } | null;
    organization: { id: number; name: string } | null;
    attachments_count: number;
    has_reply: boolean;
    created_at: string | null;
    status_changed_at: string | null;
};

export type ProblemReportDetail = ProblemReport & {
    description: string;
    page: string | null;
    app_version: string | null;
    device: string | null;
    context: {
        viewport?: string;
        screen?: string;
        timezone?: string;
        language?: string;
        theme?: string;
    };
    reply: string | null;
    replied_at: string | null;
    internal_note: string | null;
    handled_by: string | null;
    resolved_at: string | null;
    updated_at: string | null;
    attachments: {
        id: number;
        name: string;
        mime: string;
        size: number;
        width: number | null;
        height: number | null;
    }[];
};

export type ReportStats = {
    new: number;
    investigating: number;
    blocked: number;
    resolved_30d: number;
    total: number;
};

export type ReportParams = {
    search: string;
    status: string;
    category: string;
    impact: string;
    platform: string;
    sort: string;
    direction: string;
    per_page: number;
    page: number;
};

export type ReportInput = {
    status: ReportStatus;
    reply: string;
    internal_note: string;
};

/** The filters' choices; the values mirror ProblemReport's constants. */
export const STATUS_OPTIONS: Option[] = [
    { value: 'open', label: 'Open' },
    { value: 'new', label: 'New' },
    { value: 'investigating', label: 'Investigating' },
    { value: 'resolved', label: 'Resolved' },
    { value: 'closed', label: 'Closed' },
    { value: 'all', label: 'All reports' },
];

export const CATEGORY_OPTIONS: Option[] = [
    { value: 'all', label: 'Any kind' },
    { value: 'not_working', label: 'Not working' },
    { value: 'looks_wrong', label: 'Looks wrong' },
    { value: 'slow', label: 'Slow or won’t load' },
    { value: 'other', label: 'Something else' },
];

export const IMPACT_OPTIONS: Option[] = [
    { value: 'all', label: 'Any impact' },
    { value: 'blocked', label: 'Blocked' },
    { value: 'workaround', label: 'Has a workaround' },
    { value: 'minor', label: 'Minor' },
];

export const PLATFORM_OPTIONS: Option[] = [
    { value: 'all', label: 'Web and app' },
    { value: 'web', label: 'Web' },
    { value: 'ios', label: 'iPhone app' },
    { value: 'android', label: 'Android app' },
];

export function useProblemReports(params: ReportParams) {
    return useQuery({
        queryKey: ['problem-reports', params],
        queryFn: ({ signal }) =>
            api<
                Paginated<
                    ProblemReport,
                    ReportStats,
                    ListFilters & {
                        category: string;
                        impact: string;
                        platform: string;
                    }
                >
            >(`/problem-reports${query(params)}`, { signal }),
        placeholderData: keepPreviousData,
    });
}

export function useProblemReport(id: number | null) {
    return useQuery({
        queryKey: ['problem-report', id],
        queryFn: ({ signal }) =>
            api<{ data: ProblemReportDetail }>(`/problem-reports/${id}`, {
                signal,
            }),
        enabled: id !== null,
    });
}

/** The sidebar's badge: reports nobody has picked up yet. */
export function useProblemReportCounts() {
    return useQuery({
        queryKey: ['problem-report-counts'],
        queryFn: ({ signal }) =>
            api<{ new: number; open: number }>('/problem-reports/counts', {
                signal,
            }),
    });
}

export const updateProblemReport = (id: number, body: ReportInput) =>
    api<{ message: string; data: ProblemReportDetail }>(
        `/problem-reports/${id}`,
        { method: 'PATCH', body },
    );
