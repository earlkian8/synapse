import { router } from '@inertiajs/react';
import {
    Award,
    Briefcase,
    Building2,
    Clock,
    FileText,
    Pencil,
    Trash2,
    Upload,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { DOCUMENT_TYPE_OPTIONS, TYPE_LABELS } from '../constants';
import { employeeRoutes } from '../routes';
import type { EmployeeDetail, ManagedEmployee } from '../types';
import { EmployeeAvatar } from './employee-avatar';
import { EmployeeStatusBadge } from './employee-status-badge';

type Props = {
    employee: ManagedEmployee | null;
    open: boolean;
    canEdit: boolean;
    canManageDocuments: boolean;
    onOpenChange: (open: boolean) => void;
    onEdit: (employee: ManagedEmployee) => void;
};

type Tab = 'profile' | 'documents' | 'certifications' | 'history';

const TABS: { value: Tab; label: string }[] = [
    { value: 'profile', label: 'Profile' },
    { value: 'documents', label: 'Documents' },
    { value: 'certifications', label: 'Certifications' },
    { value: 'history', label: 'History' },
];

export function EmployeeDetailSheet({
    employee,
    open,
    canEdit,
    canManageDocuments,
    onOpenChange,
    onEdit,
}: Props) {
    const [detail, setDetail] = useState<EmployeeDetail | null>(null);
    const [tab, setTab] = useState<Tab>('profile');
    const [openedId, setOpenedId] = useState<number | null>(null);

    // Fetch the full record (with sub-records) when the drawer opens. State is
    // only set in the async callback, never synchronously in the effect body.
    const load = useCallback(() => {
        if (!employee) {
            return;
        }

        fetch(employeeRoutes.show(employee.id), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((res) => res.json())
            .then((json) => setDetail(json.data as EmployeeDetail));
    }, [employee]);

    useEffect(() => {
        if (open && employee) {
            load();
        }
    }, [open, employee, load]);

    // Render-phase derived resets (avoids state-syncing effects).
    if (open && employee && employee.id !== openedId) {
        setOpenedId(employee.id);
        setTab('profile');
        setDetail(null);
    }

    if (!open && openedId !== null) {
        setOpenedId(null);
    }

    const current = detail ?? (employee as EmployeeDetail | null);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-2xl"
            >
                <SheetHeader className="border-b border-border px-6 py-5">
                    {current && (
                        <div className="flex items-start gap-4">
                            <EmployeeAvatar
                                name={current.full_name}
                                initials={current.initials}
                                photo={current.photo}
                                className="size-14"
                            />
                            <div className="min-w-0 flex-1">
                                <SheetTitle className="truncate text-lg">
                                    {current.full_name}
                                </SheetTitle>
                                <p className="font-mono text-xs text-muted-foreground">
                                    {current.employee_no}
                                </p>
                                <div className="mt-2 flex flex-wrap items-center gap-2">
                                    <EmployeeStatusBadge
                                        status={current.status}
                                    />
                                    <span className="text-xs text-muted-foreground">
                                        {current.position?.title ??
                                            'No position'}
                                        {current.department
                                            ? ` · ${current.department.name}`
                                            : ''}
                                    </span>
                                </div>
                            </div>
                            {canEdit && current.status !== 'archived' && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => onEdit(current)}
                                >
                                    <Pencil className="size-4" />
                                    Edit
                                </Button>
                            )}
                        </div>
                    )}
                </SheetHeader>

                {/* Tabs */}
                <div className="flex gap-1 border-b border-border px-4 pt-2">
                    {TABS.map((t) => (
                        <button
                            key={t.value}
                            type="button"
                            onClick={() => setTab(t.value)}
                            className={cn(
                                'relative px-3 py-2 text-sm font-medium transition-colors',
                                tab === t.value
                                    ? 'text-foreground'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {t.label}
                            {tab === t.value && (
                                <span className="absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-[#0ABFBF]" />
                            )}
                        </button>
                    ))}
                </div>

                <div className="px-6 py-5">
                    {tab === 'profile' && current && <ProfileTab e={current} />}

                    {tab !== 'profile' && !detail && (
                        <div className="flex items-center justify-center py-16 text-muted-foreground">
                            <Spinner />
                        </div>
                    )}

                    {tab === 'documents' && detail && (
                        <DocumentsTab
                            e={detail}
                            canManage={canManageDocuments}
                            onChanged={load}
                        />
                    )}
                    {tab === 'certifications' && detail && (
                        <CertificationsTab
                            e={detail}
                            canManage={canManageDocuments}
                            onChanged={load}
                        />
                    )}
                    {tab === 'history' && detail && <HistoryTab e={detail} />}
                </div>
            </SheetContent>
        </Sheet>
    );
}

// ── Profile ──────────────────────────────────────────────────────────────────

function ProfileTab({ e }: { e: EmployeeDetail }) {
    const money = (v: string | null) =>
        v
            ? `₱${Number(v).toLocaleString(undefined, { minimumFractionDigits: 2 })}`
            : '—';

    return (
        <div className="space-y-6">
            <Group icon={Building2} title="Employment">
                <Row label="Department" value={e.department?.name} />
                <Row label="Position" value={e.position?.title} />
                <Row label="Manager" value={e.manager?.full_name} />
                <Row label="Work schedule" value={e.work_schedule?.name} />
                <Row label="Type" value={TYPE_LABELS[e.employment_type]} />
                <Row label="Date hired" value={e.date_hired} />
                <Row label="Date regularized" value={e.date_regularized} />
                <Row label="Tenure" value={e.tenure_human} />
            </Group>

            <Group icon={Briefcase} title="Personal">
                <Row label="Birth date" value={e.birth_date} />
                <Row label="Gender" value={e.gender} capitalize />
                <Row label="Civil status" value={e.civil_status} capitalize />
                <Row label="Email" value={e.email} />
                <Row label="Phone" value={e.phone} />
                <Row label="Address" value={e.address} />
            </Group>

            <Group icon={Award} title="Compensation & IDs">
                <Row label="Basic salary" value={money(e.basic_salary)} />
                <Row label="Bank" value={e.bank_name} />
                <Row label="Bank account" value={e.bank_account_no} />
                <Row label="TIN" value={e.tin} />
                <Row label="SSS" value={e.sss_no} />
                <Row label="PhilHealth" value={e.philhealth_no} />
                <Row label="Pag-IBIG" value={e.pagibig_no} />
            </Group>

            {e.user && (
                <Group icon={Briefcase} title="System account">
                    <Row label="Linked login" value={e.user.email} />
                </Group>
            )}
        </div>
    );
}

function Group({
    icon: Icon,
    title,
    children,
}: {
    icon: typeof Building2;
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section>
            <h3 className="mb-2 flex items-center gap-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                <Icon className="size-3.5" />
                {title}
            </h3>
            <dl className="grid grid-cols-1 gap-x-6 gap-y-1.5 sm:grid-cols-2">
                {children}
            </dl>
        </section>
    );
}

function Row({
    label,
    value,
    capitalize = false,
}: {
    label: string;
    value: string | null | undefined;
    capitalize?: boolean;
}) {
    return (
        <div className="flex items-center justify-between gap-4 border-b border-border/50 py-1.5">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd
                className={cn(
                    'text-right text-sm font-medium',
                    capitalize && 'capitalize',
                )}
            >
                {value || '—'}
            </dd>
        </div>
    );
}

// ── Documents ────────────────────────────────────────────────────────────────

function DocumentsTab({
    e,
    canManage,
    onChanged,
}: {
    e: EmployeeDetail;
    canManage: boolean;
    onChanged: () => void;
}) {
    const [title, setTitle] = useState('');
    const [type, setType] = useState('other');
    const [file, setFile] = useState<File | null>(null);
    const [busy, setBusy] = useState(false);

    const upload = () => {
        if (!file || !title.trim()) {
            return;
        }

        const formData = new FormData();
        formData.append('title', title);
        formData.append('type', type);
        formData.append('file', file);

        router.post(employeeRoutes.documents(e.id), formData, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: () => {
                setTitle('');
                setFile(null);
                setType('other');
                onChanged();
            },
        });
    };

    const remove = (docId: number) =>
        router.delete(employeeRoutes.document(e.id, docId), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: onChanged,
        });

    return (
        <div className="space-y-4">
            {canManage && (
                <div className="space-y-3 rounded-lg border border-border bg-muted/30 p-4">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div>
                            <Label className="mb-1.5 block">Title</Label>
                            <Input
                                value={title}
                                onChange={(ev) => setTitle(ev.target.value)}
                                placeholder="e.g. Employment Contract"
                            />
                        </div>
                        <div>
                            <Label className="mb-1.5 block">Type</Label>
                            <Select value={type} onValueChange={setType}>
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {DOCUMENT_TYPE_OPTIONS.map((o) => (
                                        <SelectItem
                                            key={o.value}
                                            value={o.value}
                                        >
                                            {o.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    <Input
                        type="file"
                        accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx"
                        onChange={(ev) => setFile(ev.target.files?.[0] ?? null)}
                    />
                    <Button
                        size="sm"
                        onClick={upload}
                        disabled={busy || !file || !title.trim()}
                    >
                        {busy ? <Spinner /> : <Upload className="size-4" />}
                        Upload document
                    </Button>
                </div>
            )}

            {e.documents.length === 0 ? (
                <EmptyState icon={FileText} text="No documents on file yet." />
            ) : (
                <ul className="divide-y divide-border rounded-lg border border-border">
                    {e.documents.map((doc) => (
                        <li
                            key={doc.id}
                            className="flex items-center gap-3 px-3 py-2.5"
                        >
                            <FileText className="size-4 shrink-0 text-muted-foreground" />
                            <div className="min-w-0 flex-1">
                                <a
                                    href={doc.url ?? '#'}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="block truncate text-sm font-medium hover:underline"
                                >
                                    {doc.title}
                                </a>
                                <p className="text-[11px] text-muted-foreground capitalize">
                                    {doc.type.replace('_', ' ')} ·{' '}
                                    {doc.created_human}
                                </p>
                            </div>
                            {canManage && (
                                <button
                                    type="button"
                                    onClick={() => remove(doc.id)}
                                    className="rounded-md p-1.5 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                    aria-label="Remove document"
                                >
                                    <Trash2 className="size-4" />
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

// ── Certifications ───────────────────────────────────────────────────────────

function CertificationsTab({
    e,
    canManage,
    onChanged,
}: {
    e: EmployeeDetail;
    canManage: boolean;
    onChanged: () => void;
}) {
    const [name, setName] = useState('');
    const [issuer, setIssuer] = useState('');
    const [issued, setIssued] = useState('');
    const [expiry, setExpiry] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const [busy, setBusy] = useState(false);

    const add = () => {
        if (!name.trim()) {
            return;
        }

        const formData = new FormData();
        formData.append('name', name);

        if (issuer) {
            formData.append('issuer', issuer);
        }

        if (issued) {
            formData.append('issued_date', issued);
        }

        if (expiry) {
            formData.append('expiry_date', expiry);
        }

        if (file) {
            formData.append('file', file);
        }

        router.post(employeeRoutes.certifications(e.id), formData, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: () => {
                setName('');
                setIssuer('');
                setIssued('');
                setExpiry('');
                setFile(null);
                onChanged();
            },
        });
    };

    const remove = (certId: number) =>
        router.delete(employeeRoutes.certification(e.id, certId), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: onChanged,
        });

    return (
        <div className="space-y-4">
            {canManage && (
                <div className="space-y-3 rounded-lg border border-border bg-muted/30 p-4">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div>
                            <Label className="mb-1.5 block">Name</Label>
                            <Input
                                value={name}
                                onChange={(ev) => setName(ev.target.value)}
                                placeholder="e.g. PMP Certification"
                            />
                        </div>
                        <div>
                            <Label className="mb-1.5 block">Issuer</Label>
                            <Input
                                value={issuer}
                                onChange={(ev) => setIssuer(ev.target.value)}
                            />
                        </div>
                        <div>
                            <Label className="mb-1.5 block">Issued date</Label>
                            <Input
                                type="date"
                                value={issued}
                                onChange={(ev) => setIssued(ev.target.value)}
                            />
                        </div>
                        <div>
                            <Label className="mb-1.5 block">Expiry date</Label>
                            <Input
                                type="date"
                                value={expiry}
                                onChange={(ev) => setExpiry(ev.target.value)}
                            />
                        </div>
                    </div>
                    <Input
                        type="file"
                        accept=".pdf,.jpg,.jpeg,.png,.webp"
                        onChange={(ev) => setFile(ev.target.files?.[0] ?? null)}
                    />
                    <Button
                        size="sm"
                        onClick={add}
                        disabled={busy || !name.trim()}
                    >
                        {busy ? <Spinner /> : <Award className="size-4" />}
                        Add certification
                    </Button>
                </div>
            )}

            {e.certifications.length === 0 ? (
                <EmptyState icon={Award} text="No certifications recorded." />
            ) : (
                <ul className="divide-y divide-border rounded-lg border border-border">
                    {e.certifications.map((cert) => (
                        <li
                            key={cert.id}
                            className="flex items-center gap-3 px-3 py-2.5"
                        >
                            <Award className="size-4 shrink-0 text-muted-foreground" />
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-medium">
                                    {cert.name}
                                    {cert.is_expired && (
                                        <span className="ml-2 rounded bg-rose-500/10 px-1.5 py-0.5 text-[10px] font-semibold text-rose-600">
                                            Expired
                                        </span>
                                    )}
                                </p>
                                <p className="text-[11px] text-muted-foreground">
                                    {cert.issuer ?? 'Unknown issuer'}
                                    {cert.expiry_date
                                        ? ` · expires ${cert.expiry_date}`
                                        : ''}
                                </p>
                            </div>
                            {cert.url && (
                                <a
                                    href={cert.url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="text-xs font-medium text-[#0ABFBF] hover:underline"
                                >
                                    File
                                </a>
                            )}
                            {canManage && (
                                <button
                                    type="button"
                                    onClick={() => remove(cert.id)}
                                    className="rounded-md p-1.5 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                    aria-label="Remove certification"
                                >
                                    <Trash2 className="size-4" />
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

// ── History ──────────────────────────────────────────────────────────────────

function HistoryTab({ e }: { e: EmployeeDetail }) {
    if (e.promotions.length === 0) {
        return (
            <EmptyState icon={Clock} text="No career history recorded yet." />
        );
    }

    return (
        <ol className="relative space-y-5 border-l border-border pl-5">
            {e.promotions.map((p) => (
                <li key={p.id} className="relative">
                    <span className="absolute top-1 -left-[23px] size-2.5 rounded-full border-2 border-background bg-[#0ABFBF]" />
                    <p className="text-sm font-medium">
                        {p.from_position ?? '—'} → {p.to_position ?? '—'}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {p.effective_date}
                        {p.reason ? ` · ${p.reason}` : ''}
                    </p>
                    {(p.from_salary || p.to_salary) && (
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            ₱{Number(p.from_salary ?? 0).toLocaleString()} → ₱
                            {Number(p.to_salary ?? 0).toLocaleString()}
                        </p>
                    )}
                </li>
            ))}
        </ol>
    );
}

function EmptyState({
    icon: Icon,
    text,
}: {
    icon: typeof FileText;
    text: string;
}) {
    return (
        <div className="flex flex-col items-center justify-center gap-2 py-12 text-center">
            <span className="flex size-10 items-center justify-center rounded-full bg-muted">
                <Icon className="size-5 text-muted-foreground" />
            </span>
            <p className="text-sm text-muted-foreground">{text}</p>
        </div>
    );
}
