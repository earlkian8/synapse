import { Users2 } from 'lucide-react';
import type { Application, RecruitmentPermissions, Stage } from '../types';
import { ApplicationCard } from './application-card';

type Props = {
    applications: Application[];
    can: RecruitmentPermissions;
    onOpen: (application: Application) => void;
    onMove: (application: Application, stage: Stage) => void;
    onHire: (application: Application) => void;
    onReject: (application: Application) => void;
};

/** The pipeline as a flat, responsive card grid (mirrors the postings grid). */
export function PipelineGrid({ applications, can, ...handlers }: Props) {
    if (applications.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 bg-card py-16 text-center dark:border-sidebar-border">
                <span className="flex size-12 items-center justify-center rounded-full bg-muted">
                    <Users2 className="size-6 text-muted-foreground" />
                </span>
                <p className="text-sm font-medium">No candidates here</p>
                <p className="max-w-xs text-sm text-muted-foreground">
                    Add a candidate or pick another stage to see applicants.
                </p>
            </div>
        );
    }

    return (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            {applications.map((application) => (
                <ApplicationCard
                    key={application.id}
                    application={application}
                    can={can}
                    {...handlers}
                />
            ))}
        </div>
    );
}
