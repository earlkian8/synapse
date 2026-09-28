import { ArrowDown, Sparkles } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Skeleton } from '@/components/ui/skeleton';
import { usePermissions } from '@/hooks/use-permissions';
import type { ChatMessage } from '../types';
import type { AnswerAction } from './agent-activity';
import { MessageItem } from './message-item';

/**
 * Starting points, each shown only to someone who could actually do it — a
 * suggestion that ends in "you don't have permission" is worse than none. The
 * first six someone may use are shown, so the order spreads them across
 * modules.
 */
const SUGGESTIONS: { prompt: string; permission: string | null }[] = [
    { prompt: 'How are we doing today?', permission: null },
    {
        prompt: 'How is the review cycle going?',
        permission: 'performance.view',
    },
    { prompt: 'Who is leaving this month?', permission: 'offboarding.view' },
    {
        prompt: 'What’s our turnover this year?',
        permission: 'employees.view',
    },
    {
        prompt: 'What trainings are running right now?',
        permission: 'training.view',
    },
    {
        prompt: 'Who is leading for Employee of the Month?',
        permission: 'awards.manage',
    },
    { prompt: 'Who is on leave this week?', permission: 'leave.view' },
    { prompt: 'Any meetings coming up this week?', permission: 'events.view' },
    {
        prompt: 'How is our org structure set up?',
        permission: 'setup.departments.view',
    },
    { prompt: 'When is the next holiday?', permission: 'setup.schedule.view' },
    {
        prompt: 'How do our attendance rules handle overtime?',
        permission: 'setup.attendance-policies.view',
    },
    { prompt: 'What sites do we have?', permission: 'setup.locations.view' },
    {
        prompt: 'What leave types do we offer?',
        permission: 'setup.leave-types.view',
    },
    {
        prompt: 'What does our appraisal framework measure?',
        permission: 'setup.kpi.view',
    },
    { prompt: 'Add a new employee', permission: 'employees.create' },
    {
        prompt: 'File sick leave for someone tomorrow',
        permission: 'leave.manage',
    },
    {
        prompt: 'Move a candidate to the interview stage',
        permission: 'recruitment.manage-pipeline',
    },
];

/** How many suggestions the empty state offers at most. */
const MAX_SUGGESTIONS = 6;

export function MessageList({
    messages,
    streamingId,
    sending,
    loading,
    onStreamDone,
    onRegenerate,
    onEdit,
    onRetry,
    onAnswer,
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
    onAnswer: AnswerAction;
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
                                onAnswer={onAnswer}
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
    const { can } = usePermissions();
    const suggestions = SUGGESTIONS.filter(
        ({ permission }) => permission === null || can(permission),
    ).slice(0, MAX_SUGGESTIONS);

    return (
        <div className="flex flex-col items-center gap-3 px-4 py-10 text-center">
            <span className="flex size-12 items-center justify-center rounded-2xl bg-[#0F2044] text-[#0ABFBF] ring-1 ring-border">
                <Sparkles className="size-6" />
            </span>
            <div>
                <p className="text-sm font-semibold">How can I help?</p>
                <p className="mx-auto mt-1 max-w-[280px] text-xs text-muted-foreground">
                    I can answer questions about your workspace, run its
                    reports, and take care of HR work, from leave and hiring to
                    appraisals, training, awards, events and exits. Describe
                    what you need, or drop in a CV and I'll take it from there.
                </p>
            </div>
            <div className="mt-1 flex flex-col items-stretch gap-1.5 self-stretch">
                {suggestions.map(({ prompt }) => (
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
