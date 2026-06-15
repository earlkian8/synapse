import { CalendarClock, Clock, Users2 } from 'lucide-react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { Application, RecruitmentPermissions, Stage } from '../types';
import { ApplicationActionsMenu } from './application-actions-menu';
import { RatingStars } from './rating-stars';
import { StageBadge } from './stage-badge';

type Props = {
    applications: Application[];
    can: RecruitmentPermissions;
    onOpen: (application: Application) => void;
    onMove: (application: Application, stage: Stage) => void;
    onHire: (application: Application) => void;
    onReject: (application: Application) => void;
};

export function PipelineTable({ applications, can, ...handlers }: Props) {
    return (
        <div className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
            <Table>
                <TableHeader className="bg-muted/40">
                    <TableRow className="hover:bg-transparent">
                        <TableHead>Candidate</TableHead>
                        <TableHead>Stage</TableHead>
                        <TableHead>Rating</TableHead>
                        <TableHead>Interviews</TableHead>
                        <TableHead>Applied</TableHead>
                        <TableHead className="w-10 pr-4" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {applications.length === 0 && (
                        <TableRow className="hover:bg-transparent">
                            <TableCell colSpan={6} className="py-16">
                                <div className="flex flex-col items-center justify-center gap-2 text-center">
                                    <span className="flex size-12 items-center justify-center rounded-full bg-muted">
                                        <Users2 className="size-6 text-muted-foreground" />
                                    </span>
                                    <p className="text-sm font-medium">
                                        No candidates yet
                                    </p>
                                    <p className="max-w-xs text-sm text-muted-foreground">
                                        Add a candidate to start building this
                                        pipeline.
                                    </p>
                                </div>
                            </TableCell>
                        </TableRow>
                    )}

                    {applications.map((application) => {
                        const applicant = application.applicant;
                        const terminal =
                            application.stage === 'hired' ||
                            application.stage === 'rejected';

                        return (
                            <TableRow key={application.id}>
                                <TableCell>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            handlers.onOpen(application)
                                        }
                                        className="flex items-center gap-3 text-left"
                                    >
                                        <Avatar className="size-8 rounded-lg ring-1 ring-border">
                                            <AvatarFallback className="rounded-lg bg-[#0F2044] text-[10px] font-semibold text-white">
                                                {applicant?.initials ?? '?'}
                                            </AvatarFallback>
                                        </Avatar>
                                        <span className="flex min-w-0 flex-col">
                                            <span className="truncate text-sm font-medium hover:underline">
                                                {applicant?.full_name ??
                                                    'Unknown applicant'}
                                            </span>
                                            <span className="truncate text-xs text-muted-foreground">
                                                {applicant?.headline ??
                                                    applicant?.email ??
                                                    '—'}
                                            </span>
                                        </span>
                                    </button>
                                </TableCell>
                                <TableCell>
                                    <StageBadge stage={application.stage} />
                                </TableCell>
                                <TableCell>
                                    <RatingStars value={application.rating} />
                                </TableCell>
                                <TableCell className="text-sm text-muted-foreground tabular-nums">
                                    {(application.interviews_count ?? 0) > 0 ? (
                                        <span className="inline-flex items-center gap-1">
                                            <CalendarClock className="size-3.5" />
                                            {application.interviews_count}
                                        </span>
                                    ) : (
                                        '—'
                                    )}
                                </TableCell>
                                <TableCell className="text-sm text-muted-foreground">
                                    {application.age_days !== null ? (
                                        <span className="inline-flex items-center gap-1 tabular-nums">
                                            <Clock className="size-3.5" />
                                            {application.age_days}d
                                        </span>
                                    ) : (
                                        '—'
                                    )}
                                </TableCell>
                                <TableCell className="pr-4 text-right">
                                    {!terminal && (
                                        <ApplicationActionsMenu
                                            application={application}
                                            can={can}
                                            onMove={handlers.onMove}
                                            onHire={handlers.onHire}
                                            onReject={handlers.onReject}
                                            triggerClassName="ml-auto"
                                        />
                                    )}
                                </TableCell>
                            </TableRow>
                        );
                    })}
                </TableBody>
            </Table>
        </div>
    );
}
