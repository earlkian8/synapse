import { Check, ChevronsUpDown, Globe, Search } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { cn } from '@/lib/utils';

/** One zone as the server offers it (`OrganizationClock::options()`). */
export type TimezoneOption = {
    value: string;
    label: string;
    offset: string;
};

type Props = {
    id?: string;
    value: string;
    options: TimezoneOption[];
    onChange: (value: string) => void;
    disabled?: boolean;
    invalid?: boolean;
};

/**
 * The browser's own zone when the server offers it, or null. Browsers can report
 * a legacy alias ("Asia/Calcutta") the canonical list leaves out; those are not
 * guessed at.
 */
export function browserTimeZone(options: TimezoneOption[]): string | null {
    try {
        const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;

        return options.some((option) => option.value === zone) ? zone : null;
    } catch {
        return null;
    }
}

/**
 * A searchable time-zone picker. Four hundred zones do not fit a plain select,
 * and nobody scrolls to "Asia/Manila" — typing "manila", "singapore" or "+08"
 * finds it, with the offset beside every name so zones on the same clock read
 * as the same clock.
 *
 * Deliberately small: a trigger, a filter box and a list. Arrow keys move
 * through the matches, Enter picks one, and Escape or a click outside closes it.
 */
export function TimezoneSelect({
    id,
    value,
    options,
    onChange,
    disabled = false,
    invalid = false,
}: Props) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);
    const root = useRef<HTMLDivElement>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    const list = useRef<HTMLUListElement>(null);
    const listId = useId();

    const selected = options.find((option) => option.value === value) ?? null;
    const needle = query.trim().toLowerCase();
    const matches =
        needle === ''
            ? options
            : options.filter((option) =>
                  `${option.label} ${option.value} ${option.offset}`
                      .toLowerCase()
                      .includes(needle),
              );

    // Close on a press anywhere outside the picker.
    useEffect(() => {
        if (!open) {
            return;
        }

        const onPointerDown = (event: PointerEvent) => {
            if (!root.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };

        document.addEventListener('pointerdown', onPointerDown);

        return () => document.removeEventListener('pointerdown', onPointerDown);
    }, [open]);

    // Keep the highlighted zone in view as the arrow keys move it.
    useEffect(() => {
        if (open) {
            list.current
                ?.querySelector('[data-active="true"]')
                ?.scrollIntoView({ block: 'nearest' });
        }
    }, [open, active]);

    const openList = () => {
        setQuery('');
        setActive(
            Math.max(
                options.findIndex((option) => option.value === value),
                0,
            ),
        );
        setOpen(true);
    };

    const choose = (zone: string) => {
        onChange(zone);
        setOpen(false);
        trigger.current?.focus();
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActive((index) => Math.min(index + 1, matches.length - 1));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive((index) => Math.max(index - 1, 0));
        } else if (event.key === 'Enter') {
            event.preventDefault();

            if (matches[active]) {
                choose(matches[active].value);
            }
        } else if (event.key === 'Escape') {
            event.preventDefault();
            setOpen(false);
            trigger.current?.focus();
        }
    };

    return (
        <div ref={root} className="relative">
            <button
                id={id}
                ref={trigger}
                type="button"
                disabled={disabled}
                aria-haspopup="listbox"
                aria-expanded={open}
                aria-invalid={invalid || undefined}
                onClick={() => (open ? setOpen(false) : openList())}
                className={cn(
                    'flex h-9 w-full items-center gap-2 rounded-md border border-input bg-transparent px-3 text-left text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 disabled:cursor-not-allowed disabled:opacity-60',
                    invalid && 'border-destructive',
                )}
            >
                <Globe className="size-4 shrink-0 text-muted-foreground" />
                <span
                    className={cn(
                        'min-w-0 flex-1 truncate',
                        !selected && 'text-muted-foreground',
                    )}
                >
                    {selected?.label ?? (value || 'Choose a time zone')}
                </span>
                {selected && (
                    <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                        {selected.offset}
                    </span>
                )}
                <ChevronsUpDown className="size-4 shrink-0 text-muted-foreground" />
            </button>

            {open && (
                <div className="absolute z-50 mt-1 w-full overflow-hidden rounded-md border border-border bg-popover text-popover-foreground shadow-md">
                    <div className="flex items-center gap-2 border-b border-border px-3">
                        <Search className="size-4 shrink-0 text-muted-foreground" />
                        <input
                            autoFocus
                            role="combobox"
                            aria-controls={listId}
                            aria-expanded
                            aria-activedescendant={
                                matches[active]
                                    ? `${listId}-${active}`
                                    : undefined
                            }
                            value={query}
                            onChange={(event) => {
                                setQuery(event.target.value);
                                setActive(0);
                            }}
                            onKeyDown={onKeyDown}
                            placeholder="Search a city, region or offset"
                            className="h-9 w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                        />
                    </div>

                    <ul
                        ref={list}
                        id={listId}
                        role="listbox"
                        className="max-h-64 overflow-y-auto py-1"
                    >
                        {matches.length === 0 ? (
                            <li className="px-3 py-6 text-center text-sm text-muted-foreground">
                                No time zone matches “{query.trim()}”.
                            </li>
                        ) : (
                            matches.map((option, index) => (
                                <li
                                    key={option.value}
                                    id={`${listId}-${index}`}
                                    role="option"
                                    aria-selected={option.value === value}
                                    data-active={index === active}
                                    onPointerMove={() => setActive(index)}
                                    onClick={() => choose(option.value)}
                                    className={cn(
                                        'flex cursor-pointer items-center gap-2 px-3 py-1.5 text-sm',
                                        index === active &&
                                            'bg-accent text-accent-foreground',
                                    )}
                                >
                                    <Check
                                        className={cn(
                                            'size-3.5 shrink-0',
                                            option.value === value
                                                ? 'opacity-100'
                                                : 'opacity-0',
                                        )}
                                    />
                                    <span className="min-w-0 flex-1 truncate">
                                        {option.label}
                                    </span>
                                    <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                                        {option.offset}
                                    </span>
                                </li>
                            ))
                        )}
                    </ul>
                </div>
            )}
        </div>
    );
}
