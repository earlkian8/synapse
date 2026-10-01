import { cn } from '@/lib/utils';
import { STAGE_KIND_STYLES } from '../constants';
import type { StageKind } from '../types';

export function StageBadge({ name, kind }: { name: string; kind: StageKind }) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium',
                STAGE_KIND_STYLES[kind],
            )}
        >
            {name}
        </span>
    );
}
