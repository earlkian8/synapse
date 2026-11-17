import {
    BadgeCheck,
    CircleAlert,
    FlaskConical,
    Lock,
    RotateCcw,
    Sparkles,
    TriangleAlert,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { DataTable, TableCard } from '@/components/data-table';
import { Button } from '@/components/ui/button';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { AiInterpretation } from '@/features/prediction-insights/components/ai-interpretation';
import { cn } from '@/lib/utils';
import { COMPARISON_COPY, formatDate, MODEL_COPY } from '../constants';
import { graduationRoutes } from '../routes';
import type {
    Comparison,
    Graduation,
    LocalModelSummary,
    ModelKey,
} from '../types';

/**
 * What can be done with the surface's own model, and what came of it: the model in
 * use (with the way back), a model waiting for a decision — recommended when it
 * passed its check, still the organisation's to choose when it did not (ADR 0107) —
 * a check that kept no model and why, and whether training is unlocked. Each result
 * can be explained in plain words by AI (ADR 0106) before deciding. The button that
 * starts training sits in the modal's footer, so it is in view on every tab.
 */
export function TrainingPanel({
    graduation,
    canManage,
    onActivate,
    onDismiss,
    onRevert,
}: {
    graduation: Graduation;
    canManage: boolean;
    onActivate: (hashid: string, passed: boolean) => void;
    onDismiss: (hashid: string) => void;
    onRevert: () => void;
}) {
    const { active, latest, gate_open, met_count, total_count } = graduation;
    const remaining = total_count - met_count;
    const scores = MODEL_COPY[graduation.model].scores;
    const current = active ? 'your model in use' : 'the general model';
    const Current = active ? 'Your model in use' : 'The general model';
    const by = (model: LocalModelSummary) =>
        `Trained on ${formatDate(model.trained_at)}${model.trained_by ? ` by ${model.trained_by}` : ''} · ${model.examples.toLocaleString()} examples.`;

    return (
        <div className="flex flex-col gap-3">
            {!active && !latest && (
                <p className="text-sm text-muted-foreground">
                    No model has been trained on your records yet — every{' '}
                    {scores.replace(/s$/, '')} on this page comes from the
                    general model.
                </p>
            )}

            {active && (
                <ResultCard
                    surface={graduation.model}
                    tone={active.passed ? 'active' : 'chosen'}
                    icon={BadgeCheck}
                    title={
                        active.passed
                            ? 'Your own model is in use'
                            : 'Your own model is in use — it didn’t pass its check'
                    }
                    meta={`Switched on ${formatDate(active.activated_at)}${active.activated_by ? ` by ${active.activated_by}` : ''} · trained on ${active.examples.toLocaleString()} examples from your records${active.passed ? '' : '. It was chosen knowing it wasn’t reliably more accurate than the general model'}`}
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

            {latest?.status === 'ready' && latest.passed && (
                <ResultCard
                    surface={graduation.model}
                    tone="ready"
                    icon={Sparkles}
                    title="A model trained on your records passed its check"
                    meta={`${by(latest)} Nothing changes until someone switches to it.`}
                    model={latest}
                >
                    {canManage && (
                        <>
                            <Button
                                size="sm"
                                onClick={() => onActivate(latest.hashid, true)}
                            >
                                <BadgeCheck className="size-3.5" />
                                Switch to this model
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => onDismiss(latest.hashid)}
                            >
                                Keep {current}
                            </Button>
                        </>
                    )}
                </ResultCard>
            )}

            {latest?.status === 'ready' && !latest.passed && (
                <ResultCard
                    surface={graduation.model}
                    tone="failed"
                    icon={CircleAlert}
                    title="Your new model didn’t pass its check — you can still choose it"
                    meta={`${by(latest)} ${Current} stays in use unless you switch. Read why it fell short before deciding.`}
                    model={latest}
                >
                    {canManage && (
                        <>
                            <Button
                                size="sm"
                                onClick={() => onDismiss(latest.hashid)}
                            >
                                Keep {current}
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => onActivate(latest.hashid, false)}
                            >
                                <TriangleAlert className="size-3.5" />
                                Use this model anyway
                            </Button>
                        </>
                    )}
                </ResultCard>
            )}

            {latest?.status === 'failed' && (
                <ResultCard
                    surface={graduation.model}
                    tone="failed"
                    icon={CircleAlert}
                    title="The last model trained on your records didn’t pass its check"
                    meta={`${by(latest)} No model could be kept from it, so there is nothing to switch to — ${current} stays in use. Try again once more history is recorded.`}
                    model={latest}
                />
            )}

            <div
                className={cn(
                    'flex items-start gap-3 rounded-xl border px-4 py-3',
                    gate_open
                        ? 'border-[#0ABFBF]/40 bg-[#0ABFBF]/6'
                        : 'border-dashed border-sidebar-border/70 dark:border-sidebar-border',
                )}
            >
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
                            ? `The system builds a model from your records, then tests it against the general model on your own people. It is recommended only if it is clearly more accurate — but the choice is yours either way, and your ${scores} don’t change until someone switches to it.`
                            : 'Until then the general model keeps scoring, and a model trained on too little history would only look confident.'}
                        {canManage
                            ? gate_open
                                ? ' Start it with “Train on our records” below.'
                                : ''
                            : ' Someone who can manage this page can train and switch models.'}
                    </p>
                </div>
            </div>
        </div>
    );
}

const TONES = {
    active: {
        box: 'border-emerald-500/30 bg-emerald-500/6',
        icon: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
    },
    ready: {
        box: 'border-[#0ABFBF]/40 bg-[#0ABFBF]/6',
        icon: 'bg-[#0ABFBF]/15 text-teal-700 dark:text-[#0ABFBF]',
    },
    failed: {
        box: 'border-amber-500/30 bg-amber-500/6',
        icon: 'bg-amber-500/15 text-amber-700 dark:text-amber-400',
    },
    /** In use, though it did not pass its check. */
    chosen: {
        box: 'border-emerald-500/30 bg-emerald-500/6',
        icon: 'bg-amber-500/15 text-amber-700 dark:text-amber-400',
    },
} as const;

function ResultCard({
    surface,
    tone,
    icon: Icon,
    title,
    meta,
    model,
    children,
}: {
    surface: ModelKey;
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
                <ComparisonTable comparison={model.comparison} />
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

            {model.comparison && (
                <div className="mt-4">
                    <AiInterpretation
                        key={model.hashid}
                        url={graduationRoutes.interpretation(
                            surface,
                            model.hashid,
                        )}
                        saved={model.interpretation}
                        title="What this check means"
                        intro="Have AI explain this check in plain words: what each part showed, and what switching or keeping the current model would mean. Only the check’s figures are sent — no one’s record."
                        action="Explain this result"
                        pending="Reading the check…"
                        contextLabel="What deciding means"
                        checksLabel="Before you decide"
                        upIsGood
                    />
                </div>
            )}

            {children && (
                <div className="mt-4 flex flex-wrap gap-2">{children}</div>
            )}
        </div>
    );
}

/**
 * The check in three numbers, on the organisation's own people: its model, the
 * general one, and knowing nothing about the person — one measure, stated in
 * words with which way is better, so no one has to know what it is called.
 */
function ComparisonTable({ comparison }: { comparison: Comparison }) {
    const copy = COMPARISON_COPY[comparison.metric];

    if (comparison.local === undefined || comparison.reference === undefined) {
        return null;
    }

    const rows = [
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
        <div className="mt-3">
            <TableCard className="bg-card">
                <DataTable>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Compared on your people</TableHead>
                            <TableHead className="text-right">
                                {copy.label}
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((row) => (
                            <TableRow key={row.label}>
                                <TableCell
                                    className={cn(
                                        'text-sm whitespace-normal',
                                        row.emphasis
                                            ? 'font-medium'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {row.emphasis && (
                                        <span
                                            className="mr-2 inline-block size-2 rounded-full bg-[#0ABFBF]"
                                            aria-hidden="true"
                                        />
                                    )}
                                    {row.label}
                                </TableCell>
                                <TableCell
                                    className={cn(
                                        'text-right text-sm tabular-nums',
                                        row.emphasis && 'font-semibold',
                                    )}
                                >
                                    {copy.format(row.value)}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </DataTable>
            </TableCard>

            <p className="mt-2 text-xs text-muted-foreground">
                {copy.explain} Checked on {comparison.examples.toLocaleString()}{' '}
                examples from {comparison.people.toLocaleString()} of your
                people, each scored by a model that never saw them.
                {comparison.wins_over_reference !== undefined &&
                    ` Your model came out ahead of the general one in ${Math.round(comparison.wins_over_reference * 100)}% of re-checks (${Math.round(comparison.required_share * 100)}% needed).`}
                {comparison.coverage !== undefined &&
                    comparison.promised_coverage !== undefined &&
                    ` Its likely ranges held ${Math.round(comparison.coverage * 100)}% of the ratings that followed (${Math.round(comparison.promised_coverage * 100)}% promised).`}
            </p>
        </div>
    );
}
