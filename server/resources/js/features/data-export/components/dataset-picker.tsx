import { Paperclip } from 'lucide-react';
import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';
import { SECTION_ORDER, formatCount } from '../constants';
import type { Dataset } from '../types';

type Props = {
    datasets: Dataset[];
    selected: Set<string>;
    disabled?: boolean;
    onToggle: (key: string, checked: boolean) => void;
    onToggleMany: (keys: string[], checked: boolean) => void;
};

/**
 * The kinds of record the viewer may export, grouped by the sidebar section
 * their screens sit in, each with how many records it holds today.
 */
export function DatasetPicker({
    datasets,
    selected,
    disabled = false,
    onToggle,
    onToggleMany,
}: Props) {
    const sections = SECTION_ORDER.map((section) => ({
        section,
        items: datasets.filter((dataset) => dataset.section === section),
    })).filter((group) => group.items.length > 0);

    return (
        <div className="flex flex-col gap-5">
            {sections.map(({ section, items }) => {
                const keys = items.map((item) => item.key);
                const allOn = keys.every((key) => selected.has(key));

                return (
                    <section key={section} aria-label={section}>
                        <div className="mb-2 flex items-baseline justify-between gap-3 px-1">
                            <h3 className="text-sm font-semibold">{section}</h3>
                            <button
                                type="button"
                                disabled={disabled}
                                onClick={() => onToggleMany(keys, !allOn)}
                                className="rounded text-xs font-medium text-[#078f8f] hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 dark:text-[#0ABFBF]"
                            >
                                {allOn ? 'Clear section' : 'Select section'}
                            </button>
                        </div>

                        <ul className="divide-y divide-border overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
                            {items.map((dataset) => {
                                const checked = selected.has(dataset.key);
                                const id = `dataset-${dataset.key}`;

                                return (
                                    <li key={dataset.key}>
                                        <label
                                            htmlFor={id}
                                            className={cn(
                                                'flex cursor-pointer items-start gap-3 px-4 py-3 transition-colors hover:bg-muted/50',
                                                checked &&
                                                    'bg-[#0ABFBF]/[0.04]',
                                                disabled &&
                                                    'cursor-not-allowed opacity-60',
                                            )}
                                        >
                                            <Checkbox
                                                id={id}
                                                checked={checked}
                                                disabled={disabled}
                                                onCheckedChange={(value) =>
                                                    onToggle(
                                                        dataset.key,
                                                        value === true,
                                                    )
                                                }
                                                className="mt-0.5"
                                            />
                                            <span className="min-w-0 flex-1">
                                                <span className="flex items-center gap-1.5 text-sm font-medium">
                                                    {dataset.label}
                                                    {dataset.has_files && (
                                                        <Paperclip
                                                            className="size-3.5 text-muted-foreground"
                                                            aria-label="Has uploaded files"
                                                        />
                                                    )}
                                                </span>
                                                <span className="mt-0.5 block text-xs leading-relaxed text-muted-foreground">
                                                    {dataset.description}
                                                </span>
                                            </span>
                                            <span className="shrink-0 pt-0.5 text-right text-xs text-muted-foreground tabular-nums">
                                                {formatCount(dataset.records)}{' '}
                                                {dataset.records === 1
                                                    ? 'record'
                                                    : 'records'}
                                            </span>
                                        </label>
                                    </li>
                                );
                            })}
                        </ul>
                    </section>
                );
            })}
        </div>
    );
}
