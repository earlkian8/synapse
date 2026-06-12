import { Link } from '@inertiajs/react';
import { Separator } from '@/components/ui/separator';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

const APP_VERSION = 'v1.0.0';

const footerLinks = [
    { label: 'Privacy', href: '#' },
    { label: 'Terms', href: '#' },
    { label: 'Support', href: '#' },
    { label: 'Docs', href: '#' },
];

export function AppFooter() {
    const year = new Date().getFullYear();

    return (
        <footer className="mt-auto flex h-11 shrink-0 items-center justify-between gap-3 border-t border-sidebar-border/50 bg-background px-4 text-[11px] text-muted-foreground sm:px-6">
            {/* Left: copyright + version */}
            <div className="flex min-w-0 items-center gap-2.5">
                <span className="truncate">
                    &copy; {year} NEXO. All rights reserved.
                </span>
                <Separator
                    orientation="vertical"
                    className="hidden h-3 sm:block"
                />
                <span className="hidden font-mono text-[10px] tracking-wide text-muted-foreground/70 sm:inline">
                    {APP_VERSION}
                </span>
            </div>

            {/* Right: status + links */}
            <div className="flex items-center gap-3">
                <Tooltip>
                    <TooltipTrigger asChild>
                        <span className="hidden items-center gap-1.5 md:inline-flex">
                            <span className="relative flex size-2">
                                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-500/60" />
                                <span className="relative inline-flex size-2 rounded-full bg-emerald-500" />
                            </span>
                            <span className="text-[11px] font-medium text-emerald-600 dark:text-emerald-400">
                                All systems operational
                            </span>
                        </span>
                    </TooltipTrigger>
                    <TooltipContent side="top" className="text-xs">
                        99.98% uptime this month
                    </TooltipContent>
                </Tooltip>

                <Separator
                    orientation="vertical"
                    className="hidden h-3 md:block"
                />

                <nav className="flex items-center gap-3">
                    {footerLinks.map((link) => (
                        <Link
                            key={link.label}
                            href={link.href}
                            className="transition-colors hover:text-foreground"
                        >
                            {link.label}
                        </Link>
                    ))}
                </nav>
            </div>
        </footer>
    );
}
