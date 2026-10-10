import { Check, ChevronsUpDown, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import type { Person } from '../types';

type Props = {
    colleagues: Person[];
    value: number | null;
    onChange: (id: number) => void;
    placeholder?: string;
    invalid?: boolean;
};

/**
 * Pick a colleague by typing any part of their name, position or department.
 * Every word typed has to match.
 */
export function ColleaguePicker({
    colleagues,
    value,
    onChange,
    placeholder = 'Choose a colleague',
    invalid,
}: Props) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const chosen = colleagues.find((c) => c.id === value) ?? null;

    const matches = useMemo(() => {
        const words = query.toLowerCase().split(/\s+/).filter(Boolean);

        return colleagues
            .filter((c) => {
                const haystack = [c.name, c.position, c.department]
                    .filter(Boolean)
                    .join(' ')
                    .toLowerCase();

                return words.every((word) => haystack.includes(word));
            })
            .slice(0, 50);
    }, [colleagues, query]);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    aria-invalid={invalid}
                    className={cn(
                        'h-10 w-full justify-between px-3 font-normal',
                        !chosen && 'text-muted-foreground',
                    )}
                >
                    {chosen ? (
                        <span className="flex min-w-0 items-center gap-2">
                            <PersonAvatar
                                name={chosen.name}
                                initials={chosen.initials}
                                photo={chosen.photo}
                                className="size-6 rounded-md"
                                fallbackClassName="text-[9px]"
                            />
                            <span className="truncate text-foreground">
                                {chosen.name}
                            </span>
                        </span>
                    ) : (
                        placeholder
                    )}
                    <ChevronsUpDown className="size-4 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                className="w-(--radix-popover-trigger-width) min-w-72 p-0"
                align="start"
            >
                <div className="relative border-b border-border">
                    <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        autoFocus
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Search by name or team"
                        aria-label="Search colleagues"
                        className="h-10 rounded-none border-0 pl-9 shadow-none focus-visible:ring-0"
                    />
                </div>
                <ul
                    role="listbox"
                    aria-label="Colleagues"
                    className="max-h-64 overflow-y-auto p-1"
                >
                    {matches.length === 0 && (
                        <li className="px-3 py-6 text-center text-sm text-muted-foreground">
                            No one by that name.
                        </li>
                    )}
                    {matches.map((colleague) => (
                        <li
                            key={colleague.id}
                            role="option"
                            aria-selected={colleague.id === value}
                        >
                            <button
                                type="button"
                                onClick={() => {
                                    onChange(colleague.id);
                                    setOpen(false);
                                    setQuery('');
                                }}
                                className="flex w-full items-center gap-2.5 rounded-md px-2 py-1.5 text-left text-sm hover:bg-muted focus-visible:bg-muted focus-visible:outline-none"
                            >
                                <PersonAvatar
                                    name={colleague.name}
                                    initials={colleague.initials}
                                    photo={colleague.photo}
                                    className="size-7 rounded-md"
                                    fallbackClassName="text-[10px]"
                                />
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate font-medium">
                                        {colleague.name}
                                    </span>
                                    {colleague.position && (
                                        <span className="block truncate text-xs text-muted-foreground">
                                            {colleague.position}
                                        </span>
                                    )}
                                </span>
                                {colleague.id === value && (
                                    <Check className="size-4 text-[#08767c] dark:text-[#0ABFBF]" />
                                )}
                            </button>
                        </li>
                    ))}
                </ul>
            </PopoverContent>
        </Popover>
    );
}
