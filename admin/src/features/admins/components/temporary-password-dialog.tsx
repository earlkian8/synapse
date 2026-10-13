import { Check, Copy, KeyRound } from 'lucide-react';
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

export type IssuedPassword = {
    name: string;
    email: string;
    password: string;
    reason: 'created' | 'reset';
};

/**
 * A one-time password, shown to the admin who issued it and to nobody else,
 * ever: the server keeps only its hash, and its holder must replace it at their
 * first sign-in.
 */
export function TemporaryPasswordDialog({
    issued,
    onClose,
}: {
    issued: IssuedPassword | null;
    onClose: () => void;
}) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        if (!issued) {
            return;
        }

        try {
            await navigator.clipboard.writeText(issued.password);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            setCopied(false);
        }
    };

    return (
        <Dialog
            open={issued !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent
                className="sm:max-w-md"
                onInteractOutside={(event) => event.preventDefault()}
            >
                <DialogHeader>
                    <span className="mb-1 flex size-10 items-center justify-center rounded-full bg-[#0ABFBF]/12 text-[#08767c] dark:text-[#0ABFBF]">
                        <KeyRound className="size-5" />
                    </span>
                    <DialogTitle>
                        {issued?.reason === 'created'
                            ? `${issued.name} can now sign in`
                            : `New password for ${issued?.name}`}
                    </DialogTitle>
                    <DialogDescription>
                        Give this password to {issued?.name} somewhere only they
                        can read it. It is shown once. At their first sign-in
                        they choose their own
                        {issued?.reason === 'created'
                            ? ' and set up an authenticator app'
                            : ''}
                        .
                    </DialogDescription>
                </DialogHeader>

                <div className="rounded-lg border border-sidebar-border/70 bg-muted/40 p-3">
                    <p className="text-xs text-muted-foreground">
                        {issued?.email}
                    </p>
                    <p
                        className="mt-1 font-mono text-[15px] tracking-wide break-all select-all"
                        data-testid="temporary-password"
                    >
                        {issued?.password}
                    </p>
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={() => void copy()}>
                        {copied ? (
                            <Check className="size-4" />
                        ) : (
                            <Copy className="size-4" />
                        )}
                        {copied ? 'Copied' : 'Copy password'}
                    </Button>
                    <Button onClick={onClose}>Done</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
