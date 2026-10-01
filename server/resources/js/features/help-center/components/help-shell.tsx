import type { ReactNode } from 'react';
import { PageBody } from '@/components/data-table';
import type { HelpCategory } from '../types';
import { HelpNav } from './help-nav';
import { HelpNavSheet } from './help-nav-sheet';
import { HelpSearchBox } from './help-search-box';

/**
 * The frame of every Help Center page past the home: the topics down the left
 * (a panel on a phone), the search across the top, and the page in the middle.
 */
export function HelpShell({
    categories,
    currentCategory,
    currentArticle,
    query = '',
    liveSearch = false,
    children,
}: {
    categories: HelpCategory[];
    currentCategory?: string;
    currentArticle?: string;
    query?: string;
    liveSearch?: boolean;
    children: ReactNode;
}) {
    const nav = { categories, currentCategory, currentArticle };

    return (
        <PageBody className="gap-6">
            <div className="flex items-center gap-2">
                <HelpNavSheet key={currentCategory} {...nav} />
                <HelpSearchBox
                    defaultValue={query}
                    live={liveSearch}
                    autoFocus={liveSearch}
                    className="w-full max-w-xl"
                />
            </div>

            <div className="grid gap-8 lg:grid-cols-[15rem_minmax(0,1fr)] xl:gap-10">
                <aside className="hidden lg:block">
                    <div className="sticky top-20 max-h-[calc(100svh-6rem)] overflow-y-auto pr-1 pb-6">
                        <HelpNav key={currentCategory} {...nav} />
                    </div>
                </aside>

                <div className="min-w-0">{children}</div>
            </div>
        </PageBody>
    );
}
