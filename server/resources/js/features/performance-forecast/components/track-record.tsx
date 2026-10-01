import { ClipboardCheck } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { ForecastTrackRecord } from '../types';

/** Below this many checked forecasts, a verdict would be noise. */
const FAIR_VERDICT_AT = 20;

/** The share of actual ratings each range is built to hold. */
const PROMISED_WITHIN = 0.8;

/**
 * How the viewed forecast did, once its period's appraisals are completed. Every
 * forecast promised a range that four in five next ratings land in and a band with
 * a stated chance of being right; this checks those promises against this
 * organisation's own results — the only test that speaks for *this* workforce.
 */
export function TrackRecordCard({
    record,
    periodName,
    scoredBy,
}: {
    record: ForecastTrackRecord | null;
    periodName: string | null;
    /** Whose model made the forecasts: the organisation's own, or the general one. */
    scoredBy: 'own' | 'general';
}) {
    if (!record || !periodName) {
        return null;
    }

    if (record.checked === 0) {
        return (
            <p className="flex items-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 px-4 py-2.5 text-xs text-muted-foreground dark:border-sidebar-border">
                <ClipboardCheck className="size-3.5 shrink-0" />
                This forecast will be checked against {periodName}'s appraisals
                as they are completed.
            </p>
        );
    }

    const enough = record.checked >= FAIR_VERDICT_AT;
    const within = record.within_range ?? null;
    const holding = within === null || within >= PROMISED_WITHIN - 0.1;

    return (
        <div
            className={cn(
                'flex flex-col gap-3 rounded-xl border px-4 py-3',
                enough && !holding
                    ? 'border-amber-500/30 bg-amber-500/5'
                    : 'border-sidebar-border/70 bg-card/60 dark:border-sidebar-border',
            )}
        >
            <div className="flex items-start gap-2.5">
                <ClipboardCheck className="mt-0.5 size-4 shrink-0 text-[#0ABFBF]" />
                <div className="flex flex-col gap-0.5">
                    <span className="text-sm font-medium">
                        How this forecast did
                    </span>
                    <span className="text-xs text-muted-foreground">
                        Checked against {record.checked} of {record.forecasts}{' '}
                        completed appraisals for {periodName}.{' '}
                        {!enough
                            ? `Too few to judge yet — a fair verdict needs about ${FAIR_VERDICT_AT}.`
                            : holding
                              ? 'The ranges are holding up here.'
                              : scoredBy === 'own'
                                ? 'Ratings here are moving more than in the history your model learned from — read the ranges as optimistic.'
                                : 'Ratings here are moving more than in the reference workforce — read the ranges as optimistic.'}
                    </span>
                </div>
            </div>
            <dl className="grid grid-cols-3 gap-2">
                <Stat
                    label="Average miss"
                    value={
                        record.mean_error === null
                            ? '—'
                            : `${record.mean_error.toFixed(1)} pts`
                    }
                />
                <Stat
                    label="Inside their range"
                    value={
                        within === null
                            ? '—'
                            : `${Math.round(within * record.checked)} of ${record.checked}`
                    }
                    hint="promised: about 4 in 5"
                />
                <Stat
                    label="Band right"
                    value={
                        record.band_right === null
                            ? '—'
                            : `${Math.round(record.band_right * 100)}%`
                    }
                    hint={
                        record.expected_band_right === null
                            ? undefined
                            : `expected ${Math.round(record.expected_band_right * 100)}%`
                    }
                />
            </dl>
        </div>
    );
}

function Stat({
    label,
    value,
    hint,
}: {
    label: string;
    value: string;
    hint?: string;
}) {
    return (
        <div className="flex flex-col gap-0.5 rounded-lg border border-sidebar-border/60 bg-card/40 px-3 py-2 dark:border-sidebar-border">
            <dt className="text-[11px] text-muted-foreground">{label}</dt>
            <dd className="text-sm font-medium tabular-nums">{value}</dd>
            {hint && (
                <span className="text-[10px] text-muted-foreground">
                    {hint}
                </span>
            )}
        </div>
    );
}
