import { Trophy, XCircle } from 'lucide-react';
import { cn } from '@/lib/utils';
import { STAGE_KIND_DOT } from '../constants';
import type {
    Application,
    PipelineStage,
    RecruitmentPermissions,
} from '../types';
import { ApplicationCard } from './application-card';

type Props = {
    /** Every stage in the posting's pipeline, already in position order. */
    stages: PipelineStage[];
    applications: Application[];
    can: RecruitmentPermissions;
    onOpen: (application: Application) => void;
    onMove: (application: Application, stageId: number) => void;
    onHire: (application: Application) => void;
    onReject: (application: Application) => void;
};

/**
 * The pipeline as a Kanban board — one column per stage, left to right in the
 * order HR defined them, so the hiring process reads as a sequence at a
 * glance instead of hiding behind a stage-filter tab. Open stages lead;
 * "Hired" and "Rejected/lost" stages trail, visually de-emphasised since
 * candidates who land there are done moving.
 */
export function PipelineBoard({
    stages,
    applications,
    can,
    onOpen,
    onMove,
    onHire,
    onReject,
}: Props) {
    const openStages = stages.filter((s) => s.kind === 'open');

    const byStage = new Map<number, Application[]>();

    for (const stage of stages) {
        byStage.set(stage.id, []);
    }

    for (const application of applications) {
        byStage.get(application.stage_id)?.push(application);
    }

    if (stages.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center gap-1 rounded-xl border border-dashed border-border py-16 text-center">
                <p className="text-sm font-medium">
                    This posting has no pipeline stages
                </p>
                <p className="max-w-sm text-sm text-muted-foreground">
                    Ask an administrator to assign a hiring process to this
                    posting from Company Setup.
                </p>
            </div>
        );
    }

    return (
        <div className="flex gap-3 overflow-x-auto pb-2">
            {stages.map((stage) => (
                <BoardColumn
                    key={stage.id}
                    stage={stage}
                    openStages={openStages}
                    applications={byStage.get(stage.id) ?? []}
                    can={can}
                    onOpen={onOpen}
                    onMove={onMove}
                    onHire={onHire}
                    onReject={onReject}
                />
            ))}
        </div>
    );
}

function BoardColumn({
    stage,
    openStages,
    applications,
    can,
    onOpen,
    onMove,
    onHire,
    onReject,
}: {
    stage: PipelineStage;
    openStages: PipelineStage[];
    applications: Application[];
    can: RecruitmentPermissions;
    onOpen: (application: Application) => void;
    onMove: (application: Application, stageId: number) => void;
    onHire: (application: Application) => void;
    onReject: (application: Application) => void;
}) {
    const terminal = stage.kind !== 'open';

    return (
        <div
            className={cn(
                'flex w-72 shrink-0 flex-col rounded-xl border border-sidebar-border/70 bg-muted/20 dark:border-sidebar-border',
                terminal && 'opacity-90',
            )}
        >
            <div className="flex items-center gap-2 border-b border-border/60 px-3 py-2.5">
                <span
                    className={cn(
                        'size-1.5 shrink-0 rounded-full',
                        STAGE_KIND_DOT[stage.kind],
                    )}
                />
                <span className="min-w-0 flex-1 truncate text-xs font-semibold tracking-tight">
                    {stage.name}
                </span>
                {stage.kind === 'won' && (
                    <Trophy className="size-3.5 shrink-0 text-emerald-500" />
                )}
                {stage.kind === 'lost' && (
                    <XCircle className="size-3.5 shrink-0 text-slate-400" />
                )}
                <span className="shrink-0 rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground tabular-nums">
                    {applications.length}
                </span>
            </div>

            <div className="flex flex-1 flex-col gap-2 p-2">
                {applications.length === 0 ? (
                    <p className="rounded-lg border border-dashed border-border/70 px-2 py-6 text-center text-xs text-muted-foreground">
                        No candidates
                    </p>
                ) : (
                    applications.map((application) => (
                        <ApplicationCard
                            key={application.id}
                            application={application}
                            openStages={openStages}
                            can={can}
                            onOpen={onOpen}
                            onMove={onMove}
                            onHire={onHire}
                            onReject={onReject}
                        />
                    ))
                )}
            </div>
        </div>
    );
}
