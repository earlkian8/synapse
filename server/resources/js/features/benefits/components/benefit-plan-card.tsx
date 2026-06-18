import { Link } from '@inertiajs/react';
import { ArrowRight, Users } from 'lucide-react';
import { cn } from '@/lib/utils';
import { CATEGORY_META, FREQUENCY_SUFFIX, formatPeso } from '../constants';
import { benefitsRoutes } from '../routes';
import type { BenefitPlan } from '../types';

/**
 * A benefit plan as a card — the entry point into its roster. Shows the category,
 * provider, enrollment headcount and the employee / employer share per period.
 */
export function BenefitPlanCard({ plan }: { plan: BenefitPlan }) {
    const meta = CATEGORY_META[plan.category];
    const Icon = meta.icon;
    const suffix = FREQUENCY_SUFFIX[plan.frequency];

    return (
        <Link
            href={benefitsRoutes.show(plan.hashid)}
            className="group flex flex-col gap-4 rounded-xl border border-sidebar-border/70 bg-card p-5 shadow-sm transition-all hover:border-[#0ABFBF]/40 hover:shadow-md dark:border-sidebar-border"
        >
            <div className="flex items-start justify-between gap-3">
                <div className="flex min-w-0 items-center gap-3">
                    <span
                        className={cn(
                            'flex size-10 shrink-0 items-center justify-center rounded-lg',
                            meta.accent,
                        )}
                    >
                        <Icon className="size-5" />
                    </span>
                    <div className="min-w-0">
                        <p className="truncate font-semibold">{plan.name}</p>
                        <p className="truncate text-xs text-muted-foreground">
                            {plan.provider ?? 'In-house'}
                        </p>
                    </div>
                </div>
                {!plan.is_active && (
                    <span className="shrink-0 rounded-full border border-border bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground">
                        Inactive
                    </span>
                )}
            </div>

            <div className="flex items-end justify-between gap-3">
                <div className="flex flex-col gap-0.5">
                    <span className="text-[11px] tracking-wide text-muted-foreground uppercase">
                        Cost {suffix && <span>{suffix}</span>}
                    </span>
                    <span className="text-lg font-semibold tracking-tight tabular-nums">
                        {formatPeso(plan.total_cost)}
                    </span>
                    <span className="text-[11px] text-muted-foreground tabular-nums">
                        EE {formatPeso(plan.employee_cost)} · ER{' '}
                        {formatPeso(plan.employer_cost)}
                    </span>
                </div>
                <div className="flex items-center gap-1.5 rounded-lg bg-muted/60 px-2.5 py-1.5 text-sm">
                    <Users className="size-4 text-muted-foreground" />
                    <span className="font-semibold tabular-nums">
                        {plan.active_count}
                    </span>
                    <span className="text-xs text-muted-foreground">
                        enrolled
                    </span>
                </div>
            </div>

            <div className="flex items-center gap-1 text-xs font-medium text-[#0a8b91] dark:text-[#0ABFBF]">
                Manage roster
                <ArrowRight className="size-3.5 transition-transform group-hover:translate-x-0.5" />
            </div>
        </Link>
    );
}
