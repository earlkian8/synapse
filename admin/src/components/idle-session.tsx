import { Clock } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useSignOut } from '@/features/auth/use-sign-out';
import { api } from '@/lib/api';
import { formatCountdown } from '@/lib/format';
import { useSession } from '@/lib/session';

const WARN_BEFORE = 2 * 60 * 1000;

/**
 * Warns two minutes before the session ends, and ends it here when it does.
 *
 * The server ends a session that has had no request for the idle limit, so a
 * person reading a page for half an hour is signed out. This counts the same
 * clock (from the last request that went through) and offers to keep going —
 * one request restarts it. The absolute end of a session cannot be put off.
 */
export function IdleSession() {
    const session = useSession();
    const signOut = useSignOut();
    const [now, setNow] = useState(() => Date.now());
    const [staying, setStaying] = useState(false);

    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(timer);
    }, []);

    const idleEnds = session
        ? session.lastActivity + session.idleMinutes * 60_000
        : Infinity;
    const hardEnds = session ? new Date(session.expiresAt).getTime() : Infinity;
    const reason = hardEnds <= idleEnds ? 'expired' : 'idle';
    const remaining = Math.min(idleEnds, hardEnds) - now;
    const over = session !== null && remaining <= 0;

    useEffect(() => {
        if (over) {
            void signOut(reason);
        }
        // signOut is recreated each render; `over` is the trigger.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [over, reason]);

    const stay = async () => {
        setStaying(true);
        await api('/me').catch(() => undefined);
        setStaying(false);
    };

    return (
        <Dialog
            open={session !== null && remaining > 0 && remaining <= WARN_BEFORE}
        >
            <DialogContent
                className="sm:max-w-sm"
                onEscapeKeyDown={(event) => event.preventDefault()}
                onInteractOutside={(event) => event.preventDefault()}
                showCloseButton={false}
            >
                <DialogHeader>
                    <span className="mb-1 flex size-10 items-center justify-center rounded-full bg-amber-500/10 text-amber-600 dark:text-amber-400">
                        <Clock className="size-5" />
                    </span>
                    <DialogTitle>
                        {reason === 'idle'
                            ? 'Are you still there?'
                            : 'Your session is ending'}
                    </DialogTitle>
                    <DialogDescription>
                        {reason === 'idle'
                            ? 'Nothing has happened here for a while, so you will be signed out in '
                            : 'Sessions last eight hours. You will be signed out in '}
                        <span className="font-medium text-foreground tabular-nums">
                            {formatCountdown(remaining)}
                        </span>
                        .
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => void signOut('signed_out')}
                    >
                        Sign out now
                    </Button>
                    {reason === 'idle' && (
                        <Button onClick={() => void stay()} disabled={staying}>
                            {staying && <Spinner />}
                            Stay signed in
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
