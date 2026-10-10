import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { Fragment, useEffect, useRef } from 'react';
import { usePermissions } from '@/hooks/use-permissions';
import { cn } from '@/lib/utils';

export type ModuleNavItem<K extends string> = {
    key: K;
    label: string;
    href: string;
    icon: LucideIcon;
    /** Shown only to someone holding this permission. */
    permission?: string;
    /** A count on the item, e.g. what waits for review. */
    count?: number | null;
};

/**
 * A module's sections — the segmented control in the page header that moves
 * between a module's screens (Leave's Requests / Balances first). Each item is
 * shown to whoever may open it; groups are separated by a rule (e.g. what is
 * yours, then what you manage). With fewer than two items there is nothing to
 * switch between, and it renders nothing.
 */
export function ModuleNav<K extends string>({
    label,
    groups,
    current,
}: {
    /** Names the navigation for assistive tech, e.g. "Leave sections". */
    label: string;
    groups: ModuleNavItem<K>[][];
    current: K;
}) {
    const { can } = usePermissions();
    const scroller = useRef<HTMLElement>(null);

    // On a narrow screen the control scrolls sideways: start with the current
    // section in view rather than cut off at the edge.
    useEffect(() => {
        const nav = scroller.current;
        const active = nav?.querySelector<HTMLElement>('[aria-current="page"]');

        if (nav && active && nav.scrollWidth > nav.clientWidth) {
            nav.scrollLeft =
                active.offsetLeft - (nav.clientWidth - active.offsetWidth) / 2;
        }
    }, [current]);
    const visible = groups
        .map((group) =>
            group.filter((item) => !item.permission || can(item.permission)),
        )
        .filter((group) => group.length > 0);

    if (visible.flat().length < 2) {
        return null;
    }

    return (
        <nav
            ref={scroller}
            aria-label={label}
            className="relative max-w-full [scrollbar-width:none] overflow-x-auto"
        >
            <div className="inline-flex items-center gap-1 rounded-lg border border-sidebar-border/70 bg-card p-1 dark:border-sidebar-border">
                {visible.map((group, index) => (
                    <Fragment key={group[0].key}>
                        {index > 0 && (
                            <span
                                aria-hidden
                                className="mx-0.5 h-5 w-px shrink-0 bg-border"
                            />
                        )}
                        {group.map((item) => {
                            const active = item.key === current;

                            return (
                                <Link
                                    key={item.key}
                                    href={item.href}
                                    aria-current={active ? 'page' : undefined}
                                    className={cn(
                                        'inline-flex shrink-0 items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium whitespace-nowrap transition-colors',
                                        active
                                            ? 'bg-[#0ABFBF]/10 text-[#0ABFBF]'
                                            : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    <item.icon className="size-4" />
                                    {item.label}
                                    {item.count ? (
                                        <span className="rounded-full bg-[#0ABFBF]/15 px-1.5 text-xs text-[#08767c] tabular-nums dark:text-[#0ABFBF]">
                                            {item.count}
                                        </span>
                                    ) : null}
                                </Link>
                            );
                        })}
                    </Fragment>
                ))}
            </div>
        </nav>
    );
}
