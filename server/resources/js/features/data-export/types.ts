export type ExportFormat = 'csv' | 'json';

export type ExportStatus =
    'queued' | 'building' | 'ready' | 'failed' | 'expired';

/** One kind of record the viewer may export (DataExportCatalogue). */
export type Dataset = {
    key: string;
    label: string;
    /** The sidebar section its screens sit in. */
    section: string;
    description: string;
    /** How many tables (files) it writes. */
    tables: number;
    /** Whether it names uploaded files that can come along. */
    has_files: boolean;
    /** The rows it holds today, across its tables. */
    records: number;
};

export type ExportFiles = {
    count: number;
    bytes: number;
    missing: number;
    /** Left out because copying them would have outrun the build's time. */
    skipped?: number;
};

/** One archive in the history (DataExportResource). */
export type DataExportItem = {
    hashid: string;
    status: ExportStatus;
    format: ExportFormat;
    datasets: { key: string; label: string; rows: number | null }[];
    include_files: boolean;
    rows: number | null;
    files: ExportFiles | null;
    size_bytes: number | null;
    error: string | null;
    requested_by: {
        name: string;
        initials: string;
        avatar: string | null;
    } | null;
    is_mine: boolean;
    created_at: string | null;
    completed_at: string | null;
    expires_at: string | null;
    download_count: number;
    last_downloaded_at: string | null;
    can_download: boolean;
    can_delete: boolean;
};

export type DataExportPageProps = {
    datasets: Dataset[];
    exports: DataExportItem[];
    can: { create: boolean };
    retention_days: number;
    /** What the next archive will be called. */
    archive_name: string;
};
