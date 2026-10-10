import { router, usePage } from '@inertiajs/react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import {
    BookOpen,
    BriefcaseBusiness,
    CalendarClock,
    CalendarDays,
    Gauge,
    GraduationCap,
    LayoutGrid,
    Network,
    Search,
    ShieldCheck,
    UserCog,
    UserRoundCheck,
    UserRoundMinus,
    UserRoundSearch,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useId, useMemo, useState } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import { Spinner } from '@/components/ui/spinner';
import { AssistantMark } from '@/features/assistant/components/assistant-mark';
import { assistantLauncher } from '@/features/assistant/launcher';
import { useAppNavigation } from '@/hooks/use-app-navigation';
import { cn, toUrl } from '@/lib/utils';
import { MAX_QUERY_LENGTH, MIN_QUERY_LENGTH } from '../api';
import type { SearchGroupKey } from '../types';
import { useGlobalSearch } from '../use-global-search';

/** Each kind wears its sidebar icon, so a result looks like where it opens. */
const KIND_ICONS: Record<SearchGroupKey, LucideIcon> = {
    screens: LayoutGrid,
    employees: Users,
    applicants: UserRoundSearch,
    'job-postings': BriefcaseBusiness,
    onboarding: UserRoundCheck,
    offboarding: UserRoundMinus,
    leave: CalendarDays,
    appraisals: Gauge,
    training: GraduationCap,
    events: CalendarClock,
    departments: Network,
    users: UserCog,
    roles: ShieldCheck,
    help: BookOpen,
};

/** One selectable row, whichever list it came from. */
type Entry = {
    id: string;
    title: string;
    subtitle: string | null;
    hint: string | null;
    icon: LucideIcon | 'assistant';
    run: () => void;
};

type Section = { key: string; label: string; entries: Entry[] };

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/**
 * The ⌘K palette (ADR 0069). With nothing typed it lists the screens the
 * person can open; from two characters it searches everything they may open,
 * grouped by kind. ↑ ↓ move, ↵ opens, Esc closes.
 *
 * The body mounts only while the dialog is open, so every opening starts from
 * an empty search.
 */
export function SearchPalette({ open, onOpenChange }: Props) {
    return (
        <DialogPrimitive.Root open={open} onOpenChange={onOpenChange}>
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-black/40 backdrop-blur-[2px] data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0" />
                <DialogPrimitive.Content
                    aria-describedby={undefined}
                    className="fixed top-4 left-1/2 z-50 flex max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-[640px] -translate-x-1/2 flex-col overflow-hidden rounded-xl border bg-background shadow-2xl duration-150 outline-none data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=closed]:zoom-out-[0.98] data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-[0.98] sm:top-[12vh] sm:max-h-[min(560px,76vh)]"
                >
                    <DialogPrimitive.Title className="sr-only">
                        Search
                    </DialogPrimitive.Title>
                    <PaletteBody close={() => onOpenChange(false)} />
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}

function PaletteBody({ close }: { close: () => void }) {
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);
    const { term, groups, status, error, whenAnswered } =
        useGlobalSearch(query);
    const navigation = useAppNavigation();
    const assistantOffered = usePage().props.auth.assistant;
    const listId = useId();
    const optionId = (index: number) => `${listId}-option-${index}`;

    const visit = (href: string) => {
        close();
        router.visit(href);
    };

    // The sidebar's screens, by address — both the list shown before anything
    // is typed and the icon a screen result wears.
    const screens = useMemo(
        () =>
            navigation.flatMap((group) =>
                group.items.map((item) => ({
                    href: toUrl(item.href),
                    title: item.title,
                    menu: group.label,
                    icon: item.icon ?? LayoutGrid,
                })),
            ),
        [navigation],
    );

    const sections: Section[] = [];

    if (term.length < MIN_QUERY_LENGTH) {
        const typed = term.toLowerCase();
        const matching = screens.filter((screen) =>
            screen.title.toLowerCase().includes(typed),
        );

        if (matching.length > 0) {
            sections.push({
                key: 'go-to',
                label: 'Go to',
                entries: matching.map((screen) => ({
                    id: `go-to:${screen.href}`,
                    title: screen.title,
                    subtitle: screen.menu,
                    hint: null,
                    icon: screen.icon,
                    run: () => visit(screen.href),
                })),
            });
        }
    } else {
        for (const group of groups) {
            sections.push({
                key: group.key,
                label: group.label,
                entries: group.items.map((item) => ({
                    id: item.id,
                    title: item.title,
                    subtitle: item.subtitle,
                    hint: item.hint,
                    icon:
                        group.key === 'screens'
                            ? (screens.find((s) => s.href === item.href)
                                  ?.icon ?? KIND_ICONS.screens)
                            : KIND_ICONS[group.key],
                    run: () => visit(item.href),
                })),
            });
        }

        if (assistantOffered) {
            sections.push({
                key: 'assistant',
                label: 'Assistant',
                entries: [
                    {
                        id: 'assistant',
                        title: `Ask the assistant: “${term}”`,
                        subtitle: 'It can look further, or do it for you',
                        hint: null,
                        icon: 'assistant',
                        run: () => {
                            close();
                            assistantLauncher.open(term);
                        },
                    },
                ],
            });
        }
    }

    const entries = sections.flatMap((section) => section.entries);
    const current = Math.min(active, entries.length - 1);
    const words = term.length >= MIN_QUERY_LENGTH ? term.split(' ') : [];

    // Each section's first row, counted across the whole list.
    const offsets = sections.map((_, i) =>
        sections
            .slice(0, i)
            .reduce((count, section) => count + section.entries.length, 0),
    );

    // Keep the highlighted row in view as the arrow keys move it.
    useEffect(() => {
        if (current >= 0) {
            document
                .getElementById(`${listId}-option-${current}`)
                ?.scrollIntoView({ block: 'nearest' });
        }
    }, [current, listId]);

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.nativeEvent.isComposing) {
            return;
        }

        // Typed and pressed Enter before the answer came back: open the best
        // match for what was typed, not a row left from the last search (or
        // the assistant, the only row on a first search).
        if (event.key === 'Enter' && status === 'loading') {
            event.preventDefault();
            whenAnswered((answered) => {
                const best = answered[0]?.items[0];

                if (best) {
                    visit(best.href);
                }
            });

            return;
        }

        if (entries.length === 0) {
            return;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            setActive((current + step + entries.length) % entries.length);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            entries[current]?.run();
        }
    };

    const nothingFound =
        status === 'ready' &&
        groups.length === 0 &&
        term.length >= MIN_QUERY_LENGTH;

    return (
        <>
            <div className="flex h-13 shrink-0 items-center gap-3 border-b px-4">
                {status === 'loading' ? (
                    <Spinner className="size-[18px] text-muted-foreground" />
                ) : (
                    <Search
                        className="size-[18px] text-muted-foreground"
                        aria-hidden="true"
                    />
                )}
                <input
                    autoFocus
                    type="text"
                    value={query}
                    maxLength={MAX_QUERY_LENGTH}
                    onChange={(event) => {
                        setQuery(event.target.value);
                        setActive(0);
                    }}
                    onKeyDown={onKeyDown}
                    placeholder="Search people, records, screens and help…"
                    aria-label="Search"
                    role="combobox"
                    aria-expanded={entries.length > 0}
                    aria-controls={listId}
                    aria-autocomplete="list"
                    aria-activedescendant={
                        current >= 0 ? optionId(current) : undefined
                    }
                    autoComplete="off"
                    spellCheck={false}
                    className="h-full min-w-0 flex-1 bg-transparent text-[15px] text-foreground outline-none placeholder:text-muted-foreground"
                />
                <DialogPrimitive.Close asChild>
                    <button
                        type="button"
                        className="rounded-md border px-1.5 py-0.5 text-[11px] font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                    >
                        Esc
                        <span className="sr-only"> to close search</span>
                    </button>
                </DialogPrimitive.Close>
            </div>

            <div
                id={listId}
                role="listbox"
                aria-label="Search results"
                className={cn(
                    'min-h-0 flex-1 overflow-y-auto overscroll-contain p-2 transition-opacity',
                    status === 'loading' && groups.length > 0 && 'opacity-60',
                )}
            >
                {sections.map((section, sectionIndex) => (
                    <div
                        key={section.key}
                        role="group"
                        aria-labelledby={`${listId}-${section.key}`}
                        className="pb-1 [&+&]:mt-1 [&+&]:border-t [&+&]:pt-2"
                    >
                        <div
                            id={`${listId}-${section.key}`}
                            className="px-2 pt-1 pb-1.5 text-xs font-medium text-muted-foreground"
                        >
                            {section.label}
                        </div>
                        {section.entries.map((entry, entryIndex) => {
                            const position = offsets[sectionIndex] + entryIndex;
                            const selected = position === current;

                            return (
                                <div
                                    key={entry.id}
                                    id={optionId(position)}
                                    role="option"
                                    aria-selected={selected}
                                    onMouseMove={() =>
                                        position !== current &&
                                        setActive(position)
                                    }
                                    onMouseDown={(event) =>
                                        event.preventDefault()
                                    }
                                    onClick={entry.run}
                                    className={cn(
                                        'relative flex cursor-pointer items-center gap-3 rounded-lg px-2 py-2 select-none',
                                        selected && 'bg-muted',
                                    )}
                                >
                                    {selected && (
                                        <span
                                            aria-hidden="true"
                                            className="absolute inset-y-2 left-0 w-0.5 rounded-full bg-assistant-ink dark:bg-assistant-signal"
                                        />
                                    )}
                                    <EntryIcon
                                        icon={entry.icon}
                                        selected={selected}
                                    />
                                    <div className="min-w-0 flex-1">
                                        <div className="truncate text-[13.5px] leading-5 text-foreground">
                                            <Highlight
                                                text={entry.title}
                                                words={words}
                                            />
                                        </div>
                                        {entry.subtitle && (
                                            <div className="truncate text-xs leading-4 text-muted-foreground">
                                                {entry.subtitle}
                                            </div>
                                        )}
                                    </div>
                                    {entry.hint && (
                                        <span className="max-w-[40%] shrink-0 truncate text-xs text-muted-foreground tabular-nums">
                                            {entry.hint}
                                        </span>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                ))}

                {status === 'loading' && groups.length === 0 && (
                    <Notice>Searching…</Notice>
                )}

                {nothingFound && (
                    <Notice>
                        Nothing you can open matches “{term}”. Try a name, an
                        employee number, or the screen you are after.
                    </Notice>
                )}

                {status === 'error' && <Notice tone="error">{error}</Notice>}

                {term.length < MIN_QUERY_LENGTH &&
                    entries.length === 0 &&
                    term.length > 0 && (
                        <Notice>Keep typing to search everything.</Notice>
                    )}
            </div>

            <div className="hidden shrink-0 items-center gap-4 border-t px-4 py-2 text-[11.5px] text-muted-foreground sm:flex">
                <KeyHint keys={['↑', '↓']}>to move</KeyHint>
                <KeyHint keys={['↵']}>to open</KeyHint>
                <KeyHint keys={['Esc']}>to close</KeyHint>
            </div>
        </>
    );
}

function EntryIcon({
    icon,
    selected,
}: {
    icon: Entry['icon'];
    selected: boolean;
}) {
    const Icon = icon === 'assistant' ? null : icon;

    return (
        <span
            aria-hidden="true"
            className={cn(
                'flex size-8 shrink-0 items-center justify-center rounded-md border transition-colors',
                selected
                    ? 'border-assistant-ink/25 bg-background text-assistant-ink dark:border-border dark:text-foreground'
                    : 'border-border/70 bg-muted/40 text-muted-foreground',
            )}
        >
            {Icon ? (
                <Icon className="size-4" />
            ) : (
                <AssistantMark className="text-assistant-ink dark:text-foreground" />
            )}
        </span>
    );
}

/**
 * The words that matched, picked out in the brand teal — teal is what the
 * system found, as in the assistant's trace.
 */
function Highlight({ text, words }: { text: string; words: string[] }) {
    const needles = words.filter((word) => word.length > 0);

    if (needles.length === 0) {
        return <>{text}</>;
    }

    const pattern = new RegExp(
        `(${needles.map((word) => word.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|')})`,
        'gi',
    );

    return (
        <>
            {text.split(pattern).map((part, i) =>
                i % 2 === 1 ? (
                    <mark
                        key={i}
                        className="bg-transparent font-semibold text-assistant-signal-text"
                    >
                        {part}
                    </mark>
                ) : (
                    part
                ),
            )}
        </>
    );
}

function Notice({
    children,
    tone = 'muted',
}: {
    children: ReactNode;
    tone?: 'muted' | 'error';
}) {
    return (
        <p
            role={tone === 'error' ? 'alert' : 'status'}
            className={cn(
                'px-3 py-8 text-center text-sm',
                tone === 'error' ? 'text-destructive' : 'text-muted-foreground',
            )}
        >
            {children}
        </p>
    );
}

function KeyHint({ keys, children }: { keys: string[]; children: ReactNode }) {
    return (
        <span className="flex items-center gap-1.5">
            {keys.map((key) => (
                <kbd
                    key={key}
                    className="min-w-5 rounded border bg-muted px-1 text-center font-sans text-[11px] leading-4"
                >
                    {key}
                </kbd>
            ))}
            {children}
        </span>
    );
}
