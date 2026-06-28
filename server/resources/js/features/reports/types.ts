/** Shapes for the Reports module (see App\Support\Reports + ReportController). */

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

export type ReportMeta = {
    key: string;
    name: string;
    description: string;
    group: string;
    filters: ReportFilter[];
    columns: ReportColumn[];
};

export type SummaryStat = { label: string; value: string };

export type Paginator = {
    current_page: number;
    last_page: number;
    per_page: number;
    from: number;
    to: number;
    total: number;
};

export type ReportRow = Record<string, string | number>;

export type ReportShowProps = {
    report: ReportMeta;
    applied: Record<string, string>;
    rows: ReportRow[];
    summary: SummaryStat[];
    meta: Paginator;
};

export type ReportListItem = {
    key: string;
    name: string;
    description: string;
    group: string;
};

export type ReportsIndexProps = { reports: ReportListItem[] };
