/**
 * Problem reports (ADR 0096), as the reporter reads them. The server's
 * `Support\ProblemReports\MyProblemReports` shapes every one of these.
 */

export type ReportCategory = 'not_working' | 'looks_wrong' | 'slow' | 'other';

export type ReportImpact = 'blocked' | 'workaround' | 'minor';

export type ReportStatus = 'new' | 'investigating' | 'resolved' | 'closed';

export type ReportAttachment = {
    id: number;
    name: string;
    /** The app's own file route: it opens for the reporter alone. */
    url: string;
    width: number | null;
    height: number | null;
};

export type ProblemReport = {
    reference: string;
    category: ReportCategory;
    category_label: string;
    impact: ReportImpact;
    impact_label: string;
    subject: string;
    description: string;
    platform: 'web' | 'ios' | 'android';
    platform_label: string;
    page: string | null;
    app_version: string | null;
    status: ReportStatus;
    status_label: string;
    is_open: boolean;
    /** The SYNAPSE team's reply, when there is one. */
    reply: string | null;
    replied_at: string | null;
    resolved_at: string | null;
    updated_at: string | null;
    created_at: string | null;
    attachments: ReportAttachment[];
};

export type ProblemReportsPageProps = {
    reports: ProblemReport[];
    /** The report to open over the list (a notification's link), if any. */
    focus: string | null;
};
