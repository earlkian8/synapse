import { Search, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

type Props = {
    value: string;
    onSearch: (value: string) => void;
    placeholder: string;
    label: string;
    /**
     * Milliseconds to wait after typing stops — for a search the server runs.
     * 0 (a search over rows already on the page) applies each keystroke.
     */
    delay?: number;
    className?: string;
};

/**
 * The search box every list starts its toolbar with. A server-run search waits
 * until typing stops and follows the server's value when filters are reset.
 */
export function SearchInput({
    value,
    onSearch,
    placeholder,
    label,
    delay = 350,
    className,
}: Props) {
    const [term, setTerm] = useState(value);
    const [synced, setSynced] = useState(value);

    if (value !== synced) {
        setSynced(value);
        setTerm(value);
    }

    useEffect(() => {
        if (delay === 0) {
            return;
        }

        const handle = window.setTimeout(() => {
            if (term !== value) {
                onSearch(term);
            }
        }, delay);

        return () => window.clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [term]);

    const change = (next: string) => {
        setTerm(next);

        if (delay === 0) {
            onSearch(next);
        }
    };

    return (
        <div className={cn('relative w-full sm:w-64', className)}>
            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input
                value={term}
                onChange={(event) => change(event.target.value)}
                placeholder={placeholder}
                className="h-9 pl-9"
                aria-label={label}
            />
            {term && (
                <button
                    type="button"
                    onClick={() => change('')}
                    className="absolute top-1/2 right-2 -translate-y-1/2 rounded-sm p-0.5 text-muted-foreground hover:text-foreground"
                    aria-label="Clear search"
                >
                    <X className="size-4" />
                </button>
            )}
        </div>
    );
}
