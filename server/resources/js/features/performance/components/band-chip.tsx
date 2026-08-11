import { cn } from '@/lib/utils';
import { bandTone } from '../constants';
import type { BandTone } from '../types';

type Props = {
    label: string | null;
    tone?: BandTone;
    size?: 'sm' | 'md';
    className?: string;
};

/**
 * The rating a company gave, in that company's own words. Used everywhere a
 * result is named — the list, the scorecard, the calibration table — so
 * "Outstanding", "A" and "Exceptional Leader" all read as the same kind of
 * thing.
 */
export function BandChip({ label, tone, size = 'sm', className }: Props) {
    if (!label) {
        return (
            <span
                className={cn(
                    'inline-flex shrink-0 items-center rounded-full border border-dashed border-border px-2 py-0.5 text-xs text-muted-foreground',
                    className,
                )}
            >
                Unrated
            </span>
        );
    }

    const palette = bandTone(tone);

    return (
        <span
            className={cn(
                'inline-flex shrink-0 items-center rounded-full border font-medium',
                palette.border,
                palette.soft,
                palette.text,
                size === 'md' ? 'px-2.5 py-1 text-sm' : 'px-2 py-0.5 text-xs',
                className,
            )}
        >
            {label}
        </span>
    );
}
