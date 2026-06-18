import { Link } from '@inertiajs/react';
import { GraduationCap, Trophy, Users } from 'lucide-react';
import { formatDateRange } from '../constants';
import { trainingRoutes } from '../routes';
import type { TrainingProgram } from '../types';
import { ProgramStatusBadge } from './training-status-badge';

/** A program tile on the training overview: schedule, seat usage and completions. */
export function ProgramCard({ program }: { program: TrainingProgram }) {
    const seatLabel =
        program.capacity === null
            ? `${program.active_count} enrolled`
            : `${program.active_count} / ${program.capacity} seats`;

    const fillPct =
        program.capacity && program.capacity > 0
            ? Math.min(100, (program.active_count / program.capacity) * 100)
            : null;

    return (
        <Link
            href={trainingRoutes.show(program.hashid)}
            className="group flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm transition-colors hover:border-[#0ABFBF]/50 dark:border-sidebar-border"
        >
            <div className="flex items-start justify-between gap-2">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[#0ABFBF]">
                    <GraduationCap className="size-4.5" />
                </span>
                <ProgramStatusBadge status={program.status} />
            </div>

            <div className="min-w-0">
                <p className="truncate text-sm font-semibold">{program.name}</p>
                <p className="truncate text-xs text-muted-foreground">
                    {program.provider ?? 'In-house'} ·{' '}
                    {formatDateRange(program.start_date, program.end_date)}
                </p>
            </div>

            {/* Seat usage */}
            <div className="mt-auto flex flex-col gap-1.5">
                {fillPct !== null && (
                    <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                        <div
                            className="h-full rounded-full bg-[#0ABFBF]"
                            style={{ width: `${fillPct}%` }}
                        />
                    </div>
                )}
                <div className="flex items-center justify-between text-xs text-muted-foreground tabular-nums">
                    <span className="flex items-center gap-1">
                        <Users className="size-3.5" />
                        {seatLabel}
                    </span>
                    <span className="flex items-center gap-1">
                        <Trophy className="size-3.5" />
                        {program.completed_count} done
                    </span>
                </div>
            </div>
        </Link>
    );
}
