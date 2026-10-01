import { Head, usePage } from '@inertiajs/react';
import { HeaderIcon } from '@/components/data-table';
import { ArticleList } from '@/features/help-center/components/article-list';
import { HelpShell } from '@/features/help-center/components/help-shell';
import { StillNeedHelp } from '@/features/help-center/components/still-need-help';
import { CategoryIcon } from '@/features/help-center/icons';
import type { HelpCategoryPageProps } from '@/features/help-center/types';

/** A Help Center topic: what it covers, and every article in it. */
export default function HelpCategoryPage() {
    const { category, categories } = usePage<HelpCategoryPageProps>().props;
    const count = category.articles.length;

    return (
        <>
            <Head title={`${category.title} — Help Center`} />

            <HelpShell categories={categories} currentCategory={category.key}>
                <div className="flex flex-col gap-6">
                    <header className="flex items-start gap-3">
                        <HeaderIcon>
                            <CategoryIcon name={category.icon} />
                        </HeaderIcon>
                        <div className="min-w-0">
                            <h1 className="text-2xl font-semibold tracking-tight">
                                {category.title}
                            </h1>
                            <p className="mt-1 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                                {category.description}
                            </p>
                            <p className="mt-2 text-xs text-muted-foreground">
                                {count} {count === 1 ? 'article' : 'articles'}
                            </p>
                        </div>
                    </header>

                    <ArticleList articles={category.articles} />

                    <StillNeedHelp topic={category.title} compact />
                </div>
            </HelpShell>
        </>
    );
}

HelpCategoryPage.layout = (props: HelpCategoryPageProps) => ({
    breadcrumbs: [
        { title: 'Help Center', href: '/help' },
        { title: props.category.title, href: props.category.href },
    ],
});
