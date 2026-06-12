import { ArrowDown, Sparkles } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Skeleton } from '@/components/ui/skeleton';
import type { ChatMessage } from '../types';
import { MessageItem } from './message-item';

const SUGGESTIONS = [
    'Add a new employee',
    'Onboard the attached CV',
    'File sick leave for someone tomorrow',
    'Move a candidate to the interview stage',
    'Who is on leave this week?',
];

export function MessageList({
    messages,
    streamingId,
    sending,
    loading,
    onStreamDone,
    onRegenerate,
    onEdit,
    onRetry,
    onPickSuggestion,
}: {
    messages: ChatMessage[];
    streamingId: number | string | null;
    sending: boolean;
    loading: boolean;
    onStreamDone: (id: number | string) => void;
    onRegenerate: () => void;
    onEdit: (id: number | string, text: string) => void;
    onRetry: () => void;
    onPickSuggestion: (prompt: string) => void;
}) {
    const scrollRef = useRef<HTMLDivElement>(null);
    const contentRef = useRef<HTMLDivElement>(null);
    const [atBottom, setAtBottom] = useState(true);

    // Auto-pin to the latest content while the user is already near the bottom.
    useEffect(() => {
        const scroller = scrollRef.current;
        const content = contentRef.current;

        if (!scroller || !content) {
            return;
        }

        const pin = () => {
            const distance =
                scroller.scrollHeight -
                scroller.scrollTop -
                scroller.clientHeight;

            if (distance < 180) {
                scroller.scrollTop = scroller.scrollHeight;
            }
        };

        const observer = new ResizeObserver(pin);
        observer.observe(content);

        return () => observer.disconnect();
    }, []);

    const onScroll = () => {
        const scroller = scrollRef.current;

        if (!scroller) {
            return;
        }

        const distance =
            scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight;
        setAtBottom(distance < 80);
    };

    const scrollToBottom = () => {
        const scroller = scrollRef.current;

        if (scroller) {
            scroller.scrollTo({
                top: scroller.scrollHeight,
                behavior: 'smooth',
            });
        }
    };

    const lastAssistantId = [...messages]
        .reverse()
        .find((m) => m.role === 'assistant' && !m.pending)?.id;

    return (
        <div className="relative min-h-0 flex-1">
            <div
                ref={scrollRef}
                onScroll={onScroll}
                className="h-full overflow-y-auto px-3.5 py-4"
            >
                <div ref={contentRef} className="flex flex-col gap-4">
                    {loading ? (
                        <LoadingSkeleton />
                    ) : messages.length === 0 ? (
                        <EmptyState onPick={onPickSuggestion} />
                    ) : (
                        messages.map((message) => (
                            <MessageItem
                                key={message.id}
                                message={message}
                                isLast={message.id === lastAssistantId}
                                streaming={streamingId === message.id}
                                sending={sending}
                                onStreamDone={onStreamDone}
                                onRegenerate={onRegenerate}
                                onEdit={onEdit}
                                onRetry={onRetry}
                            />
                        ))
                    )}
                </div>
            </div>

            {!atBottom && messages.length > 0 && (
                <button
                    type="button"
                    onClick={scrollToBottom}
                    aria-label="Scroll to latest"
                    className="absolute bottom-3 left-1/2 flex size-8 -translate-x-1/2 animate-in items-center justify-center rounded-full border border-border bg-card text-foreground shadow-md transition-colors zoom-in-90 fade-in hover:bg-muted"
                >
                    <ArrowDown className="size-4" />
                </button>
            )}
        </div>
    );
}

function EmptyState({ onPick }: { onPick: (prompt: string) => void }) {
    return (
        <div className="flex flex-col items-center gap-3 px-4 py-10 text-center">
            <span className="flex size-12 items-center justify-center rounded-2xl bg-[#0F2044] text-[#0ABFBF] ring-1 ring-border">
                <Sparkles className="size-6" />
            </span>
            <div>
                <p className="text-sm font-semibold">How can I help?</p>
                <p className="mx-auto mt-1 max-w-[280px] text-xs text-muted-foreground">
                    I can manage employees, leave, onboarding and recruitment
                    for you. Describe what you need, or drop in a CV and I'll
                    take it from there.
                </p>
            </div>
            <div className="mt-1 flex flex-col items-stretch gap-1.5 self-stretch">
                {SUGGESTIONS.map((prompt) => (
                    <button
                        key={prompt}
                        type="button"
                        onClick={() => onPick(prompt)}
                        className="rounded-xl border border-border bg-card px-3 py-2 text-left text-xs text-foreground transition-colors hover:border-[#0ABFBF]/50 hover:bg-muted"
                    >
                        {prompt}
                    </button>
                ))}
            </div>
        </div>
    );
}

function LoadingSkeleton() {
    return (
        <div className="flex flex-col gap-4">
            <div className="flex justify-end">
                <Skeleton className="h-9 w-40 rounded-2xl" />
            </div>
            <div className="flex gap-2.5">
                <Skeleton className="size-7 shrink-0 rounded-lg" />
                <Skeleton className="h-16 w-56 rounded-2xl" />
            </div>
            <div className="flex justify-end">
                <Skeleton className="h-9 w-28 rounded-2xl" />
            </div>
            <div className="flex gap-2.5">
                <Skeleton className="size-7 shrink-0 rounded-lg" />
                <Skeleton className="h-12 w-44 rounded-2xl" />
            </div>
        </div>
    );
}
