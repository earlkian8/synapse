import { Head, Link, usePage } from '@inertiajs/react';
import { Clock, ListTree } from 'lucide-react';
import { useMemo } from 'react';
import { ArticleBody } from '@/features/help-center/components/article-body';
import { ArticleList } from '@/features/help-center/components/article-list';
import { ArticlePager } from '@/features/help-center/components/article-pager';
import { ArticleToc } from '@/features/help-center/components/article-toc';
import { HelpShell } from '@/features/help-center/components/help-shell';
import { StillNeedHelp } from '@/features/help-center/components/still-need-help';
import { headingsOf } from '@/features/help-center/lib/markdown';
import type { HelpArticlePageProps } from '@/features/help-center/types';

/**
 * One Help Center article, laid out like documentation: the topics on the
 * left, the article in the middle, and "On this page" on the right — folded
 * into the top of the article where the screen is too narrow for it.
 */
export default function HelpArticlePage() {
    const { article, categories } = usePage<HelpArticlePageProps>().props;
    const headings = useMemo(() => headingsOf(article.body), [article.body]);
    const category = categories.find((c) => c.key === article.category);

    return (
        <>
            <Head title={`${article.title} — Help Center`} />

            <HelpShell
                categories={categories}
                currentCategory={article.category}
                currentArticle={article.slug}
            >
                <div className="grid gap-10 xl:grid-cols-[minmax(0,1fr)_13rem]">
                    <article
                        aria-labelledby="help-article-title"
                        className="max-w-3xl min-w-0"
                    >
                        <header className="border-b border-border/60 pb-6">
                            <Link
                                href={category?.href ?? '/help'}
                                className="text-xs font-semibold tracking-wide text-[#0A9E9E] uppercase hover:underline dark:text-[#0ABFBF]"
                            >
                                {article.category_title}
                            </Link>
                            <h1
                                id="help-article-title"
                                className="mt-2 text-[28px] leading-tight font-semibold tracking-tight text-balance"
                            >
                                {article.title}
                            </h1>
                            <p className="mt-3 text-base leading-relaxed text-pretty text-muted-foreground">
                                {article.summary}
                            </p>
                            <p className="mt-4 inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                                <Clock className="size-3.5" />
                                {article.reading_minutes} min read
                            </p>
                        </header>

                        {headings.length >= 2 && (
                            <details className="group mt-6 rounded-xl border border-border bg-muted/30 px-4 py-3 xl:hidden">
                                <summary className="flex cursor-pointer list-none items-center gap-2 text-sm font-medium [&::-webkit-details-marker]:hidden">
                                    <ListTree className="size-4 text-muted-foreground" />
                                    On this page
                                    <span className="ml-auto text-xs text-muted-foreground group-open:hidden">
                                        {headings.length} sections
                                    </span>
                                </summary>
                                <ul className="mt-3 space-y-1.5 border-t border-border/60 pt-3">
                                    {headings.map((heading) => (
                                        <li
                                            key={heading.id}
                                            className={
                                                heading.level === 3
                                                    ? 'pl-4'
                                                    : undefined
                                            }
                                        >
                                            <a
                                                href={`#${heading.id}`}
                                                className="text-sm text-muted-foreground hover:text-foreground"
                                            >
                                                {heading.title}
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                            </details>
                        )}

                        <div className="mt-8">
                            <ArticleBody markdown={article.body} />
                        </div>

                        {article.related.length > 0 && (
                            <section
                                aria-labelledby="help-related"
                                className="mt-12"
                            >
                                <h2
                                    id="help-related"
                                    className="mb-3 text-base font-semibold tracking-tight"
                                >
                                    Related articles
                                </h2>
                                <ArticleList
                                    articles={article.related}
                                    showCategory
                                />
                            </section>
                        )}

                        <div className="mt-10">
                            <ArticlePager
                                previous={article.previous}
                                next={article.next}
                            />
                        </div>

                        <div className="mt-10">
                            <StillNeedHelp topic={article.title} compact />
                        </div>
                    </article>

                    <aside className="hidden xl:block">
                        <div className="sticky top-20">
                            <ArticleToc headings={headings} />
                        </div>
                    </aside>
                </div>
            </HelpShell>
        </>
    );
}

HelpArticlePage.layout = (props: HelpArticlePageProps) => ({
    breadcrumbs: [
        { title: 'Help Center', href: '/help' },
        {
            title: props.article.category_title,
            href: `/help/${props.article.category}`,
        },
        { title: props.article.title, href: props.article.href },
    ],
});
