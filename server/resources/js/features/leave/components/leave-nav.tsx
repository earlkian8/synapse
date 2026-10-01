import { Link } from '@inertiajs/react';
import { CalendarRange, Inbox } from 'lucide-react';
import { cn } from '@/lib/utils';
import { leaveRoutes } from '../routes';

const TABS = [
    { href: leaveRoutes.index, label: 'Requests', icon: Inbox },
    { href: leaveRoutes.balances, label: 'Balances', icon: CalendarRange },
] as const;

export function LeaveNav({ active }: { active: 'requests' | 'balances' }) {
    return (
        <div className="inline-flex items-center gap-1 rounded-lg border border-sidebar-border/70 bg-card p-1 dark:border-sidebar-border">
            {TABS.map((tab) => {
                const isActive =
                    (tab.label === 'Requests') === (active === 'requests');

                return (
                    <Link
                        key={tab.href}
                        href={tab.href}
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                            isActive
                                ? 'bg-[#0ABFBF]/10 text-[#0ABFBF]'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        <tab.icon className="size-4" />
                        {tab.label}
                    </Link>
                );
            })}
        </div>
    );
}
