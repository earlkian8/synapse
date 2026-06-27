import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    Download,
    FileSpreadsheet,
    Upload,
    X,
} from 'lucide-react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { importUsers } from '../api';
import { userRoutes } from '../routes';
import type { ImportResult } from '../types';

/** Build and download a CSV error report from the rejected rows. */
function downloadErrorReport(result: ImportResult) {
    const rows = [
        ['Row', 'Email', 'Errors'],
        ...result.errors.map((e) => [
            String(e.row),
            e.email ?? '',
            e.messages.join('; '),
        ]),
    ];

    const csv = rows
        .map((cells) =>
            cells.map((cell) => `"${cell.replace(/"/g, '""')}"`).join(','),
        )
        .join('\r\n');

    const url = URL.createObjectURL(
        new Blob([csv], { type: 'text/csv;charset=utf-8;' }),
    );
    const link = document.createElement('a');
    link.href = url;
    link.download = `users-import-errors-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
    URL.revokeObjectURL(url);
}

export function ImportUsersDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [file, setFile] = useState<File | null>(null);
    const [processing, setProcessing] = useState(false);
    const [result, setResult] = useState<ImportResult | null>(null);
    const [dragging, setDragging] = useState(false);
    const inputRef = useRef<HTMLInputElement>(null);

    const handleOpenChange = (next: boolean) => {
        if (!next) {
            // Reset everything when the dialog closes.
            setFile(null);
            setResult(null);
            setProcessing(false);
        }

        onOpenChange(next);
    };

    const pick = (picked: File | null) => {
        setFile(picked);
        setResult(null);
    };

    const submit = async () => {
        if (!file) {
            return;
        }

        setProcessing(true);

        try {
            const res = await importUsers(file);
            setResult(res);

            if (res.created > 0) {
                toast.success(
                    `Imported ${res.created} user${res.created === 1 ? '' : 's'}.`,
                );
                // Refresh the table + stats without leaving the dialog.
                router.reload({ only: ['users', 'stats'] });
            } else if (res.failed > 0) {
                toast.error('No users imported — see the errors below.');
            }
        } catch (error) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'The import could not be processed.',
            );
        } finally {
            setProcessing(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <FileSpreadsheet className="size-5 text-[#0ABFBF]" />
                        Import users
                    </DialogTitle>
                    <DialogDescription>
                        Bulk-create accounts from a CSV. Each new user is
                        created unverified and emailed a verification link — no
                        passwords are imported.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-col gap-4">
                    {/* Template + format help */}
                    <div className="flex flex-col gap-2 rounded-xl border border-sidebar-border/70 bg-card/50 p-3 text-sm dark:border-sidebar-border">
                        <div className="flex items-center justify-between gap-2">
                            <span className="text-muted-foreground">
                                New to this? Start from the template.
                            </span>
                            <Button variant="outline" size="sm" asChild>
                                <a href={userRoutes.importTemplate}>
                                    <Download className="size-4" />
                                    Template
                                </a>
                            </Button>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Required columns:{' '}
                            <code className="font-mono">first_name</code>,{' '}
                            <code className="font-mono">last_name</code>,{' '}
                            <code className="font-mono">email</code>. Optional:{' '}
                            middle_name, suffix, phone_number, employee_id,
                            is_active, role. Up to 200 rows per file.
                        </p>
                    </div>

                    {/* File picker */}
                    <div>
                        <input
                            ref={inputRef}
                            type="file"
                            accept=".csv,text/csv"
                            className="sr-only"
                            onChange={(e) => pick(e.target.files?.[0] ?? null)}
                        />
                        {file ? (
                            <div className="flex items-center gap-3 rounded-xl border border-sidebar-border/70 bg-card px-3 py-2.5 dark:border-sidebar-border">
                                <FileSpreadsheet className="size-5 shrink-0 text-[#0ABFBF]" />
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium">
                                        {file.name}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {(file.size / 1024).toFixed(1)} KB
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => pick(null)}
                                    className="rounded-sm p-1 text-muted-foreground hover:text-foreground"
                                    aria-label="Remove file"
                                >
                                    <X className="size-4" />
                                </button>
                            </div>
                        ) : (
                            <button
                                type="button"
                                onClick={() => inputRef.current?.click()}
                                onDragOver={(e) => {
                                    e.preventDefault();
                                    setDragging(true);
                                }}
                                onDragLeave={() => setDragging(false)}
                                onDrop={(e) => {
                                    e.preventDefault();
                                    setDragging(false);
                                    pick(e.dataTransfer.files?.[0] ?? null);
                                }}
                                className={cn(
                                    'flex w-full flex-col items-center justify-center gap-2 rounded-xl border border-dashed px-4 py-8 text-center transition-colors',
                                    dragging
                                        ? 'border-[#0ABFBF] bg-[#0ABFBF]/5'
                                        : 'border-sidebar-border/70 bg-card/50 hover:border-[#0ABFBF]/50 hover:bg-[#0ABFBF]/5 dark:border-sidebar-border',
                                )}
                            >
                                <span className="flex size-10 items-center justify-center rounded-full bg-[#0ABFBF]/10 text-[#0ABFBF]">
                                    <Upload className="size-5" />
                                </span>
                                <span className="text-sm font-medium">
                                    Choose a CSV file
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    or drop it here
                                </span>
                            </button>
                        )}
                    </div>

                    {/* Result */}
                    {result && <ImportSummary result={result} />}
                </div>

                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => handleOpenChange(false)}
                    >
                        {result ? 'Done' : 'Cancel'}
                    </Button>
                    <Button onClick={submit} disabled={!file || processing}>
                        {processing ? (
                            <Spinner />
                        ) : (
                            <Upload className="size-4" />
                        )}
                        {processing ? 'Importing…' : 'Import'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function ImportSummary({ result }: { result: ImportResult }) {
    const hasErrors = result.errors.length > 0;

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center gap-2 text-sm">
                <span
                    className={cn(
                        'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 font-medium',
                        result.created > 0
                            ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                            : 'border-slate-500/25 bg-slate-500/10 text-slate-600 dark:text-slate-300',
                    )}
                >
                    <CheckCircle2 className="size-3.5" />
                    {result.created} created
                </span>
                {result.failed > 0 && (
                    <span className="inline-flex items-center gap-1.5 rounded-full border border-rose-500/30 bg-rose-500/10 px-2.5 py-1 font-medium text-rose-600 dark:text-rose-400">
                        <AlertTriangle className="size-3.5" />
                        {result.failed} skipped
                    </span>
                )}
            </div>

            {result.verification_failed > 0 && (
                <p className="text-xs text-amber-600 dark:text-amber-400">
                    {result.verification_failed} verification email
                    {result.verification_failed === 1 ? '' : 's'} could not be
                    sent — those users can request a new link from their
                    actions.
                </p>
            )}

            {hasErrors && (
                <div className="flex flex-col gap-2 rounded-xl border border-rose-500/25 bg-rose-500/5 p-3">
                    <div className="flex items-center justify-between gap-2">
                        <span className="text-xs font-medium tracking-wide text-rose-600 uppercase dark:text-rose-400">
                            Rows needing attention
                        </span>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="h-7 text-xs text-muted-foreground"
                            onClick={() => downloadErrorReport(result)}
                        >
                            <Download className="size-3.5" />
                            Error report
                        </Button>
                    </div>
                    <ul className="max-h-44 divide-y divide-border overflow-y-auto text-sm">
                        {result.errors.map((error, index) => (
                            <li
                                key={`${error.row}-${index}`}
                                className="flex flex-col gap-0.5 py-1.5 first:pt-0"
                            >
                                <span className="text-xs font-medium">
                                    Row {error.row}
                                    {error.email ? ` · ${error.email}` : ''}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {error.messages.join(' · ')}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
