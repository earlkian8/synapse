import { Link } from '@inertiajs/react';
import { ChevronRight, FileText } from 'lucide-react';
import type { ReactNode } from 'react';
import type { HelpArticleSummary } from '../types';

/**
 * Articles as a list of rows — a topic's contents, or search results. Each
 * row is one link; `detail` adds a line under the summary (a search snippet).
 */
export function ArticleList<T extends HelpArticleSummary>({
    articles,
    showCategory = false,
    detail,
}: {
    articles: T[];
    showCategory?: boolean;
    detail?: (article: T) => ReactNode;
}) {
    return (
        <ul className="divide-y divide-border/70 overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
            {articles.map((article) => (
                <li key={article.slug}>
                    <Link
                        href={article.href}
                        className="group flex items-start gap-3.5 px-4 py-4 transition-colors hover:bg-muted/40 sm:px-5"
                    >
                        <FileText className="mt-0.5 size-[18px] shrink-0 text-muted-foreground/60 transition-colors group-hover:text-[#0ABFBF]" />
                        <span className="min-w-0 flex-1">
                            {showCategory && (
                                <span className="mb-0.5 block text-[11px] font-medium tracking-wide text-[#0A9E9E] uppercase dark:text-[#0ABFBF]">
                                    {article.category_title}
                                </span>
                            )}
                            <span className="block font-medium tracking-tight text-foreground">
                                {article.title}
                            </span>
                            <span className="mt-0.5 block text-sm leading-relaxed text-muted-foreground">
                                {article.summary}
                            </span>
                            {detail?.(article)}
                        </span>
                        <ChevronRight className="mt-1 size-4 shrink-0 text-muted-foreground/40 transition-transform group-hover:translate-x-0.5 group-hover:text-foreground" />
                    </Link>
                </li>
            ))}
        </ul>
    );
}
