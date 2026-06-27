import { KeyRound, Mail, TriangleAlert, UserPlus } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import type { ManagedEmployee } from '../types';

type Props = {
    employee: ManagedEmployee | null;
    open: boolean;
    processing: boolean;
    onOpenChange: (open: boolean) => void;
    onConfirm: () => void;
};

/**
 * Right-side confirmation drawer for resetting an employee's mobile-app login.
 * Generates a fresh temporary password and emails it to the employee; provisions
 * the account first if they never had one.
 */
export function EmployeeResetPasswordSheet({
    employee,
    open,
    processing,
    onOpenChange,
    onConfirm,
}: Props) {
    const email = employee?.email ?? null;
    const hasAccount = Boolean(employee?.user_id);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent side="right" className="w-full gap-0 sm:max-w-md">
                <SheetHeader>
                    <div className="flex size-11 items-center justify-center rounded-xl bg-primary/10 text-primary">
                        <KeyRound className="size-5" />
                    </div>
                    <SheetTitle className="mt-3">Reset password</SheetTitle>
                    <SheetDescription>
                        {employee
                            ? `Generate a new temporary password for ${employee.full_name} and email it to them so they can sign in to the SYNAPSE app.`
                            : ''}
                    </SheetDescription>
                </SheetHeader>

                <div className="flex flex-col gap-3 px-4">
                    {email ? (
                        <div className="flex items-center gap-3 rounded-lg border bg-muted/40 p-3">
                            <Mail className="size-4 shrink-0 text-muted-foreground" />
                            <div className="min-w-0">
                                <p className="text-xs text-muted-foreground">
                                    Credentials will be sent to
                                </p>
                                <p className="truncate text-sm font-medium">
                                    {email}
                                </p>
                            </div>
                        </div>
                    ) : (
                        <div className="flex items-center gap-3 rounded-lg border border-amber-300/60 bg-amber-50 p-3 text-amber-900 dark:border-amber-400/30 dark:bg-amber-950/40 dark:text-amber-200">
                            <TriangleAlert className="size-4 shrink-0" />
                            <p className="text-sm">
                                This employee has no email address on file, so
                                credentials can’t be sent.
                            </p>
                        </div>
                    )}

                    {email && !hasAccount && (
                        <div className="flex items-center gap-3 rounded-lg border bg-muted/40 p-3 text-muted-foreground">
                            <UserPlus className="size-4 shrink-0" />
                            <p className="text-sm">
                                No login account exists yet — one will be
                                created with the <code>staff</code> role.
                            </p>
                        </div>
                    )}
                </div>

                <SheetFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={processing}
                    >
                        Cancel
                    </Button>
                    <Button onClick={onConfirm} disabled={processing || !email}>
                        {processing && <Spinner />}
                        Send credentials
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
