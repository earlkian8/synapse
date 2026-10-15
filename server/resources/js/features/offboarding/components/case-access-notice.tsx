import { router } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { userRoutes } from '@/features/users/routes';
import type { CaseAccess } from '../types';
import { ConfirmDialog } from './confirm-dialog';

/**
 * After an exit is completed, the leaver can still sign in to this workspace —
 * on purpose: they answer the exit interview and download their certificate
 * with it. This says so and lets HR end it when the time comes (ADR 0089),
 * through the same action as the User Management screen.
 */
export function CaseAccessNotice({
    access,
    name,
}: {
    access: CaseAccess;
    name: string;
}) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const remove = () =>
        router.delete(userRoutes.destroy(access.user_id), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setOpen(false);
            },
        });

    return (
        <div className="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card px-4 py-3 shadow-sm sm:flex-row sm:items-center dark:border-sidebar-border">
            <KeyRound className="hidden size-4 shrink-0 text-muted-foreground sm:block" />
            <div className="min-w-0 flex-1 text-sm">
                <p className="font-medium">
                    {name} can still sign in to this workspace
                </p>
                <p className="mt-0.5 text-muted-foreground">
                    {access.can_remove
                        ? 'They use it to answer the exit interview and download their certificate. Remove their access once they no longer need it.'
                        : access.reason}
                </p>
            </div>
            {access.can_remove && (
                <Button
                    size="sm"
                    variant="outline"
                    className="shrink-0"
                    onClick={() => setOpen(true)}
                >
                    Remove access
                </Button>
            )}

            <ConfirmDialog
                open={open}
                onOpenChange={setOpen}
                title={`Remove ${name}'s access?`}
                description={
                    access.shared
                        ? `${name} is removed from this workspace and loses their roles here. Their account stays, because they belong to another workspace too.`
                        : `${name}'s account belongs only to this workspace, so it is archived. You can restore it from the Trash Bin.`
                }
                confirmLabel="Remove access"
                destructive
                processing={processing}
                onConfirm={remove}
            />
        </div>
    );
}
