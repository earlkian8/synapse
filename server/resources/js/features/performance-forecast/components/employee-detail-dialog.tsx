import { PersonAvatar } from '@/components/person-avatar';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import {
    confidenceLabel,
    formatConfidence,
    formatRating,
    ratingBarTone,
    ratingTone,
} from '../constants';
import type { ForecastScore } from '../types';
import { BandBadge } from './band-badge';
import { TrajectoryChart } from './trajectory-chart';

/**
 * The features sent to the model that we ground in real HR data, with friendly
 * labels and formatters. Only the ones present in the snapshot are shown — the
 * rest were imputed by the pipeline and are deliberately not presented as fact.
 */
const INPUT_FIELDS: {
    key: string;
    label: string;
    format: (value: number | string) => string;
}[] = [
    {
        key: 'manager_rating',
        label: 'Latest manager rating',
        format: (v) => `${Number(v).toFixed(1)} / 5`,
    },
    {
        key: 'kpi_achievement_percent',
        label: 'KPI achievement',
        format: (v) => `${Math.round(Number(v))}%`,
    },
    {
        key: 'performance_last_year',
        label: 'Rating last cycle',
        format: (v) => `${Math.round(Number(v))} / 100`,
    },
    {
        key: 'performance_two_years_ago',
        label: 'Rating two cycles ago',
        format: (v) => `${Math.round(Number(v))} / 100`,
    },
    {
        key: 'years_at_company',
        label: 'Tenure',
        format: (v) => `${Number(v).toFixed(1)} yrs`,
    },
    {
        key: 'years_since_last_promotion',
        label: 'Since last promotion',
        format: (v) => `${Number(v).toFixed(1)} yrs`,
    },
    {
        key: 'certifications_count',
        label: 'Certifications',
        format: (v) => `${Math.round(Number(v))}`,
    },
    {
        key: 'employment_type',
        label: 'Employment type',
        format: (v) => String(v),
    },
];

/** A drill-down on one employee's forecast: rating, confidence, trajectory, inputs. */
export function EmployeeDetailDialog({
    score,
    onOpenChange,
}: {
    score: ForecastScore | null;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={score !== null} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                {score && score.employee && (
                    <>
                        <DialogHeader>
                            <DialogTitle className="flex items-center gap-3">
                                <PersonAvatar
                                    name={score.employee.full_name}
                                    initials={score.employee.initials}
                                    photo={score.employee.photo}
                                    className="size-10"
                                />
                                <span className="flex min-w-0 flex-col">
                                    <span className="truncate text-base">
                                        {score.employee.full_name}
                                    </span>
                                    <span className="truncate text-xs font-normal text-muted-foreground">
                                        {score.employee.position ??
                                            score.employee.employee_no}
                                        {score.employee.department
                                            ? ` · ${score.employee.department}`
                                            : ''}
                                    </span>
                                </span>
                            </DialogTitle>
                            <DialogDescription className="sr-only">
                                Performance forecast for{' '}
                                {score.employee.full_name}
                            </DialogDescription>
                        </DialogHeader>

                        {/* Rating headline */}
                        <div className="flex flex-col gap-2 rounded-xl border border-sidebar-border/70 bg-card/60 p-4 dark:border-sidebar-border">
                            <div className="flex items-end justify-between">
                                <div className="flex items-baseline gap-1.5">
                                    <span
                                        className={cn(
                                            'text-4xl font-semibold tracking-tight tabular-nums',
                                            ratingTone(score.predicted_rating),
                                        )}
                                    >
                                        {formatRating(score.predicted_rating)}
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        / 100 predicted
                                    </span>
                                </div>
                                <BandBadge band={score.band} />
                            </div>
                            <div className="h-2 overflow-hidden rounded-full bg-muted">
                                <div
                                    className={cn(
                                        'h-full rounded-full',
                                        ratingBarTone(score.predicted_rating),
                                    )}
                                    style={{
                                        width: `${score.predicted_rating}%`,
                                    }}
                                />
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {confidenceLabel(score.confidence)} ·{' '}
                                {formatConfidence(score.confidence)} of the
                                forecast rests on this employee's recorded
                                history
                            </p>
                        </div>

                        {/* Trajectory */}
                        <div className="flex flex-col gap-1">
                            <h3 className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                Rating trajectory
                            </h3>
                            {score.history.length > 0 ? (
                                <TrajectoryChart
                                    history={score.history}
                                    forecast={score.predicted_rating}
                                />
                            ) : (
                                <p className="rounded-lg border border-dashed border-sidebar-border/70 bg-card/40 px-3 py-4 text-center text-xs text-muted-foreground dark:border-sidebar-border">
                                    No prior evaluations — this forecast leans
                                    on role and tenure signals, so treat it as
                                    indicative.
                                </p>
                            )}
                        </div>

                        {/* What we based it on */}
                        <div className="flex flex-col gap-2">
                            <h3 className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                What this is based on
                            </h3>
                            <FeatureGrid features={score.features} />
                            <p className="mt-1 text-xs text-muted-foreground">
                                Only signals grounded in real HR data are shown;
                                anything missing is imputed by the model.
                                Demographic attributes are never used.
                            </p>
                        </div>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}

function FeatureGrid({
    features,
}: {
    features: Record<string, number | string>;
}) {
    const rows = INPUT_FIELDS.filter((f) => features[f.key] !== undefined);

    if (rows.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                No recorded signals for this employee yet.
            </p>
        );
    }

    return (
        <dl className="grid grid-cols-2 gap-x-4 gap-y-2">
            {rows.map((field) => (
                <div
                    key={field.key}
                    className="flex flex-col gap-0.5 rounded-lg border border-sidebar-border/60 bg-card/40 px-3 py-2 dark:border-sidebar-border"
                >
                    <dt className="text-[11px] text-muted-foreground">
                        {field.label}
                    </dt>
                    <dd className="text-sm font-medium tabular-nums">
                        {field.format(features[field.key])}
                    </dd>
                </div>
            ))}
        </dl>
    );
}
