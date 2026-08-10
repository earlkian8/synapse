import { router } from '@inertiajs/react';
import { Check, Copy, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { ConfirmDialog } from '@/features/employees/components/confirm-dialog';
import { employeeRoutes } from '@/features/employees/routes';

type Props = {
    code: string | null;
    enabled: boolean;
    canManage: boolean;
};

/**
 * The company join code (ADR 0026).
 *
 * The code is set as large as it can usefully be read: this is a thing people
 * recite across a desk or put on a slide at induction, so legibility at a distance
 * is the whole design problem. It is spaced into characters rather than run
 * together because that is how it gets read aloud, and the alphabet it is drawn
 * from has no O/0 or I/1 to mishear.
 */
export function JoinCodeCard({ code, enabled, canManage }: Props) {
    const [copied, setCopied] = useState(false);
    const [confirmRotate, setConfirmRotate] = useState(false);
    const [rotating, setRotating] = useState(false);

    const copy = async () => {
        if (!code) {
            return;
        }

        try {
            await navigator.clipboard.writeText(code);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            // Clipboard blocked (insecure origin, denied permission) — the code is
            // on screen in full, so there is nothing to recover from.
        }
    };

    const rotate = () =>
        router.post(
            employeeRoutes.joinCode,
            {},
            {
                preserveScroll: true,
                onStart: () => setRotating(true),
                onFinish: () => {
                    setRotating(false);
                    setConfirmRotate(false);
                },
            },
        );

    const toggle = (next: boolean) =>
        router.patch(
            employeeRoutes.joinCode,
            { enabled: next },
            { preserveScroll: true },
        );

    return (
        <>
            <div className="flex flex-col gap-4 rounded-xl border border-sidebar-border/70 bg-card p-5 sm:flex-row sm:items-center sm:justify-between dark:border-sidebar-border">
                <div className="flex flex-col gap-2">
                    <span className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        Company join code
                    </span>

                    {enabled && code ? (
                        <div className="flex flex-wrap items-center gap-3">
                            <span className="font-mono text-3xl font-semibold tracking-[0.28em] tabular-nums sm:text-4xl">
                                {code}
                            </span>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-8 text-muted-foreground"
                                onClick={copy}
                                aria-label={
                                    copied
                                        ? 'Join code copied'
                                        : 'Copy join code'
                                }
                            >
                                {copied ? (
                                    <Check className="size-4 text-[#0ABFBF]" />
                                ) : (
                                    <Copy className="size-4" />
                                )}
                            </Button>
                        </div>
                    ) : (
                        <span className="font-mono text-3xl font-semibold tracking-[0.28em] text-muted-foreground/40 line-through sm:text-4xl">
                            {code ?? '———————'}
                        </span>
                    )}

                    <p className="max-w-md text-sm text-muted-foreground">
                        {enabled
                            ? 'Staff enter this in the SYNAPSE app to reach your company. If their email matches a record here they join straight away; anyone else waits for you below.'
                            : 'Joining by code is off. Invite people individually instead — their invitations still work.'}
                    </p>
                </div>

                {canManage && (
                    <div className="flex items-center gap-4 sm:flex-col sm:items-end">
                        <label className="flex items-center gap-2 text-sm">
                            <Switch
                                checked={enabled}
                                onCheckedChange={toggle}
                                aria-label="Allow people to join with the company code"
                            />
                            <span className="text-muted-foreground">
                                Allow joining by code
                            </span>
                        </label>

                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setConfirmRotate(true)}
                        >
                            <RefreshCw className="size-4" />
                            New code
                        </Button>
                    </div>
                )}
            </div>

            <ConfirmDialog
                open={confirmRotate}
                onOpenChange={setConfirmRotate}
                title="Generate a new join code?"
                description="The current code stops working immediately, so anyone still holding it will need the new one. Invitations you've already sent are unaffected."
                confirmLabel="Generate new code"
                processing={rotating}
                onConfirm={rotate}
            />
        </>
    );
}
