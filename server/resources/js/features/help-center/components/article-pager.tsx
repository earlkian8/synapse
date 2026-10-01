import { Link } from '@inertiajs/react';
import { ArrowLeft, ArrowRight } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { HelpArticleLink } from '../types';

/**
 * Previous and next, in reading order across the whole manual — so it can be
 * read straight through, like a book.
 */
export function ArticlePager({
    previous,
    next,
}: {
    previous: HelpArticleLink | null;
    next: HelpArticleLink | null;
}) {
    if (!previous && !next) {
        return null;
    }

    return (
        <nav aria-label="More articles" className="grid gap-3 sm:grid-cols-2">
            {previous ? (
                <PagerLink article={previous} direction="previous" />
            ) : (
                <span className="hidden sm:block" />
            )}
            {next && <PagerLink article={next} direction="next" />}
        </nav>
    );
}

function PagerLink({
    article,
    direction,
}: {
    article: HelpArticleLink;
    direction: 'previous' | 'next';
}) {
    const isNext = direction === 'next';

    return (
        <Link
            href={article.href}
            rel={isNext ? 'next' : 'prev'}
            className={cn(
                'group flex flex-col gap-1 rounded-xl border border-sidebar-border/70 px-4 py-3.5 transition-colors hover:border-[#0ABFBF]/50 hover:bg-muted/30 dark:border-sidebar-border',
                isNext && 'sm:items-end sm:text-right',
            )}
        >
            <span className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                {!isNext && (
                    <ArrowLeft className="size-3.5 transition-transform group-hover:-translate-x-0.5" />
                )}
                {isNext ? 'Next' : 'Previous'} · {article.category_title}
                {isNext && (
                    <ArrowRight className="size-3.5 transition-transform group-hover:translate-x-0.5" />
                )}
            </span>
            <span className="font-medium tracking-tight text-foreground">
                {article.title}
            </span>
        </Link>
    );
}
