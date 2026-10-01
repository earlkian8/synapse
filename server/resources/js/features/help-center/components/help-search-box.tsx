import { Search } from 'lucide-react';
import { useEffect, useRef } from 'react';
import { cn } from '@/lib/utils';
import { useHelpSearch } from '../use-help-search';

type Props = {
    defaultValue?: string;
    /** `hero` for the navy band on the home page, `inline` everywhere else. */
    variant?: 'hero' | 'inline';
    /** Refresh results as the person types (the results page itself). */
    live?: boolean;
    autoFocus?: boolean;
    className?: string;
};

/**
 * The Help Center's search. "/" focuses it from anywhere on a help page, the
 * way documentation sites do, unless the person is already typing somewhere.
 */
export function HelpSearchBox({
    defaultValue = '',
    variant = 'inline',
    live = false,
    autoFocus = false,
    className,
}: Props) {
    const { query, change, submit } = useHelpSearch(defaultValue, live);
    const input = useRef<HTMLInputElement>(null);

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement | null;
            const typing =
                target?.isContentEditable ||
                ['INPUT', 'TEXTAREA', 'SELECT'].includes(target?.tagName ?? '');

            if (
                event.key === '/' &&
                !typing &&
                !event.metaKey &&
                !event.ctrlKey
            ) {
                event.preventDefault();
                input.current?.focus();
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, []);

    const hero = variant === 'hero';

    return (
        <form
            role="search"
            onSubmit={(event) => {
                event.preventDefault();
                submit();
            }}
            className={cn('relative', className)}
        >
            <Search
                aria-hidden
                className={cn(
                    'pointer-events-none absolute top-1/2 -translate-y-1/2',
                    hero
                        ? 'left-4 size-5 text-[#0F2044]/50'
                        : 'left-3 size-4 text-muted-foreground',
                )}
            />
            <input
                ref={input}
                type="search"
                name="q"
                value={query}
                onChange={(event) => change(event.target.value)}
                autoFocus={autoFocus}
                maxLength={120}
                autoComplete="off"
                placeholder={
                    hero
                        ? 'Search for answers — "approve leave", "clock in", "invite"…'
                        : 'Search the Help Center…'
                }
                aria-label="Search the Help Center"
                className={cn(
                    'w-full outline-none [&::-webkit-search-cancel-button]:cursor-pointer',
                    hero
                        ? 'h-13 rounded-xl bg-white pr-14 pl-12 text-[15px] text-[#0F2044] shadow-lg ring-1 shadow-black/20 ring-white/20 placeholder:text-[#0F2044]/45 focus:ring-4 focus:ring-[#0ABFBF]/40'
                        : 'h-10 rounded-lg border border-input bg-background pr-10 pl-9 text-sm transition-colors placeholder:text-muted-foreground hover:bg-muted/30 focus:border-ring focus:ring-2 focus:ring-ring/30',
                )}
            />
            <kbd
                aria-hidden
                className={cn(
                    'pointer-events-none absolute top-1/2 right-3 hidden -translate-y-1/2 rounded-md border px-1.5 font-mono text-[11px]',
                    // Out of the way of the box's own clear button once it has text.
                    query === '' && 'sm:block',
                    hero
                        ? 'border-[#0F2044]/15 text-[#0F2044]/45'
                        : 'border-border text-muted-foreground',
                )}
            >
                /
            </kbd>
        </form>
    );
}
