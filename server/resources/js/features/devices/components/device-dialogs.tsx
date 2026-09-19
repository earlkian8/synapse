import { router, useForm } from '@inertiajs/react';
import {
    Check,
    Copy,
    FileUp,
    KeyRound,
    ScanLine,
    TabletSmartphone,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import { FormSelect } from '@/components/form-select';
import InputError from '@/components/input-error';
import {
    Modal,
    ModalBody,
    ModalContent,
    ModalFooter,
    ModalHeader,
    ModalIcon,
} from '@/components/modal';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useClipboard } from '@/hooks/use-clipboard';
import { cn } from '@/lib/utils';
import { deviceRoutes } from '../routes';
import type {
    AttendanceDevice,
    CsvField,
    CsvMapping,
    ImportResult,
    IssuedKey,
} from '../types';

const NONE = '__none__';

export const DEVICE_TYPE_LABELS: Record<AttendanceDevice['type'], string> = {
    kiosk: 'Kiosk',
    biometric: 'Biometric scanner',
};

export function DeviceIcon({
    type,
    className,
}: {
    type: AttendanceDevice['type'];
    className?: string;
}) {
    return type === 'kiosk' ? (
        <TabletSmartphone className={className} />
    ) : (
        <ScanLine className={className} />
    );
}

// ── Register or edit ─────────────────────────────────────────────────────────

/**
 * Register a device, or rename it and move it. Its kind is chosen once: a
 * kiosk's key opens the kiosk, a scanner's only sends punches.
 */
export function DeviceFormModal({
    device,
    open,
    onOpenChange,
    locations,
}: {
    device: AttendanceDevice | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    locations: { id: number; name: string }[];
}) {
    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="md">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <DeviceIcon type={device?.type ?? 'biometric'} />
                        </ModalIcon>
                    }
                    title={device ? device.name : 'Register a device'}
                    description={
                        device
                            ? 'Rename it or say where it stands. Its kind cannot change.'
                            : 'A kiosk is a shared tablet people punch on. A biometric scanner sends what it records.'
                    }
                />
                {open && (
                    <DeviceForm
                        key={device?.id ?? 'new'}
                        device={device}
                        locations={locations}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function DeviceForm({
    device,
    locations,
    onDone,
}: {
    device: AttendanceDevice | null;
    locations: { id: number; name: string }[];
    onDone: () => void;
}) {
    const { data, setData, post, processing, errors, transform } = useForm({
        name: device?.name ?? '',
        type: device?.type ?? ('biometric' as AttendanceDevice['type']),
        work_location_id: device?.work_location_id
            ? String(device.work_location_id)
            : '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        transform((current) => ({
            name: current.name,
            work_location_id: current.work_location_id || null,
            ...(device ? {} : { type: current.type }),
        }));

        post(device ? deviceRoutes.update(device.hashid) : deviceRoutes.store, {
            preserveScroll: true,
            onSuccess: onDone,
        });
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <ModalBody className="flex flex-col gap-4">
                <FormField label="Name" required error={errors.name}>
                    <Input
                        value={data.name}
                        onChange={(event) =>
                            setData('name', event.target.value)
                        }
                        placeholder="Front door scanner"
                        autoFocus
                    />
                </FormField>

                {!device && (
                    <FormField label="Kind" required group error={errors.type}>
                        <div
                            role="radiogroup"
                            className="grid grid-cols-2 gap-2"
                        >
                            {(['biometric', 'kiosk'] as const).map((type) => (
                                <button
                                    key={type}
                                    type="button"
                                    role="radio"
                                    aria-checked={data.type === type}
                                    onClick={() => setData('type', type)}
                                    className={cn(
                                        'flex items-center gap-2 rounded-lg border px-3 py-2.5 text-left text-sm transition-colors',
                                        data.type === type
                                            ? 'border-[#0ABFBF] bg-[#0ABFBF]/10 font-medium'
                                            : 'border-border hover:bg-muted',
                                    )}
                                >
                                    <DeviceIcon
                                        type={type}
                                        className="size-4 shrink-0"
                                    />
                                    {DEVICE_TYPE_LABELS[type]}
                                </button>
                            ))}
                        </div>
                    </FormField>
                )}

                <FormField
                    label="Location"
                    hint="Where it stands. Its punches are recorded as made there."
                    error={errors.work_location_id}
                >
                    <FormSelect
                        value={data.work_location_id || NONE}
                        placeholder="Not at a location"
                        noneValue={NONE}
                        onChange={(value) =>
                            setData(
                                'work_location_id',
                                value === NONE ? '' : value,
                            )
                        }
                        options={locations.map((location) => ({
                            value: String(location.id),
                            label: location.name,
                        }))}
                    />
                </FormField>
            </ModalBody>
            <ModalFooter>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
                <Button type="submit" disabled={processing}>
                    {processing && <Spinner />}
                    {device ? 'Save device' : 'Register device'}
                </Button>
            </ModalFooter>
        </form>
    );
}

// ── The key, once ────────────────────────────────────────────────────────────

/**
 * The key, the one time it exists in the clear, with what to do with it. It is
 * held in this dialog's memory only; closing it is the last anybody sees of it.
 */
export function KeyDialog({
    issued,
    endpoints,
    onClose,
}: {
    issued: IssuedKey | null;
    endpoints: { punches: string; kiosk: string };
    onClose: () => void;
}) {
    const kioskLink = issued
        ? `${endpoints.kiosk}#key=${encodeURIComponent(issued.key)}`
        : '';

    return (
        <Modal
            open={issued !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <ModalContent size="lg">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <KeyRound />
                        </ModalIcon>
                    }
                    title={issued ? `${issued.name}’s key` : 'Device key'}
                    description="Copy it now. It is not stored anywhere it can be read back, so it will not be shown again — a lost key is replaced, not recovered."
                />
                {issued && (
                    <ModalBody className="flex flex-col gap-4">
                        <CopyRow label="Key" value={issued.key} />

                        {issued.type === 'kiosk' ? (
                            <div className="space-y-2">
                                <p className="text-sm">
                                    Open this link once on the tablet. It keeps
                                    the key and becomes the kiosk; nobody signs
                                    in on it.
                                </p>
                                <CopyRow label="Kiosk link" value={kioskLink} />
                            </div>
                        ) : (
                            <div className="space-y-2 text-sm">
                                <p>
                                    Have the scanner send its punches to this
                                    address, with the key as a bearer token.
                                    Sending one again is safe: it is recognised
                                    by its own id.
                                </p>
                                <CopyRow
                                    label="Address"
                                    value={endpoints.punches}
                                />
                                <pre className="overflow-x-auto rounded-lg bg-muted px-3 py-2.5 text-xs leading-relaxed">
                                    {`POST ${endpoints.punches}
Authorization: Bearer <key>
Content-Type: application/json

{
  "punches": [
    { "external_id": "10231", "employee_ref": "EMP-0042",
      "punched_at": "2026-09-19 08:02:11", "type": "clock_in" }
  ]
}`}
                                </pre>
                                <p className="text-xs text-muted-foreground">
                                    `type` may be left out; it is worked out
                                    from the day. A time with no offset is read
                                    on the company’s clock. A scanner that
                                    cannot send can be imported from its CSV
                                    export instead.
                                </p>
                            </div>
                        )}
                    </ModalBody>
                )}
                <ModalFooter>
                    <Button type="button" onClick={onClose}>
                        I’ve copied it
                    </Button>
                </ModalFooter>
            </ModalContent>
        </Modal>
    );
}

function CopyRow({ label, value }: { label: string; value: string }) {
    const [copied, copy] = useClipboard();
    const done = copied === value;

    return (
        <div className="space-y-1.5">
            <span className="text-xs font-medium text-muted-foreground">
                {label}
            </span>
            <div className="flex items-center gap-2">
                <code className="min-w-0 flex-1 truncate rounded-md border border-border bg-muted/50 px-2.5 py-2 font-mono text-xs select-all">
                    {value}
                </code>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => void copy(value)}
                >
                    {done ? (
                        <Check className="size-4" />
                    ) : (
                        <Copy className="size-4" />
                    )}
                    {done ? 'Copied' : 'Copy'}
                </Button>
            </div>
        </div>
    );
}

// ── CSV import ───────────────────────────────────────────────────────────────

const FIELDS: { field: CsvField; label: string; hint?: string }[] = [
    {
        field: 'employee_ref',
        label: 'Who',
        hint: 'Employee number or enrolment ID',
    },
    {
        field: 'punched_at',
        label: 'Date and time',
        hint: 'One column with both',
    },
    { field: 'date', label: 'Date', hint: 'When the time is split in two' },
    { field: 'time', label: 'Time' },
    {
        field: 'type',
        label: 'In / out',
        hint: 'Optional: worked out when missing',
    },
    {
        field: 'external_id',
        label: 'Record id',
        hint: 'Optional: stops a re-import doubling up',
    },
];

/**
 * Feed a scanner that cannot push from its CSV export. The header is read here,
 * in the browser, so the columns can be chosen before anything is sent; the
 * choice is remembered for the next file from the same device.
 */
export function ImportDialog({
    device,
    result,
    onClose,
}: {
    device: AttendanceDevice | null;
    result: ImportResult | null;
    onClose: () => void;
}) {
    return (
        <Modal
            open={device !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <ModalContent size="lg">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <FileUp />
                        </ModalIcon>
                    }
                    title={
                        device
                            ? `Import punches for ${device.name}`
                            : 'Import punches'
                    }
                    description="Every row is recorded as the device saw it — out of order or twice included — and flagged for sign-off when it doesn’t add up."
                />
                {device && (
                    <ImportForm
                        key={device.id}
                        device={device}
                        result={
                            result?.device === device.hashid ? result : null
                        }
                        onClose={onClose}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function ImportForm({
    device,
    result,
    onClose,
}: {
    device: AttendanceDevice;
    result: ImportResult | null;
    onClose: () => void;
}) {
    const [file, setFile] = useState<File | null>(null);
    const [headers, setHeaders] = useState<string[]>([]);
    const [mapping, setMapping] = useState<CsvMapping>(
        device.csv_mapping ?? {},
    );
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const pick = async (chosen: File | null) => {
        setFile(chosen);
        setErrors({});

        if (!chosen) {
            setHeaders([]);

            return;
        }

        const found = readHeader(await chosen.slice(0, 64 * 1024).text());
        setHeaders(found);

        // Keep a remembered choice only when this file has that column.
        setMapping((current) =>
            Object.fromEntries(
                Object.entries(current).filter(
                    ([, column]) => column && found.includes(column),
                ),
            ),
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!file) {
            return;
        }

        router.post(
            deviceRoutes.importCsv(device.hashid),
            { file, mapping },
            {
                forceFormData: true,
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (bag) => setErrors(bag),
            },
        );
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <ModalBody className="flex flex-col gap-4">
                {result && <ImportOutcome result={result} />}

                <FormField label="CSV file" required error={errors.file}>
                    <Input
                        type="file"
                        accept=".csv,text/csv,text/plain"
                        onChange={(event) =>
                            void pick(event.target.files?.[0] ?? null)
                        }
                    />
                </FormField>

                {headers.length > 0 && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {FIELDS.map(({ field, label, hint }) => (
                            <FormField
                                key={field}
                                label={label}
                                hint={hint}
                                required={field === 'employee_ref'}
                                error={errors[`mapping.${field}`]}
                            >
                                <FormSelect
                                    value={mapping[field] ?? NONE}
                                    placeholder="Not in this file"
                                    noneValue={NONE}
                                    onChange={(value) =>
                                        setMapping((current) => ({
                                            ...current,
                                            [field]:
                                                value === NONE ? null : value,
                                        }))
                                    }
                                    options={headers.map((header) => ({
                                        value: header,
                                        label: header,
                                    }))}
                                />
                            </FormField>
                        ))}
                    </div>
                )}
                <InputError message={errors.mapping} />
            </ModalBody>
            <ModalFooter>
                <Button type="button" variant="ghost" onClick={onClose}>
                    Close
                </Button>
                <Button
                    type="submit"
                    disabled={processing || !file || !mapping.employee_ref}
                >
                    {processing && <Spinner />}
                    Import punches
                </Button>
            </ModalFooter>
        </form>
    );
}

/** What the last import did, and the rows that need a look. */
function ImportOutcome({ result }: { result: ImportResult }) {
    return (
        <div className="space-y-2 rounded-lg border border-border bg-muted/40 px-3 py-2.5 text-sm">
            <p>
                <span className="font-medium tabular-nums">
                    {result.accepted}
                </span>{' '}
                recorded
                {result.duplicates > 0 && (
                    <>
                        ,{' '}
                        <span className="tabular-nums">
                            {result.duplicates}
                        </span>{' '}
                        already imported
                    </>
                )}
                {result.rejected > 0 && (
                    <>
                        ,{' '}
                        <span className="font-medium text-rose-600 tabular-nums dark:text-rose-400">
                            {result.rejected}
                        </span>{' '}
                        not recorded
                    </>
                )}
                .
            </p>
            {result.problems.map((problem) => (
                <p key={problem} className="text-xs text-muted-foreground">
                    {problem}
                </p>
            ))}
            {result.issues.length > 0 && (
                <ul className="max-h-40 space-y-1 overflow-y-auto text-xs">
                    {result.issues.map((issue) => (
                        <li key={`${issue.line}-${issue.message}`}>
                            <span className="text-muted-foreground tabular-nums">
                                Line {issue.line}:
                            </span>{' '}
                            {issue.message}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/** The header row of a CSV, however it is delimited. */
function readHeader(text: string): string[] {
    const line = text.replace(/^\uFEFF/, '').split(/\r?\n/)[0] ?? '';
    const delimiter = [',', ';', '\t'].sort(
        (a, b) => line.split(b).length - line.split(a).length,
    )[0];

    const cells: string[] = [];
    let cell = '';
    let quoted = false;

    for (let i = 0; i < line.length; i++) {
        const char = line[i];

        if (char === '"') {
            if (quoted && line[i + 1] === '"') {
                cell += '"';
                i++;
            } else {
                quoted = !quoted;
            }
        } else if (char === delimiter && !quoted) {
            cells.push(cell.trim());
            cell = '';
        } else {
            cell += char;
        }
    }

    cells.push(cell.trim());

    return cells.filter((name) => name !== '');
}
