import { Head, usePage } from '@inertiajs/react';
import { HeartHandshake } from 'lucide-react';
import { useMemo } from 'react';
import { BenefitPlanCard } from '@/features/benefits/components/benefit-plan-card';
import { BenefitStatsCards } from '@/features/benefits/components/benefit-stats';
import { CATEGORY_LABELS, CATEGORY_ORDER } from '@/features/benefits/constants';
import type {
    BenefitCategory,
    BenefitPlan,
    BenefitsIndexPageProps,
} from '@/features/benefits/types';

export default function BenefitsIndex() {
    const { plans, stats } = usePage<BenefitsIndexPageProps>().props;

    // Group plans by category, preserving the canonical category order.
    const grouped = useMemo(() => {
        const map = new Map<BenefitCategory, BenefitPlan[]>();

        for (const plan of plans) {
            const list = map.get(plan.category) ?? [];
            list.push(plan);
            map.set(plan.category, list);
        }

        return CATEGORY_ORDER.filter((c) => map.has(c)).map((category) => ({
            category,
            items: map.get(category) as BenefitPlan[],
        }));
    }, [plans]);

    return (
        <>
            <Head title="Benefits Administration" />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Benefits Administration
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        The organisation's benefit plans and who's enrolled.
                    </p>
                </div>

                <BenefitStatsCards stats={stats} />

                {plans.length === 0 ? (
                    <EmptyState />
                ) : (
                    <div className="flex flex-col gap-7">
                        {grouped.map(({ category, items }) => (
                            <section
                                key={category}
                                className="flex flex-col gap-3"
                            >
                                <h2 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                    {CATEGORY_LABELS[category]}
                                </h2>
                                <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                                    {items.map((plan) => (
                                        <BenefitPlanCard
                                            key={plan.id}
                                            plan={plan}
                                        />
                                    ))}
                                </div>
                            </section>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

function EmptyState() {
    return (
        <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-16 text-center dark:border-sidebar-border">
            <span className="flex size-11 items-center justify-center rounded-full bg-[#0ABFBF]/10 text-[#0ABFBF]">
                <HeartHandshake className="size-5" />
            </span>
            <p className="text-sm font-medium">No benefit plans yet</p>
            <p className="max-w-sm text-sm text-muted-foreground">
                Add benefit plans under Company Setup → Benefits Configuration,
                then enroll employees here.
            </p>
        </div>
    );
}

BenefitsIndex.layout = {
    breadcrumbs: [{ title: 'Benefits', href: '/benefits' }],
};
