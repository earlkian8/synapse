import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

/**
 * The controls a weighted list is edited with — a percent box, a running total,
 * and the arithmetic behind both.
 *
 * Weights appear twice in this codebase's appraisal work: in the framework
 * editor under Company Setup, and in the setup wizard, where a company designs
 * its first framework. They read the same in both places because they are the
 * same components.
 */

/** A weight box that says what unit it is in and keeps the browser's spinners out. */
export function PercentInput({
    value,
    onChange,
    label,
    className,
}: {
    value: number;
    onChange: (value: number) => void;
    label: string;
    className?: string;
}) {
    return (
        <div className="relative">
            <Input
                type="number"
                min="0"
                max="100"
                value={value}
                onChange={(event) => onChange(Number(event.target.value))}
                aria-label={label}
                className={cn(
                    '[appearance:textfield] pr-6 text-right tabular-nums [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none',
                    className,
                )}
            />
            <span
                aria-hidden="true"
                className="pointer-events-none absolute inset-y-0 right-2 flex items-center text-xs text-muted-foreground"
            >
                %
            </span>
        </div>
    );
}

/** A running total, and the one-click way to make it add up. */
export function WeightTally({
    total,
    empty,
    text,
    onSplit,
}: {
    total: number;
    empty: boolean;
    text: string;
    onSplit?: () => void;
}) {
    const balanced = Math.round(total) === 100;

    return (
        <div className="flex items-center gap-1.5">
            <span
                className={cn(
                    'text-xs tabular-nums',
                    empty
                        ? 'text-muted-foreground'
                        : balanced
                          ? 'text-emerald-600 dark:text-emerald-400'
                          : 'text-amber-600 dark:text-amber-400',
                )}
            >
                {text}
            </span>
            {!balanced && !empty && onSplit && (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="h-7 px-2 text-xs"
                    onClick={onSplit}
                >
                    Split evenly
                </Button>
            )}
        </div>
    );
}

/**
 * What a line is worth in the appraisal as a whole — its share of its section,
 * of the section's share of everything. Weights are relative, so a section that
 * totals 120% still resolves; that is what makes this worth stating.
 */
export function shareOf(
    sectionWeight: number,
    sectionTotal: number,
    itemWeight: number,
    itemTotal: number,
): number | null {
    if (sectionTotal <= 0 || itemTotal <= 0) {
        return null;
    }

    return (sectionWeight / sectionTotal) * (itemWeight / itemTotal) * 100;
}

/** Spread 100% evenly over the matching rows, the remainder on the first. */
export function splitEvenly<T extends { weight: number }>(
    rows: T[],
    matches: (row: T) => boolean,
): T[] {
    const count = rows.filter(matches).length;

    if (count === 0) {
        return rows;
    }

    const each = Math.floor(100 / count);
    let first = true;

    return rows.map((row) => {
        if (!matches(row)) {
            return row;
        }

        const weight = first ? 100 - each * (count - 1) : each;
        first = false;

        return { ...row, weight };
    });
}

/** Weights are stored as decimals; totals are read, not audited. */
export function roundWeight(value: number): number {
    return Math.round(value * 10) / 10;
}
