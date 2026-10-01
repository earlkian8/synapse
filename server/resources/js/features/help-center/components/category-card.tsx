import { Link } from '@inertiajs/react';
import { ArrowRight, ChevronRight } from 'lucide-react';
import { CategoryIcon } from '../icons';
import type { HelpCategory } from '../types';

/** How many articles a card lists before "View all". */
const PREVIEW = 3;

/**
 * A topic on the Help Center's home: what it covers, and its first few
 * articles to jump straight to.
 */
export function CategoryCard({ category }: { category: HelpCategory }) {
    const count = category.articles.length;

    return (
        <section
            aria-labelledby={`help-category-${category.key}`}
            className="group/card flex flex-col rounded-xl border border-sidebar-border/70 bg-card p-5 transition-colors hover:border-[#0ABFBF]/40 dark:border-sidebar-border"
        >
            <div className="flex items-start gap-3">
                <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[#0ABFBF]">
                    <CategoryIcon name={category.icon} className="size-5" />
                </span>
                <div className="min-w-0">
                    <h2
                        id={`help-category-${category.key}`}
                        className="text-[15px] font-semibold tracking-tight"
                    >
                        <Link
                            href={category.href}
                            className="hover:text-[#0A9E9E] dark:hover:text-[#0ABFBF]"
                        >
                            {category.title}
                        </Link>
                    </h2>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {count} {count === 1 ? 'article' : 'articles'}
                    </p>
                </div>
            </div>

            <p className="mt-3 text-sm leading-relaxed text-muted-foreground">
                {category.description}
            </p>

            <ul className="mt-4 flex flex-1 flex-col gap-0.5 border-t border-border/60 pt-3">
                {category.articles.slice(0, PREVIEW).map((article) => (
                    <li key={article.slug}>
                        <Link
                            href={article.href}
                            className="group -mx-2 flex items-center justify-between gap-2 rounded-md px-2 py-1.5 text-sm text-foreground/80 transition-colors hover:bg-muted/60 hover:text-foreground"
                        >
                            <span className="truncate">{article.title}</span>
                            <ChevronRight className="size-3.5 shrink-0 text-muted-foreground/50 transition-transform group-hover:translate-x-0.5 group-hover:text-[#0ABFBF]" />
                        </Link>
                    </li>
                ))}
            </ul>

            {count > PREVIEW && (
                <Link
                    href={category.href}
                    className="mt-3 inline-flex items-center gap-1 self-start text-xs font-medium text-[#0A9E9E] hover:underline dark:text-[#0ABFBF]"
                >
                    View all {count}
                    <ArrowRight className="size-3" />
                </Link>
            )}
        </section>
    );
}
