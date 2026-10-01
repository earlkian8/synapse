import { CalendarClock, Clock, Plus, Users2 } from 'lucide-react';
import {
    DataTable,
    EmptyTableRow,
    rowOpens,
    TableCard,
} from '@/components/data-table';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type {
    Application,
    PipelineStage,
    RecruitmentPermissions,
} from '../types';
import { ApplicationActionsMenu } from './application-actions-menu';
import { FitBadge } from './fit-score';
import { RatingStars } from './rating-stars';
import { StageBadge } from './stage-badge';

type Props = {
    applications: Application[];
    /** The posting's open-kind stages, in order — feeds the actions menu. */
    openStages: PipelineStage[];
    can: RecruitmentPermissions;
    filtered: boolean;
    onOpen: (application: Application) => void;
    onMove: (application: Application, stageId: number) => void;
    onHire: (application: Application) => void;
    onReject: (application: Application) => void;
    onAdd: () => void;
};

/**
 * A posting's candidates as a table: how well each fits, where they are in the
 * pipeline, and how long ago they applied. A row opens the candidate; the menu
 * moves, hires or rejects them.
 */
export function PipelineTable({
    applications,
    openStages,
    can,
    filtered,
    onAdd,
    ...handlers
}: Props) {
    return (
        <TableCard>
            <DataTable>
                <TableHeader>
                    <TableRow>
                        <TableHead>Candidate</TableHead>
                        <TableHead>Fit</TableHead>
                        <TableHead>Stage</TableHead>
                        <TableHead>Rating</TableHead>
                        <TableHead className="text-right">Interviews</TableHead>
                        <TableHead className="text-right">Applied</TableHead>
                        <TableHead className="w-10" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {applications.length === 0 && (
                        <EmptyTableRow
                            colSpan={7}
                            icon={Users2}
                            title={
                                filtered
                                    ? 'No candidates match'
                                    : 'No candidates yet'
                            }
                            description={
                                filtered
                                    ? 'Try another search, or focus a different stage.'
                                    : 'Add a candidate to start building this pipeline.'
                            }
                            action={
                                !filtered &&
                                can.create && (
                                    <Button size="sm" onClick={onAdd}>
                                        <Plus className="size-4" />
                                        Add candidate
                                    </Button>
                                )
                            }
                        />
                    )}

                    {applications.map((application) => (
                        <CandidateRow
                            key={application.id}
                            application={application}
                            openStages={openStages}
                            can={can}
                            {...handlers}
                        />
                    ))}
                </TableBody>
            </DataTable>
        </TableCard>
    );
}

function CandidateRow({
    application,
    openStages,
    can,
    onOpen,
    onMove,
    onHire,
    onReject,
}: Omit<Props, 'applications' | 'filtered' | 'onAdd'> & {
    application: Application;
}) {
    const applicant = application.applicant;
    const name = applicant?.full_name ?? 'Unknown applicant';
    const terminal = application.stage_kind !== 'open';

    return (
        <TableRow {...rowOpens(() => onOpen(application))}>
            <TableCell>
                <div className="flex min-w-0 items-center gap-2.5">
                    <Avatar className="size-8 rounded-lg ring-1 ring-border">
                        <AvatarFallback className="rounded-lg bg-[#0F2044] text-[11px] font-semibold text-white">
                            {applicant?.initials ?? '?'}
                        </AvatarFallback>
                    </Avatar>
                    <div className="min-w-0">
                        <button
                            type="button"
                            onClick={() => onOpen(application)}
                            className="block max-w-full truncate text-left text-sm font-medium hover:text-[#0ABFBF]"
                        >
                            {name}
                        </button>
                        <span className="block truncate text-xs text-muted-foreground">
                            {applicant?.headline ?? applicant?.email ?? '—'}
                        </span>
                    </div>
                </div>
            </TableCell>
            <TableCell>
                <FitBadge fit={application.fit} rank={application.fit_rank} />
            </TableCell>
            <TableCell>
                <StageBadge
                    name={application.stage}
                    kind={application.stage_kind}
                />
            </TableCell>
            <TableCell>
                <RatingStars value={application.rating} />
            </TableCell>
            <TableCell className="text-right text-sm text-muted-foreground tabular-nums">
                {(application.interviews_count ?? 0) > 0 ? (
                    <span className="inline-flex items-center gap-1">
                        <CalendarClock className="size-3.5" />
                        {application.interviews_count}
                    </span>
                ) : (
                    '—'
                )}
            </TableCell>
            <TableCell
                className="text-right text-sm text-muted-foreground tabular-nums"
                title={
                    application.age_days !== null
                        ? `Applied ${application.age_days} day${application.age_days === 1 ? '' : 's'} ago`
                        : undefined
                }
            >
                {application.age_days !== null ? (
                    <span className="inline-flex items-center gap-1">
                        <Clock className="size-3.5" />
                        {application.age_days}d
                    </span>
                ) : (
                    '—'
                )}
            </TableCell>
            <TableCell className="text-right">
                {!terminal && (
                    <ApplicationActionsMenu
                        application={application}
                        openStages={openStages}
                        can={can}
                        onMove={onMove}
                        onHire={onHire}
                        onReject={onReject}
                    />
                )}
            </TableCell>
        </TableRow>
    );
}
