import { router } from '@inertiajs/react';
import {
    CalendarPlus,
    FileText,
    Globe,
    Linkedin,
    Mail,
    MapPin,
    Paperclip,
    Phone,
    Trash2,
    UserRoundCheck,
    XCircle,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
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
import {
    MODE_LABELS,
    MODE_OPTIONS,
    MOVABLE_STAGES,
    RESULT_OPTIONS,
    RESULT_STYLES,
    SOURCE_LABELS,
} from '../constants';
import { recruitmentRoutes } from '../routes';
import type {
    Application,
    ApplicationDetail,
    InterviewerRef,
    InterviewMode,
    RecruitmentPermissions,
    Stage,
} from '../types';
import { RatingStars } from './rating-stars';
import { StageBadge } from './stage-badge';

type Props = {
    application: Application | null;
    open: boolean;
    can: RecruitmentPermissions;
    interviewers: InterviewerRef[];
    onOpenChange: (open: boolean) => void;
};

const NONE = '__none__';

export function ApplicationDetailSheet({
    application,
    open,
    can,
    interviewers,
    onOpenChange,
}: Props) {
    const [detail, setDetail] = useState<ApplicationDetail | null>(null);
    const [openedId, setOpenedId] = useState<number | null>(null);

    const load = useCallback(() => {
        if (!application) {
            return;
        }

        fetch(recruitmentRoutes.application(application.id), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((res) => res.json())
            .then((json) => setDetail(json.data as ApplicationDetail));
    }, [application]);

    useEffect(() => {
        if (open && application) {
            load();
        }
    }, [open, application, load]);

    if (open && application && application.id !== openedId) {
        setOpenedId(application.id);
        setDetail(null);
    }

    if (!open && openedId !== null) {
        setOpenedId(null);
    }

    const current = detail ?? (application as ApplicationDetail | null);

    // Nothing to show yet (sheet closed, or no application selected). Render a
    // bare sheet so the open/close animation still works, and bail before any
    // of the handlers below dereference a null record.
    if (!current) {
        return (
            <Sheet open={open} onOpenChange={onOpenChange}>
                <SheetContent
                    side="right"
                    className="w-full gap-0 overflow-y-auto p-0 sm:max-w-xl"
                />
            </Sheet>
        );
    }

    const applicant = current.applicant;

    const act = (run: () => void) => run();

    const move = (stage: Stage) =>
        router.patch(
            recruitmentRoutes.applicationStage(current.id),
            { stage },
            { preserveScroll: true, onSuccess: load },
        );

    const rate = (rating: number) =>
        router.post(
            recruitmentRoutes.application(current.id),
            { rating },
            { preserveScroll: true, onSuccess: load },
        );

    const reject = (reason: string) =>
        router.patch(
            recruitmentRoutes.applicationReject(current.id),
            { reason },
            { preserveScroll: true, onSuccess: () => onOpenChange(false) },
        );

    const hire = () =>
        router.post(
            recruitmentRoutes.applicationHire(current.id),
            {},
            { preserveScroll: true, onSuccess: () => onOpenChange(false) },
        );

    const removeApplication = () =>
        router.delete(recruitmentRoutes.application(current.id), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });

    const terminal =
        current?.stage === 'hired' || current?.stage === 'rejected';

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-xl"
            >
                <SheetHeader className="border-b border-border px-6 py-5">
                    {applicant && (
                        <div className="flex items-start gap-4">
                            <Avatar className="size-12 rounded-lg ring-1 ring-border">
                                <AvatarFallback className="rounded-lg bg-[#0F2044] text-sm font-semibold text-white">
                                    {applicant.initials}
                                </AvatarFallback>
                            </Avatar>
                            <div className="min-w-0 flex-1">
                                <SheetTitle className="truncate text-lg">
                                    {applicant.full_name}
                                </SheetTitle>
                                <p className="truncate text-xs text-muted-foreground">
                                    {applicant.headline ?? '—'}
                                </p>
                                <div className="mt-2 flex flex-wrap items-center gap-2">
                                    <StageBadge stage={current.stage} />
                                    <span className="text-xs text-muted-foreground">
                                        via {SOURCE_LABELS[applicant.source]}
                                        {current?.applied_human
                                            ? ` · applied ${current.applied_human}`
                                            : ''}
                                    </span>
                                </div>
                            </div>
                        </div>
                    )}
                </SheetHeader>

                <div className="space-y-6 px-6 py-5">
                    {/* Contact */}
                    {applicant && (
                        <div className="flex flex-wrap gap-x-5 gap-y-1.5 text-sm">
                            {applicant.email && (
                                <span className="inline-flex items-center gap-1.5 text-muted-foreground">
                                    <Mail className="size-3.5" />
                                    {applicant.email}
                                </span>
                            )}
                            {applicant.phone && (
                                <span className="inline-flex items-center gap-1.5 text-muted-foreground">
                                    <Phone className="size-3.5" />
                                    {applicant.phone}
                                </span>
                            )}
                            {applicant.resume_url && (
                                <a
                                    href={applicant.resume_url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="inline-flex items-center gap-1.5 font-medium text-[#0ABFBF] hover:underline"
                                >
                                    <FileText className="size-3.5" />
                                    Résumé
                                </a>
                            )}
                        </div>
                    )}

                    {/* Profile */}
                    {applicant &&
                        (applicant.current_location ||
                            applicant.headline ||
                            applicant.years_experience != null ||
                            applicant.linkedin_url ||
                            applicant.portfolio_url) && (
                            <Group title="Profile">
                                {applicant.headline && (
                                    <Row
                                        label="Headline"
                                        value={applicant.headline}
                                    />
                                )}
                                {applicant.current_location && (
                                    <Row
                                        label="Location"
                                        value={applicant.current_location}
                                    />
                                )}
                                {applicant.years_experience != null && (
                                    <Row
                                        label="Experience"
                                        value={`${applicant.years_experience} ${applicant.years_experience === 1 ? 'year' : 'years'}`}
                                    />
                                )}
                                {(applicant.linkedin_url ||
                                    applicant.portfolio_url) && (
                                    <div className="flex flex-wrap gap-x-4 gap-y-1.5 pt-1 text-sm">
                                        {applicant.linkedin_url && (
                                            <a
                                                href={applicant.linkedin_url}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="inline-flex items-center gap-1.5 font-medium text-[#0ABFBF] hover:underline"
                                            >
                                                <Linkedin className="size-3.5" />
                                                LinkedIn
                                            </a>
                                        )}
                                        {applicant.portfolio_url && (
                                            <a
                                                href={applicant.portfolio_url}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="inline-flex items-center gap-1.5 font-medium text-[#0ABFBF] hover:underline"
                                            >
                                                <Globe className="size-3.5" />
                                                Portfolio
                                            </a>
                                        )}
                                    </div>
                                )}
                            </Group>
                        )}

                    {/* Documents */}
                    {applicant?.documents && applicant.documents.length > 0 && (
                        <Group title="Documents">
                            <ul className="space-y-1.5">
                                {applicant.documents.map((doc) => (
                                    <li key={doc.id}>
                                        <a
                                            href={doc.url ?? '#'}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="inline-flex items-center gap-1.5 text-sm font-medium text-[#0ABFBF] hover:underline"
                                        >
                                            <Paperclip className="size-3.5" />
                                            {doc.title}
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        </Group>
                    )}

                    {/* Assessment */}
                    <Group title="Assessment">
                        <div className="flex items-center justify-between">
                            <span className="text-sm text-muted-foreground">
                                Rating
                            </span>
                            <RatingStars
                                size="md"
                                value={current?.rating ?? null}
                                onChange={
                                    can.update && !terminal ? rate : undefined
                                }
                            />
                        </div>
                        {current?.expected_salary && (
                            <Row
                                label="Expected salary"
                                value={`₱${Number(current.expected_salary).toLocaleString()}`}
                            />
                        )}
                        {current?.cover_note && (
                            <p className="rounded-md bg-muted/40 p-3 text-sm text-muted-foreground">
                                {current.cover_note}
                            </p>
                        )}
                        {current?.rejected_reason && (
                            <p className="rounded-md bg-rose-500/5 p-3 text-sm text-rose-600">
                                Rejected: {current.rejected_reason}
                            </p>
                        )}
                        {current?.hired_employee && (
                            <Row
                                label="Hired as"
                                value={`${current.hired_employee.full_name} (${current.hired_employee.employee_no})`}
                            />
                        )}
                    </Group>

                    {/* Pipeline actions */}
                    {!terminal && can.managePipeline && (
                        <Group title="Move to stage">
                            <div className="flex flex-wrap gap-2">
                                {MOVABLE_STAGES.filter(
                                    (s) => s.value !== current?.stage,
                                ).map((s) => (
                                    <Button
                                        key={s.value}
                                        variant="outline"
                                        size="sm"
                                        onClick={() => act(() => move(s.value))}
                                    >
                                        {s.label}
                                    </Button>
                                ))}
                            </div>
                        </Group>
                    )}

                    {/* Interviews */}
                    <InterviewsSection
                        detail={detail}
                        canSchedule={can.scheduleInterviews && !terminal}
                        interviewers={interviewers}
                        onChanged={load}
                    />
                </div>

                {/* Footer actions */}
                {!terminal && (
                    <div className="sticky bottom-0 flex flex-wrap items-center gap-2 border-t border-border bg-background px-6 py-4">
                        {can.hire &&
                            ['interview', 'offer'].includes(
                                current?.stage ?? '',
                            ) && (
                                <Button size="sm" onClick={hire}>
                                    <UserRoundCheck className="size-4" />
                                    Hire candidate
                                </Button>
                            )}
                        {can.managePipeline && (
                            <RejectControl onReject={reject} />
                        )}
                        {can.managePipeline && (
                            <Button
                                variant="ghost"
                                size="sm"
                                className="ml-auto text-muted-foreground hover:text-destructive"
                                onClick={removeApplication}
                            >
                                <Trash2 className="size-4" />
                                Remove
                            </Button>
                        )}
                    </div>
                )}
            </SheetContent>
        </Sheet>
    );
}

function RejectControl({ onReject }: { onReject: (reason: string) => void }) {
    const [openInput, setOpenInput] = useState(false);
    const [reason, setReason] = useState('');

    if (!openInput) {
        return (
            <Button
                variant="outline"
                size="sm"
                className="text-destructive hover:text-destructive"
                onClick={() => setOpenInput(true)}
            >
                <XCircle className="size-4" />
                Reject
            </Button>
        );
    }

    return (
        <div className="flex w-full items-center gap-2">
            <Input
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                placeholder="Reason (optional)"
                className="h-9"
            />
            <Button
                variant="destructive"
                size="sm"
                onClick={() => onReject(reason)}
            >
                Confirm
            </Button>
        </div>
    );
}

function InterviewsSection({
    detail,
    canSchedule,
    interviewers,
    onChanged,
}: {
    detail: ApplicationDetail | null;
    canSchedule: boolean;
    interviewers: InterviewerRef[];
    onChanged: () => void;
}) {
    const [showForm, setShowForm] = useState(false);
    const [interviewerId, setInterviewerId] = useState(NONE);
    const [scheduledAt, setScheduledAt] = useState('');
    const [mode, setMode] = useState<InterviewMode>('onsite');
    const [location, setLocation] = useState('');
    const [busy, setBusy] = useState(false);

    if (!detail) {
        return (
            <div className="flex items-center justify-center py-6 text-muted-foreground">
                <Spinner />
            </div>
        );
    }

    const schedule = () => {
        if (!scheduledAt) {
            return;
        }

        router.post(
            recruitmentRoutes.applicationInterviews(detail.id),
            {
                interviewer_id:
                    interviewerId === NONE ? null : Number(interviewerId),
                scheduled_at: scheduledAt,
                mode,
                location: location || null,
            },
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onSuccess: () => {
                    setShowForm(false);
                    setInterviewerId(NONE);
                    setScheduledAt('');
                    setMode('onsite');
                    setLocation('');
                    onChanged();
                },
            },
        );
    };

    const setResult = (interviewId: number, result: string) =>
        router.post(
            recruitmentRoutes.interview(interviewId),
            { result },
            { preserveScroll: true, onSuccess: onChanged },
        );

    const remove = (interviewId: number) =>
        router.delete(recruitmentRoutes.interview(interviewId), {
            preserveScroll: true,
            onSuccess: onChanged,
        });

    return (
        <Group title="Interviews">
            {detail.interviews.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    No interviews scheduled.
                </p>
            ) : (
                <ul className="space-y-2">
                    {detail.interviews.map((interview) => (
                        <li
                            key={interview.id}
                            className="rounded-lg border border-border p-3"
                        >
                            <div className="flex items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <p className="text-sm font-medium">
                                        {interview.scheduled_label}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {MODE_LABELS[interview.mode]}
                                        {interview.interviewer
                                            ? ` · ${interview.interviewer}`
                                            : ''}
                                        {interview.location
                                            ? ` · ${interview.location}`
                                            : ''}
                                    </p>
                                </div>
                                <span
                                    className={cn(
                                        'inline-flex shrink-0 items-center rounded-full border px-2 py-0.5 text-[10px] font-medium capitalize',
                                        RESULT_STYLES[interview.result],
                                    )}
                                >
                                    {interview.result}
                                </span>
                            </div>
                            {canSchedule && (
                                <div className="mt-2 flex items-center gap-2">
                                    <Select
                                        value={interview.result}
                                        onValueChange={(v) =>
                                            setResult(interview.id, v)
                                        }
                                    >
                                        <SelectTrigger
                                            size="sm"
                                            className="h-7 w-[120px]"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {RESULT_OPTIONS.map((o) => (
                                                <SelectItem
                                                    key={o.value}
                                                    value={o.value}
                                                >
                                                    {o.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <button
                                        type="button"
                                        onClick={() => remove(interview.id)}
                                        className="rounded-md p-1.5 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                        aria-label="Remove interview"
                                    >
                                        <Trash2 className="size-4" />
                                    </button>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {canSchedule &&
                (showForm ? (
                    <div className="space-y-3 rounded-lg border border-border bg-muted/30 p-3">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div>
                                <Label className="mb-1.5 block text-xs">
                                    When
                                </Label>
                                <Input
                                    type="datetime-local"
                                    value={scheduledAt}
                                    onChange={(e) =>
                                        setScheduledAt(e.target.value)
                                    }
                                />
                            </div>
                            <div>
                                <Label className="mb-1.5 block text-xs">
                                    Mode
                                </Label>
                                <Select
                                    value={mode}
                                    onValueChange={(v) =>
                                        setMode(v as InterviewMode)
                                    }
                                >
                                    <SelectTrigger className="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {MODE_OPTIONS.map((o) => (
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
                            <div>
                                <Label className="mb-1.5 block text-xs">
                                    Interviewer
                                </Label>
                                <Select
                                    value={interviewerId}
                                    onValueChange={setInterviewerId}
                                >
                                    <SelectTrigger className="w-full">
                                        <SelectValue placeholder="Unassigned" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>
                                            Unassigned
                                        </SelectItem>
                                        {interviewers.map((u) => (
                                            <SelectItem
                                                key={u.id}
                                                value={String(u.id)}
                                            >
                                                {u.full_name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="mb-1.5 block text-xs">
                                    Location / link
                                </Label>
                                <Input
                                    value={location}
                                    onChange={(e) =>
                                        setLocation(e.target.value)
                                    }
                                    placeholder="Office or meeting URL"
                                />
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            <Button
                                size="sm"
                                onClick={schedule}
                                disabled={busy || !scheduledAt}
                            >
                                {busy ? (
                                    <Spinner />
                                ) : (
                                    <MapPin className="size-4" />
                                )}
                                Schedule
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => setShowForm(false)}
                            >
                                Cancel
                            </Button>
                        </div>
                    </div>
                ) : (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setShowForm(true)}
                    >
                        <CalendarPlus className="size-4" />
                        Schedule interview
                    </Button>
                ))}
        </Group>
    );
}

function Group({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section className="space-y-3">
            <h3 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                {title}
            </h3>
            {children}
        </section>
    );
}

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-center justify-between gap-4 text-sm">
            <span className="text-muted-foreground">{label}</span>
            <span className="text-right font-medium">{value}</span>
        </div>
    );
}
