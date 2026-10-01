import { router } from '@inertiajs/react';
import { Plus, Workflow } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { recruitmentPipelineRoutes } from '../routes';
import type { Pipeline, PipelinesPageProps } from '../types';
import { PipelineCard } from './pipeline-card';
import { PipelineFormDialog } from './pipeline-form-dialog';

type Props = PipelinesPageProps & {
    /** What sits beside the actions — the screen's title, or a step's section heading. */
    heading?: ReactNode;
};

/**
 * The company's hiring processes (ADR 0029) with every action Company Setup
 * offers on them — create, edit (stages, order, default) and delete. Rendered by
 * the Recruitment Pipelines screen and by the setup wizard's step for it, from
 * the same props.
 */
export function PipelinesManager({ pipelines, can, heading }: Props) {
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
            <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                {heading}

                {can.configure && (
                    <Button
                        size="sm"
                        className="lg:ml-auto"
                        onClick={openCreate}
                    >
                        <Plus className="size-4" />
                        New pipeline
                    </Button>
                )}
            </div>

            {pipelines.length === 0 ? (
                <EmptyState onCreate={can.configure ? openCreate : undefined} />
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
