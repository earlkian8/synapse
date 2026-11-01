import { ScoreHistogram } from './score-histogram';

const EVERYONE = [
    {
        key: 'all',
        label: 'Everyone assessed',
        fill: 'bg-muted-foreground/25',
        svgFill: 'fill-muted-foreground/25',
    },
] as const;

/**
 * Where one score sits among everyone in the same run: a compact distribution with
 * the person marked, and the share of the run below them in words.
 */
export function WorkforcePosition({
    scores,
    value,
    name,
    noun,
    thresholds = [],
}: {
    /** Every score in the run, this person's included. */
    scores: number[];
    value: number;
    name: string;
    /** What the score measures, e.g. "risk", "readiness". */
    noun: string;
    thresholds?: { value: number; label: string }[];
}) {
    if (scores.length < 2) {
        return null;
    }

    // Compare with everyone else in the run (not with themselves).
    const others = scores.length - 1;
    const below = scores.filter((s) => s < value).length;
    const above = scores.filter((s) => s > value).length;
    const higher = below >= above;

    return (
        <div className="flex flex-col gap-1.5">
            <p className="text-xs text-muted-foreground">
                {higher ? 'Higher' : 'Lower'} {noun} than{' '}
                <span className="font-medium text-foreground">
                    {higher ? below : above} of the {others}
                </span>{' '}
                {others === 1 ? 'other person' : 'others'} in this run.
            </p>
            <ScoreHistogram
                items={scores.map((score) => ({
                    score,
                    group: 'all' as const,
                }))}
                groups={EVERYONE}
                thresholds={thresholds}
                marker={{ value, label: `${name}: ${Math.round(value)}` }}
                height={44}
                label={`Where ${name} sits among everyone in this run`}
            />
        </div>
    );
}
