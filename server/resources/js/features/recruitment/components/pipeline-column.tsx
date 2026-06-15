import { cn } from '@/lib/utils';
import type { Application, RecruitmentPermissions, Stage } from '../types';
import { ApplicationCard } from './application-card';

type Props = {
    label: string;
    dot: string;
    applications: Application[];
    can: RecruitmentPermissions;
    onOpen: (application: Application) => void;
    onMove: (application: Application, stage: Stage) => void;
    onHire: (application: Application) => void;
    onReject: (application: Application) => void;
};

export function PipelineColumn({
    label,
    dot,
    applications,
    can,
    ...handlers
}: Props) {
    return (
        <div className="flex w-72 shrink-0 flex-col rounded-xl border border-sidebar-border/70 bg-muted/30 dark:border-sidebar-border">
            <div className="flex items-center justify-between px-3 py-2.5">
                <span className="flex items-center gap-2 text-sm font-medium">
                    <span className={cn('size-2 rounded-full', dot)} />
                    {label}
                </span>
                <span className="rounded-full bg-background px-2 py-0.5 text-xs font-medium text-muted-foreground tabular-nums">
                    {applications.length}
                </span>
            </div>

            <div className="flex flex-1 flex-col gap-2 px-2 pb-2">
                {applications.length === 0 ? (
                    <div className="flex flex-1 items-center justify-center rounded-lg border border-dashed border-border/60 py-8 text-center text-xs text-muted-foreground">
                        Empty
                    </div>
                ) : (
                    applications.map((application) => (
                        <ApplicationCard
                            key={application.id}
                            application={application}
                            can={can}
                            {...handlers}
                        />
                    ))
                )}
            </div>
        </div>
    );
}
