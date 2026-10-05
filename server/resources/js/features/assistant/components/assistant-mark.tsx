import { cn } from '@/lib/utils';

/**
 * The assistant's mark: a hollow node over a filled one — a two-step work trace
 * (`agent-activity.tsx`) in miniature. An open node is something read, a filled
 * node something done, so the button that opens the assistant already says what
 * it is for: it finds things out, and it does things.
 */
export function AssistantMark({ className }: { className?: string }) {
    return (
        <svg
            viewBox="0 0 16 16"
            fill="none"
            aria-hidden="true"
            className={cn('size-4 shrink-0', className)}
        >
            <circle
                cx="8"
                cy="3.75"
                r="2.25"
                stroke="currentColor"
                strokeWidth="1.5"
            />
            <path
                d="M8 6.75v2.5"
                stroke="currentColor"
                strokeWidth="1.5"
                strokeLinecap="round"
            />
            <circle
                cx="8"
                cy="12.25"
                r="2.75"
                className="fill-assistant-signal"
            />
        </svg>
    );
}
