import { router } from '@inertiajs/react';
import {
    ArrowRight,
    CalendarPlus,
    FileText,
    Globe,
    Linkedin,
    Mail,
    MapPin,
    Paperclip,
    Phone,
    Sparkles,
    Trash2,
    UserRoundCheck,
    XCircle,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { FormSelect } from '@/components/form-select';
import {
    Modal,
    ModalBody,
    ModalContent,
    ModalFooter,
    ModalHeader,
    ModalSection,
} from '@/components/modal';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import {
    MODE_LABELS,
    MODE_OPTIONS,
    RECOMMENDATION_STYLES,
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
    Recommendation,
    RecruitmentPermissions,
    Stage,
} from '../types';
import { ApplicantInsights } from './applicant-insights';
import { FitMeter } from './fit-score';
import { RatingStars } from './rating-stars';
import { StageBadge } from './stage-badge';
import { StageStepper } from './stage-stepper';

type Props = {
    application: Application | null;
    open: boolean;
    can: RecruitmentPermissions;
    interviewers: InterviewerRef[];
    onOpenChange: (open: boolean) => void;
};

const NONE = '__none__';

/** Which footer action is asking the recruiter to confirm itself. */
type Pending = 'none' | 'reject' | 'remove';

export function ApplicationDetailDialog({
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

    // No candidate selected yet — there is nothing to put in a dialog.
    if (!current) {
        return null;
    }

    const applicant = current.applicant;

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

    const runRecommendation = (rec: Recommendation) => {
        if (rec.action === 'hire') {
            hire();
        } else if (rec.action === 'reject') {
            reject('');
        } else if (rec.action) {
            move(rec.action);
        }
    };

    const canRunRecommendation = (rec: Recommendation) =>
        rec.action !== null &&
        (rec.action === 'hire' ? can.hire : can.managePipeline);

    const terminal = current.stage === 'hired' || current.stage === 'rejected';

    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="2xl">
                <ModalHeader
                    icon={
                        applicant && (
                            <Avatar className="size-11 shrink-0 rounded-xl ring-1 ring-border">
                                <AvatarFallback className="rounded-xl bg-[#0F2044] text-sm font-semibold text-white">
                                    {applicant.initials}
                                </AvatarFallback>
                            </Avatar>
                        )
                    }
                    title={applicant?.full_name ?? 'Candidate'}
                    description={applicant?.headline ?? 'No headline given'}
                    meta={
                        <>
                            <StageBadge stage={current.stage} />
                            <span className="text-xs text-muted-foreground">
                                {applicant
                                    ? `via ${SOURCE_LABELS[applicant.source]}`
                                    : ''}
                                {current.applied_human
                                    ? ` · applied ${current.applied_human}`
                                    : ''}
                            </span>
                            {current.fit_rank && (
                                <span className="rounded-full border border-border px-2 py-0.5 text-xs font-medium text-muted-foreground tabular-nums">
                                    Rank #{current.fit_rank.position}/
                                    {current.fit_rank.total}
                                </span>
                            )}
                        </>
                    }
                />

                <ModalBody className="lg:grid lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start lg:gap-x-7">
                    {/* Contact — identity first, spanning the full width */}
                    {applicant && (
                        <div className="mb-6 flex flex-wrap gap-x-5 gap-y-1.5 rounded-lg bg-muted/40 px-3.5 py-2.5 text-sm lg:col-span-2">
                            {applicant.email ? (
                                <a
                                    href={`mailto:${applicant.email}`}
                                    className="inline-flex items-center gap-1.5 text-muted-foreground hover:text-foreground"
                                >
                                    <Mail className="size-3.5" />
                                    {applicant.email}
                                </a>
                            ) : null}
                            {applicant.phone ? (
                                <a
                                    href={`tel:${applicant.phone}`}
                                    className="inline-flex items-center gap-1.5 text-muted-foreground hover:text-foreground"
                                >
                                    <Phone className="size-3.5" />
                                    {applicant.phone}
                                </a>
                            ) : null}
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
                            {!applicant.email &&
                                !applicant.phone &&
                                !applicant.resume_url && (
                                    <span className="text-muted-foreground">
                                        No contact details on file.
                                    </span>
                                )}
                        </div>
                    )}

                    {/* Decision column */}
                    <div className="space-y-6">
                        {/* Decision support — the recommended next step for HR */}
                        {current.recommendation && !terminal && (
                            <div
                                className={cn(
                                    'rounded-xl border p-4',
                                    RECOMMENDATION_STYLES[
                                        current.recommendation.tone
                                    ],
                                )}
                            >
                                <div className="flex items-start gap-3">
                                    <Sparkles className="mt-0.5 size-4 shrink-0" />
                                    <div className="min-w-0 flex-1">
                                        <p className="text-[11px] font-semibold tracking-wide uppercase opacity-70">
                                            Recommended next step
                                        </p>
                                        <p className="mt-0.5 text-sm font-semibold">
                                            {current.recommendation.label}
                                        </p>
                                        {current.recommendation.hint && (
                                            <p className="mt-0.5 text-xs opacity-80">
                                                {current.recommendation.hint}
                                            </p>
                                        )}
                                        {canRunRecommendation(
                                            current.recommendation,
                                        ) && (
                                            <Button
                                                size="sm"
                                                variant={
                                                    current.recommendation
                                                        .tone === 'caution'
                                                        ? 'outline'
                                                        : 'default'
                                                }
                                                className="mt-2.5"
                                                onClick={() =>
                                                    runRecommendation(
                                                        current.recommendation!,
                                                    )
                                                }
                                            >
                                                {current.recommendation.label}
                                                <ArrowRight className="size-4" />
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* Where they stand, and how to move them */}
                        {!terminal && (
                            <ModalSection title="Pipeline stage">
                                <StageStepper
                                    stage={current.stage}
                                    canMove={can.managePipeline}
                                    onMove={move}
                                />
                            </ModalSection>
                        )}

                        {/* Fit score breakdown */}
                        {current.fit && (
                            <ModalSection title="Fit score">
                                <FitMeter fit={current.fit} />
                            </ModalSection>
                        )}

                        {/* AI insights — reads the résumé + documents on demand */}
                        <ApplicantInsights
                            key={current.id}
                            applicationId={current.id}
                            saved={current.ai_insights ?? null}
                        />

                        {/* Assessment */}
                        <ModalSection title="Assessment">
                            <div className="flex items-center justify-between gap-4">
                                <span className="text-sm text-muted-foreground">
                                    Rating
                                </span>
                                <RatingStars
                                    size="md"
                                    value={current.rating ?? null}
                                    onChange={
                                        can.update && !terminal
                                            ? rate
                                            : undefined
                                    }
                                />
                            </div>
                            {current.expected_salary && (
                                <Row
                                    label="Expected salary"
                                    value={`₱${Number(current.expected_salary).toLocaleString()}`}
                                />
                            )}
                            {current.cover_note && (
                                <p className="rounded-md bg-muted/40 p-3 text-sm leading-relaxed text-muted-foreground">
                                    {current.cover_note}
                                </p>
                            )}
                            {current.rejected_reason && (
                                <p className="rounded-md bg-rose-500/5 p-3 text-sm text-rose-600">
                                    Rejected: {current.rejected_reason}
                                </p>
                            )}
                            {current.hired_employee && (
                                <Row
                                    label="Hired as"
                                    value={`${current.hired_employee.full_name} (${current.hired_employee.employee_no})`}
                                />
                            )}
                        </ModalSection>

                        {/* Interviews */}
                        <InterviewsSection
                            detail={detail}
                            canSchedule={can.scheduleInterviews && !terminal}
                            interviewers={interviewers}
                            onChanged={load}
                        />
                    </div>

                    {/* Reference column */}
                    <div className="mt-6 space-y-6 border-t border-border pt-6 lg:mt-0 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-7">
                        {applicant && (
                            <ModalSection title="Profile">
                                <div className="space-y-2.5">
                                    <Row
                                        label="Location"
                                        value={
                                            applicant.current_location ?? '—'
                                        }
                                    />
                                    <Row
                                        label="Experience"
                                        value={
                                            applicant.years_experience == null
                                                ? '—'
                                                : `${applicant.years_experience} ${
                                                      applicant.years_experience ===
                                                      1
                                                          ? 'year'
                                                          : 'years'
                                                  }`
                                        }
                                    />
                                    <Row
                                        label="Source"
                                        value={SOURCE_LABELS[applicant.source]}
                                    />
                                </div>
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
                            </ModalSection>
                        )}

                        {applicant?.documents &&
                            applicant.documents.length > 0 && (
                                <ModalSection title="Documents">
                                    <ul className="space-y-1.5">
                                        {applicant.documents.map((doc) => (
                                            <li key={doc.id}>
                                                <a
                                                    href={doc.url ?? '#'}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="inline-flex items-center gap-1.5 text-sm font-medium text-[#0ABFBF] hover:underline"
                                                >
                                                    <Paperclip className="size-3.5 shrink-0" />
                                                    {doc.title}
                                                </a>
                                            </li>
                                        ))}
                                    </ul>
                                </ModalSection>
                            )}

                        {detail?.other_applications &&
                            detail.other_applications.length > 0 && (
                                <ModalSection title="Other applications">
                                    <ul className="space-y-2">
                                        {detail.other_applications.map(
                                            (other) => (
                                                <li
                                                    key={other.id}
                                                    className="flex items-center justify-between gap-2 text-sm"
                                                >
                                                    <span className="min-w-0 truncate text-muted-foreground">
                                                        {other.posting ??
                                                            'Unknown role'}
                                                    </span>
                                                    <StageBadge
                                                        stage={other.stage}
                                                    />
                                                </li>
                                            ),
                                        )}
                                    </ul>
                                </ModalSection>
                            )}
                    </div>
                </ModalBody>

                {!terminal && (
                    <DecisionBar
                        canHire={
                            can.hire &&
                            ['interview', 'offer'].includes(current.stage)
                        }
                        canManage={can.managePipeline}
                        onHire={hire}
                        onReject={reject}
                        onRemove={removeApplication}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

/**
 * The candidate's decision bar. Rejecting and removing both ask for a second
 * press inside the bar itself rather than opening another dialog on top of this
 * one — rejecting collects an optional reason on the way.
 */
function DecisionBar({
    canHire,
    canManage,
    onHire,
    onReject,
    onRemove,
}: {
    canHire: boolean;
    canManage: boolean;
    onHire: () => void;
    onReject: (reason: string) => void;
    onRemove: () => void;
}) {
    const [pending, setPending] = useState<Pending>('none');
    const [reason, setReason] = useState('');

    // Escape backs out of the confirmation instead of closing the whole modal.
    const escapeCancels = (event: React.KeyboardEvent) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            setPending('none');
        }
    };

    if (pending === 'reject') {
        return (
            <ModalFooter className="justify-between gap-3">
                <div className="flex min-w-0 flex-1 items-center gap-2">
                    <Label
                        htmlFor="reject-reason"
                        className="shrink-0 text-xs text-muted-foreground"
                    >
                        Reason
                    </Label>
                    <Input
                        id="reject-reason"
                        autoFocus
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        onKeyDown={(e) => {
                            escapeCancels(e);

                            if (e.key === 'Enter') {
                                e.preventDefault();
                                onReject(reason);
                            }
                        }}
                        placeholder="Optional — shown on the candidate's record"
                        className="h-9"
                    />
                </div>
                <div className="flex shrink-0 items-center gap-2">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setPending('none')}
                    >
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        size="sm"
                        onClick={() => onReject(reason)}
                    >
                        Reject candidate
                    </Button>
                </div>
            </ModalFooter>
        );
    }

    if (pending === 'remove') {
        return (
            <ModalFooter className="justify-between gap-3">
                <p className="text-sm text-muted-foreground">
                    Remove this application from the pipeline? The candidate's
                    profile is kept.
                </p>
                <div className="flex shrink-0 items-center gap-2">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setPending('none')}
                    >
                        Cancel
                    </Button>
                    <Button variant="destructive" size="sm" onClick={onRemove}>
                        Remove application
                    </Button>
                </div>
            </ModalFooter>
        );
    }

    return (
        <ModalFooter className="justify-start">
            {canHire && (
                <Button size="sm" onClick={onHire}>
                    <UserRoundCheck className="size-4" />
                    Hire candidate
                </Button>
            )}
            {canManage && (
                <Button
                    variant="outline"
                    size="sm"
                    className="text-destructive hover:text-destructive"
                    onClick={() => setPending('reject')}
                >
                    <XCircle className="size-4" />
                    Reject
                </Button>
            )}
            {canManage && (
                <Button
                    variant="ghost"
                    size="sm"
                    className="ml-auto text-muted-foreground hover:text-destructive"
                    onClick={() => setPending('remove')}
                >
                    <Trash2 className="size-4" />
                    Remove
                </Button>
            )}
        </ModalFooter>
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
            <div className="flex items-center justify-center gap-2 py-6 text-sm text-muted-foreground">
                <Spinner />
                Loading interviews…
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
        <ModalSection
            title="Interviews"
            action={
                canSchedule &&
                !showForm && (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setShowForm(true)}
                    >
                        <CalendarPlus className="size-4" />
                        Schedule
                    </Button>
                )
            }
        >
            {detail.interviews.length === 0 && !showForm ? (
                <p className="rounded-lg border border-dashed border-border px-3 py-4 text-center text-sm text-muted-foreground">
                    No interviews scheduled yet.
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
                                <div className="mt-2.5 flex items-center gap-2">
                                    <div className="w-32">
                                        <FormSelect
                                            value={interview.result}
                                            onChange={(v) =>
                                                setResult(interview.id, v)
                                            }
                                            options={RESULT_OPTIONS}
                                        />
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => remove(interview.id)}
                                        className="rounded-md p-1.5 text-muted-foreground hover:bg-destructive/10 hover:text-destructive focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        aria-label={`Remove the interview on ${interview.scheduled_label ?? 'this date'}`}
                                    >
                                        <Trash2 className="size-4" />
                                    </button>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {canSchedule && showForm && (
                <div className="space-y-3 rounded-lg border border-border bg-muted/30 p-3">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div>
                            <Label
                                htmlFor="interview-when"
                                className="mb-1.5 block text-xs"
                            >
                                When
                            </Label>
                            <Input
                                id="interview-when"
                                type="datetime-local"
                                value={scheduledAt}
                                onChange={(e) => setScheduledAt(e.target.value)}
                            />
                        </div>
                        <div>
                            <Label
                                htmlFor="interview-mode"
                                className="mb-1.5 block text-xs"
                            >
                                Mode
                            </Label>
                            <FormSelect
                                id="interview-mode"
                                value={mode}
                                onChange={(v) => setMode(v as InterviewMode)}
                                options={MODE_OPTIONS}
                            />
                        </div>
                        <div>
                            <Label
                                htmlFor="interview-interviewer"
                                className="mb-1.5 block text-xs"
                            >
                                Interviewer
                            </Label>
                            <FormSelect
                                id="interview-interviewer"
                                value={interviewerId}
                                onChange={setInterviewerId}
                                placeholder="Unassigned"
                                noneValue={NONE}
                                options={interviewers.map((u) => ({
                                    value: String(u.id),
                                    label: u.full_name,
                                }))}
                            />
                        </div>
                        <div>
                            <Label
                                htmlFor="interview-location"
                                className="mb-1.5 block text-xs"
                            >
                                Location / link
                            </Label>
                            <Input
                                id="interview-location"
                                value={location}
                                onChange={(e) => setLocation(e.target.value)}
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
                            {busy ? <Spinner /> : <MapPin className="size-4" />}
                            Schedule interview
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
            )}
        </ModalSection>
    );
}

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-baseline justify-between gap-4 text-sm">
            <span className="shrink-0 text-muted-foreground">{label}</span>
            <span className="text-right font-medium">{value}</span>
        </div>
    );
}
