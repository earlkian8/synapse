import { Search, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

type Props = {
    /** The search as the server last applied it. */
    value: string;
    onSearch: (value: string) => void;
    placeholder: string;
    label: string;
    className?: string;
};

/**
 * A search box that applies itself 350 ms after typing stops, and follows the
 * server's value when the filters are reset — the same behaviour as the
 * Employees toolbar.
 */
export function SearchInput({
    value,
    onSearch,
    placeholder,
    label,
    className,
}: Props) {
    const [term, setTerm] = useState(value);
    const [synced, setSynced] = useState(value);

    if (value !== synced) {
        setSynced(value);
        setTerm(value);
    }

    useEffect(() => {
        const handle = window.setTimeout(() => {
            if (term !== value) {
                onSearch(term);
            }
        }, 350);

        return () => window.clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [term]);

    return (
        <div className={cn('relative w-full sm:w-64', className)}>
            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input
                value={term}
                onChange={(event) => setTerm(event.target.value)}
                placeholder={placeholder}
                className="h-9 pl-9"
                aria-label={label}
            />
            {term && (
                <button
                    type="button"
                    onClick={() => setTerm('')}
                    className="absolute top-1/2 right-2 -translate-y-1/2 rounded-sm p-0.5 text-muted-foreground hover:text-foreground"
                    aria-label="Clear search"
                >
                    <X className="size-4" />
                </button>
            )}
        </div>
    );
}
