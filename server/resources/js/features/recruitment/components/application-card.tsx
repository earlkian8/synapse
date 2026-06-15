import { CalendarClock, Clock } from 'lucide-react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import type { Application, RecruitmentPermissions, Stage } from '../types';
import { ApplicationActionsMenu } from './application-actions-menu';
import { RatingStars } from './rating-stars';

type Props = {
    application: Application;
    can: RecruitmentPermissions;
    onOpen: (application: Application) => void;
    onMove: (application: Application, stage: Stage) => void;
    onHire: (application: Application) => void;
    onReject: (application: Application) => void;
};

export function ApplicationCard({
    application,
    can,
    onOpen,
    onMove,
    onHire,
    onReject,
}: Props) {
    const applicant = application.applicant;
    const terminal =
        application.stage === 'hired' || application.stage === 'rejected';

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
                    <span className="block truncate text-sm font-medium hover:underline">
                        {applicant?.full_name ?? 'Unknown applicant'}
                    </span>
                    <span className="block truncate text-xs text-muted-foreground">
                        {applicant?.headline ?? applicant?.email ?? '—'}
                    </span>
                </button>

                {!terminal && (
                    <ApplicationActionsMenu
                        application={application}
                        can={can}
                        onMove={onMove}
                        onHire={onHire}
                        onReject={onReject}
                        triggerClassName="opacity-0 group-hover:opacity-100 data-[state=open]:opacity-100"
                    />
                )}
            </div>

            <div className="mt-2.5 flex items-center justify-between">
                <RatingStars value={application.rating} />
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
        </div>
    );
}
