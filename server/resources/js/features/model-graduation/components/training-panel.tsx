import {
    BadgeCheck,
    CircleAlert,
    FlaskConical,
    Lock,
    RotateCcw,
    Sparkles,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { COMPARISON_COPY, formatDate, MODEL_COPY } from '../constants';
import type { Comparison, Graduation, LocalModelSummary } from '../types';

/**
 * What can be done with the surface's own model, and what came of it: the model in
 * use (with the way back), a model that passed its check and awaits a decision, a
 * check that did not pass and why, and the button that starts training — locked
 * until every requirement is met.
 */
export function TrainingPanel({
    graduation,
    canManage,
    training,
    onTrain,
    onActivate,
    onRevert,
}: {
    graduation: Graduation;
    canManage: boolean;
    training: boolean;
    onTrain: () => void;
    onActivate: (hashid: string) => void;
    onRevert: () => void;
}) {
    const { active, latest, gate_open, met_count, total_count } = graduation;
    const remaining = total_count - met_count;
    const scores = MODEL_COPY[graduation.model].scores;

    return (
        <div className="flex flex-col gap-3">
            {active && (
                <ResultCard
                    tone="active"
                    icon={BadgeCheck}
                    title="Your own model is in use"
                    meta={`Switched on ${formatDate(active.activated_at)}${active.activated_by ? ` by ${active.activated_by}` : ''} · trained on ${active.examples.toLocaleString()} examples from your records`}
                    model={active}
                >
                    {canManage && (
                        <Button size="sm" variant="outline" onClick={onRevert}>
                            <RotateCcw className="size-3.5" />
                            Switch back to the general model
                        </Button>
                    )}
                </ResultCard>
            )}

            {latest?.status === 'ready' && (
                <ResultCard
                    tone="ready"
                    icon={Sparkles}
                    title="A model trained on your records passed its check"
                    meta={`Trained on ${formatDate(latest.trained_at)}${latest.trained_by ? ` by ${latest.trained_by}` : ''} · ${latest.examples.toLocaleString()} examples. Nothing changes until someone switches to it.`}
                    model={latest}
                >
                    {canManage && (
                        <Button
                            size="sm"
                            onClick={() => onActivate(latest.hashid)}
                        >
                            <BadgeCheck className="size-3.5" />
                            Switch to this model
                        </Button>
                    )}
                </ResultCard>
            )}

            {latest?.status === 'failed' && (
                <ResultCard
                    tone="failed"
                    icon={CircleAlert}
                    title="The last model trained on your records didn’t pass its check"
                    meta={`Trained on ${formatDate(latest.trained_at)}${latest.trained_by ? ` by ${latest.trained_by}` : ''} · ${latest.examples.toLocaleString()} examples. ${active ? 'Your model in use' : 'The general model'} stays in use — try again once more history is recorded.`}
                    model={latest}
                />
            )}

            <div
                className={cn(
                    'flex flex-col gap-3 rounded-xl border p-4 sm:flex-row sm:items-center sm:justify-between',
                    gate_open
                        ? 'border-[#0ABFBF]/40 bg-[#0ABFBF]/[0.06]'
                        : 'border-dashed border-sidebar-border/70 dark:border-sidebar-border',
                )}
            >
                <div className="flex items-start gap-3">
                    <span
                        className={cn(
                            'mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg',
                            gate_open
                                ? 'bg-[#0ABFBF]/15 text-teal-700 dark:text-[#0ABFBF]'
                                : 'bg-muted text-muted-foreground',
                        )}
                    >
                        {gate_open ? (
                            <FlaskConical className="size-4" />
                        ) : (
                            <Lock className="size-4" />
                        )}
                    </span>
                    <div className="min-w-0">
                        <p className="text-sm font-medium">
                            {gate_open
                                ? active
                                    ? 'You can train a newer model on your records'
                                    : 'Your records are ready — you can train your own model'
                                : `Training unlocks when every requirement is met — ${remaining} still to go`}
                        </p>
                        <p className="mt-0.5 text-xs leading-relaxed text-muted-foreground">
                            {gate_open
                                ? `The system builds a model from your records, then tests it against the general model on your own people. It is offered only if it is clearly more accurate — and even then, your ${scores} don’t change until someone switches to it.`
                                : 'Until then the general model keeps scoring, and a model trained on too little history would only look confident.'}
                            {!canManage &&
                                ' Someone who can manage this page can train and switch models.'}
                        </p>
                    </div>
                </div>

                {canManage && (
                    <Button
                        size="sm"
                        className="shrink-0"
                        onClick={onTrain}
                        disabled={!gate_open || training}
                        variant={gate_open ? 'default' : 'outline'}
                    >
                        {training ? (
                            <Spinner />
                        ) : gate_open ? (
                            <FlaskConical className="size-3.5" />
                        ) : (
                            <Lock className="size-3.5" />
                        )}
                        {training
                            ? 'Training and checking…'
                            : 'Train on our records'}
                    </Button>
                )}
            </div>
        </div>
    );
}

const TONES = {
    active: {
        box: 'border-emerald-500/30 bg-emerald-500/[0.06]',
        icon: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
    },
    ready: {
        box: 'border-[#0ABFBF]/40 bg-[#0ABFBF]/[0.06]',
        icon: 'bg-[#0ABFBF]/15 text-teal-700 dark:text-[#0ABFBF]',
    },
    failed: {
        box: 'border-amber-500/30 bg-amber-500/[0.06]',
        icon: 'bg-amber-500/15 text-amber-700 dark:text-amber-400',
    },
} as const;

function ResultCard({
    tone,
    icon: Icon,
    title,
    meta,
    model,
    children,
}: {
    tone: keyof typeof TONES;
    icon: typeof Sparkles;
    title: string;
    meta: string;
    model: LocalModelSummary;
    children?: ReactNode;
}) {
    return (
        <div className={cn('rounded-xl border p-4', TONES[tone].box)}>
            <div className="flex items-start gap-3">
                <span
                    className={cn(
                        'mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg',
                        TONES[tone].icon,
                    )}
                >
                    <Icon className="size-4" />
                </span>
                <div className="min-w-0 flex-1">
                    <p className="text-sm font-medium">{title}</p>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {meta}
                    </p>
                </div>
            </div>

            {model.comparison && (
                <ComparisonTiles comparison={model.comparison} />
            )}

            {model.findings.length > 0 && (
                <ul className="mt-3 flex flex-col gap-1.5 text-sm text-muted-foreground">
                    {model.findings.map((finding) => (
                        <li key={finding} className="flex gap-2">
                            <span
                                className="mt-2 size-1 shrink-0 rounded-full bg-muted-foreground/60"
                                aria-hidden="true"
                            />
                            <span>{finding}</span>
                        </li>
                    ))}
                </ul>
            )}

            {children && (
                <div className="mt-4 flex flex-wrap gap-2">{children}</div>
            )}
        </div>
    );
}

/**
 * The check in three numbers, on the organisation's own people: its model, the
 * general one, and knowing nothing about the person. One measure, stated in words
 * with which way is better, so no one has to know what it is called.
 */
function ComparisonTiles({ comparison }: { comparison: Comparison }) {
    const copy = COMPARISON_COPY[comparison.metric];

    if (comparison.local === undefined || comparison.reference === undefined) {
        return null;
    }

    const tiles = [
        { label: 'Your model', value: comparison.local, emphasis: true },
        {
            label: 'General model',
            value: comparison.reference,
            emphasis: false,
        },
        ...(comparison.baseline !== undefined
            ? [
                  {
                      label: copy.baseline,
                      value: comparison.baseline,
                      emphasis: false,
                  },
              ]
            : []),
    ];

    return (
        <div className="mt-4">
            <p className="text-xs font-medium">{copy.label}</p>
            <p className="text-xs text-muted-foreground">{copy.explain}</p>

            <dl className="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-3">
                {tiles.map((tile) => (
                    <div
                        key={tile.label}
                        className={cn(
                            'rounded-lg border bg-card px-3 py-2.5',
                            tile.emphasis
                                ? 'border-[#0ABFBF]/40'
                                : 'border-sidebar-border/70 dark:border-sidebar-border',
                        )}
                    >
                        <dt className="text-xs text-muted-foreground">
                            {tile.label}
                        </dt>
                        <dd className="mt-0.5 text-lg font-semibold tracking-tight">
                            {copy.format(tile.value)}
                        </dd>
                    </div>
                ))}
            </dl>

            <p className="mt-2 text-xs text-muted-foreground">
                Checked on {comparison.examples.toLocaleString()} examples from{' '}
                {comparison.people.toLocaleString()} of your people, each scored
                by a model that never saw them.
                {comparison.wins_over_reference !== undefined &&
                    ` Your model came out ahead of the general one in ${Math.round(comparison.wins_over_reference * 100)}% of re-checks (${Math.round(comparison.required_share * 100)}% needed).`}
                {comparison.coverage !== undefined &&
                    comparison.promised_coverage !== undefined &&
                    ` Its likely ranges held ${Math.round(comparison.coverage * 100)}% of the ratings that followed (${Math.round(comparison.promised_coverage * 100)}% promised).`}
            </p>
        </div>
    );
}
