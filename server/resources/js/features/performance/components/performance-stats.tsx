import { CheckCircle2, PenLine, Send, Users } from 'lucide-react';
import { cn } from '@/lib/utils';
import { formatPercent, formatScore } from '../constants';
import type { PerformanceStats } from '../types';

/**
 * The four numbers that answer "where is this review cycle". Coverage leads,
 * because an appraisal programme that has reached a third of the company is not
 * a programme yet — and that is the fact a status count hides.
 */
export function PerformanceStatsCards({ stats }: { stats: PerformanceStats }) {
    const covered = Math.min(stats.total, stats.eligible);

    return (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <Tile
                icon={<Users className="size-4" />}
                label="Cycle coverage"
                value={
                    stats.coverage === null
                        ? '—'
                        : `${Math.round(stats.coverage)}%`
                }
                hint={`${covered} of ${stats.eligible} active staff`}
                accent="teal"
            >
                <div className="mt-2.5 h-1.5 overflow-hidden rounded-full bg-muted">
                    <div
                        className="h-full rounded-full bg-[#0ABFBF] transition-all"
                        style={{ width: `${stats.coverage ?? 0}%` }}
                    />
                </div>
            </Tile>

            <Tile
                icon={<PenLine className="size-4" />}
                label="In progress"
                value={String(stats.draft)}
                hint="drafts still being written"
                accent="slate"
            />

            <Tile
                icon={<Send className="size-4" />}
                label="Awaiting sign-off"
                value={String(stats.submitted)}
                hint="submitted, not yet acknowledged"
                accent="amber"
            />

            <Tile
                icon={<CheckCircle2 className="size-4" />}
                label="Average attainment"
                value={formatPercent(stats.average_percent, 0)}
                hint={
                    stats.average_score === null
                        ? 'no completed appraisals yet'
                        : `${formatScore(stats.average_score)} on the 1–5 index`
                }
                accent="emerald"
            />
        </div>
    );
}

const ACCENTS = {
    teal: 'bg-[#0ABFBF]/10 text-[#0a7d82] dark:text-[#3fd6d6]',
    slate: 'bg-slate-500/10 text-slate-600 dark:text-slate-300',
    amber: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    emerald: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
} as const;

function Tile({
    icon,
    label,
    value,
    hint,
    accent,
    children,
}: {
    icon: React.ReactNode;
    label: string;
    value: string;
    hint: string;
    accent: keyof typeof ACCENTS;
    children?: React.ReactNode;
}) {
    return (
        <div className="rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border">
            <div className="flex items-center gap-2">
                <span
                    className={cn(
                        'flex size-7 items-center justify-center rounded-lg',
                        ACCENTS[accent],
                    )}
                >
                    {icon}
                </span>
                <span className="text-xs font-medium text-muted-foreground">
                    {label}
                </span>
            </div>
            <p className="mt-2 text-2xl font-semibold tracking-tight tabular-nums">
                {value}
            </p>
            <p className="text-xs text-muted-foreground">{hint}</p>
            {children}
        </div>
    );
}
