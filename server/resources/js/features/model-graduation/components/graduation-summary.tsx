import { cn } from '@/lib/utils';
import { formatShortfall, MODEL_COPY, STATUS_LABELS } from '../constants';
import type { Graduation, Requirement } from '../types';

/** Whose model is scoring the page, as a pill. */
export function StagePill({ graduation }: { graduation: Graduation }) {
    const graduated = graduation.stage === 'graduated';

    return (
        <span
            className={cn(
                'rounded-full px-2 py-0.5 text-[11px] font-medium whitespace-nowrap',
                graduated
                    ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400'
                    : 'bg-[#0ABFBF]/15 text-teal-700 dark:text-[#0ABFBF]',
            )}
        >
            {graduated ? 'Using your own model' : 'Using the general model'}
        </span>
    );
}

/** A decision is waiting: a model is ready to switch to, or one can be trained. */
export function awaitsDecision(graduation: Graduation): boolean {
    return (
        graduation.latest?.status === 'ready' ||
        (graduation.gate_open && graduation.stage !== 'graduated')
    );
}

/**
 * The requirement to name as "what's next": the actionable one furthest from
 * met, or — when only a derived or system requirement is left (the prediction
 * service being down, say) — the first one still unmet.
 */
export function nextRequirement(
    graduation: Graduation,
): Requirement | undefined {
    return (
        graduation.requirements.find(
            (requirement) => requirement.key === graduation.binding_key,
        ) ??
        graduation.requirements.find(
            (requirement) => requirement.status !== 'met',
        )
    );
}

/**
 * The one line the page itself carries: whose model scores it and, while the
 * general model does, the single thing furthest from ready.
 */
export function stripLine(graduation: Graduation): string {
    const scores = MODEL_COPY[graduation.model].scores;

    if (graduation.stage === 'graduated') {
        return `These ${scores} come from a model trained on your own records, which proved more accurate for your people.`;
    }

    if (graduation.latest?.status === 'ready') {
        return 'A model trained on your own records passed its check and is ready to switch to.';
    }

    if (graduation.gate_open) {
        return 'Your records meet every requirement — you can train a model of your own and see whether it beats the general one.';
    }

    const next = nextRequirement(graduation);
    const shortfall = next ? formatShortfall(next) : null;
    const lead =
        next?.key === graduation.binding_key
            ? 'Furthest to go'
            : 'Still needed';

    return `These ${scores} come from a general model until your own records can train one.${
        next && shortfall
            ? ` ${lead}: ${next.label.toLowerCase()} — ${shortfall.toLowerCase()}.`
            : ''
    }`;
}

/** The fuller sentence the modal opens with. */
export function Headline({ graduation }: { graduation: Graduation }) {
    const copy = MODEL_COPY[graduation.model];

    if (graduation.stage === 'graduated') {
        return (
            <>
                These {copy.scores} come from a model trained on your
                organisation’s own records, which proved more accurate for your
                people than the general model.
            </>
        );
    }

    if (graduation.latest?.status === 'ready') {
        return (
            <>
                A model trained on your own records passed its check and is
                ready to switch to. Until someone does, these {copy.scores} come
                from the general model.
            </>
        );
    }

    if (graduation.gate_open) {
        return (
            <>
                Your records now meet every requirement — you can train a model
                of your own and see whether it beats the general one.
            </>
        );
    }

    return (
        <>
            These {copy.scores} come from a general model, built on{' '}
            {copy.general}. Once your own records meet all{' '}
            {graduation.total_count} requirements, you can train one on your
            history instead.
            {graduation.stage === 'provisional' &&
                ' Nothing it could learn from is recorded yet.'}
        </>
    );
}

/**
 * One dash per requirement, filled when met: "how close are we" at a glance,
 * with the same count in words beside it so colour is never the only signal.
 */
export function RequirementDots({ graduation }: { graduation: Graduation }) {
    return (
        <span className="inline-flex items-center gap-2">
            <span className="flex gap-1" aria-hidden="true">
                {graduation.requirements.map((requirement) => (
                    <span
                        key={requirement.key}
                        title={`${requirement.label}: ${STATUS_LABELS[requirement.status]}`}
                        className={cn(
                            'h-1.5 w-4 rounded-full',
                            requirement.status === 'met'
                                ? 'bg-emerald-500'
                                : requirement.status === 'progressing'
                                  ? 'bg-amber-500/60'
                                  : 'bg-muted-foreground/25',
                        )}
                    />
                ))}
            </span>
            <span className="text-xs text-muted-foreground tabular-nums">
                {graduation.met_count} of {graduation.total_count} requirements
                met
            </span>
        </span>
    );
}
