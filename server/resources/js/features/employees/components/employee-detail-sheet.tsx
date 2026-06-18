import { Link, router } from '@inertiajs/react';
import {
    Award,
    Briefcase,
    Building2,
    Clock,
    Coins,
    FileText,
    Gauge,
    HeartHandshake,
    Pencil,
    Plus,
    Trash2,
    Upload,
    Wallet,
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
import { Switch } from '@/components/ui/switch';
import {
    CATEGORY_LABELS,
    FREQUENCY_SUFFIX,
    STATUS_LABELS,
    STATUS_STYLES,
    formatPeso,
} from '@/features/benefits/constants';
import { EvaluationStatusBadge } from '@/features/performance/components/status-badge';
import { formatScore, scoreTone } from '@/features/performance/constants';
import { performanceRoutes } from '@/features/performance/routes';
import { cn } from '@/lib/utils';
import { DOCUMENT_TYPE_OPTIONS, TYPE_LABELS } from '../constants';
import { employeeRoutes } from '../routes';
import type {
    EmployeeDetail,
    EmployeeDetailResponse,
    ManagedEmployee,
    PayItemType,
} from '../types';
import { EmployeeAvatar } from './employee-avatar';
import { EmployeeStatusBadge } from './employee-status-badge';

const peso = (v: number) =>
    `₱${v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

type Catalogue = {
    allowanceTypes: PayItemType[];
    deductionTypes: PayItemType[];
};

type Props = {
    employee: ManagedEmployee | null;
    open: boolean;
    canEdit: boolean;
    canManageDocuments: boolean;
    onOpenChange: (open: boolean) => void;
    onEdit: (employee: ManagedEmployee) => void;
};

type Tab =
    | 'profile'
    | 'compensation'
    | 'benefits'
    | 'performance'
    | 'documents'
    | 'certifications'
    | 'history';

const TABS: { value: Tab; label: string }[] = [
    { value: 'profile', label: 'Profile' },
    { value: 'compensation', label: 'Compensation' },
    { value: 'benefits', label: 'Benefits' },
    { value: 'performance', label: 'Performance' },
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
    const [catalogue, setCatalogue] = useState<Catalogue>({
        allowanceTypes: [],
        deductionTypes: [],
    });
    const [canAdjust, setCanAdjust] = useState(false);
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
            .then((json: EmployeeDetailResponse) => {
                setDetail(json.data);
                setCatalogue({
                    allowanceTypes: json.allowance_types ?? [],
                    deductionTypes: json.deduction_types ?? [],
                });
                setCanAdjust(Boolean(json.can_adjust_payroll));
            });
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

                    {tab === 'compensation' && detail && (
                        <CompensationTab
                            e={detail}
                            canManage={canAdjust}
                            catalogue={catalogue}
                            onChanged={load}
                        />
                    )}
                    {tab === 'benefits' && detail && <BenefitsTab e={detail} />}
                    {tab === 'performance' && detail && (
                        <PerformanceTab e={detail} />
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

// ── Compensation ─────────────────────────────────────────────────────────────

type PayItem = {
    id: number;
    name: string | null;
    amount: number;
    is_active: boolean;
    type_id: number | null;
};

function CompensationTab({
    e,
    canManage,
    catalogue,
    onChanged,
}: {
    e: EmployeeDetail;
    canManage: boolean;
    catalogue: Catalogue;
    onChanged: () => void;
}) {
    const handlers = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: onChanged,
    };

    return (
        <div className="space-y-6">
            <p className="text-xs text-muted-foreground">
                Recurring pay items applied to every payslip the next time a run
                is processed. Statutory deductions (SSS, PhilHealth, Pag-IBIG,
                tax) are computed automatically and aren't listed here.
            </p>

            <PayItemSection
                title="Allowances"
                icon={Wallet}
                emptyText="No recurring allowances assigned."
                addLabel="Add allowance"
                canManage={canManage}
                types={catalogue.allowanceTypes}
                items={e.allowances.map((a) => ({
                    id: a.id,
                    name: a.name,
                    amount: a.amount,
                    is_active: a.is_active,
                    type_id: a.allowance_type_id,
                }))}
                add={(typeId, amount, h) =>
                    router.post(
                        employeeRoutes.allowances(e.id),
                        { allowance_type_id: typeId, amount },
                        h,
                    )
                }
                toggle={(item, active) =>
                    router.patch(
                        employeeRoutes.allowance(e.id, item.id),
                        {
                            allowance_type_id: item.type_id,
                            amount: item.amount,
                            is_active: active,
                        },
                        handlers,
                    )
                }
                remove={(item) =>
                    router.delete(
                        employeeRoutes.allowance(e.id, item.id),
                        handlers,
                    )
                }
                onChanged={onChanged}
            />

            <PayItemSection
                title="Deductions"
                icon={Coins}
                emptyText="No recurring deductions assigned."
                addLabel="Add deduction"
                canManage={canManage}
                types={catalogue.deductionTypes}
                items={e.recurring_deductions.map((d) => ({
                    id: d.id,
                    name: d.name,
                    amount: d.amount,
                    is_active: d.is_active,
                    type_id: d.deduction_type_id,
                }))}
                add={(typeId, amount, h) =>
                    router.post(
                        employeeRoutes.deductions(e.id),
                        { deduction_type_id: typeId, amount },
                        h,
                    )
                }
                toggle={(item, active) =>
                    router.patch(
                        employeeRoutes.deduction(e.id, item.id),
                        {
                            deduction_type_id: item.type_id,
                            amount: item.amount,
                            is_active: active,
                        },
                        handlers,
                    )
                }
                remove={(item) =>
                    router.delete(
                        employeeRoutes.deduction(e.id, item.id),
                        handlers,
                    )
                }
                onChanged={onChanged}
            />
        </div>
    );
}

function PayItemSection({
    title,
    icon: Icon,
    emptyText,
    addLabel,
    canManage,
    types,
    items,
    add,
    toggle,
    remove,
    onChanged,
}: {
    title: string;
    icon: typeof Wallet;
    emptyText: string;
    addLabel: string;
    canManage: boolean;
    types: PayItemType[];
    items: PayItem[];
    add: (typeId: number, amount: number, handlers: object) => void;
    toggle: (item: PayItem, active: boolean) => void;
    remove: (item: PayItem) => void;
    onChanged: () => void;
}) {
    const [typeId, setTypeId] = useState('');
    const [amount, setAmount] = useState('');
    const [busy, setBusy] = useState(false);

    const submit = () => {
        const value = Number(amount);

        if (!typeId || amount === '' || Number.isNaN(value) || value < 0) {
            return;
        }

        add(Number(typeId), value, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: () => {
                setTypeId('');
                setAmount('');
                onChanged();
            },
        });
    };

    return (
        <section className="space-y-3">
            <h3 className="flex items-center gap-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                <Icon className="size-3.5" />
                {title}
            </h3>

            {items.length === 0 ? (
                <p className="text-sm text-muted-foreground">{emptyText}</p>
            ) : (
                <ul className="divide-y divide-border rounded-lg border border-border">
                    {items.map((item) => (
                        <li
                            key={item.id}
                            className="flex items-center gap-3 px-3 py-2.5"
                        >
                            <div className="min-w-0 flex-1">
                                <p
                                    className={cn(
                                        'truncate text-sm font-medium',
                                        !item.is_active &&
                                            'text-muted-foreground line-through',
                                    )}
                                >
                                    {item.name ?? 'Unknown type'}
                                </p>
                                <p className="text-[11px] text-muted-foreground tabular-nums">
                                    {peso(item.amount)} / period
                                </p>
                            </div>
                            {canManage ? (
                                <>
                                    <Switch
                                        checked={item.is_active}
                                        onCheckedChange={(v) => toggle(item, v)}
                                        aria-label="Active"
                                    />
                                    <button
                                        type="button"
                                        onClick={() => remove(item)}
                                        className="rounded-md p-1.5 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                        aria-label={`Remove ${title.toLowerCase()}`}
                                    >
                                        <Trash2 className="size-4" />
                                    </button>
                                </>
                            ) : (
                                <span
                                    className={cn(
                                        'rounded px-1.5 py-0.5 text-[10px] font-semibold',
                                        item.is_active
                                            ? 'bg-emerald-500/10 text-emerald-600'
                                            : 'bg-muted text-muted-foreground',
                                    )}
                                >
                                    {item.is_active ? 'Active' : 'Inactive'}
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {canManage && (
                <div className="flex flex-wrap items-end gap-2 rounded-lg border border-border bg-muted/30 p-3">
                    <div className="min-w-[10rem] flex-1">
                        <Label className="mb-1.5 block">Type</Label>
                        <Select value={typeId} onValueChange={setTypeId}>
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="Select type" />
                            </SelectTrigger>
                            <SelectContent>
                                {types.length === 0 ? (
                                    <div className="px-2 py-1.5 text-xs text-muted-foreground">
                                        No types configured in Setup.
                                    </div>
                                ) : (
                                    types.map((t) => (
                                        <SelectItem
                                            key={t.id}
                                            value={String(t.id)}
                                        >
                                            {t.name}
                                        </SelectItem>
                                    ))
                                )}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="w-32">
                        <Label className="mb-1.5 block">Amount</Label>
                        <Input
                            type="number"
                            min="0"
                            step="0.01"
                            inputMode="decimal"
                            value={amount}
                            onChange={(ev) => setAmount(ev.target.value)}
                            placeholder="0.00"
                        />
                    </div>
                    <Button
                        size="sm"
                        onClick={submit}
                        disabled={busy || !typeId || amount === ''}
                    >
                        {busy ? <Spinner /> : <Plus className="size-4" />}
                        {addLabel}
                    </Button>
                </div>
            )}
        </section>
    );
}

// ── Benefits ─────────────────────────────────────────────────────────────────

function BenefitsTab({ e }: { e: EmployeeDetail }) {
    const categoryLabels = CATEGORY_LABELS as Record<string, string>;
    const freqSuffix = FREQUENCY_SUFFIX as Record<string, string>;

    if (e.benefit_enrollments.length === 0) {
        return (
            <EmptyState
                icon={HeartHandshake}
                text="Not enrolled in any benefit plans."
            />
        );
    }

    return (
        <div className="space-y-3">
            <p className="text-xs text-muted-foreground">
                Benefit-plan enrollments. Enroll or change these from the
                Benefits module.
            </p>
            <ul className="divide-y divide-border rounded-lg border border-border">
                {e.benefit_enrollments.map((b) => (
                    <li
                        key={b.id}
                        className="flex items-center gap-3 px-3 py-2.5"
                    >
                        <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[#0ABFBF]">
                            <HeartHandshake className="size-4" />
                        </span>
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-medium">
                                {b.plan?.name ?? 'Unknown plan'}
                            </p>
                            <p className="text-[11px] text-muted-foreground">
                                {b.plan
                                    ? (categoryLabels[b.plan.category] ??
                                      b.plan.category)
                                    : ''}
                                {b.plan && b.plan.employee_cost > 0
                                    ? ` · ${formatPeso(b.plan.employee_cost)}${freqSuffix[b.plan.frequency] ?? ''}`
                                    : ' · employer-paid'}
                            </p>
                        </div>
                        <span
                            className={cn(
                                'inline-flex shrink-0 items-center rounded-full border px-2 py-0.5 text-[11px] font-medium',
                                STATUS_STYLES[b.status],
                            )}
                        >
                            {STATUS_LABELS[b.status]}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

// ── Performance ──────────────────────────────────────────────────────────────

function PerformanceTab({ e }: { e: EmployeeDetail }) {
    if (e.performance_evaluations.length === 0) {
        return (
            <EmptyState icon={Gauge} text="No performance evaluations yet." />
        );
    }

    return (
        <div className="space-y-3">
            <p className="text-xs text-muted-foreground">
                Performance evaluations. Conduct or review these from the
                Performance Management module.
            </p>
            <ul className="divide-y divide-border rounded-lg border border-border">
                {e.performance_evaluations.map((evaluation) => (
                    <li key={evaluation.hashid}>
                        <Link
                            href={performanceRoutes.show(evaluation.hashid)}
                            className="flex items-center gap-3 px-3 py-2.5 transition-colors hover:bg-muted/50"
                        >
                            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[#0ABFBF]">
                                <Gauge className="size-4" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-medium">
                                    {evaluation.period?.name ?? 'Evaluation'}
                                </p>
                                <p className="text-[11px] text-muted-foreground">
                                    <span
                                        className={cn(
                                            'font-semibold tabular-nums',
                                            scoreTone(evaluation.overall_score),
                                        )}
                                    >
                                        {formatScore(evaluation.overall_score)}
                                    </span>{' '}
                                    / 5.00
                                </p>
                            </div>
                            <EvaluationStatusBadge status={evaluation.status} />
                        </Link>
                    </li>
                ))}
            </ul>
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
