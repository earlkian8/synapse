import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, KanbanSquare, List, Plus, Users2 } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { AddCandidateDialog } from '@/features/recruitment/components/add-candidate-dialog';
import { ApplicationDetailDialog } from '@/features/recruitment/components/application-detail-dialog';
import { ConfirmDialog } from '@/features/recruitment/components/confirm-dialog';
import { PipelineBoard } from '@/features/recruitment/components/pipeline-board';
import { PipelineInsights } from '@/features/recruitment/components/pipeline-insights';
import { PipelineTable } from '@/features/recruitment/components/pipeline-table';
import { PipelineToolbar } from '@/features/recruitment/components/pipeline-toolbar';
import { PostingDeadline } from '@/features/recruitment/components/posting-deadline';
import { PostingStatusBadge } from '@/features/recruitment/components/posting-status-badge';
import { TYPE_LABELS } from '@/features/recruitment/constants';
import { usePipelineView } from '@/features/recruitment/hooks/use-pipeline-view';
import { recruitmentRoutes } from '@/features/recruitment/routes';
import type {
    Application,
    PipelinePageProps,
    PipelineSort,
    PipelineStage,
    PipelineView,
} from '@/features/recruitment/types';

type ConfirmConfig = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    destructive?: boolean;
    /** Tags the hire action so its invitation toggle can be rendered. */
    kind?: 'hire';
    run: () => void;
};

export default function RecruitmentPipeline() {
    const { posting, applications, insights, options, can } =
        usePage<PipelinePageProps>().props;
    const { view, changeView } = usePipelineView();

    const stages = useMemo(() => posting.pipeline?.stages ?? [], [posting]);
    const openStages = useMemo(
        () => stages.filter((s) => s.kind === 'open'),
        [stages],
    );

    // Which stage the decision-support panel (and, in table view, the row
    // filter) is focused on. The board itself always shows every stage at
    // once — that's the point — so this never hides a column.
    const [focus, setFocus] = useState<PipelineStage | 'all'>('all');
    const [search, setSearch] = useState('');
    const [sort, setSort] = useState<PipelineSort>('default');
    const [detailApp, setDetailApp] = useState<Application | null>(null);
    const [detailOpen, setDetailOpen] = useState(false);
    const [addOpen, setAddOpen] = useState(false);
    const [confirm, setConfirm] = useState<ConfirmConfig | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    // Whether hiring should invite the new hire to the app. A ref mirrors the
    // state so the hire request reads the live value, not the value captured
    // when the confirm dialog was opened.
    const [sendInvitation, setSendInvitation] = useState(true);
    const sendInvitationRef = useRef(true);
    const toggleSendInvitation = (value: boolean) => {
        sendInvitationRef.current = value;
        setSendInvitation(value);
    };

    // The board always shows every stage; the table (a bulk-scanning view)
    // additionally narrows to the focused stage.
    const scope = useMemo(
        () =>
            view === 'table' && focus !== 'all'
                ? applications.filter((a) => a.stage_id === focus.id)
                : applications,
        [applications, view, focus],
    );

    const visibleApplications = useMemo(() => {
        const term = search.trim().toLowerCase();

        const filtered = term
            ? scope.filter((a) => {
                  const applicant = a.applicant;

                  return [
                      applicant?.full_name,
                      applicant?.email,
                      applicant?.headline,
                  ]
                      .filter(Boolean)
                      .some((field) => field!.toLowerCase().includes(term));
              })
            : scope;

        if (sort === 'default') {
            return filtered;
        }

        const time = (value: string | null) =>
            value ? new Date(value).getTime() : 0;

        return [...filtered].sort((a, b) => {
            switch (sort) {
                case 'fit':
                    return (b.fit?.value ?? -1) - (a.fit?.value ?? -1);
                case 'rating':
                    return (b.rating ?? -1) - (a.rating ?? -1);
                case 'recent':
                    return time(b.applied_at) - time(a.applied_at);
                case 'oldest':
                    return time(a.applied_at) - time(b.applied_at);
                case 'name':
                    return (a.applicant?.full_name ?? '').localeCompare(
                        b.applicant?.full_name ?? '',
                    );
                default:
                    return 0;
            }
        });
    }, [scope, search, sort]);

    const askConfirm = (config: ConfirmConfig) => {
        setConfirm(config);
        setConfirmOpen(true);
    };

    const withProcessing = {
        preserveScroll: true,
        onStart: () => setProcessing(true),
        onFinish: () => {
            setProcessing(false);
            setConfirmOpen(false);
        },
    };

    const openDetail = (application: Application) => {
        setDetailApp(application);
        setDetailOpen(true);
    };

    const move = (application: Application, stageId: number) =>
        router.patch(
            recruitmentRoutes.applicationStage(application.id),
            { stage_id: stageId },
            { preserveScroll: true },
        );

    const hire = (application: Application) => {
        toggleSendInvitation(true);
        askConfirm({
            title: `Hire ${application.applicant?.full_name}?`,
            description:
                'This creates an employee record from the applicant and this posting, copies their résumé into the 201 file, and marks the application hired.',
            confirmLabel: 'Hire & create employee',
            kind: 'hire',
            run: () =>
                router.post(
                    recruitmentRoutes.applicationHire(application.id),
                    { send_invitation: sendInvitationRef.current },
                    withProcessing,
                ),
        });
    };

    const reject = (application: Application) =>
        askConfirm({
            title: `Reject ${application.applicant?.full_name}?`,
            description:
                "The candidate will be moved to the pipeline's rejected stage. You can add a reason from the candidate drawer instead.",
            confirmLabel: 'Reject',
            destructive: true,
            run: () =>
                router.patch(
                    recruitmentRoutes.applicationReject(application.id),
                    { reason: null },
                    withProcessing,
                ),
        });

    return (
        <>
            <Head title={`${posting.title} — Pipeline`} />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div className="flex items-start gap-3">
                        <Button
                            variant="outline"
                            size="icon"
                            className="size-9 shrink-0"
                            asChild
                        >
                            <Link
                                href={recruitmentRoutes.index}
                                aria-label="Back to postings"
                            >
                                <ArrowLeft className="size-4" />
                            </Link>
                        </Button>
                        <div>
                            <p className="text-xs text-muted-foreground">
                                <Link
                                    href={recruitmentRoutes.index}
                                    className="hover:text-foreground hover:underline"
                                >
                                    Recruitment
                                </Link>
                                {' / '}
                                <span>{posting.title}</span>
                            </p>
                            <div className="mt-0.5 flex items-center gap-2">
                                <h1 className="text-xl font-semibold tracking-tight">
                                    {posting.title}
                                </h1>
                                <PostingStatusBadge status={posting.status} />
                            </div>
                            <p className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground">
                                <span>
                                    {posting.department?.name ??
                                        'No department'}{' '}
                                    · {TYPE_LABELS[posting.employment_type]}
                                    {posting.pipeline
                                        ? ` · ${posting.pipeline.name}`
                                        : ''}
                                </span>
                                <span aria-hidden>·</span>
                                <span className="inline-flex items-center gap-1">
                                    <Users2 className="size-3.5" />
                                    {posting.hired_count ?? 0}/
                                    {posting.openings} hired
                                </span>
                                {posting.closing_date && (
                                    <>
                                        <span aria-hidden>·</span>
                                        <PostingDeadline posting={posting} />
                                    </>
                                )}
                            </p>
                            {posting.skills.length > 0 && (
                                <div className="mt-2 flex flex-wrap items-center gap-1.5">
                                    {posting.min_years_experience != null && (
                                        <span className="rounded-md bg-muted px-1.5 py-0.5 text-[11px] font-medium text-muted-foreground">
                                            {posting.min_years_experience}+ yrs
                                        </span>
                                    )}
                                    {posting.skills.map((skill) => (
                                        <span
                                            key={skill}
                                            className="rounded-md bg-muted px-1.5 py-0.5 text-[11px] font-medium text-muted-foreground"
                                        >
                                            {skill}
                                        </span>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <ToggleGroup
                            type="single"
                            value={view}
                            onValueChange={(value) =>
                                value && changeView(value as PipelineView)
                            }
                            variant="outline"
                            size="sm"
                            aria-label="Switch layout"
                        >
                            <ToggleGroupItem
                                value="board"
                                aria-label="Board view"
                            >
                                <KanbanSquare className="size-4" />
                            </ToggleGroupItem>
                            <ToggleGroupItem
                                value="table"
                                aria-label="Table view"
                            >
                                <List className="size-4" />
                            </ToggleGroupItem>
                        </ToggleGroup>

                        {can.create && (
                            <Button size="sm" onClick={() => setAddOpen(true)}>
                                <Plus className="size-4" />
                                Add candidate
                            </Button>
                        )}
                    </div>
                </div>

                <div className="flex flex-col gap-4">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <Select
                            value={focus === 'all' ? 'all' : String(focus.id)}
                            onValueChange={(value) =>
                                setFocus(
                                    value === 'all'
                                        ? 'all'
                                        : (stages.find(
                                              (s) => String(s.id) === value,
                                          ) ?? 'all'),
                                )
                            }
                        >
                            <SelectTrigger
                                className="w-[190px]"
                                aria-label="Focus a stage"
                            >
                                <SelectValue placeholder="All stages" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All stages</SelectItem>
                                {stages.map((s) => (
                                    <SelectItem key={s.id} value={String(s.id)}>
                                        {s.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <PipelineInsights insights={insights} stage={focus} />

                    <PipelineToolbar
                        search={search}
                        sort={sort}
                        shown={visibleApplications.length}
                        total={scope.length}
                        canExport={can.export}
                        exportUrl={recruitmentRoutes.pipelineExport(
                            posting.hashid,
                        )}
                        onSearch={setSearch}
                        onSort={setSort}
                    />

                    {view === 'board' ? (
                        <PipelineBoard
                            stages={stages}
                            applications={visibleApplications}
                            can={can}
                            onOpen={openDetail}
                            onMove={move}
                            onHire={hire}
                            onReject={reject}
                        />
                    ) : (
                        <PipelineTable
                            applications={visibleApplications}
                            openStages={openStages}
                            can={can}
                            onOpen={openDetail}
                            onMove={move}
                            onHire={hire}
                            onReject={reject}
                        />
                    )}
                </div>
            </div>

            <AddCandidateDialog
                postingId={posting.hashid}
                options={options}
                open={addOpen}
                onOpenChange={setAddOpen}
            />

            <ApplicationDetailDialog
                application={detailApp}
                open={detailOpen}
                can={can}
                interviewers={options.interviewers}
                openStages={openStages}
                onOpenChange={setDetailOpen}
            />

            {confirm && (
                <ConfirmDialog
                    open={confirmOpen}
                    onOpenChange={setConfirmOpen}
                    title={confirm.title}
                    description={confirm.description}
                    confirmLabel={confirm.confirmLabel}
                    destructive={confirm.destructive}
                    processing={processing}
                    extra={
                        confirm.kind === 'hire' ? (
                            <label className="flex items-start gap-3 rounded-lg border bg-muted/40 p-3">
                                <Checkbox
                                    checked={sendInvitation}
                                    onCheckedChange={(value) =>
                                        toggleSendInvitation(value === true)
                                    }
                                    className="mt-0.5"
                                />
                                <span className="space-y-0.5">
                                    <Label className="cursor-pointer">
                                        Invite the new hire to the app
                                    </Label>
                                    <span className="block text-xs text-muted-foreground">
                                        Emails them a link and a code to claim
                                        this record with an account they set up
                                        themselves. You can invite them later
                                        from App access instead.
                                    </span>
                                </span>
                            </label>
                        ) : undefined
                    }
                    onConfirm={confirm.run}
                />
            )}
        </>
    );
}

RecruitmentPipeline.layout = (props: PipelinePageProps) => ({
    breadcrumbs: [
        { title: 'Recruitment', href: recruitmentRoutes.index },
        {
            title: props.posting.title,
            href: recruitmentRoutes.show(props.posting.hashid),
        },
    ],
});
