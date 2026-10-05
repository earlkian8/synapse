import { FileArchive, FileText, Folder, ShieldCheck } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';
import { FORMATS, formatCount } from '../constants';
import type { ExportComposer } from '../hooks/use-export-composer';

type Props = {
    composer: ExportComposer;
    archiveName: string;
    retentionDays: number;
    /** Another export is being prepared, so this one has to wait. */
    busy: boolean;
};

/**
 * The next archive, previewed as the folders it will unzip into, with its
 * format and whether the uploaded files come along.
 */
export function ArchivePanel({
    composer,
    archiveName,
    retentionDays,
    busy,
}: Props) {
    const { form, chosen, records, filesAvailable } = composer;
    const extension = form.data.format;
    const withFiles = form.data.include_files && filesAvailable;
    const datasetError = Object.entries(form.errors).find(([key]) =>
        key.startsWith('datasets'),
    )?.[1];

    const entries = [
        { name: 'README.txt', icon: FileText, note: 'What is inside' },
        { name: 'manifest.json', icon: FileText, note: 'For software' },
        ...chosen.map((dataset) => ({
            name: `${dataset.key}/`,
            icon: Folder,
            note: `${dataset.tables} .${extension} ${dataset.tables === 1 ? 'file' : 'files'}`,
        })),
        ...(withFiles
            ? [{ name: 'files/', icon: Folder, note: 'Uploads' }]
            : []),
    ];

    return (
        <div className="flex flex-col overflow-hidden rounded-xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border">
            <div className="border-b border-border px-4 py-3">
                <h2 className="text-sm font-semibold">Your archive</h2>
                <p className="mt-0.5 text-xs text-muted-foreground">
                    {chosen.length === 0
                        ? 'Choose what to export.'
                        : `${chosen.length} ${chosen.length === 1 ? 'kind' : 'kinds'} of record, ${formatCount(records)} ${records === 1 ? 'record' : 'records'}`}
                </p>
            </div>

            {/* The archive as it will unzip. */}
            <div className="bg-muted/40 px-4 py-3 font-mono text-xs">
                <p className="flex items-center gap-2 font-medium break-all text-foreground">
                    <FileArchive className="size-4 shrink-0 text-[#0ABFBF]" />
                    {archiveName}
                </p>
                <ul className="mt-1.5 max-h-56 overflow-y-auto">
                    {entries.map((entry, index) => {
                        const last = index === entries.length - 1;
                        const Icon = entry.icon;

                        return (
                            <li
                                key={entry.name}
                                className="flex items-center gap-1.5 py-0.5 text-muted-foreground"
                            >
                                <span
                                    aria-hidden
                                    className="w-4 shrink-0 text-border"
                                >
                                    {last ? '└' : '├'}
                                </span>
                                <Icon className="size-3.5 shrink-0" />
                                <span className="truncate text-foreground">
                                    {entry.name}
                                </span>
                                <span className="ml-auto shrink-0 pl-2 font-sans">
                                    {entry.note}
                                </span>
                            </li>
                        );
                    })}
                </ul>
            </div>

            <div className="flex flex-col gap-4 px-4 py-4">
                <fieldset className="flex flex-col gap-2">
                    <legend className="mb-2 text-xs font-medium">Format</legend>
                    <div
                        role="radiogroup"
                        aria-label="Format"
                        className="grid grid-cols-2 gap-2"
                    >
                        {FORMATS.map((format) => {
                            const active = form.data.format === format.value;

                            return (
                                <button
                                    key={format.value}
                                    type="button"
                                    role="radio"
                                    aria-checked={active}
                                    onClick={() =>
                                        form.setData('format', format.value)
                                    }
                                    className={cn(
                                        'flex flex-col items-start gap-0.5 rounded-lg border px-3 py-2 text-left transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                        active
                                            ? 'border-[#0ABFBF] bg-[#0ABFBF]/[0.06]'
                                            : 'border-border hover:bg-muted/50',
                                    )}
                                >
                                    <span className="text-sm font-medium">
                                        {format.label}
                                    </span>
                                    <span className="text-[11px] leading-snug text-muted-foreground">
                                        {format.hint}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                    <InputError message={form.errors.format} />
                </fieldset>

                <div className="flex items-start justify-between gap-3">
                    <div className="flex flex-col gap-0.5">
                        <Label htmlFor="include-files" className="text-xs">
                            Include uploaded files
                        </Label>
                        <p className="text-[11px] leading-snug text-muted-foreground">
                            {filesAvailable
                                ? 'Photos, résumés, documents and certificates. Makes the archive larger.'
                                : 'None of the records chosen have uploads.'}
                        </p>
                    </div>
                    <Switch
                        id="include-files"
                        checked={withFiles}
                        disabled={!filesAvailable}
                        onCheckedChange={(checked) =>
                            form.setData('include_files', checked)
                        }
                    />
                </div>

                <p className="flex gap-2 rounded-lg bg-muted/60 px-3 py-2 text-[11px] leading-relaxed text-muted-foreground">
                    <ShieldCheck className="mt-0.5 size-3.5 shrink-0 text-[#0ABFBF]" />
                    <span>
                        Only you can download it, for {retentionDays} days.
                        Passwords and sign-in secrets are never included. It
                        holds personal information: keep it safe.
                    </span>
                </p>

                <InputError message={datasetError} />

                <Button
                    onClick={composer.submit}
                    disabled={form.processing || chosen.length === 0 || busy}
                >
                    {form.processing ? (
                        <Spinner />
                    ) : (
                        <FileArchive className="size-4" />
                    )}
                    {busy ? 'An export is being prepared' : 'Prepare archive'}
                </Button>
            </div>
        </div>
    );
}
