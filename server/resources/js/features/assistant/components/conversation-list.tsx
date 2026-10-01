import {
    Check,
    Pencil,
    Pin,
    PinOff,
    Plus,
    Search,
    Trash2,
    X,
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

function groupFor(conversation: Conversation): (typeof GROUP_ORDER)[number] {
    if (conversation.pinned) {
        return 'Pinned';
    }

    if (!conversation.lastActivityAt) {
        return 'Older';
    }

    const then = new Date(conversation.lastActivityAt);
    const now = new Date();
    const startOfToday = new Date(
        now.getFullYear(),
        now.getMonth(),
        now.getDate(),
    ).getTime();
    const ts = then.getTime();
    const day = 86_400_000;

    if (ts >= startOfToday) {
        return 'Today';
    }

    if (ts >= startOfToday - day) {
        return 'Yesterday';
    }

    if (ts >= startOfToday - 7 * day) {
        return 'Previous 7 days';
    }

    return 'Older';
}

export function ConversationList({
    conversations,
    activeId,
    onOpen,
    onNew,
    onRename,
    onTogglePin,
    onDelete,
    onClearAll,
    onClose,
}: {
    conversations: Conversation[];
    activeId: number | null;
    onOpen: (id: number) => void;
    onNew: () => void;
    onRename: (id: number, title: string) => void;
    onTogglePin: (id: number, pinned: boolean) => void;
    onDelete: (id: number) => void;
    onClearAll: () => void;
    onClose: () => void;
}) {
    const [query, setQuery] = useState('');

    const groups = useMemo(() => {
        const filtered = conversations.filter((c) => {
            const q = query.trim().toLowerCase();

            return (
                q === '' ||
                c.title.toLowerCase().includes(q) ||
                (c.preview ?? '').toLowerCase().includes(q)
            );
        });

        const map = new Map<string, Conversation[]>();

        for (const conversation of filtered) {
            const group = groupFor(conversation);
            map.set(group, [...(map.get(group) ?? []), conversation]);
        }

        return GROUP_ORDER.map((name) => ({
            name,
            items: map.get(name) ?? [],
        })).filter((g) => g.items.length > 0);
    }, [conversations, query]);

    return (
        <div className="absolute inset-0 z-10 flex animate-in flex-col bg-card duration-200 fade-in slide-in-from-left-2">
            {/* Header */}
            <div className="flex items-center gap-2 border-b border-border px-3 py-2.5">
                <p className="flex-1 text-sm font-semibold">Conversations</p>
                <button
                    type="button"
                    onClick={onClose}
                    aria-label="Close history"
                    className="flex size-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                >
                    <X className="size-4" />
                </button>
            </div>

            {/* New + search */}
            <div className="flex flex-col gap-2 px-3 py-2.5">
                <button
                    type="button"
                    onClick={onNew}
                    className="flex items-center gap-2 rounded-lg bg-[#0F2044] px-3 py-2 text-sm font-medium text-white transition-colors hover:bg-[#0F2044]/90"
                >
                    <Plus className="size-4" />
                    New conversation
                </button>
                <div className="relative">
                    <Search className="absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground" />
                    <input
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Search conversations…"
                        className="w-full rounded-lg border border-input bg-background py-1.5 pr-2.5 pl-8 text-sm outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                    />
                </div>
            </div>

            {/* List */}
            <div className="min-h-0 flex-1 overflow-y-auto px-2 pb-2">
                {groups.length === 0 ? (
                    <p className="px-3 py-8 text-center text-xs text-muted-foreground">
                        {conversations.length === 0
                            ? 'No conversations yet.'
                            : 'No matches.'}
                    </p>
                ) : (
                    groups.map((group) => (
                        <div key={group.name} className="mb-2">
                            <p className="px-2 py-1.5 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                                {group.name}
                            </p>
                            <div className="flex flex-col gap-0.5">
                                {group.items.map((conversation) => (
                                    <ConversationRow
                                        key={conversation.id}
                                        conversation={conversation}
                                        active={conversation.id === activeId}
                                        onOpen={onOpen}
                                        onRename={onRename}
                                        onTogglePin={onTogglePin}
                                        onDelete={onDelete}
                                    />
                                ))}
                            </div>
                        </div>
                    ))
                )}
            </div>

            {conversations.length > 0 && (
                <div className="border-t border-border px-3 py-2">
                    <button
                        type="button"
                        onClick={onClearAll}
                        className="flex w-full items-center justify-center gap-1.5 rounded-md py-1.5 text-xs text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
                    >
                        <Trash2 className="size-3.5" />
                        Clear all conversations
                    </button>
                </div>
            )}
        </div>
    );
}

function ConversationRow({
    conversation,
    active,
    onOpen,
    onRename,
    onTogglePin,
    onDelete,
}: {
    conversation: Conversation;
    active: boolean;
    onOpen: (id: number) => void;
    onRename: (id: number, title: string) => void;
    onTogglePin: (id: number, pinned: boolean) => void;
    onDelete: (id: number) => void;
}) {
    const [renaming, setRenaming] = useState(false);
    const [draft, setDraft] = useState(conversation.title);

    if (renaming) {
        return (
            <div className="flex items-center gap-1 rounded-lg bg-muted px-2 py-1.5">
                <input
                    value={draft}
                    autoFocus
                    onChange={(e) => setDraft(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            onRename(conversation.id, draft);
                            setRenaming(false);
                        } else if (e.key === 'Escape') {
                            setRenaming(false);
                            setDraft(conversation.title);
                        }
                    }}
                    className="min-w-0 flex-1 rounded-md border border-input bg-background px-2 py-1 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring/30"
                />
                <button
                    type="button"
                    aria-label="Save name"
                    onClick={() => {
                        onRename(conversation.id, draft);
                        setRenaming(false);
                    }}
                    className="flex size-6 items-center justify-center rounded-md text-emerald-500 hover:bg-background"
                >
                    <Check className="size-3.5" />
                </button>
            </div>
        );
    }

    return (
        <div
            className={cn(
                'group/row flex items-center gap-1 rounded-lg px-2 py-1.5 transition-colors',
                active ? 'bg-muted' : 'hover:bg-muted/60',
            )}
        >
            <button
                type="button"
                onClick={() => onOpen(conversation.id)}
                className="flex min-w-0 flex-1 flex-col items-start text-left"
            >
                <span className="flex w-full items-center gap-1.5">
                    {conversation.pinned && (
                        <Pin className="size-3 shrink-0 text-[#0ABFBF]" />
                    )}
                    <span className="truncate text-sm font-medium">
                        {conversation.title}
                    </span>
                </span>
                {conversation.preview && (
                    <span className="w-full truncate text-[11px] text-muted-foreground">
                        {conversation.preview}
                    </span>
                )}
            </button>

            <div className="flex shrink-0 items-center opacity-0 transition-opacity group-hover/row:opacity-100">
                <RowButton
                    label={conversation.pinned ? 'Unpin' : 'Pin'}
                    onClick={() =>
                        onTogglePin(conversation.id, !conversation.pinned)
                    }
                >
                    {conversation.pinned ? (
                        <PinOff className="size-3.5" />
                    ) : (
                        <Pin className="size-3.5" />
                    )}
                </RowButton>
                <RowButton
                    label="Rename"
                    onClick={() => {
                        setDraft(conversation.title);
                        setRenaming(true);
                    }}
                >
                    <Pencil className="size-3.5" />
                </RowButton>
                <RowButton
                    label="Delete"
                    destructive
                    onClick={() => onDelete(conversation.id)}
                >
                    <Trash2 className="size-3.5" />
                </RowButton>
            </div>
        </div>
    );
}

function RowButton({
    label,
    onClick,
    destructive,
    children,
}: {
    label: string;
    onClick: () => void;
    destructive?: boolean;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            title={label}
            aria-label={label}
            className={cn(
                'flex size-6 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-background',
                destructive
                    ? 'hover:text-destructive'
                    : 'hover:text-foreground',
            )}
        >
            {children}
        </button>
    );
}
