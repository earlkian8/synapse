import { Head, router, usePage } from '@inertiajs/react';
import { Plus, Workflow } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { PipelineCard } from '@/features/recruitment-pipelines/components/pipeline-card';
import { PipelineFormDialog } from '@/features/recruitment-pipelines/components/pipeline-form-dialog';
import { recruitmentPipelineRoutes } from '@/features/recruitment-pipelines/routes';
import type {
    Pipeline,
    PipelinesPageProps,
} from '@/features/recruitment-pipelines/types';

export default function SetupRecruitmentPipelines() {
    const { pipelines, can } = usePage<PipelinesPageProps>().props;

    const [formPipeline, setFormPipeline] = useState<Pipeline | null>(null);
    const [formOpen, setFormOpen] = useState(false);
    const [target, setTarget] = useState<Pipeline | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const openCreate = () => {
        setFormPipeline(null);
        setFormOpen(true);
    };

    const openEdit = (pipeline: Pipeline) => {
        setFormPipeline(pipeline);
        setFormOpen(true);
    };

    const askDelete = (pipeline: Pipeline) => {
        setTarget(pipeline);
        setConfirmOpen(true);
    };

    const remove = () => {
        if (!target) {
            return;
        }

        router.delete(recruitmentPipelineRoutes.destroy(target.hashid), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setConfirmOpen(false);
            },
        });
    };

    return (
        <>
            <Head title="Recruitment Pipelines" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div className="flex flex-col gap-1">
                        <h1 className="text-xl font-semibold tracking-tight">
                            Recruitment Pipelines
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            The hiring processes your job postings can run on —
                            each a named, ordered set of stages. Assign a
                            different one to any role that needs its own
                            process.
                        </p>
                    </div>

                    {can.configure && (
                        <Button size="sm" onClick={openCreate}>
                            <Plus className="size-4" />
                            New pipeline
                        </Button>
                    )}
                </div>

                {pipelines.length === 0 ? (
                    <EmptyState
                        onCreate={can.configure ? openCreate : undefined}
                    />
                ) : (
                    <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                        {pipelines.map((pipeline) => (
                            <PipelineCard
                                key={pipeline.id}
                                pipeline={pipeline}
                                canManage={can.configure}
                                onEdit={openEdit}
                                onDelete={askDelete}
                            />
                        ))}
                    </div>
                )}
            </div>

            <PipelineFormDialog
                pipeline={formPipeline}
                open={formOpen}
                onOpenChange={setFormOpen}
            />

            <ConfirmDialog
                open={confirmOpen}
                onOpenChange={setConfirmOpen}
                title={`Delete "${target?.name}"?`}
                description="Postings still assigned to this pipeline keep it — pick another pipeline for them first, or this delete will be refused."
                confirmLabel="Delete pipeline"
                destructive
                processing={processing}
                onConfirm={remove}
            />
        </>
    );
}

function EmptyState({ onCreate }: { onCreate?: () => void }) {
    return (
        <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-16 text-center dark:border-sidebar-border">
            <span className="flex size-11 items-center justify-center rounded-full bg-[#0ABFBF]/10 text-[#0ABFBF]">
                <Workflow className="size-5" />
            </span>
            <p className="text-sm font-medium">No pipelines yet</p>
            <p className="max-w-sm text-sm text-muted-foreground">
                Create a pipeline to define the stages job postings hire through
                — or start from the standard template and adjust it.
            </p>
            {onCreate && (
                <Button size="sm" className="mt-2" onClick={onCreate}>
                    <Plus className="size-4" />
                    New pipeline
                </Button>
            )}
        </div>
    );
}

SetupRecruitmentPipelines.layout = {
    breadcrumbs: [
        { title: 'Company Setup', href: '/setup/departments' },
        {
            title: 'Recruitment Pipelines',
            href: '/setup/recruitment-pipelines',
        },
    ],
};
