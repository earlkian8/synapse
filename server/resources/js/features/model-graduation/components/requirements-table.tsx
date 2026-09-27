import {
    ChevronRight,
    CircleCheck,
    CircleDashed,
    CircleDot,
} from 'lucide-react';
import { Fragment } from 'react';
import { DataTable, rowOpens, TableCard } from '@/components/data-table';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import {
    completion,
    formatProgress,
    formatShortfall,
    GROUP_COPY,
    GROUP_ORDER,
    STATUS_BARS,
    STATUS_LABELS,
    STATUS_STYLES,
    STATUS_TRACKS,
} from '../constants';
import type { Requirement, RequirementStatus } from '../types';

/**
 * Every requirement as one table: a header row per group (groups are
 * categories, not steps — nothing here happens in sequence), then a row per
 * requirement with how far along it is and what is still missing. A row opens
 * the requirement in full: what to do, and why the threshold is that number.
 */
export function RequirementsTable({
    requirements,
    bindingKey,
    onOpen,
}: {
    requirements: Requirement[];
    bindingKey: string | null;
    onOpen: (requirement: Requirement) => void;
}) {
    return (
        <TableCard>
            <DataTable>
                <TableHeader>
                    <TableRow>
                        <TableHead>Requirement</TableHead>
                        <TableHead className="w-52">Progress</TableHead>
                        <TableHead className="w-48">Still needed</TableHead>
                        <TableHead className="w-8" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {GROUP_ORDER.map((group) => {
                        const rows = requirements.filter(
                            (requirement) => requirement.group === group,
                        );

                        if (rows.length === 0) {
                            return null;
                        }

                        const met = rows.filter(
                            (requirement) => requirement.status === 'met',
                        ).length;

                        return (
                            <Fragment key={group}>
                                <TableRow className="bg-muted/30 hover:bg-muted/30">
                                    <TableCell
                                        colSpan={4}
                                        className="py-1.5 whitespace-normal"
                                    >
                                        <span className="text-xs font-semibold">
                                            {GROUP_COPY[group].label}
                                        </span>
                                        <span className="ml-2 text-xs text-muted-foreground tabular-nums">
                                            {met} of {rows.length} done
                                        </span>
                                        <span className="block text-[11px] text-muted-foreground">
                                            {GROUP_COPY[group].hint}
                                        </span>
                                    </TableCell>
                                </TableRow>
                                {rows.map((requirement) => (
                                    <RequirementRow
                                        key={requirement.key}
                                        requirement={requirement}
                                        isBinding={
                                            requirement.key === bindingKey
                                        }
                                        onOpen={() => onOpen(requirement)}
                                    />
                                ))}
                            </Fragment>
                        );
                    })}
                </TableBody>
            </DataTable>
        </TableCard>
    );
}

function RequirementRow({
    requirement,
    isBinding,
    onOpen,
}: {
    requirement: Requirement;
    isBinding: boolean;
    onOpen: () => void;
}) {
    const met = requirement.status === 'met';
    const percent = completion(requirement.current, requirement.required);
    const shortfall = formatShortfall(requirement);
    const opens = rowOpens(onOpen);

    return (
        <TableRow
            {...opens}
            aria-label={`${requirement.label}: open the details`}
            className={cn(opens.className, isBinding && 'bg-[#0ABFBF]/5')}
        >
            <TableCell className="whitespace-normal">
                <div className="flex items-start gap-2.5">
                    <StatusIcon status={requirement.status} />
                    <div className="min-w-0">
                        <p className="text-sm font-medium">
                            {requirement.label}
                            {isBinding && (
                                <span className="ml-2 rounded-full bg-[#0ABFBF]/15 px-2 py-0.5 text-[11px] font-medium whitespace-nowrap text-teal-700 dark:text-[#0ABFBF]">
                                    Furthest to go
                                </span>
                            )}
                        </p>
                        <p className="mt-0.5 text-xs leading-relaxed text-muted-foreground">
                            {requirement.summary}
                        </p>
                    </div>
                </div>
            </TableCell>
            <TableCell>
                <p className="text-sm font-medium tabular-nums">
                    {formatProgress(requirement)}
                </p>
                {requirement.format !== 'check' && (
                    <div
                        className={cn(
                            'mt-1.5 h-1.5 overflow-hidden rounded-full',
                            STATUS_TRACKS[requirement.status],
                        )}
                        role="meter"
                        aria-label={requirement.label}
                        aria-valuemin={0}
                        aria-valuemax={100}
                        aria-valuenow={percent}
                    >
                        <div
                            className={cn(
                                'h-full rounded-full',
                                STATUS_BARS[requirement.status],
                            )}
                            style={{
                                width: `${met ? 100 : Math.max(percent, 2)}%`,
                            }}
                        />
                    </div>
                )}
            </TableCell>
            <TableCell className="whitespace-normal">
                {shortfall ? (
                    <span className="text-sm">{shortfall}</span>
                ) : (
                    <span
                        className={cn(
                            'inline-flex items-center gap-1 text-sm font-medium',
                            STATUS_STYLES.met,
                        )}
                    >
                        <CircleCheck className="size-3.5" />
                        {STATUS_LABELS.met}
                    </span>
                )}
            </TableCell>
            <TableCell>
                <ChevronRight className="size-4 text-muted-foreground" />
            </TableCell>
        </TableRow>
    );
}

export function StatusIcon({ status }: { status: RequirementStatus }) {
    const Icon =
        status === 'met'
            ? CircleCheck
            : status === 'progressing'
              ? CircleDot
              : CircleDashed;

    return (
        <span className={cn('mt-0.5 shrink-0', STATUS_STYLES[status])}>
            <Icon className="size-4" aria-hidden="true" />
            <span className="sr-only">{STATUS_LABELS[status]}</span>
        </span>
    );
}
