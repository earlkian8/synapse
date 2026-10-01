import { CheckCircle2, PenLine, Send, Users } from 'lucide-react';
import { StatTiles } from '@/components/data-table';
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
        <StatTiles
            tiles={[
                {
                    key: 'coverage',
                    label: 'Cycle coverage',
                    value:
                        stats.coverage === null
                            ? '—'
                            : `${Math.round(stats.coverage)}%`,
                    hint: `${covered} of ${stats.eligible} staff`,
                    icon: Users,
                    accent: 'teal',
                },
                {
                    key: 'draft',
                    label: 'In progress',
                    value: stats.draft.toLocaleString(),
                    hint: 'drafts',
                    icon: PenLine,
                    accent: 'slate',
                },
                {
                    key: 'submitted',
                    label: 'Awaiting sign-off',
                    value: stats.submitted.toLocaleString(),
                    hint: 'submitted',
                    icon: Send,
                    accent: 'amber',
                },
                {
                    key: 'average',
                    label: 'Average attainment',
                    value: formatPercent(stats.average_percent, 0),
                    hint:
                        stats.average_score === null
                            ? undefined
                            : `${formatScore(stats.average_score)} / 5`,
                    icon: CheckCircle2,
                    accent: 'emerald',
                },
            ]}
        />
    );
}
