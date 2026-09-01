import { cn } from '@/lib/utils';
import { POSTING_STATUS_LABELS, POSTING_STATUS_STYLES } from '../constants';
import type { PostingStatus } from '../types';

export function PostingStatusBadge({ status }: { status: PostingStatus }) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium',
                POSTING_STATUS_STYLES[status] ?? POSTING_STATUS_STYLES.draft,
            )}
        >
            {POSTING_STATUS_LABELS[status] ?? status}
        </span>
    );
}

/**
 * The posting's own real lifecycle, drawn as a sequence: unlike a pipeline's
 * arbitrary stages, draft → open → closed/filled always means the same three
 * steps, so a stepper earns its keep here.
 */
export function PostingStatusProgress({ status }: { status: PostingStatus }) {
    const terminal = status === 'closed' || status === 'filled';
    const steps: { key: PostingStatus; label: string; reached: boolean }[] = [
        { key: 'draft', label: 'Draft', reached: true },
        {
            key: 'open',
            label: 'Open',
            reached: status === 'open' || terminal,
        },
        {
            key: status === 'filled' ? 'filled' : 'closed',
            label: status === 'filled' ? 'Filled' : 'Closed',
            reached: terminal,
        },
    ];

    return (
        <div
            className="flex items-center gap-1"
            aria-label={`Status: ${POSTING_STATUS_LABELS[status]}`}
        >
            {steps.map((step, index) => (
                <span key={step.key} className="flex items-center gap-1">
                    {index > 0 && (
                        <span
                            className={cn(
                                'h-px w-3 shrink-0',
                                step.reached
                                    ? 'bg-current opacity-40'
                                    : 'bg-border',
                            )}
                        />
                    )}
                    <span
                        className={cn(
                            'inline-flex items-center gap-1 text-[11px] font-medium',
                            step.reached
                                ? (
                                      POSTING_STATUS_STYLES[step.key] ??
                                      POSTING_STATUS_STYLES.draft
                                  )
                                      .split(' ')
                                      .filter(
                                          (cls) =>
                                              cls.startsWith('text-') ||
                                              cls.startsWith('dark:text-'),
                                      )
                                      .join(' ')
                                : 'text-muted-foreground/50',
                        )}
                    >
                        <span
                            className={cn(
                                'size-1.5 rounded-full',
                                step.reached ? 'bg-current' : 'bg-border',
                            )}
                        />
                        {step.label}
                    </span>
                </span>
            ))}
        </div>
    );
}
