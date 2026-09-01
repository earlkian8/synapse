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
    formatScore,
    INPUT_FIELDS,
    scoreBarTone,
    scoreTone,
    TIER_DESCRIPTIONS,
} from '../constants';
import type { RiskScore } from '../types';
import { RiskBadge } from './risk-badge';

/** A drill-down on one employee's attrition risk: score, confidence, signals. */
export function EmployeeDetailDialog({
    score,
    onOpenChange,
}: {
    score: RiskScore | null;
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
                                Attrition-risk breakdown for{' '}
                                {score.employee.full_name}
                            </DialogDescription>
                        </DialogHeader>

                        {/* Risk headline */}
                        <div className="flex flex-col gap-2 rounded-xl border border-sidebar-border/70 bg-card/60 p-4 dark:border-sidebar-border">
                            <div className="flex items-end justify-between">
                                <div className="flex items-baseline gap-1.5">
                                    <span
                                        className={cn(
                                            'text-4xl font-semibold tracking-tight tabular-nums',
                                            scoreTone(score.score),
                                        )}
                                    >
                                        {formatScore(score.score)}
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        / 100 flight risk
                                    </span>
                                </div>
                                <RiskBadge tier={score.tier} />
                            </div>
                            <div className="h-2 overflow-hidden rounded-full bg-muted">
                                <div
                                    className={cn(
                                        'h-full rounded-full',
                                        scoreBarTone(score.score),
                                    )}
                                    style={{ width: `${score.score}%` }}
                                />
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {TIER_DESCRIPTIONS[score.tier]} · simulated
                                probability of leaving{' '}
                                {(score.probability * 100).toFixed(1)}%
                            </p>
                        </div>

                        {/* Confidence */}
                        <div className="flex items-center justify-between gap-3 rounded-xl border border-sidebar-border/60 bg-card/40 px-4 py-3 dark:border-sidebar-border">
                            <div className="flex flex-col">
                                <span className="text-sm font-medium">
                                    {confidenceLabel(score.confidence)}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {formatConfidence(score.confidence)}{' '}
                                    simulated confidence, based on this demo
                                    employee's tenure
                                </span>
                            </div>
                            <div className="flex w-24 flex-col gap-1">
                                <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                                    <div
                                        className="h-full rounded-full bg-[#0ABFBF]"
                                        style={{
                                            width: `${Math.round(score.confidence * 100)}%`,
                                        }}
                                    />
                                </div>
                            </div>
                        </div>

                        {/* What we based it on */}
                        <div className="flex flex-col gap-2">
                            <h3 className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                What this is based on
                            </h3>
                            <FeatureGrid features={score.features} />
                            <p className="mt-1 text-xs text-muted-foreground">
                                These are simulated signals for a fabricated
                                employee — not real HR data. Pay rates, equity,
                                engagement surveys and demographic / protected
                                attributes are deliberately excluded, mirroring
                                what a real deployment would omit.
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
