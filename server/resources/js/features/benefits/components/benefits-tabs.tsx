import { Link, usePage } from '@inertiajs/react';
import { HeartHandshake, Landmark } from 'lucide-react';
import { cn } from '@/lib/utils';
import { benefitsRoutes } from '../routes';

const TABS = [
    { label: 'Plans', href: benefitsRoutes.index, icon: HeartHandshake },
    {
        label: 'Contributions',
        href: '/benefits/contributions',
        icon: Landmark,
    },
] as const;

/** Secondary nav for the Benefits area: enrollment plans vs. statutory contributions. */
export function BenefitsTabs() {
    const { url } = usePage();
    const path = url.split('?')[0];

    return (
        <div className="flex items-center gap-1 border-b border-border">
            {TABS.map((tab) => {
                const active =
                    tab.href === benefitsRoutes.index
                        ? path === '/benefits'
                        : path.startsWith(tab.href);
                const Icon = tab.icon;

                return (
                    <Link
                        key={tab.href}
                        href={tab.href}
                        className={cn(
                            'flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium transition-colors',
                            active
                                ? 'border-[#0ABFBF] text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        )}
                    >
                        <Icon className="size-4" />
                        {tab.label}
                    </Link>
                );
            })}
        </div>
    );
}
