import { Link, router } from '@inertiajs/react';
import {
    Award,
    Briefcase,
    Building2,
    CalendarClock,
    Clock,
    Eye,
    EyeOff,
    FileText,
    Gauge,
    GraduationCap,
    Pencil,
    Trash2,
    Upload,
    UserRoundMinus,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { FormField } from '@/components/form-field';
import { FormSelect } from '@/components/form-select';
import {
    Modal,
    ModalBody,
    ModalContent,
    ModalFooter,
    ModalHeader,
} from '@/components/modal';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { AwardTypeBadge } from '@/features/awards/components/award-type-badge';
import { ResponseBadge } from '@/features/events/components/event-status-badge';
import { formatDateTime as formatEventDate } from '@/features/events/constants';
import { eventRoutes } from '@/features/events/routes';
import { CaseStatusBadge as OffboardingStatusBadge } from '@/features/offboarding/components/case-status-badge';
import { TypeBadge as OffboardingTypeBadge } from '@/features/offboarding/components/type-badge';
import { formatDate as formatOffboardingDate } from '@/features/offboarding/constants';
import { offboardingRoutes } from '@/features/offboarding/routes';
import { BandChip } from '@/features/performance/components/band-chip';
import { EvaluationStatusBadge } from '@/features/performance/components/status-badge';
import { formatPercent } from '@/features/performance/constants';
import { performanceRoutes } from '@/features/performance/routes';
import { EnrollmentStatusBadge as TrainingStatusBadge } from '@/features/training/components/training-status-badge';
import { formatScore as formatTrainingScore } from '@/features/training/constants';
import { trainingRoutes } from '@/features/training/routes';
import { cn } from '@/lib/utils';
import { DOCUMENT_TYPE_OPTIONS, TYPE_LABELS } from '../constants';
import { employeeRoutes } from '../routes';
import type {
    EmployeeDetail,
    EmployeeDetailResponse,
    ManagedEmployee,
} from '../types';
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

type Tab =
    | 'profile'
    | 'performance'
    | 'training'
    | 'awards'
    | 'events'
    | 'offboarding'
    | 'documents'
    | 'certifications'
    | 'history';

const TABS: { value: Tab; label: string }[] = [
    { value: 'profile', label: 'Profile' },
    { value: 'performance', label: 'Performance' },
    { value: 'training', label: 'Training' },
    { value: 'awards', label: 'Awards' },
    { value: 'events', label: 'Events' },
    { value: 'offboarding', label: 'Offboarding' },
    { value: 'documents', label: 'Documents' },
    { value: 'certifications', label: 'Certifications' },
    { value: 'history', label: 'History' },
];

export function EmployeeDetailDialog({
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

    // Fetch the full record (with sub-records) when the modal opens. State is
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

    // Nothing selected — there is no record to put in a dialog.
    if (!current) {
        return null;
    }

    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="2xl">
                <ModalHeader
                    icon={
                        <EmployeeAvatar
                            name={current.full_name}
                            initials={current.initials}
                            photo={current.photo}
                            className="size-12 shrink-0"
                        />
                    }
                    title={current.full_name}
                    description={
                        <span className="font-mono text-xs">
                            {current.employee_no}
                        </span>
                    }
                    meta={
                        <>
                            <EmployeeStatusBadge status={current.status} />
                            <span className="text-xs text-muted-foreground">
                                {current.position?.title ?? 'No position'}
                                {current.department
                                    ? ` · ${current.department.name}`
                                    : ''}
                            </span>
                        </>
                    }
                />

                <TabStrip value={tab} onChange={setTab} />

                <ModalBody
                    id={`employee-panel-${tab}`}
                    role="tabpanel"
                    aria-labelledby={`employee-tab-${tab}`}
                >
                    {tab === 'profile' && <ProfileTab e={current} />}

                    {tab !== 'profile' && !detail && (
                        <div className="flex items-center justify-center gap-2 py-16 text-sm text-muted-foreground">
                            <Spinner />
                            Loading…
                        </div>
                    )}

                    {tab === 'performance' && detail && (
                        <PerformanceTab e={detail} />
                    )}
                    {tab === 'training' && detail && <TrainingTab e={detail} />}
                    {tab === 'awards' && detail && <AwardsTab e={detail} />}
                    {tab === 'events' && detail && <EventsTab e={detail} />}
                    {tab === 'offboarding' && detail && (
                        <OffboardingTab e={detail} />
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
                </ModalBody>

                {canEdit && current.status !== 'archived' && (
                    <ModalFooter>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => {
                                onOpenChange(false);
                                onEdit(current);
                            }}
                        >
                            <Pencil className="size-4" />
                            Edit employee
                        </Button>
                    </ModalFooter>
                )}
            </ModalContent>
        </Modal>
    );
}

/**
 * The record's tabs. A real tablist: arrow keys move between tabs, Home/End
 * jump to the ends, and only the active tab is in the page's tab order — so
 * reaching the panel does not mean pressing Tab nine times.
 */
function TabStrip({
    value,
    onChange,
}: {
    value: Tab;
    onChange: (tab: Tab) => void;
}) {
    const strip = useRef<HTMLDivElement>(null);

    const move = (event: React.KeyboardEvent) => {
        const index = TABS.findIndex((t) => t.value === value);

        const next = {
            ArrowRight: (index + 1) % TABS.length,
            ArrowLeft: (index - 1 + TABS.length) % TABS.length,
            Home: 0,
            End: TABS.length - 1,
        }[event.key];

        if (next === undefined) {
            return;
        }

        event.preventDefault();
        onChange(TABS[next].value);
        strip.current
            ?.querySelectorAll<HTMLButtonElement>('[role="tab"]')
            [next]?.focus();
    };

    return (
        <div
            ref={strip}
            role="tablist"
            aria-label="Employee record sections"
            onKeyDown={move}
            className="flex shrink-0 gap-1 overflow-x-auto border-b border-border px-4 pt-2"
        >
            {TABS.map((t) => {
                const active = t.value === value;

                return (
                    <button
                        key={t.value}
                        id={`employee-tab-${t.value}`}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        aria-controls={`employee-panel-${t.value}`}
                        tabIndex={active ? 0 : -1}
                        onClick={() => onChange(t.value)}
                        className={cn(
                            'relative shrink-0 px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            active
                                ? 'text-foreground'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {t.label}
                        {active && (
                            <span className="absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-[#0ABFBF]" />
                        )}
                    </button>
                );
            })}
        </div>
    );
}

// ── Profile ──────────────────────────────────────────────────────────────────

function ProfileTab({ e }: { e: EmployeeDetail }) {
    const money = (v: string | null) =>
        v
            ? `₱${Number(v).toLocaleString(undefined, { minimumFractionDigits: 2 })}`
            : '—';

    return (
        <div className="grid items-start gap-6 lg:grid-cols-2 lg:gap-x-8">
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

            <Group icon={Award} title="Compensation">
                <Row label="Basic salary" value={money(e.basic_salary)} />
                <Row label="Bank" value={e.bank_name} />
                <Row label="Bank account" value={e.bank_account_no} sensitive />
            </Group>

            <Group
                icon={FileText}
                title="Government IDs"
                note="Hidden until revealed, and never surfaced by the assistant."
            >
                <Row label="TIN" value={e.tin} sensitive />
                <Row label="SSS" value={e.sss_no} sensitive />
                <Row label="PhilHealth" value={e.philhealth_no} sensitive />
                <Row label="Pag-IBIG" value={e.pagibig_no} sensitive />
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
    note,
    children,
}: {
    icon: typeof Building2;
    title: string;
    note?: string;
    children: React.ReactNode;
}) {
    return (
        <section>
            <h3 className="mb-2 flex items-center gap-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                <Icon className="size-3.5" />
                {title}
            </h3>
            {note && (
                <p className="mb-2 text-xs text-muted-foreground/80">{note}</p>
            )}
            <dl className="grid grid-cols-1 gap-x-6 gap-y-1.5">{children}</dl>
        </section>
    );
}

function Row({
    label,
    value,
    capitalize = false,
    sensitive = false,
}: {
    label: string;
    value: string | null | undefined;
    capitalize?: boolean;
    sensitive?: boolean;
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
                {sensitive ? (
                    <SensitiveValue label={label} value={value} />
                ) : (
                    value || '—'
                )}
            </dd>
        </div>
    );
}

/**
 * A statutory number or bank account, masked until asked for. This is
 * shoulder-surfing cover, not access control — whoever can open this record was
 * already sent the value. It exists so a 201 file opened on a shared screen
 * does not put somebody's TIN in the room by default.
 */
function SensitiveValue({
    label,
    value,
}: {
    label: string;
    value: string | null | undefined;
}) {
    const [shown, setShown] = useState(false);

    if (!value) {
        return <>—</>;
    }

    return (
        <span className="inline-flex items-center gap-1.5">
            <span className="font-mono text-sm">
                {shown ? value : '•'.repeat(Math.min(value.length, 12))}
            </span>
            <button
                type="button"
                onClick={() => setShown((s) => !s)}
                className="rounded-sm p-0.5 text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                aria-label={`${shown ? 'Hide' : 'Reveal'} ${label}`}
            >
                {shown ? (
                    <EyeOff className="size-3.5" />
                ) : (
                    <Eye className="size-3.5" />
                )}
            </button>
        </span>
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
                                    {evaluation.period?.name ?? 'Appraisal'}
                                </p>
                                <p className="truncate text-[11px] text-muted-foreground">
                                    {evaluation.template_name ?? 'Standard'}
                                    {evaluation.overall_percent !== null && (
                                        <span className="tabular-nums">
                                            {' · '}
                                            {formatPercent(
                                                evaluation.overall_percent,
                                            )}
                                        </span>
                                    )}
                                </p>
                            </div>
                            <BandChip
                                label={evaluation.result_label}
                                tone={evaluation.result_tone ?? undefined}
                            />
                            <EvaluationStatusBadge status={evaluation.status} />
                        </Link>
                    </li>
                ))}
            </ul>
        </div>
    );
}

// ── Training ─────────────────────────────────────────────────────────────────

function TrainingTab({ e }: { e: EmployeeDetail }) {
    if (e.training_enrollments.length === 0) {
        return (
            <EmptyState
                icon={GraduationCap}
                text="Not enrolled in any training programs."
            />
        );
    }

    return (
        <div className="space-y-3">
            <p className="text-xs text-muted-foreground">
                Training enrollments. Manage these from the Training &
                Development module.
            </p>
            <ul className="divide-y divide-border rounded-lg border border-border">
                {e.training_enrollments.map((t, i) => {
                    const row = (
                        <>
                            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[#0ABFBF]">
                                <GraduationCap className="size-4" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-medium">
                                    {t.program?.name ?? 'Unknown program'}
                                </p>
                                <p className="text-[11px] text-muted-foreground">
                                    {t.program?.provider ?? 'In-house'}
                                    {t.score !== null
                                        ? ` · ${formatTrainingScore(t.score)}`
                                        : ''}
                                </p>
                            </div>
                            <TrainingStatusBadge status={t.status} />
                        </>
                    );

                    return t.program ? (
                        <li key={`${t.program.hashid}-${i}`}>
                            <Link
                                href={trainingRoutes.show(t.program.hashid)}
                                className="flex items-center gap-3 px-3 py-2.5 transition-colors hover:bg-muted/50"
                            >
                                {row}
                            </Link>
                        </li>
                    ) : (
                        <li
                            key={`unknown-${i}`}
                            className="flex items-center gap-3 px-3 py-2.5"
                        >
                            {row}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

// ── Awards ───────────────────────────────────────────────────────────────────

function AwardsTab({ e }: { e: EmployeeDetail }) {
    if (e.awards.length === 0) {
        return <EmptyState icon={Award} text="No recognitions yet." />;
    }

    return (
        <div className="space-y-3">
            <p className="text-xs text-muted-foreground">
                Recognitions received. Give or manage these from the Awards &
                Recognition module.
            </p>
            <ul className="divide-y divide-border rounded-lg border border-border">
                {e.awards.map((award) => (
                    <li
                        key={award.id}
                        className="flex items-start gap-3 px-3 py-2.5"
                    >
                        <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[#0ABFBF]">
                            <Award className="size-4" />
                        </span>
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2">
                                {award.award_type && (
                                    <AwardTypeBadge
                                        name={award.award_type.name}
                                        color={award.award_type.color}
                                    />
                                )}
                                <span className="text-[11px] text-muted-foreground tabular-nums">
                                    {formatAwardDate(award.awarded_on)}
                                </span>
                            </div>
                            {award.reason && (
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {award.reason}
                                </p>
                            )}
                        </div>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/** Format an ISO date (YYYY-MM-DD) as "Jun 16, 2026". */
function formatAwardDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(`${iso}T00:00:00`).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

// ── Events ───────────────────────────────────────────────────────────────────

function EventsTab({ e }: { e: EmployeeDetail }) {
    if (e.events.length === 0) {
        return (
            <EmptyState
                icon={CalendarClock}
                text="Not invited to any events yet."
            />
        );
    }

    return (
        <div className="space-y-3">
            <p className="text-xs text-muted-foreground">
                Events and meetings this employee is invited to. Manage these
                from the Events & Meetings module.
            </p>
            <ul className="divide-y divide-border rounded-lg border border-border">
                {e.events.map((invite) => (
                    <li key={invite.id}>
                        <Link
                            href={eventRoutes.show(invite.event.hashid)}
                            className="flex items-center gap-3 px-3 py-2.5 transition-colors hover:bg-muted/50"
                        >
                            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[#0ABFBF]">
                                <CalendarClock className="size-4" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-medium">
                                    {invite.event.title}
                                </p>
                                <p className="text-[11px] text-muted-foreground">
                                    {formatEventDate(invite.event.starts_at)}
                                </p>
                            </div>
                            <ResponseBadge response={invite.response} />
                        </Link>
                    </li>
                ))}
            </ul>
        </div>
    );
}

// ── Offboarding ──────────────────────────────────────────────────────────────

function OffboardingTab({ e }: { e: EmployeeDetail }) {
    const o = e.offboarding;

    if (!o) {
        return (
            <EmptyState
                icon={UserRoundMinus}
                text="No offboarding on record."
            />
        );
    }

    return (
        <div className="space-y-3">
            <p className="text-xs text-muted-foreground">
                The employee's exit case. Manage it from the Offboarding module.
            </p>
            <Link
                href={offboardingRoutes.show(o.hashid)}
                className="block rounded-lg border border-border p-3 transition-colors hover:bg-muted/50"
            >
                <div className="flex items-center justify-between gap-2">
                    <span className="inline-flex items-center gap-2">
                        <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[#0ABFBF]">
                            <UserRoundMinus className="size-4" />
                        </span>
                        <OffboardingTypeBadge type={o.type} />
                    </span>
                    <OffboardingStatusBadge status={o.status} />
                </div>
                <div className="mt-3 space-y-1.5">
                    <div className="flex items-center justify-between text-xs">
                        <span className="font-medium text-foreground tabular-nums">
                            {o.clearance.cleared}/{o.clearance.total} cleared
                        </span>
                        <span className="text-muted-foreground tabular-nums">
                            {o.clearance.percent}%
                        </span>
                    </div>
                    <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                        <div
                            className={cn(
                                'h-full rounded-full',
                                o.clearance.percent >= 100
                                    ? 'bg-emerald-500'
                                    : 'bg-[#0ABFBF]',
                            )}
                            style={{ width: `${o.clearance.percent}%` }}
                        />
                    </div>
                </div>
                {o.last_working_day && (
                    <p className="mt-2 text-[11px] text-muted-foreground">
                        Last working day{' '}
                        {formatOffboardingDate(o.last_working_day)}
                    </p>
                )}
            </Link>
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
                        <FormField label="Title">
                            <Input
                                value={title}
                                onChange={(ev) => setTitle(ev.target.value)}
                                placeholder="e.g. Employment Contract"
                            />
                        </FormField>
                        <FormField label="Type">
                            <FormSelect
                                value={type}
                                onChange={setType}
                                options={DOCUMENT_TYPE_OPTIONS}
                            />
                        </FormField>
                    </div>
                    <FormField label="File" hint="PDF, image or Word document.">
                        <Input
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx"
                            onChange={(ev) =>
                                setFile(ev.target.files?.[0] ?? null)
                            }
                        />
                    </FormField>
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
                                    className="rounded-md p-1.5 text-muted-foreground hover:bg-destructive/10 hover:text-destructive focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    aria-label={`Remove ${doc.title}`}
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
                        <FormField label="Name">
                            <Input
                                value={name}
                                onChange={(ev) => setName(ev.target.value)}
                                placeholder="e.g. PMP Certification"
                            />
                        </FormField>
                        <FormField label="Issuer">
                            <Input
                                value={issuer}
                                onChange={(ev) => setIssuer(ev.target.value)}
                            />
                        </FormField>
                        <FormField label="Issued date">
                            <Input
                                type="date"
                                value={issued}
                                onChange={(ev) => setIssued(ev.target.value)}
                            />
                        </FormField>
                        <FormField label="Expiry date">
                            <Input
                                type="date"
                                min={issued || undefined}
                                value={expiry}
                                onChange={(ev) => setExpiry(ev.target.value)}
                            />
                        </FormField>
                    </div>
                    <FormField label="File" hint="Optional — PDF or image.">
                        <Input
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png,.webp"
                            onChange={(ev) =>
                                setFile(ev.target.files?.[0] ?? null)
                            }
                        />
                    </FormField>
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
                                    className="rounded-md p-1.5 text-muted-foreground hover:bg-destructive/10 hover:text-destructive focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    aria-label={`Remove ${cert.name}`}
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
