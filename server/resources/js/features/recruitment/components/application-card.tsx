import {
    CalendarClock,
    Clock,
    MoreHorizontal,
    MoveRight,
    UserRoundCheck,
    XCircle,
} from 'lucide-react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { MOVABLE_STAGES } from '../constants';
import type { Application, RecruitmentPermissions, Stage } from '../types';
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
    const canHire =
        can.hire && ['interview', 'offer'].includes(application.stage);

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
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button
                                type="button"
                                className="rounded-md p-1 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100 hover:bg-muted data-[state=open]:bg-muted data-[state=open]:opacity-100"
                                aria-label="Card actions"
                            >
                                <MoreHorizontal className="size-4" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-44">
                            {can.managePipeline && (
                                <DropdownMenuSub>
                                    <DropdownMenuSubTrigger>
                                        <MoveRight className="size-4" />
                                        Move to
                                    </DropdownMenuSubTrigger>
                                    <DropdownMenuSubContent>
                                        {MOVABLE_STAGES.filter(
                                            (s) => s.value !== application.stage,
                                        ).map((s) => (
                                            <DropdownMenuItem
                                                key={s.value}
                                                onSelect={() =>
                                                    onMove(application, s.value)
                                                }
                                            >
                                                {s.label}
                                            </DropdownMenuItem>
                                        ))}
                                    </DropdownMenuSubContent>
                                </DropdownMenuSub>
                            )}
                            {canHire && (
                                <DropdownMenuItem
                                    onSelect={() => onHire(application)}
                                >
                                    <UserRoundCheck className="size-4" />
                                    Hire
                                </DropdownMenuItem>
                            )}
                            {can.managePipeline && (
                                <>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        variant="destructive"
                                        onSelect={() => onReject(application)}
                                    >
                                        <XCircle className="size-4" />
                                        Reject
                                    </DropdownMenuItem>
                                </>
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
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
