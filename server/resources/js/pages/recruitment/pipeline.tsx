import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, LayoutGrid, List, Plus, Users2 } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { AddCandidateSheet } from '@/features/recruitment/components/add-candidate-sheet';
import { ApplicationDetailSheet } from '@/features/recruitment/components/application-detail-sheet';
import { ConfirmDialog } from '@/features/recruitment/components/confirm-dialog';
import { PipelineGrid } from '@/features/recruitment/components/pipeline-grid';
import { PipelineStageTabs } from '@/features/recruitment/components/pipeline-stage-tabs';
import { PipelineTable } from '@/features/recruitment/components/pipeline-table';
import { PostingStatusBadge } from '@/features/recruitment/components/posting-status-badge';
import { TYPE_LABELS } from '@/features/recruitment/constants';
import { usePipelineView } from '@/features/recruitment/hooks/use-pipeline-view';
import { recruitmentRoutes } from '@/features/recruitment/routes';
import type {
    Application,
    PipelinePageProps,
    PipelineView,
    Stage,
    StageFilter,
} from '@/features/recruitment/types';

type ConfirmConfig = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    destructive?: boolean;
    /** Tags the hire action so its credential-email toggle can be rendered. */
    kind?: 'hire';
    run: () => void;
};

export default function RecruitmentPipeline() {
    const { posting, applications, options, can } =
        usePage<PipelinePageProps>().props;
    const { view, changeView } = usePipelineView();

    const [stage, setStage] = useState<StageFilter>('all');
    const [detailApp, setDetailApp] = useState<Application | null>(null);
    const [detailOpen, setDetailOpen] = useState(false);
    const [addOpen, setAddOpen] = useState(false);
    const [confirm, setConfirm] = useState<ConfirmConfig | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    // Whether hiring should email the new hire their login credentials. A ref
    // mirrors the state so the hire request reads the live value, not the value
    // captured when the confirm dialog was opened.
    const [sendCredentials, setSendCredentials] = useState(true);
    const sendCredentialsRef = useRef(true);
    const toggleSendCredentials = (value: boolean) => {
        sendCredentialsRef.current = value;
        setSendCredentials(value);
    };

    const stageCounts = useMemo(() => {
        const counts: Record<Stage, number> = {
            applied: 0,
            screening: 0,
            interview: 0,
            offer: 0,
            hired: 0,
            rejected: 0,
        };

        for (const application of applications) {
            counts[application.stage] += 1;
        }

        return counts;
    }, [applications]);

    const visibleApplications = useMemo(
        () =>
            stage === 'all'
                ? applications
                : applications.filter((a) => a.stage === stage),
        [applications, stage],
    );

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

    const move = (application: Application, stage: Stage) =>
        router.patch(
            recruitmentRoutes.applicationStage(application.id),
            { stage },
            { preserveScroll: true },
        );

    const hire = (application: Application) => {
        toggleSendCredentials(true);
        askConfirm({
            title: `Hire ${application.applicant?.full_name}?`,
            description:
                'This creates an employee record from the applicant and this posting, provisions their mobile-app login, copies their résumé into the 201 file, and marks the application hired.',
            confirmLabel: 'Hire & create employee',
            kind: 'hire',
            run: () =>
                router.post(
                    recruitmentRoutes.applicationHire(application.id),
                    { send_credentials: sendCredentialsRef.current },
                    withProcessing,
                ),
        });
    };

    const reject = (application: Application) =>
        askConfirm({
            title: `Reject ${application.applicant?.full_name}?`,
            description:
                'The candidate will be moved to the Rejected column. You can add a reason from the candidate drawer instead.',
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
                            <div className="flex items-center gap-2">
                                <h1 className="text-xl font-semibold tracking-tight">
                                    {posting.title}
                                </h1>
                                <PostingStatusBadge status={posting.status} />
                            </div>
                            <p className="mt-0.5 text-sm text-muted-foreground">
                                {posting.department?.name ?? 'No department'} ·{' '}
                                {TYPE_LABELS[posting.employment_type]} ·{' '}
                                <span className="inline-flex items-center gap-1">
                                    <Users2 className="size-3.5" />
                                    {posting.hired_count ?? 0}/
                                    {posting.openings} hired
                                </span>
                            </p>
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
                                value="table"
                                aria-label="Table view"
                            >
                                <List className="size-4" />
                            </ToggleGroupItem>
                            <ToggleGroupItem
                                value="grid"
                                aria-label="Card view"
                            >
                                <LayoutGrid className="size-4" />
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
                    <PipelineStageTabs
                        value={stage}
                        counts={stageCounts}
                        total={applications.length}
                        onChange={setStage}
                    />

                    {view === 'grid' ? (
                        <PipelineGrid
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
                            can={can}
                            onOpen={openDetail}
                            onMove={move}
                            onHire={hire}
                            onReject={reject}
                        />
                    )}
                </div>
            </div>

            <AddCandidateSheet
                postingId={posting.hashid}
                options={options}
                open={addOpen}
                onOpenChange={setAddOpen}
            />

            <ApplicationDetailSheet
                application={detailApp}
                open={detailOpen}
                can={can}
                interviewers={options.interviewers}
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
                                    checked={sendCredentials}
                                    onCheckedChange={(value) =>
                                        toggleSendCredentials(value === true)
                                    }
                                    className="mt-0.5"
                                />
                                <span className="space-y-0.5">
                                    <Label className="cursor-pointer">
                                        Email login credentials to the new hire
                                    </Label>
                                    <span className="block text-xs text-muted-foreground">
                                        Sends their email and a temporary
                                        password so they can sign in to the
                                        SYNAPSE app.
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
