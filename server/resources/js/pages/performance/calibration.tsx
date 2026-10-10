import { Head, router, usePage } from '@inertiajs/react';
import { CalendarRange, Plus, Scale } from 'lucide-react';
import { useState } from 'react';
import {
    DataTable,
    EmptyTableRow,
    PageBody,
    PageHeader,
    rowOpens,
    TableCard,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { PerformanceNav } from '@/features/performance/components/performance-nav';
import { SessionFormModal } from '@/features/performance/components/session-form-modal';
import { PeriodStatusBadge } from '@/features/performance/components/status-badge';
import {
    formatDate,
    PILL,
    SESSION_STATUS,
} from '@/features/performance/constants';
import { performanceRoutes } from '@/features/performance/routes';
import type { CalibrationIndexPageProps } from '@/features/performance/types';
import { cn } from '@/lib/utils';

/**
 * Calibration (ADR 0073): the sessions of a review cycle, where the people
 * running it compare submitted ratings side by side and move the ones that read
 * differently from the rest, each with a reason. A session holds its appraisals
 * back from employees until it is complete.
 */
export default function Calibration() {
    const {
        sessions,
        periods,
        currentPeriodId,
        departments,
        calibrators,
        can,
        nav,
    } = usePage<CalibrationIndexPageProps>().props;

    const [creating, setCreating] = useState(false);
    const period = periods.find((p) => p.id === currentPeriodId) ?? null;
    const usable = periods.filter(
        (p) => p.status !== 'draft' && !p.is_archived,
    );

    return (
        <>
            <Head title="Calibration" />

            <PageBody>
                <PageHeader
                    title="Calibration"
                    description={
                        <div className="mt-1 flex flex-wrap items-center gap-2">
                            <span className="flex items-center gap-1.5">
                                <CalendarRange className="size-4" />
                                Review cycle
                            </span>
                            <Select
                                value={
                                    currentPeriodId === null
                                        ? ''
                                        : String(currentPeriodId)
                                }
                                onValueChange={(value) =>
                                    router.get(
                                        performanceRoutes.calibration(
                                            Number(value),
                                        ),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <SelectTrigger
                                    size="sm"
                                    className="w-56 text-foreground"
                                    aria-label="Review cycle"
                                >
                                    <SelectValue placeholder="No cycles yet" />
                                </SelectTrigger>
                                <SelectContent>
                                    {periods.map((p) => (
                                        <SelectItem
                                            key={p.id}
                                            value={String(p.id)}
                                        >
                                            {p.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {period && (
                                <PeriodStatusBadge status={period.status} />
                            )}
                        </div>
                    }
                    actions={
                        <PerformanceNav current="calibration" counts={nav} />
                    }
                />

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        Compare a cycle’s submitted ratings side by side and
                        move any that were rated against a different bar. Each
                        move needs a reason; attainment is left as scored.
                    </p>
                    {can.manage && (
                        <Button
                            size="sm"
                            onClick={() => setCreating(true)}
                            disabled={usable.length === 0}
                        >
                            <Plus className="size-4" />
                            New session
                        </Button>
                    )}
                </div>

                <TableCard>
                    <DataTable>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Session</TableHead>
                                <TableHead>Covers</TableHead>
                                <TableHead className="w-32">Meets</TableHead>
                                <TableHead className="w-28 text-right">
                                    Moves
                                </TableHead>
                                <TableHead className="w-28">Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {sessions.length === 0 ? (
                                <EmptyTableRow
                                    colSpan={5}
                                    icon={Scale}
                                    title="No sessions in this cycle"
                                    description={
                                        can.manage
                                            ? 'Open one once managers have submitted their appraisals, for the whole cycle or a few departments at a time.'
                                            : 'Nobody has calibrated this cycle yet.'
                                    }
                                />
                            ) : (
                                sessions.map((session) => (
                                    <TableRow
                                        key={session.id}
                                        {...rowOpens(
                                            performanceRoutes.session(
                                                session.hashid,
                                            ),
                                        )}
                                    >
                                        <TableCell className="whitespace-normal">
                                            <p className="min-w-40 text-sm font-medium">
                                                {session.name}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {session.facilitator
                                                    ? `Run by ${session.facilitator}`
                                                    : 'Facilitator not recorded'}
                                                {session.participants &&
                                                    session.participants
                                                        .length > 0 &&
                                                    ` · ${session.participants.length} taking part`}
                                            </p>
                                        </TableCell>
                                        <TableCell className="text-sm whitespace-normal text-muted-foreground">
                                            {session.scope_label}
                                        </TableCell>
                                        <TableCell className="text-sm text-muted-foreground tabular-nums">
                                            {formatDate(session.scheduled_for)}
                                        </TableCell>
                                        <TableCell className="text-right text-sm tabular-nums">
                                            {session.adjustments_count}
                                        </TableCell>
                                        <TableCell>
                                            <span
                                                className={cn(
                                                    PILL,
                                                    SESSION_STATUS[
                                                        session.status
                                                    ].className,
                                                )}
                                            >
                                                {
                                                    SESSION_STATUS[
                                                        session.status
                                                    ].label
                                                }
                                            </span>
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </DataTable>
                </TableCard>
            </PageBody>

            {can.manage && (
                <SessionFormModal
                    open={creating}
                    onOpenChange={setCreating}
                    periods={usable}
                    defaultPeriodId={currentPeriodId}
                    departments={departments}
                    calibrators={calibrators}
                />
            )}
        </>
    );
}

Calibration.layout = {
    breadcrumbs: [
        { title: 'Performance Management', href: '/performance' },
        { title: 'Calibration', href: '/performance/calibration' },
    ],
};
