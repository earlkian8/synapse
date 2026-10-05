import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { tourTarget } from '@/features/product-tour/targets';
import { cn } from '@/lib/utils';
import { assistantLauncher, useAssistantOpen } from '../launcher';
import { AssistantMark } from './assistant-mark';

/** The id of the panel the button controls (see `assistant.tsx`). */
export const ASSISTANT_PANEL_ID = 'assistant-panel';

const isMac = () =>
    typeof navigator !== 'undefined' &&
    /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);

/**
 * The assistant's place in the top bar. It used to float over the bottom-right
 * corner of every page, where it sat on the footer's links and on whatever a
 * page put there; in the bar it covers nothing. ⌘J / Ctrl+J opens and closes
 * the panel from anywhere.
 */
export function AssistantButton() {
    const offered = usePage().props.auth.assistant;
    const open = useAssistantOpen();
    const shortcut = isMac() ? '⌘J' : 'Ctrl+J';

    useEffect(() => {
        if (!offered) {
            return;
        }

        const onKeyDown = (event: KeyboardEvent) => {
            if (
                event.key.toLowerCase() === 'j' &&
                (event.metaKey || event.ctrlKey) &&
                !event.altKey &&
                !event.shiftKey
            ) {
                event.preventDefault();
                assistantLauncher.toggle();
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [offered]);

    if (!offered) {
        return null;
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    onClick={() => assistantLauncher.toggle()}
                    aria-expanded={open}
                    aria-controls={open ? ASSISTANT_PANEL_ID : undefined}
                    aria-keyshortcuts="Meta+J Control+J"
                    {...tourTarget('assistant')}
                    className={cn(
                        'flex h-8 shrink-0 items-center gap-2 rounded-lg border px-2 text-[13px] font-medium transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring/50 sm:px-2.5',
                        open
                            ? 'border-assistant-ink bg-assistant-ink text-white'
                            : 'border-input bg-background text-foreground hover:bg-muted',
                    )}
                >
                    <AssistantMark
                        className={
                            open
                                ? 'text-white'
                                : 'text-assistant-ink dark:text-foreground'
                        }
                    />
                    <span className="sr-only sm:not-sr-only">Assistant</span>
                    <kbd
                        className={cn(
                            'hidden rounded px-1 font-sans text-[11px] leading-4 @4xl/header:inline',
                            open
                                ? 'bg-white/15 text-white/80'
                                : 'bg-muted text-muted-foreground',
                        )}
                    >
                        {shortcut}
                    </kbd>
                </button>
            </TooltipTrigger>
            <TooltipContent side="bottom" className="text-xs">
                {`${open ? 'Close the assistant' : 'Ask the assistant'} (${shortcut})`}
            </TooltipContent>
        </Tooltip>
    );
}
