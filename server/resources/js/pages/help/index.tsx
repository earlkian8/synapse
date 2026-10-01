import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { PageBody } from '@/components/data-table';
import { CategoryCard } from '@/features/help-center/components/category-card';
import { HelpHero } from '@/features/help-center/components/help-hero';
import { StillNeedHelp } from '@/features/help-center/components/still-need-help';
import type { HelpIndexPageProps } from '@/features/help-center/types';

/**
 * The Help Center (ADR 0062): SYNAPSE's user manual. A search, a few places
 * to start chosen for this person, and every topic they can read about.
 */
export default function HelpIndex() {
    const { categories, featured } = usePage<HelpIndexPageProps>().props;

    return (
        <>
            <Head title="Help Center" />

            <PageBody className="gap-10">
                <HelpHero />

                {featured.length > 0 && (
                    <section aria-labelledby="help-start-here">
                        <h2
                            id="help-start-here"
                            className="text-lg font-semibold tracking-tight"
                        >
                            Start here
                        </h2>
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            The guides most people in your role read first.
                        </p>

                        <ul className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            {featured.map((article) => (
                                <li key={article.slug}>
                                    <Link
                                        href={article.href}
                                        className="group flex h-full flex-col rounded-xl border border-sidebar-border/70 bg-card p-4 transition-colors hover:border-[#0ABFBF]/50 dark:border-sidebar-border"
                                    >
                                        <span className="text-[11px] font-medium tracking-wide text-[#0A9E9E] uppercase dark:text-[#0ABFBF]">
                                            {article.category_title}
                                        </span>
                                        <span className="mt-1 font-medium tracking-tight">
                                            {article.title}
                                        </span>
                                        <span className="mt-1 flex-1 text-sm leading-relaxed text-muted-foreground">
                                            {article.summary}
                                        </span>
                                        <span className="mt-3 inline-flex items-center gap-1 text-xs font-medium text-foreground/70 transition-colors group-hover:text-[#0A9E9E] dark:group-hover:text-[#0ABFBF]">
                                            Read the guide
                                            <ArrowRight className="size-3 transition-transform group-hover:translate-x-0.5" />
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <section aria-labelledby="help-topics">
                    <h2
                        id="help-topics"
                        className="text-lg font-semibold tracking-tight"
                    >
                        Browse by topic
                    </h2>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        Only the parts of SYNAPSE your role can use are listed.
                    </p>

                    <div className="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {categories.map((category) => (
                            <CategoryCard
                                key={category.key}
                                category={category}
                            />
                        ))}
                    </div>
                </section>

                <StillNeedHelp />
            </PageBody>
        </>
    );
}

HelpIndex.layout = {
    breadcrumbs: [{ title: 'Help Center', href: '/help' }],
};
