import {
    AlertTriangle,
    CircleCheck,
    DoorOpen,
    Eye,
    Sparkles,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import type { Verdict } from '../types';

const TONES: Record<
    Verdict['tone'],
    { icon: typeof Eye; panel: string; chip: string }
> = {
    urgent: {
        icon: AlertTriangle,
        panel: 'border-rose-500/25 bg-rose-500/[0.05]',
        chip: 'bg-rose-500/12 text-rose-700 dark:text-rose-300',
    },
    watch: {
        icon: Eye,
        panel: 'border-amber-500/30 bg-amber-500/[0.06]',
        chip: 'bg-amber-500/15 text-amber-800 dark:text-amber-300',
    },
    calm: {
        icon: CircleCheck,
        panel: 'border-sidebar-border/70 bg-muted/30 dark:border-sidebar-border',
        chip: 'bg-muted text-foreground/80',
    },
    positive: {
        icon: Sparkles,
        panel: 'border-teal-600/25 bg-teal-600/[0.05]',
        chip: 'bg-teal-600/12 text-teal-800 dark:text-teal-300',
    },
    closed: {
        icon: DoorOpen,
        panel: 'border-sidebar-border/70 bg-muted/40 dark:border-sidebar-border',
        chip: 'bg-foreground/10 text-foreground',
    },
};

/**
 * The read on one person in a sentence HR can act on: how urgent it is, what the
 * prediction says once their other predictions and record are taken into account,
 * and anything that changes how far to trust it. Tone is carried by an icon and a
 * word as well as colour.
 */
export function VerdictCard({ verdict }: { verdict: Verdict }) {
    const tone = TONES[verdict.tone];
    const Icon = tone.icon;

    return (
        <div
            className={cn(
                'flex flex-col gap-2 rounded-xl border px-4 py-3.5',
                tone.panel,
            )}
        >
            <span
                className={cn(
                    'inline-flex w-fit items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-semibold',
                    tone.chip,
                )}
            >
                <Icon className="size-3.5" />
                {verdict.label}
            </span>
            <p className="text-[15px] leading-snug font-medium text-pretty">
                {verdict.headline}
            </p>
            {verdict.notes.length > 0 && (
                <ul className="flex flex-col gap-1">
                    {verdict.notes.map((note) => (
                        <li
                            key={note}
                            className="text-xs leading-relaxed text-muted-foreground"
                        >
                            {note}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
