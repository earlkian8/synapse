/** Shapes for the Reports analytics workspace (see App\Support\Reports + ReportController). */

export type FilterOption = { value: string; label: string };

export type ReportFilter = {
    key: string;
    type: 'select' | 'daterange' | 'month' | 'search';
    label: string;
    options?: FilterOption[];
    default?: string | { start: string; end: string };
    placeholder?: string;
};

export type ReportColumn = {
    key: string;
    label: string;
    align?: 'left' | 'right';
    type?: 'text' | 'number' | 'date' | 'badge';
};

export type SummaryStat = { label: string; value: string };

export type ChartPoint = { label: string; value: number };

export type ChartSpec = {
    type: 'donut' | 'bars';
    title: string;
    segments?: ChartPoint[];
    bars?: ChartPoint[];
};

export type MlSignal = {
    key: string;
    label: string;
    tone: 'rose' | 'teal' | 'amber';
    href: string;
    value: string;
    detail: string;
    breakdown: Record<string, number>;
};

export type Insights = {
    available: boolean;
    reason?: string;
    retryable?: boolean;
    headline?: string;
    whats_happening?: string;
    what_happened?: string;
    why?: string;
    recommendations?: string[];
    generated_at?: string;
};

export type Paginator = {
    current_page: number;
    last_page: number;
    per_page: number;
    from: number;
    to: number;
    total: number;
};

export type ReportRow = Record<string, string | number>;

export type ReportListItem = {
    key: string;
    name: string;
    description: string;
    group: string;
};

export type ActiveReport = {
    key: string;
    name: string;
    description: string;
    group: string;
    filters: ReportFilter[];
    columns: ReportColumn[];
    applied: Record<string, string>;
    rows: ReportRow[];
    summary: SummaryStat[];
    charts: ChartSpec[];
    signals: MlSignal[];
    ai_enabled: boolean;
    meta: Paginator;
};

export type ReportsWorkspaceProps = {
    reports: ReportListItem[];
    active: ActiveReport | null;
};
