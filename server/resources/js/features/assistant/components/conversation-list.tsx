import {
    Check,
    Pencil,
    Pin,
    PinOff,
    Search,
    SquarePen,
    Trash2,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { cn } from '@/lib/utils';
import type { Conversation } from '../types';

const GROUP_ORDER = [
    'Pinned',
    'Today',
    'Yesterday',
    'Previous 7 days',
    'Older',
] as const;

type Group = (typeof GROUP_ORDER)[number];

const DAY = 86_400_000;

function groupFor(conversation: Conversation, startOfToday: number): Group {
    if (conversation.pinned) {
        return 'Pinned';
    }

    if (!conversation.lastActivityAt) {
        return 'Older';
    }

    const ts = new Date(conversation.lastActivityAt).getTime();

    if (ts >= startOfToday) {
        return 'Today';
    }

    if (ts >= startOfToday - DAY) {
        return 'Yesterday';
    }

    if (ts >= startOfToday - 7 * DAY) {
        return 'Previous 7 days';
    }

    return 'Older';
}

/** When a thread was last used, as short as its age allows: 14:05, Mon, 28 Sep. */
function whenLabel(iso: string | null, startOfToday: number): string {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);
    const ts = date.getTime();

    if (Number.isNaN(ts)) {
        return '';
    }

    if (ts >= startOfToday) {
        return date.toLocaleTimeString(undefined, {
            hour: 'numeric',
            minute: '2-digit',
        });
    }

    if (ts >= startOfToday - 6 * DAY) {
        return date.toLocaleDateString(undefined, { weekday: 'short' });
    }

    return date.toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
    });
}

/** A reply's first line as plain text: the preview is cut from its markdown. */
function plain(preview: string): string {
    return preview
        .replace(/\[([^\]]*)\]\([^)]*\)/g, '$1')
        .replace(/[*_`#>|~]+/g, '')
        .replace(/\s+/g, ' ')
        .trim();
}

/**
 * The conversation history, laid over the thread when the panel's title is
 * pressed. Searching, pinning, renaming and deleting happen in place; deleting
 * one thread, or all of them, asks once more before it goes.
 */
export function ConversationList({
    conversations,
    activeId,
    onOpen,
    onNew,
    onRename,
    onTogglePin,
    onDelete,
    onClearAll,
}: {
    conversations: Conversation[];
    activeId: number | null;
    onOpen: (id: number) => void;
    onNew: () => void;
    onRename: (id: number, title: string) => void;
    onTogglePin: (id: number, pinned: boolean) => void;
    onDelete: (id: number) => void;
    onClearAll: () => void;
}) {
    const [query, setQuery] = useState('');
    const [confirmingClear, setConfirmingClear] = useState(false);
    // Focus goes back to "Delete all…" when its question is answered "Keep".
    const [refocusClear, setRefocusClear] = useState(false);

    const keepAll = () => {
        setConfirmingClear(false);
        setRefocusClear(true);
    };

    const { groups, startOfToday } = useMemo(() => {
        const now = new Date();
        const today = new Date(
            now.getFullYear(),
            now.getMonth(),
            now.getDate(),
        ).getTime();
        const q = query.trim().toLowerCase();

        const map = new Map<Group, Conversation[]>();

        for (const conversation of conversations) {
            if (
                q !== '' &&
                !conversation.title.toLowerCase().includes(q) &&
                !(conversation.preview ?? '').toLowerCase().includes(q)
            ) {
                continue;
            }

            const group = groupFor(conversation, today);
            map.set(group, [...(map.get(group) ?? []), conversation]);
        }

        return {
            startOfToday: today,
            groups: GROUP_ORDER.map((name) => ({
                name,
                items: map.get(name) ?? [],
            })).filter((g) => g.items.length > 0),
        };
    }, [conversations, query]);

    return (
        <div className="absolute inset-0 z-10 flex animate-in flex-col bg-background duration-150 fade-in motion-reduce:animate-none">
            <div className="flex items-center gap-2 px-3 pt-3 pb-2">
                <div className="relative flex-1">
                    <Search className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground" />
                    <input
                        type="search"
                        value={query}
                        autoFocus
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Search conversations"
                        aria-label="Search conversations"
                        className="h-8 w-full rounded-md border border-input bg-background pr-2.5 pl-8 text-sm outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                    />
                </div>
                <button
                    type="button"
                    onClick={onNew}
                    className="flex h-8 shrink-0 items-center gap-1.5 rounded-md bg-assistant-ink px-2.5 text-xs font-medium text-white transition-opacity hover:opacity-90"
                >
                    <SquarePen className="size-3.5" />
                    New
                </button>
            </div>

            <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-2 pb-2">
                {groups.length === 0 ? (
                    <p className="px-2 py-10 text-center text-sm text-muted-foreground">
                        {conversations.length === 0
                            ? 'Your conversations will be listed here.'
                            : `Nothing matches “${query.trim()}”.`}
                    </p>
                ) : (
                    groups.map((group) => (
                        <section
                            key={group.name}
                            aria-label={group.name}
                            className="mt-2 first:mt-0"
                        >
                            <h3 className="px-2 py-1.5 text-xs font-medium text-muted-foreground">
                                {group.name}
                            </h3>
                            <ul className="flex flex-col">
                                {group.items.map((conversation) => (
                                    <ConversationRow
                                        key={conversation.id}
                                        conversation={conversation}
                                        when={whenLabel(
                                            conversation.lastActivityAt,
                                            startOfToday,
                                        )}
                                        active={conversation.id === activeId}
                                        onOpen={onOpen}
                                        onRename={onRename}
                                        onTogglePin={onTogglePin}
                                        onDelete={onDelete}
                                    />
                                ))}
                            </ul>
                        </section>
                    ))
                )}
            </div>

            {conversations.length > 0 && (
                <div
                    onKeyDown={(event) => {
                        if (confirmingClear && event.key === 'Escape') {
                            event.preventDefault();
                            keepAll();
                        }
                    }}
                    className="flex h-11 shrink-0 items-center justify-center gap-2 border-t border-border px-3 text-xs"
                >
                    {confirmingClear ? (
                        <>
                            <span className="text-foreground">
                                Delete all {conversations.length} conversations?
                            </span>
                            <button
                                type="button"
                                onClick={keepAll}
                                className="rounded-md px-2 py-1 font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                            >
                                Keep them
                            </button>
                            <button
                                type="button"
                                autoFocus
                                onClick={() => {
                                    setConfirmingClear(false);
                                    onClearAll();
                                }}
                                className="rounded-md bg-destructive px-2 py-1 font-medium text-white transition-opacity hover:opacity-90"
                            >
                                Delete all
                            </button>
                        </>
                    ) : (
                        <button
                            type="button"
                            autoFocus={refocusClear}
                            onClick={() => setConfirmingClear(true)}
                            className="flex items-center gap-1.5 rounded-md px-2 py-1 text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
                        >
                            <Trash2 className="size-3.5" />
                            Delete all conversations
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}

function ConversationRow({
    conversation,
    when,
    active,
    onOpen,
    onRename,
    onTogglePin,
    onDelete,
}: {
    conversation: Conversation;
    when: string;
    active: boolean;
    onOpen: (id: number) => void;
    onRename: (id: number, title: string) => void;
    onTogglePin: (id: number, pinned: boolean) => void;
    onDelete: (id: number) => void;
}) {
    type Mode = 'view' | 'rename' | 'delete';
    const [mode, setMode] = useState<Mode>('view');
    const [draft, setDraft] = useState(conversation.title);
    // Back in view, focus returns to the button that left it, so the keyboard
    // (and Escape) stay inside the panel.
    const [returnTo, setReturnTo] = useState<Mode | null>(null);

    const back = (from: Mode) => {
        setMode('view');
        setReturnTo(from);
    };

    const save = () => {
        onRename(conversation.id, draft);
        back('rename');
    };

    if (mode === 'rename') {
        return (
            <li className="flex items-center gap-1 rounded-md bg-muted px-1.5 py-1">
                <input
                    value={draft}
                    autoFocus
                    aria-label="Conversation name"
                    onChange={(e) => setDraft(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            save();
                        } else if (e.key === 'Escape') {
                            e.preventDefault();
                            setDraft(conversation.title);
                            back('rename');
                        }
                    }}
                    className="h-7 min-w-0 flex-1 rounded border border-input bg-background px-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring/30"
                />
                <button
                    type="button"
                    aria-label="Save name"
                    onClick={save}
                    className="flex size-7 items-center justify-center rounded-md text-foreground hover:bg-background"
                >
                    <Check className="size-3.5" />
                </button>
            </li>
        );
    }

    if (mode === 'delete') {
        return (
            <li
                onKeyDown={(e) => {
                    if (e.key === 'Escape') {
                        e.preventDefault();
                        back('delete');
                    }
                }}
                className="flex items-center gap-2 rounded-md bg-destructive/[0.07] py-1.5 pr-1.5 pl-2.5 text-xs"
            >
                <span className="min-w-0 flex-1 truncate">
                    Delete “{conversation.title}”?
                </span>
                <button
                    type="button"
                    onClick={() => back('delete')}
                    className="rounded-md px-2 py-1 font-medium text-muted-foreground transition-colors hover:bg-background hover:text-foreground"
                >
                    Keep
                </button>
                <button
                    type="button"
                    autoFocus
                    onClick={() => onDelete(conversation.id)}
                    className="rounded-md bg-destructive px-2 py-1 font-medium text-white transition-opacity hover:opacity-90"
                >
                    Delete
                </button>
            </li>
        );
    }

    return (
        <li
            className={cn(
                'group/row relative flex items-center rounded-md transition-colors',
                active ? 'bg-muted' : 'hover:bg-muted/60',
            )}
        >
            <button
                type="button"
                onClick={() => onOpen(conversation.id)}
                aria-current={active ? 'true' : undefined}
                className="flex min-w-0 flex-1 flex-col gap-0.5 rounded-md px-2.5 py-2 text-left outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
            >
                <span className="flex w-full items-baseline gap-2">
                    {conversation.pinned && (
                        <Pin
                            aria-label="Pinned"
                            className="size-3 shrink-0 self-center text-assistant-signal-text"
                        />
                    )}
                    <span className="min-w-0 flex-1 truncate text-sm font-medium">
                        {conversation.title}
                    </span>
                    {when && (
                        <span className="shrink-0 text-[11px] text-muted-foreground tabular-nums group-focus-within/row:invisible group-hover/row:invisible">
                            {when}
                        </span>
                    )}
                </span>
                {conversation.preview && (
                    <span className="w-full truncate text-xs text-muted-foreground">
                        {plain(conversation.preview)}
                    </span>
                )}
            </button>

            <div className="absolute top-1.5 right-1.5 flex items-center rounded-md bg-muted opacity-0 transition-opacity group-focus-within/row:opacity-100 group-hover/row:opacity-100">
                <RowButton
                    label={conversation.pinned ? 'Unpin' : 'Pin'}
                    onClick={() =>
                        onTogglePin(conversation.id, !conversation.pinned)
                    }
                >
                    {conversation.pinned ? <PinOff /> : <Pin />}
                </RowButton>
                <RowButton
                    label="Rename"
                    autoFocus={returnTo === 'rename'}
                    onClick={() => {
                        setDraft(conversation.title);
                        setMode('rename');
                    }}
                >
                    <Pencil />
                </RowButton>
                <RowButton
                    label="Delete"
                    destructive
                    autoFocus={returnTo === 'delete'}
                    onClick={() => setMode('delete')}
                >
                    <Trash2 />
                </RowButton>
            </div>
        </li>
    );
}

function RowButton({
    label,
    onClick,
    destructive,
    autoFocus,
    children,
}: {
    label: string;
    onClick: () => void;
    destructive?: boolean;
    autoFocus?: boolean;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            autoFocus={autoFocus}
            onClick={onClick}
            title={label}
            aria-label={label}
            className={cn(
                'flex size-6 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-background [&_svg]:size-3.5',
                destructive
                    ? 'hover:text-destructive'
                    : 'hover:text-foreground',
            )}
        >
            {children}
        </button>
    );
}
