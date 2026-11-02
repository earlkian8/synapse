import { ExternalLink } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import { PersonAvatar } from '@/components/person-avatar';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { focusDialogItself } from '../lib';

export type BreakdownPerson = {
    id: number;
    full_name: string;
    initials: string;
    photo: string | null;
    position: string | null;
    department: string | null;
    employee_no: string;
};

export type BreakdownTab = {
    key: string;
    label: string;
    content: ReactNode;
};

/**
 * The frame every predictive breakdown sits in (ADR 0099): the person, a link to
 * their profile, and the breakdown as underline tabs — Summary first, so the
 * decision is the first thing read and the evidence is one tab away. The tabs reset
 * to Summary whenever another person opens.
 */
export function BreakdownDialog({
    person,
    description,
    profileUrl,
    tabs,
    onOpenChange,
}: {
    person: BreakdownPerson | null;
    /** What the dialog holds, for screen readers. */
    description: string;
    profileUrl?: string | null;
    tabs: BreakdownTab[];
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={person !== null} onOpenChange={onOpenChange}>
            <DialogContent
                className="flex max-h-[92vh] flex-col gap-0 overflow-hidden p-0 sm:max-w-3xl"
                onOpenAutoFocus={focusDialogItself}
            >
                {person && (
                    <Frame
                        key={person.id}
                        person={person}
                        description={description}
                        profileUrl={profileUrl ?? null}
                        tabs={tabs}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function Frame({
    person,
    description,
    profileUrl,
    tabs,
}: {
    person: BreakdownPerson;
    description: string;
    profileUrl: string | null;
    tabs: BreakdownTab[];
}) {
    const [active, setActive] = useState(tabs[0]?.key ?? '');
    const id = useId();
    const tabRefs = useRef<Record<string, HTMLButtonElement | null>>({});
    const current = tabs.find((t) => t.key === active) ?? tabs[0];

    const onKey = (event: KeyboardEvent<HTMLDivElement>) => {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
            return;
        }

        event.preventDefault();
        const index = tabs.findIndex((t) => t.key === current?.key);
        const next =
            event.key === 'Home'
                ? 0
                : event.key === 'End'
                  ? tabs.length - 1
                  : (index +
                        (event.key === 'ArrowLeft' ? -1 : 1) +
                        tabs.length) %
                    tabs.length;
        const key = tabs[next].key;

        setActive(key);
        tabRefs.current[key]?.focus();
    };

    return (
        <>
            <header className="flex flex-col gap-3 border-b border-border px-5 pt-5 sm:px-6">
                <div className="flex items-center gap-3 pr-8">
                    <PersonAvatar
                        name={person.full_name}
                        initials={person.initials}
                        photo={person.photo}
                        className="size-11"
                    />
                    <div className="flex min-w-0 flex-1 flex-col">
                        <DialogTitle className="truncate text-base leading-tight">
                            {person.full_name}
                        </DialogTitle>
                        <p className="truncate text-xs text-muted-foreground">
                            {person.position ?? person.employee_no}
                            {person.department ? ` · ${person.department}` : ''}
                        </p>
                    </div>
                    {profileUrl && (
                        <a
                            href={profileUrl}
                            className="hidden shrink-0 items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:inline-flex"
                        >
                            Profile
                            <ExternalLink className="size-3" />
                        </a>
                    )}
                </div>
                <DialogDescription className="sr-only">
                    {description}
                </DialogDescription>

                <div
                    role="tablist"
                    aria-label={`${person.full_name}: breakdown`}
                    className="-mx-1 flex items-center gap-1 overflow-x-auto"
                    onKeyDown={onKey}
                >
                    {tabs.map((tab) => {
                        const selected = tab.key === current?.key;

                        return (
                            <button
                                key={tab.key}
                                ref={(node) => {
                                    tabRefs.current[tab.key] = node;
                                }}
                                id={`${id}-tab-${tab.key}`}
                                type="button"
                                role="tab"
                                aria-selected={selected}
                                aria-controls={`${id}-panel`}
                                tabIndex={selected ? 0 : -1}
                                onClick={() => setActive(tab.key)}
                                className={cn(
                                    'border-b-2 px-2.5 pt-1 pb-2 text-sm font-medium whitespace-nowrap transition-colors focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                    selected
                                        ? 'border-[#0ABFBF] text-foreground'
                                        : 'border-transparent text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {tab.label}
                            </button>
                        );
                    })}
                </div>
            </header>

            <div
                id={`${id}-panel`}
                role="tabpanel"
                aria-labelledby={`${id}-tab-${current?.key}`}
                className="flex min-h-[24rem] flex-col gap-6 overflow-y-auto px-5 py-5 sm:px-6"
            >
                {current?.content}
            </div>
        </>
    );
}

/** A titled block inside a breakdown tab. */
export function BreakdownSection({
    title,
    description,
    aside,
    children,
    className,
}: {
    title: string;
    description?: ReactNode;
    /** A short note beside the title (a count, a date). */
    aside?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section className={cn('flex flex-col gap-2.5', className)}>
            <div className="flex flex-col gap-0.5">
                <div className="flex items-baseline justify-between gap-3">
                    <h3 className="text-sm font-semibold">{title}</h3>
                    {aside && (
                        <span className="shrink-0 text-xs text-muted-foreground">
                            {aside}
                        </span>
                    )}
                </div>
                {description && (
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {children}
        </section>
    );
}
