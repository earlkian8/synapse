import { Eye, EyeOff, LockKeyhole, UsersRound } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

/**
 * Who sees a check-in, said plainly once (ADR 0102). Confidential, not anonymous:
 * the system knows whose answers are whose so a person can see their own months —
 * and that is all anyone can see of them.
 */
export function WhoSeesThis({ minGroup = 5 }: { minGroup?: number }) {
    const rows: { icon: LucideIcon; who: string; what: string }[] = [
        {
            icon: Eye,
            who: 'You',
            what: 'Your answers and your notes, every month.',
        },
        {
            icon: UsersRound,
            who: 'HR and department heads',
            what: `Results for groups of ${minGroup} or more, never one person's. Smaller groups are pooled until they are big enough.`,
        },
        {
            icon: EyeOff,
            who: 'Nobody else',
            what: 'Not your manager one by one, not your employee record, not the assistant, not exports.',
        },
    ];

    return (
        <section className="flex flex-col gap-3 rounded-2xl border border-sidebar-border/70 p-5 dark:border-sidebar-border">
            <h2 className="flex items-center gap-2 text-sm font-semibold">
                <LockKeyhole className="size-4" aria-hidden />
                Who sees your check-in
            </h2>
            <ul className="flex flex-col gap-3">
                {rows.map(({ icon: Icon, who, what }) => (
                    <li key={who} className="flex items-start gap-3">
                        <span className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                            <Icon className="size-3.5" aria-hidden />
                        </span>
                        <span className="text-sm">
                            <span className="font-medium">{who}.</span>{' '}
                            <span className="text-muted-foreground">
                                {what}
                            </span>
                        </span>
                    </li>
                ))}
            </ul>
            <p className="text-xs text-muted-foreground">
                The questions are published, tested measures: mood, overall job
                satisfaction, the three-item Utrecht Work Engagement Scale, and
                how often you have considered leaving.
            </p>
        </section>
    );
}
