import { Link } from '@inertiajs/react';
import { Gift, Medal } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { recognitionRoutes } from '../routes';
import type { RecognitionMe } from '../types';

/**
 * The person's standing: their points, and the kudos with points they can still
 * give this month — one dot per kudos, filled while it is still theirs to give.
 */
export function PointsCard({
    me,
    onNominate,
}: {
    me: RecognitionMe;
    onNominate?: () => void;
}) {
    const used = Math.max(0, me.kudos_monthly_limit - me.kudos_left);

    return (
        <section
            aria-label="Your points"
            className="flex flex-col gap-4 rounded-xl border border-sidebar-border/70 bg-card p-5 dark:border-sidebar-border"
        >
            <div>
                <p className="text-sm text-muted-foreground">Your points</p>
                <p className="mt-1 text-4xl leading-none font-semibold tracking-tight tabular-nums">
                    {me.balance.toLocaleString()}
                </p>
            </div>

            {me.kudos_points > 0 && me.kudos_monthly_limit > 0 && (
                <div className="flex flex-col gap-1.5">
                    <div className="flex gap-1" aria-hidden>
                        {Array.from(
                            { length: me.kudos_monthly_limit },
                            (_, i) => (
                                <span
                                    key={i}
                                    className={cn(
                                        'h-1.5 flex-1 rounded-full',
                                        i < used ? 'bg-muted' : 'bg-[#0ABFBF]',
                                    )}
                                />
                            ),
                        )}
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {me.kudos_left > 0
                            ? `${me.kudos_left} of ${me.kudos_monthly_limit} kudos this month still give ${me.kudos_points} points each.`
                            : 'Your kudos this month are still sent — without points until next month.'}
                    </p>
                </div>
            )}

            <div className="flex flex-col gap-2">
                {onNominate && (
                    <Button variant="outline" onClick={onNominate}>
                        <Medal className="size-4" />
                        Nominate a colleague
                    </Button>
                )}
                <Button variant="outline" asChild>
                    <Link href={recognitionRoutes.rewards}>
                        <Gift className="size-4" />
                        Spend points
                    </Link>
                </Button>
            </div>
        </section>
    );
}
