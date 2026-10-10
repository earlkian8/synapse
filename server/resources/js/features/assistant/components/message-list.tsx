import { ArrowDown } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Skeleton } from '@/components/ui/skeleton';
import { usePermissions } from '@/hooks/use-permissions';
import type { ChatMessage } from '../types';
import { TraceNode } from './agent-activity';
import type { AnswerAction } from './agent-activity';
import { AssistantMark } from './assistant-mark';
import { MessageItem } from './message-item';

/**
 * Starting points, each shown only to someone who could actually do it — a
 * suggestion that ends in "you don't have permission" is worse than none. They
 * are split by what they lead to: a question is answered from the record, a
 * task changes it (and may wait for the user's Confirm). The first few of each
 * someone may use are shown, so the order spreads them across modules.
 */
type Suggestion = { prompt: string; permission: string | null };

const QUESTIONS: Suggestion[] = [
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
    {
        prompt: 'What stages do our hiring pipelines have?',
        permission: 'recruitment.configure-pipelines',
    },
    {
        prompt: 'What is on our exit clearance checklist?',
        permission: 'offboarding.manage-programs',
    },
    {
        prompt: 'Who hasn’t verified their email yet?',
        permission: 'users.view',
    },
    { prompt: 'Which roles can approve leave?', permission: 'roles.view' },
    {
        prompt: 'What changed in the activity log this week?',
        permission: 'activity-logs.view',
    },
    {
        prompt: 'Who is at risk of leaving?',
        permission: 'analytics.attrition.view',
    },
    {
        prompt: 'Who is ready for promotion?',
        permission: 'analytics.promotion.view',
    },
    {
        prompt: 'Who is forecast below target next cycle?',
        permission: 'analytics.performance.view',
    },
];

const TASKS: Suggestion[] = [
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

/** How many of each the empty state offers at most. */
const MAX_QUESTIONS = 4;
const MAX_TASKS = 2;

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

    // A conversation opened from history starts at its latest turn.
    useEffect(() => {
        const scroller = scrollRef.current;

        if (!loading && scroller) {
            scroller.scrollTop = scroller.scrollHeight;
        }
    }, [loading]);

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
                className="h-full overflow-y-auto overscroll-contain"
            >
                <div
                    ref={contentRef}
                    className="flex min-h-full flex-col gap-6 px-4 pt-5 pb-4"
                >
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
                    className="absolute bottom-2 left-1/2 flex h-7 -translate-x-1/2 animate-in items-center gap-1 rounded-full border border-border bg-background pr-3 pl-2 text-xs font-medium text-foreground shadow-sm transition-colors fade-in hover:bg-muted motion-reduce:animate-none"
                >
                    <ArrowDown className="size-3.5" />
                    Latest
                </button>
            )}
        </div>
    );
}

/**
 * A new conversation: what the assistant is for, in two sentences, and a few
 * starting points the person could actually use. They sit low in the panel,
 * beside the composer they fill — picking one types it, it does not send it.
 */
function EmptyState({ onPick }: { onPick: (prompt: string) => void }) {
    const { can } = usePermissions();
    const allowed = ({ permission }: Suggestion) =>
        permission === null || can(permission);

    const questions = QUESTIONS.filter(allowed).slice(0, MAX_QUESTIONS);
    const tasks = TASKS.filter(allowed).slice(0, MAX_TASKS);

    return (
        <div className="mt-auto flex flex-col gap-6">
            <div className="flex flex-col gap-3">
                <AssistantMark className="size-6 text-assistant-ink dark:text-foreground" />
                <div className="flex flex-col gap-1.5">
                    <h2 className="text-lg leading-snug font-semibold tracking-tight text-balance">
                        Ask about your workspace, or hand over the work.
                    </h2>
                    <p className="max-w-[46ch] text-sm leading-6 text-muted-foreground">
                        Answers come from your live records, limited to what
                        your role can see. Changes that notify people, are hard
                        to undo or touch many records wait for you to confirm.
                        You can also attach a CV or a document.
                    </p>
                </div>
            </div>

            <SuggestionGroup
                title="Ask"
                node="read"
                items={questions}
                onPick={onPick}
            />
            <SuggestionGroup
                title="Do"
                node="change"
                items={tasks}
                onPick={onPick}
            />
        </div>
    );
}

function SuggestionGroup({
    title,
    node,
    items,
    onPick,
}: {
    title: string;
    node: 'read' | 'change';
    items: Suggestion[];
    onPick: (prompt: string) => void;
}) {
    if (items.length === 0) {
        return null;
    }

    return (
        <section aria-label={title} className="flex flex-col gap-1">
            <h3 className="text-xs font-medium text-muted-foreground">
                {title}
            </h3>
            <ul className="-mx-2 flex flex-col">
                {items.map(({ prompt }) => (
                    <li key={prompt}>
                        <button
                            type="button"
                            onClick={() => onPick(prompt)}
                            className="flex w-full items-center gap-3 rounded-md px-2 py-1.5 text-left text-sm text-foreground/90 transition-colors outline-none hover:bg-muted hover:text-foreground focus-visible:bg-muted focus-visible:ring-2 focus-visible:ring-ring/50"
                        >
                            <TraceNode kind={node} />
                            {prompt}
                        </button>
                    </li>
                ))}
            </ul>
        </section>
    );
}

function LoadingSkeleton() {
    return (
        <div aria-hidden="true" className="flex flex-col gap-6">
            <Skeleton className="ml-auto h-9 w-44 rounded-2xl rounded-tr-md" />
            <div className="flex flex-col gap-2">
                <Skeleton className="h-3.5 w-40" />
                <Skeleton className="h-3.5 w-full" />
                <Skeleton className="h-3.5 w-4/5" />
            </div>
            <Skeleton className="ml-auto h-9 w-32 rounded-2xl rounded-tr-md" />
            <div className="flex flex-col gap-2">
                <Skeleton className="h-3.5 w-full" />
                <Skeleton className="h-3.5 w-3/5" />
            </div>
        </div>
    );
}
