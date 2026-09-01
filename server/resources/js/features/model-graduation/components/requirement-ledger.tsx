import { Check, ChevronRight } from 'lucide-react';
import { cn } from '@/lib/utils';
import {
    completion,
    formatProgress,
    GROUP_LABELS,
    GROUP_ORDER,
    GROUP_SUMMARIES,
    STATUS_BARS,
    STATUS_LABELS,
    STATUS_STYLES,
} from '../constants';
import type { Requirement } from '../types';

/**
 * Every graduation requirement, grouped by the kind of problem it guards
 * against. The groups are categories rather than steps, so they carry no
 * ordinal markers — nothing here happens in sequence.
 */
export function RequirementLedger({
    requirements,
    bindingKey,
    onOpen,
}: {
    requirements: Requirement[];
    bindingKey: string;
    onOpen: (requirement: Requirement) => void;
}) {
    return (
        <div className="flex flex-col gap-4">
            {GROUP_ORDER.map((group) => {
                const rows = requirements.filter((r) => r.group === group);

                if (rows.length === 0) {
                    return null;
                }

                const met = rows.filter((r) => r.status === 'met').length;

                return (
                    <section
                        key={group}
                        className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border"
                    >
                        <header className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border">
                            <div className="min-w-0">
                                <h3 className="text-sm font-medium">
                                    {GROUP_LABELS[group]}
                                </h3>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    {GROUP_SUMMARIES[group]}
                                </p>
                            </div>
                            <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                                {met} of {rows.length} met
                            </span>
                        </header>

                        <ul className="divide-y divide-border">
                            {rows.map((requirement) => (
                                <RequirementRow
                                    key={requirement.key}
                                    requirement={requirement}
                                    isBinding={requirement.key === bindingKey}
                                    onOpen={() => onOpen(requirement)}
                                />
                            ))}
                        </ul>
                    </section>
                );
            })}
        </div>
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
    const percent = completion(requirement.current, requirement.required);
    const isMet = requirement.status === 'met';

    return (
        <li className="relative">
            {/* Ties this row back to the "holding this back" panel above. */}
            {isBinding && (
                <span
                    className="absolute inset-y-0 left-0 w-0.5 bg-[#0ABFBF]"
                    aria-hidden="true"
                />
            )}

            <button
                type="button"
                onClick={onOpen}
                className="flex w-full items-start gap-3 px-4 py-3.5 text-left transition-colors hover:bg-muted/50"
            >
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                        <p className="text-sm font-medium">
                            {requirement.label}
                            {isBinding && (
                                <span className="ml-2 text-[11px] font-normal text-[#0ABFBF]">
                                    furthest away
                                </span>
                            )}
                        </p>
                        <p className="shrink-0 text-sm font-semibold tabular-nums">
                            {isMet ? (
                                <span className="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400">
                                    <Check
                                        className="size-3.5"
                                        strokeWidth={3}
                                    />
                                    {requirement.required === 1
                                        ? 'Configured'
                                        : formatProgress(
                                              requirement.current,
                                              requirement.required,
                                          )}
                                </span>
                            ) : (
                                formatProgress(
                                    requirement.current,
                                    requirement.required,
                                )
                            )}
                        </p>
                    </div>

                    <p className="mt-1 text-xs text-muted-foreground">
                        {requirement.summary}
                    </p>

                    <div className="mt-2.5 flex items-center gap-3">
                        <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                            <div
                                className={cn(
                                    'h-full rounded-full',
                                    STATUS_BARS[requirement.status],
                                )}
                                style={{
                                    width: `${Math.max(percent, isMet ? 100 : 1.5)}%`,
                                }}
                            />
                        </div>
                        <span
                            className={cn(
                                'shrink-0 rounded-full border px-2 py-0.5 text-[11px] font-medium',
                                STATUS_STYLES[requirement.status],
                            )}
                        >
                            {STATUS_LABELS[requirement.status]}
                        </span>
                    </div>
                </div>

                <ChevronRight className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
            </button>
        </li>
    );
}
