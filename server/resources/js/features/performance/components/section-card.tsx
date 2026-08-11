import { cn } from '@/lib/utils';
import { formatPercent } from '../constants';
import type { PerformanceScore, SectionResult } from '../types';
import { ScoreRow } from './score-row';

type Props = {
    section: SectionResult;
    /** What the framework said this section is for (snapshot). */
    description: string | null;
    lines: PerformanceScore[];
    editable: boolean;
    onScoreChange: (id: number, value: number) => void;
    onRemarksChange: (id: number, value: string) => void;
};

/**
 * One weighted section of an appraisal — "Goals & delivery, 50% of the result".
 *
 * The section is the unit an appraisal framework is actually written in, so it
 * is the unit the scorecard is drawn in: its own weight, its own running
 * attainment, and how much of it has been filled in, above the criteria it
 * holds. Someone reviewing a person can see which half of the form still needs
 * them without counting rows.
 */
export function SectionCard({
    section,
    description,
    lines,
    editable,
    onScoreChange,
    onRemarksChange,
}: Props) {
    const lineWeight = lines.reduce((sum, line) => sum + (line.weight || 0), 0);
    const complete = section.total > 0 && section.scored === section.total;

    return (
        <section className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border">
            <header className="flex flex-col gap-3 border-b border-border px-4 py-3.5 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="text-sm font-semibold">
                            {section.name ?? 'Performance criteria'}
                        </h2>
                        <span className="rounded-full border border-[#0ABFBF]/25 bg-[#0ABFBF]/10 px-2 py-0.5 text-[11px] font-semibold text-[#0a7d82] tabular-nums dark:text-[#3fd6d6]">
                            {section.weight}% of the result
                        </span>
                    </div>
                    {description && (
                        <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                            {description}
                        </p>
                    )}
                </div>

                <div className="flex shrink-0 items-center gap-4">
                    <div className="text-right">
                        <p className="text-sm font-semibold tabular-nums">
                            {formatPercent(section.percent)}
                        </p>
                        <p
                            className={cn(
                                'text-[11px] tabular-nums',
                                complete
                                    ? 'text-muted-foreground'
                                    : 'text-amber-600 dark:text-amber-400',
                            )}
                        >
                            {section.scored} of {section.total} rated
                        </p>
                    </div>
                    <div className="h-9 w-1.5 overflow-hidden rounded-full bg-muted">
                        <div
                            className="w-full rounded-full bg-[#0ABFBF] transition-all"
                            style={{
                                height: `${section.percent ?? 0}%`,
                                marginTop: `${100 - (section.percent ?? 0)}%`,
                            }}
                        />
                    </div>
                </div>
            </header>

            <div className="divide-y divide-border">
                {lines.map((line) => (
                    <ScoreRow
                        key={line.id}
                        line={line}
                        share={
                            lineWeight > 0
                                ? ((line.weight || 0) / lineWeight) * 100
                                : null
                        }
                        editable={editable}
                        onScoreChange={(value) => onScoreChange(line.id, value)}
                        onRemarksChange={(value) =>
                            onRemarksChange(line.id, value)
                        }
                    />
                ))}
            </div>
        </section>
    );
}
