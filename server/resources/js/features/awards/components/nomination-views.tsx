import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { awardsRoutes } from '../routes';

const VIEWS = [
    {
        key: 'colleagues',
        label: 'From colleagues',
        href: awardsRoutes.nominations,
    },
    { key: 'shortlist', label: 'AI shortlist', href: awardsRoutes.shortlist },
] as const;

/**
 * The Nominations section's two views (ADR 0071): who colleagues put forward,
 * and who the records suggest. Uses the shared underline tab style.
 */
export function NominationViews({
    current,
    pending,
}: {
    current: (typeof VIEWS)[number]['key'];
    pending?: number | null;
}) {
    return (
        <nav
            aria-label="Nomination views"
            className="flex items-center gap-1 overflow-x-auto border-b border-border"
        >
            {VIEWS.map((view) => (
                <Link
                    key={view.key}
                    href={view.href}
                    aria-current={view.key === current ? 'page' : undefined}
                    className={cn(
                        'inline-flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors',
                        view.key === current
                            ? 'border-[#0ABFBF] text-foreground'
                            : 'border-transparent text-muted-foreground hover:text-foreground',
                    )}
                >
                    {view.label}
                    {view.key === 'colleagues' && pending ? (
                        <span className="text-muted-foreground tabular-nums">
                            {pending}
                        </span>
                    ) : null}
                </Link>
            ))}
        </nav>
    );
}
