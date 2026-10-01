import { Head, Link, usePage } from '@inertiajs/react';
import { SearchX } from 'lucide-react';
import { ArticleList } from '@/features/help-center/components/article-list';
import { HelpShell } from '@/features/help-center/components/help-shell';
import { Highlighted } from '@/features/help-center/components/highlighted';
import { StillNeedHelp } from '@/features/help-center/components/still-need-help';
import { CategoryIcon } from '@/features/help-center/icons';
import type {
    HelpCategory,
    HelpSearchPageProps,
} from '@/features/help-center/types';

/**
 * Searching the Help Center. Results refresh as the person types, and only
 * ever include articles they may read; each shows the line of the article
 * that matched, with the searched-for words drawn in bold.
 */
export default function HelpSearchPage() {
    const { query, results, partial, terms, categories } =
        usePage<HelpSearchPageProps>().props;

    const searched = query.trim() !== '';
    const count = results.length;

    return (
        <>
            <Head
                title={
                    searched
                        ? `“${query}” — Help Center`
                        : 'Search — Help Center'
                }
            />

            <HelpShell categories={categories} query={query} liveSearch>
                <div className="flex flex-col gap-6">
                    <p className="sr-only" aria-live="polite">
                        {searched
                            ? `${count} ${count === 1 ? 'result' : 'results'}`
                            : ''}
                    </p>
                    {!searched ? (
                        <BrowseInstead
                            title="Search the Help Center"
                            description="Type a few words about what you want to do — “approve leave”, “night shift”, “invite”. Or start from a topic:"
                            categories={categories}
                        />
                    ) : count === 0 ? (
                        <>
                            <div className="flex flex-col items-center rounded-xl border border-dashed border-border px-6 py-10 text-center">
                                <SearchX className="size-8 text-muted-foreground/60" />
                                <h1 className="mt-3 text-lg font-semibold tracking-tight">
                                    Nothing found for “{query}”
                                </h1>
                                <p className="mt-1 max-w-md text-sm text-muted-foreground">
                                    Try fewer or different words, check the
                                    spelling, or browse a topic. Articles about
                                    screens your role can’t open aren’t listed.
                                </p>
                            </div>
                            <BrowseInstead
                                title="Browse a topic"
                                categories={categories}
                            />
                            <StillNeedHelp topic={query} compact />
                        </>
                    ) : (
                        <>
                            <header>
                                <h1 className="text-xl font-semibold tracking-tight">
                                    {count} {count === 1 ? 'result' : 'results'}{' '}
                                    for “{query}”
                                </h1>
                                {partial && (
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        No article matched every word, so these
                                        match some of them.
                                    </p>
                                )}
                            </header>

                            <ArticleList
                                articles={results}
                                showCategory
                                detail={(article) => (
                                    <span className="mt-2 block text-[13px] leading-relaxed text-muted-foreground/90">
                                        <Highlighted
                                            text={article.snippet}
                                            terms={terms}
                                        />
                                    </span>
                                )}
                            />

                            <StillNeedHelp topic={query} compact />
                        </>
                    )}
                </div>
            </HelpShell>
        </>
    );
}

/** The topics as quick links, for when a search has nothing to show. */
function BrowseInstead({
    title,
    description,
    categories,
}: {
    title: string;
    description?: string;
    categories: HelpCategory[];
}) {
    return (
        <section aria-labelledby="help-browse">
            <h2
                id="help-browse"
                className="text-lg font-semibold tracking-tight"
            >
                {title}
            </h2>
            {description && (
                <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
                    {description}
                </p>
            )}
            <ul className="mt-4 grid gap-2 sm:grid-cols-2">
                {categories.map((category) => {
                    return (
                        <li key={category.key}>
                            <Link
                                href={category.href}
                                className="flex items-center gap-3 rounded-lg border border-sidebar-border/70 px-3 py-2.5 text-sm transition-colors hover:border-[#0ABFBF]/50 hover:bg-muted/30 dark:border-sidebar-border"
                            >
                                <CategoryIcon
                                    name={category.icon}
                                    className="size-4 text-[#0ABFBF]"
                                />
                                <span className="flex-1 font-medium">
                                    {category.title}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {category.articles.length}
                                </span>
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}

HelpSearchPage.layout = {
    breadcrumbs: [
        { title: 'Help Center', href: '/help' },
        { title: 'Search', href: '/help/search' },
    ],
};
