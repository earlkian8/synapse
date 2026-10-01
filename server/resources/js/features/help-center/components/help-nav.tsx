import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useState } from 'react';
import { cn } from '@/lib/utils';
import { CategoryIcon } from '../icons';
import type { HelpCategory } from '../types';

type Props = {
    categories: HelpCategory[];
    /** The topic and article being read, to open and mark them. */
    currentCategory?: string;
    currentArticle?: string;
    /** Called after a link is followed — the phone sheet closes itself. */
    onNavigate?: () => void;
};

/**
 * Every topic and its articles, as a documentation sidebar: the topic being
 * read starts open, the rest fold to their titles. It lists only what the
 * reader may open, like the app's own sidebar.
 */
export function HelpNav({
    categories,
    currentCategory,
    currentArticle,
    onNavigate,
}: Props) {
    const [open, setOpen] = useState<Set<string>>(
        () => new Set(currentCategory ? [currentCategory] : []),
    );

    const toggle = (key: string) =>
        setOpen((current) => {
            const next = new Set(current);

            if (next.has(key)) {
                next.delete(key);
            } else {
                next.add(key);
            }

            return next;
        });

    return (
        <nav aria-label="Help topics" className="text-sm">
            <Link
                href="/help"
                onClick={onNavigate}
                className="mb-3 flex items-center gap-2 rounded-md px-2 py-1.5 text-[13px] font-medium text-muted-foreground transition-colors hover:bg-muted/60 hover:text-foreground"
            >
                Help Center home
            </Link>

            <ul className="space-y-0.5">
                {categories.map((category) => {
                    const expanded = open.has(category.key);
                    const listId = `help-nav-${category.key}`;

                    return (
                        <li key={category.key}>
                            <button
                                type="button"
                                onClick={() => toggle(category.key)}
                                aria-expanded={expanded}
                                aria-controls={listId}
                                className={cn(
                                    'flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left font-medium transition-colors hover:bg-muted/60',
                                    category.key === currentCategory
                                        ? 'text-foreground'
                                        : 'text-foreground/75',
                                )}
                            >
                                <CategoryIcon
                                    name={category.icon}
                                    className="size-4 shrink-0 text-muted-foreground"
                                />
                                <span className="flex-1 truncate">
                                    {category.title}
                                </span>
                                <ChevronRight
                                    className={cn(
                                        'size-3.5 shrink-0 text-muted-foreground/60 transition-transform',
                                        expanded && 'rotate-90',
                                    )}
                                />
                            </button>

                            {expanded && (
                                <ul
                                    id={listId}
                                    className="my-1 ml-[15px] space-y-px border-l border-border pl-2"
                                >
                                    <li>
                                        <Link
                                            href={category.href}
                                            onClick={onNavigate}
                                            aria-current={
                                                category.key ===
                                                    currentCategory &&
                                                !currentArticle
                                                    ? 'page'
                                                    : undefined
                                            }
                                            className="block rounded-md px-2 py-1 text-[13px] text-muted-foreground transition-colors hover:text-foreground aria-[current=page]:font-medium aria-[current=page]:text-[#0A9E9E] dark:aria-[current=page]:text-[#0ABFBF]"
                                        >
                                            Overview
                                        </Link>
                                    </li>
                                    {category.articles.map((article) => (
                                        <li key={article.slug}>
                                            <Link
                                                href={article.href}
                                                onClick={onNavigate}
                                                aria-current={
                                                    article.slug ===
                                                    currentArticle
                                                        ? 'page'
                                                        : undefined
                                                }
                                                className="block rounded-md px-2 py-1 text-[13px] leading-snug text-muted-foreground transition-colors hover:bg-muted/50 hover:text-foreground aria-[current=page]:bg-[#0ABFBF]/10 aria-[current=page]:font-medium aria-[current=page]:text-[#0A9E9E] dark:aria-[current=page]:text-[#0ABFBF]"
                                            >
                                                {article.title}
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
