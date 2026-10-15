import { Check, Plus, X } from 'lucide-react';
import { useId, useState } from 'react';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

type Props = {
    /** The department's name, for labels read aloud. */
    department: string;
    /** The titles this department usually has — offered as toggles. */
    suggested: string[];
    /** The titles that will be created, suggested or typed. */
    value: string[];
    onChange: (titles: string[]) => void;
    className?: string;
};

const same = (a: string, b: string) =>
    a.trim().toLowerCase() === b.trim().toLowerCase();

/**
 * The positions a department brings with it on the departments step: its
 * usual titles as toggles, and a field for the ones the company uses instead.
 * Whatever is left selected is created under the department when it is added.
 *
 * Salary grades and descriptions are not asked for here — they are set on the
 * department board below, once the positions exist.
 */
export default function PositionPicker({
    department,
    suggested,
    value,
    onChange,
    className,
}: Props) {
    const inputId = useId();
    const [draft, setDraft] = useState('');

    const typed = value.filter(
        (title) => !suggested.some((offer) => same(offer, title)),
    );

    const toggle = (title: string) =>
        onChange(
            value.some((picked) => same(picked, title))
                ? value.filter((picked) => !same(picked, title))
                : [...value, title],
        );

    const add = () => {
        const title = draft.trim();

        if (title === '') {
            return;
        }

        // Typing a title already offered picks that one rather than a twin.
        const offered = suggested.find((offer) => same(offer, title));

        if (!value.some((picked) => same(picked, title))) {
            onChange([...value, offered ?? title]);
        }

        setDraft('');
    };

    return (
        <div className={cn('flex flex-col gap-2.5', className)}>
            <p className="text-xs font-medium text-foreground">
                Positions{' '}
                <span className="font-normal text-muted-foreground">
                    {value.length === 0
                        ? '· none yet'
                        : `· ${value.length} to add`}
                </span>
            </p>

            {(suggested.length > 0 || typed.length > 0) && (
                <ul className="flex flex-wrap gap-1.5">
                    {suggested.map((title) => {
                        const picked = value.some((p) => same(p, title));

                        return (
                            <li key={title}>
                                <button
                                    type="button"
                                    aria-pressed={picked}
                                    onClick={() => toggle(title)}
                                    className={cn(
                                        'inline-flex h-7 items-center gap-1 rounded-full border px-2.5 text-xs transition-colors focus-visible:ring-2 focus-visible:ring-[#0ABFBF]/40 focus-visible:outline-none',
                                        picked
                                            ? 'border-[#0ABFBF]/60 bg-[#0ABFBF]/10 text-foreground'
                                            : 'border-dashed border-sidebar-border text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {picked ? (
                                        <Check
                                            className="size-3 text-[#0ABFBF]"
                                            strokeWidth={3}
                                        />
                                    ) : (
                                        <Plus className="size-3" />
                                    )}
                                    {title}
                                </button>
                            </li>
                        );
                    })}

                    {typed.map((title) => (
                        <li key={title}>
                            <span className="inline-flex h-7 items-center gap-1 rounded-full border border-[#0ABFBF]/60 bg-[#0ABFBF]/10 pr-1 pl-2.5 text-xs text-foreground">
                                {title}
                                <button
                                    type="button"
                                    onClick={() => toggle(title)}
                                    aria-label={`Remove ${title}`}
                                    className="rounded-full p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                                >
                                    <X className="size-3" />
                                </button>
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            <div className="flex gap-2">
                <label htmlFor={inputId} className="sr-only">
                    Add a position to {department}
                </label>
                <Input
                    id={inputId}
                    value={draft}
                    onChange={(event) => setDraft(event.target.value)}
                    onKeyDown={(event) => {
                        // Enter adds the title; it never submits the step.
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            add();
                        }
                    }}
                    placeholder="Add a position, e.g. Team Lead"
                    maxLength={255}
                    className="h-8 bg-background text-xs"
                />
                <button
                    type="button"
                    onClick={add}
                    disabled={draft.trim() === ''}
                    className="inline-flex h-8 shrink-0 items-center gap-1 rounded-md border border-input bg-background px-2.5 text-xs font-medium transition-colors hover:bg-accent disabled:opacity-50"
                >
                    <Plus className="size-3.5" />
                    Add
                </button>
            </div>
        </div>
    );
}
