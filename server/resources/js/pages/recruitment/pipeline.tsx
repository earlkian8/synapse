import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Plus, Users2 } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { AddCandidateSheet } from '@/features/recruitment/components/add-candidate-sheet';
import { ApplicationDetailSheet } from '@/features/recruitment/components/application-detail-sheet';
import { ConfirmDialog } from '@/features/recruitment/components/confirm-dialog';
import { PipelineBoard } from '@/features/recruitment/components/pipeline-board';
import { PostingStatusBadge } from '@/features/recruitment/components/posting-status-badge';
import { TYPE_LABELS } from '@/features/recruitment/constants';
import { recruitmentRoutes } from '@/features/recruitment/routes';
import type {
    Application,
    PipelinePageProps,
    Stage,
} from '@/features/recruitment/types';

type ConfirmConfig = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    destructive?: boolean;
    run: () => void;
};

export default function RecruitmentPipeline() {
    const { posting, applications, options, can } =
        usePage<PipelinePageProps>().props;

    const [detailApp, setDetailApp] = useState<Application | null>(null);
    const [detailOpen, setDetailOpen] = useState(false);
    const [addOpen, setAddOpen] = useState(false);
    const [confirm, setConfirm] = useState<ConfirmConfig | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

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

    const hire = (application: Application) =>
        askConfirm({
            title: `Hire ${application.applicant?.full_name}?`,
            description:
                'This creates an employee record from the applicant and this posting, copies their résumé into the 201 file, and marks the application hired.',
            confirmLabel: 'Hire & create employee',
            run: () =>
                router.post(
                    recruitmentRoutes.applicationHire(application.id),
                    {},
                    withProcessing,
                ),
        });

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
                                    {posting.hired_count ?? 0}/{posting.openings}{' '}
                                    hired
                                </span>
                            </p>
                        </div>
                    </div>

                    {can.create && (
                        <Button size="sm" onClick={() => setAddOpen(true)}>
                            <Plus className="size-4" />
                            Add candidate
                        </Button>
                    )}
                </div>

                <PipelineBoard
                    applications={applications}
                    can={can}
                    onOpen={openDetail}
                    onMove={move}
                    onHire={hire}
                    onReject={reject}
                />
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
