import { CalendarClock, ChevronRight, Clock } from 'lucide-react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import type {
    Application,
    PipelineStage,
    RecruitmentPermissions,
} from '../types';
import { ApplicationActionsMenu } from './application-actions-menu';
import { FitBadge } from './fit-score';
import { RatingStars } from './rating-stars';

type Props = {
    application: Application;
    /** The posting's open-kind stages, in order — drives the "Advance" quick action. */
    openStages: PipelineStage[];
    can: RecruitmentPermissions;
    onOpen: (application: Application) => void;
    onMove: (application: Application, stageId: number) => void;
    onHire: (application: Application) => void;
    onReject: (application: Application) => void;
};

export function ApplicationCard({
    application,
    openStages,
    can,
    onOpen,
    onMove,
    onHire,
    onReject,
}: Props) {
    const applicant = application.applicant;
    const terminal = application.stage_kind !== 'open';
    const currentPosition =
        openStages.find((s) => s.id === application.stage_id)?.position ?? -1;
    const nextStage = openStages.find((s) => s.position > currentPosition);

    return (
        <div className="group rounded-lg border border-border bg-card p-3 shadow-sm transition-shadow hover:shadow-md">
            <div className="flex items-start gap-2.5">
                <Avatar className="size-8 rounded-lg ring-1 ring-border">
                    <AvatarFallback className="rounded-lg bg-[#0F2044] text-[10px] font-semibold text-white">
                        {applicant?.initials ?? '?'}
                    </AvatarFallback>
                </Avatar>
                <button
                    type="button"
                    onClick={() => onOpen(application)}
                    className="min-w-0 flex-1 text-left"
                >
                    <span className="flex items-center gap-1.5">
                        <span className="truncate text-sm font-medium hover:underline">
                            {applicant?.full_name ?? 'Unknown applicant'}
                        </span>
                        {application.fit_rank &&
                            application.fit_rank.position <= 3 && (
                                <span className="shrink-0 rounded bg-amber-500/15 px-1 text-[10px] font-semibold text-amber-600 dark:text-amber-400">
                                    #{application.fit_rank.position}
                                </span>
                            )}
                    </span>
                    <span className="block truncate text-xs text-muted-foreground">
                        {applicant?.headline ?? applicant?.email ?? '—'}
                    </span>
                </button>

                {!terminal && (
                    <ApplicationActionsMenu
                        application={application}
                        openStages={openStages}
                        can={can}
                        onMove={onMove}
                        onHire={onHire}
                        onReject={onReject}
                        triggerClassName="opacity-0 group-hover:opacity-100 data-[state=open]:opacity-100"
                    />
                )}
            </div>

            <div className="mt-2.5 flex items-center justify-between">
                <div className="flex items-center gap-2">
                    <FitBadge fit={application.fit} />
                    <RatingStars value={application.rating} />
                </div>
                <div className="flex items-center gap-2 text-[11px] text-muted-foreground">
                    {(application.interviews_count ?? 0) > 0 && (
                        <span className="inline-flex items-center gap-1">
                            <CalendarClock className="size-3" />
                            {application.interviews_count}
                        </span>
                    )}
                    {application.age_days !== null && (
                        <span className="inline-flex items-center gap-1">
                            <Clock className="size-3" />
                            {application.age_days}d
                        </span>
                    )}
                </div>
            </div>

            {!terminal && can.managePipeline && nextStage && (
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="mt-2.5 h-7 w-full gap-1 text-xs"
                    onClick={() => onMove(application, nextStage.id)}
                >
                    Advance to {nextStage.name}
                    <ChevronRight className="size-3.5" />
                </Button>
            )}
        </div>
    );
}
