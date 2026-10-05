import { Head, usePage, usePoll } from '@inertiajs/react';
import { DatabaseBackup, Lock } from 'lucide-react';
import { useEffect, useState } from 'react';
import { deleteExport } from '@/features/data-export/api';
import { ArchivePanel } from '@/features/data-export/components/archive-panel';
import { DatasetPicker } from '@/features/data-export/components/dataset-picker';
import {
    describeDatasets,
    ExportHistory,
} from '@/features/data-export/components/export-history';
import {
    POLL_INTERVAL_MS,
    isInProgress,
} from '@/features/data-export/constants';
import { useExportComposer } from '@/features/data-export/hooks/use-export-composer';
import type {
    DataExportItem,
    DataExportPageProps,
} from '@/features/data-export/types';
import { ConfirmDialog } from '@/features/users/components/confirm-dialog';

export default function DataExportIndex() {
    const { datasets, exports, can, retention_days, archive_name } =
        usePage<DataExportPageProps>().props;

    const composer = useExportComposer(datasets);
    const [deleting, setDeleting] = useState<DataExportItem | null>(null);
    const [processing, setProcessing] = useState(false);

    const busy = exports.some((item) => isInProgress(item.status));

    // While an archive is being written, watch the history for it to finish.
    const { start, stop } = usePoll(
        POLL_INTERVAL_MS,
        { only: ['exports'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (busy) {
            start();
        } else {
            stop();
        }

        return stop;
    }, [busy, start, stop]);

    const confirmDelete = () => {
        if (!deleting) {
            return;
        }

        deleteExport(deleting.hashid, {
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setDeleting(null);
            },
        });
    };

    return (
        <>
            <Head title="Data Export" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-1">
                    <h1 className="flex items-center gap-2 text-xl font-semibold tracking-tight">
                        <DatabaseBackup className="size-5 text-[#0ABFBF]" />
                        Data Export
                    </h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        A copy of your company's records in one archive, to keep
                        for your files or to move to another system. Every
                        record is included as stored, archived ones too.
                    </p>
                </div>

                {can.create && datasets.length > 0 && (
                    <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start">
                        <DatasetPicker
                            datasets={datasets}
                            selected={composer.selected}
                            disabled={composer.form.processing}
                            onToggle={composer.toggle}
                            onToggleMany={composer.toggleMany}
                        />

                        <div className="lg:sticky lg:top-4">
                            <ArchivePanel
                                composer={composer}
                                archiveName={archive_name}
                                retentionDays={retention_days}
                                busy={busy}
                            />
                        </div>
                    </div>
                )}

                {can.create && datasets.length === 0 && (
                    <Notice>
                        Your role does not let you view any of the records an
                        export holds, so there is nothing for you to export.
                    </Notice>
                )}

                {!can.create && (
                    <Notice>
                        You can see the archives prepared in this workspace.
                        Preparing one needs the permission to export the
                        workspace's records.
                    </Notice>
                )}

                <ExportHistory exports={exports} onDelete={setDeleting} />
            </div>

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title="Delete this archive?"
                description={
                    deleting ? (
                        <>
                            The archive of{' '}
                            <span className="font-medium text-foreground">
                                {describeDatasets(deleting)}
                            </span>{' '}
                            will be deleted, and can no longer be downloaded.
                            Your records in SYNAPSE are not affected.
                        </>
                    ) : (
                        ''
                    )
                }
                confirmLabel="Delete archive"
                destructive
                processing={processing}
                onConfirm={confirmDelete}
            />
        </>
    );
}

function Notice({ children }: { children: React.ReactNode }) {
    return (
        <p className="flex max-w-2xl items-start gap-2 rounded-xl border border-sidebar-border/70 bg-card px-4 py-3 text-sm text-muted-foreground dark:border-sidebar-border">
            <Lock className="mt-0.5 size-4 shrink-0" />
            <span>{children}</span>
        </p>
    );
}

DataExportIndex.layout = {
    breadcrumbs: [
        {
            title: 'Data Export',
            href: '/system/data-export',
        },
    ],
};
