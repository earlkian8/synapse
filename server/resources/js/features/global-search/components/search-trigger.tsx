import { Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { SearchPalette } from './search-palette';

const isMac = () =>
    typeof navigator !== 'undefined' &&
    /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);

/**
 * Global search's place in the top bar (ADR 0069): a field-shaped button where
 * the bar is wide enough, an icon where it is not, and ⌘K / Ctrl+K from
 * anywhere. Each opens the same palette. The bar's width (not the window's)
 * decides, because the docked assistant narrows it too.
 */
export function SearchTrigger() {
    const [open, setOpen] = useState(false);
    const shortcut = isMac() ? '⌘K' : 'Ctrl+K';

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (
                event.key.toLowerCase() === 'k' &&
                (event.metaKey || event.ctrlKey) &&
                !event.altKey &&
                !event.shiftKey
            ) {
                event.preventDefault();
                setOpen((isOpen) => !isOpen);
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, []);

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                aria-haspopup="dialog"
                aria-keyshortcuts="Meta+K Control+K"
                className="hidden h-8 w-64 items-center gap-2 rounded-lg border border-input bg-muted/40 pr-1.5 pl-2.5 text-[13px] text-muted-foreground transition-colors outline-none hover:bg-muted hover:text-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 lg:w-72 @4xl/header:flex"
            >
                <Search className="size-4 shrink-0" />
                <span className="flex-1 truncate text-left">
                    Search people, records, help…
                </span>
                <kbd className="rounded bg-background px-1 font-sans text-[11px] leading-4 ring-1 ring-border">
                    {shortcut}
                </kbd>
            </button>

            <Tooltip>
                <TooltipTrigger asChild>
                    <Button
                        variant="ghost"
                        size="icon"
                        onClick={() => setOpen(true)}
                        aria-label="Search"
                        aria-haspopup="dialog"
                        className="size-8 text-muted-foreground hover:text-foreground @4xl/header:hidden"
                    >
                        <Search className="size-[18px]" />
                    </Button>
                </TooltipTrigger>
                <TooltipContent side="bottom" className="text-xs">
                    {`Search (${shortcut})`}
                </TooltipContent>
            </Tooltip>

            <SearchPalette open={open} onOpenChange={setOpen} />
        </>
    );
}
