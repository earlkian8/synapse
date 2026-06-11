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
