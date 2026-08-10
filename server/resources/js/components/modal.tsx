import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';

/**
 * The app's centred modal shell. A surface that used to slide in from the edge
 * as a sheet opens instead as a dialog built from these parts:
 *
 *   Modal ─ ModalContent ─┬─ ModalHeader   identity: icon, title, description, meta
 *                         ├─ ModalBody     the only scrolling region
 *                         └─ ModalFooter   actions, always in view
 *
 * The content is height-capped and the body scrolls inside it, so the action
 * bar (save, hire, start) is never pushed below the fold on a short viewport.
 */

const SIZES = {
    sm: 'sm:max-w-md',
    md: 'sm:max-w-lg',
    lg: 'sm:max-w-2xl',
    xl: 'sm:max-w-3xl',
    '2xl': 'sm:max-w-4xl',
} as const;

export type ModalSize = keyof typeof SIZES;

export function Modal({
    open,
    onOpenChange,
    children,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    children: React.ReactNode;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            {children}
        </Dialog>
    );
}

export function ModalContent({
    size = 'md',
    className,
    children,
    ...props
}: React.ComponentProps<typeof DialogContent> & { size?: ModalSize }) {
    return (
        <DialogContent
            className={cn(
                'flex max-h-[calc(100dvh-1.5rem)] flex-col gap-0 overflow-hidden p-0 sm:max-h-[calc(100dvh-4rem)]',
                SIZES[size],
                className,
            )}
            {...props}
        >
            {children}
        </DialogContent>
    );
}

/**
 * The header. `icon` takes a badge — a brand tile, an avatar — that identifies
 * the record; `meta` takes the status row shown under the description.
 */
export function ModalHeader({
    icon,
    title,
    description,
    meta,
    className,
}: {
    icon?: React.ReactNode;
    title: React.ReactNode;
    description: React.ReactNode;
    meta?: React.ReactNode;
    className?: string;
}) {
    return (
        <DialogHeader
            className={cn(
                'shrink-0 gap-0 border-b border-border px-5 py-4 text-left sm:px-6',
                className,
            )}
        >
            <div className="flex items-start gap-3.5 pr-8">
                {icon}
                <div className="min-w-0 flex-1">
                    <DialogTitle className="truncate text-base leading-tight font-semibold tracking-tight sm:text-lg">
                        {title}
                    </DialogTitle>
                    <DialogDescription className="mt-1 text-sm leading-snug text-muted-foreground">
                        {description}
                    </DialogDescription>
                    {meta && (
                        <div className="mt-2.5 flex flex-wrap items-center gap-x-2 gap-y-1.5">
                            {meta}
                        </div>
                    )}
                </div>
            </div>
        </DialogHeader>
    );
}

/** The brand tile used as the header icon when there is no avatar to show. */
export function ModalIcon({ children }: { children: React.ReactNode }) {
    return (
        <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-[#0F2044] text-white [&_svg]:size-5">
            {children}
        </span>
    );
}

/** The scrolling region. Everything long belongs in here, not in the shell. */
export function ModalBody({
    className,
    children,
}: {
    className?: string;
    children: React.ReactNode;
}) {
    return (
        <div
            className={cn(
                'min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 py-5 sm:px-6',
                className,
            )}
        >
            {children}
        </div>
    );
}

/** The action bar. Stays pinned to the bottom of the modal, never scrolls. */
export function ModalFooter({
    className,
    children,
}: {
    className?: string;
    children: React.ReactNode;
}) {
    return (
        <div
            className={cn(
                'flex shrink-0 flex-wrap items-center justify-end gap-2 border-t border-border bg-background px-5 py-3.5 sm:px-6',
                className,
            )}
        >
            {children}
        </div>
    );
}

/** A titled block inside a modal body. */
export function ModalSection({
    title,
    hint,
    action,
    className,
    children,
}: {
    title: string;
    hint?: string;
    action?: React.ReactNode;
    className?: string;
    children: React.ReactNode;
}) {
    return (
        <section className={cn('space-y-3', className)}>
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h3 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                        {title}
                    </h3>
                    {hint && (
                        <p className="mt-1 text-xs text-muted-foreground/80">
                            {hint}
                        </p>
                    )}
                </div>
                {action}
            </div>
            {children}
        </section>
    );
}
