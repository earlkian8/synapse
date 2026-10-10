import { CalendarSync, Check, Copy, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { fetchJson } from '../constants';
import { eventRoutes } from '../routes';
import type { CalendarLinks } from '../types';

type State =
    | { status: 'idle' }
    | { status: 'loading' }
    | { status: 'ready'; links: CalendarLinks }
    | { status: 'failed' };

/**
 * "Subscribe": a private link a calendar app checks on its own, so every
 * invitation — and every change to one — shows up there (ADR 0070). The link is
 * made the first time somebody asks for it; resetting it stops the old one.
 */
export function SubscribeButton() {
    const [open, setOpen] = useState(false);
    const [state, setState] = useState<State>({ status: 'idle' });
    const [copied, setCopied] = useState(false);
    const [confirmReset, setConfirmReset] = useState(false);

    const load = (url: string, init?: RequestInit) => {
        setState({ status: 'loading' });
        fetchJson<CalendarLinks>(url, init)
            .then((links) => setState({ status: 'ready', links }))
            .catch(() => setState({ status: 'failed' }));
    };

    const show = () => {
        setOpen(true);
        setCopied(false);
        setConfirmReset(false);

        if (state.status !== 'ready') {
            load(eventRoutes.meCalendar);
        }
    };

    const copy = async (text: string) => {
        await navigator.clipboard.writeText(text);
        setCopied(true);
    };

    const reset = () => {
        setConfirmReset(false);
        setCopied(false);
        load(eventRoutes.meCalendarReset, { method: 'POST' });
    };

    return (
        <>
            <Button variant="outline" size="sm" onClick={show}>
                <CalendarSync className="size-4" />
                Subscribe
            </Button>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            Your events in your own calendar
                        </DialogTitle>
                        <DialogDescription>
                            Subscribe once. Google, Outlook or Apple Calendar
                            checks this link on its own, so new invitations and
                            changes appear there by themselves, and what you
                            decline drops off.
                        </DialogDescription>
                    </DialogHeader>

                    {state.status === 'loading' || state.status === 'idle' ? (
                        <div className="flex items-center gap-2 py-6 text-sm text-muted-foreground">
                            <Spinner /> Getting your link…
                        </div>
                    ) : state.status === 'failed' ? (
                        <p className="py-4 text-sm text-destructive">
                            Couldn’t get your link. Close this and try again.
                        </p>
                    ) : (
                        <div className="flex flex-col gap-4">
                            <div className="flex gap-2">
                                <Input
                                    readOnly
                                    value={state.links.https}
                                    aria-label="Your calendar link"
                                    onFocus={(e) => e.currentTarget.select()}
                                    className="font-mono text-xs"
                                />
                                <Button
                                    variant="outline"
                                    onClick={() => copy(state.links.https)}
                                    aria-label="Copy link"
                                >
                                    {copied ? (
                                        <Check className="size-4" />
                                    ) : (
                                        <Copy className="size-4" />
                                    )}
                                    {copied ? 'Copied' : 'Copy'}
                                </Button>
                            </div>

                            <ol className="list-decimal space-y-1.5 pl-5 text-sm text-muted-foreground">
                                <li>
                                    <span className="text-foreground">
                                        Apple Calendar or Outlook:
                                    </span>{' '}
                                    <a
                                        href={state.links.webcal}
                                        className="font-medium text-foreground underline underline-offset-4"
                                    >
                                        open the subscription
                                    </a>
                                    .
                                </li>
                                <li>
                                    <span className="text-foreground">
                                        Google Calendar:
                                    </span>{' '}
                                    Other calendars → From URL, and paste the
                                    link.
                                </li>
                            </ol>

                            <p className="text-xs text-muted-foreground">
                                Anyone with this link can see your events. If it
                                gets out, reset it — the old link stops working,
                                and you subscribe again with the new one.
                            </p>
                        </div>
                    )}

                    <DialogFooter className="gap-2 sm:justify-between">
                        {state.status === 'ready' &&
                            (confirmReset ? (
                                <Button variant="destructive" onClick={reset}>
                                    <RotateCcw className="size-4" />
                                    Reset — the old link stops
                                </Button>
                            ) : (
                                <Button
                                    variant="ghost"
                                    onClick={() => setConfirmReset(true)}
                                >
                                    <RotateCcw className="size-4" />
                                    Reset link
                                </Button>
                            ))}
                        <Button onClick={() => setOpen(false)}>Done</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
