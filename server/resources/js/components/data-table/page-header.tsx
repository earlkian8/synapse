import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

type Props = {
    title: ReactNode;
    description?: ReactNode;
    /** A way back up the hierarchy, drawn as the square arrow button. */
    back?: { href: string; label: string };
    /** An avatar or icon beside the title (a record's page). */
    leading?: ReactNode;
    /** Status badges beside the title. */
    badges?: ReactNode;
    /** Buttons on the right. */
    actions?: ReactNode;
    className?: string;
};

/**
 * The top of every list and record page: an optional way back, the title with
 * its badges, one line of description, and the page's actions on the right —
 * compact, so the content starts high.
 */
export function PageHeader({
    title,
    description,
    back,
    leading,
    badges,
    actions,
    className,
}: Props) {
    return (
        <div
            className={cn(
                'flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between',
                className,
            )}
        >
            <div className="flex min-w-0 items-start gap-3">
                {back && (
                    <Button
                        variant="outline"
                        size="icon"
                        className="size-9 shrink-0"
                        asChild
                    >
                        <Link href={back.href} aria-label={back.label}>
                            <ArrowLeft className="size-4" />
                        </Link>
                    </Button>
                )}
                {leading}
                <div className="flex min-w-0 flex-col gap-0.5">
                    <div className="flex flex-wrap items-center gap-2">
                        <h1 className="text-xl font-semibold tracking-tight">
                            {title}
                        </h1>
                        {badges}
                    </div>
                    {description && (
                        <div className="text-sm text-muted-foreground">
                            {description}
                        </div>
                    )}
                </div>
            </div>

            {actions && (
                <div className="flex shrink-0 flex-wrap items-center gap-2">
                    {actions}
                </div>
            )}
        </div>
    );
}

/** The icon tile a record page's header leads with. */
export function HeaderIcon({ children }: { children: ReactNode }) {
    return (
        <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-[#0ABFBF]/10 text-[#0ABFBF] [&_svg]:size-5">
            {children}
        </span>
    );
}

/** The page's body: the same padding and the same 16px rhythm on every page. */
export function PageBody({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('flex flex-1 flex-col gap-4 p-4 md:p-6', className)}>
            {children}
        </div>
    );
}
