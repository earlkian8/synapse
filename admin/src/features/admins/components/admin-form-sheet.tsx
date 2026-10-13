import { AtSign, UserRound } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import IconInput from '@/components/icon-input';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { ApiError } from '@/lib/api';
import type { Admin, AdminRole } from '@/lib/types';
import { cn } from '@/lib/utils';
import type { AdminInput } from '../api';

const ROLES: { value: AdminRole; label: string; description: string }[] = [
    {
        value: 'admin',
        label: 'Admin',
        description: 'Watches organisations and accounts.',
    },
    {
        value: 'super_admin',
        label: 'Super admin',
        description: 'Also manages admin accounts and reads the activity log.',
    },
];

/**
 * Adding or editing an admin: name, email and role. No password field — the
 * portal issues a one-time one when the account is created.
 */
export function AdminFormSheet({
    open,
    admin,
    onOpenChange,
    onSubmit,
}: {
    open: boolean;
    admin: Admin | null;
    onOpenChange: (open: boolean) => void;
    onSubmit: (input: AdminInput) => Promise<void>;
}) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent side="right" className="w-full gap-0 p-0 sm:max-w-md">
                {/* Keyed so each opening starts from the record, not the last draft. */}
                <AdminForm
                    key={`${admin?.id ?? 'new'}-${open}`}
                    admin={admin}
                    onSubmit={onSubmit}
                    onCancel={() => onOpenChange(false)}
                />
            </SheetContent>
        </Sheet>
    );
}

function AdminForm({
    admin,
    onSubmit,
    onCancel,
}: {
    admin: Admin | null;
    onSubmit: (input: AdminInput) => Promise<void>;
    onCancel: () => void;
}) {
    const [name, setName] = useState(admin?.name ?? '');
    const [email, setEmail] = useState(admin?.email ?? '');
    const [role, setRole] = useState<AdminRole>(admin?.role ?? 'admin');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setBusy(true);
        setError(null);

        try {
            await onSubmit({ name: name.trim(), email: email.trim(), role });
        } catch (failure) {
            setError(failure instanceof ApiError ? failure : null);
        } finally {
            setBusy(false);
        }
    };

    const roleLocked = admin?.is_self === true;
    const general =
        error &&
        !error.field('name') &&
        !error.field('email') &&
        !error.field('role')
            ? error.message
            : null;

    return (
        <form onSubmit={submit} className="flex h-full flex-col" noValidate>
            <SheetHeader className="border-b border-border px-6 py-5">
                <SheetTitle>
                    {admin ? `Edit ${admin.name}` : 'Add an admin'}
                </SheetTitle>
                <SheetDescription>
                    {admin
                        ? 'Changes apply at their next request. A role change takes effect at once.'
                        : 'They get a one-time password to sign in with, then choose their own and set up an authenticator.'}
                </SheetDescription>
            </SheetHeader>

            <div className="flex-1 space-y-5 overflow-y-auto px-6 py-6">
                <div className="grid gap-2">
                    <Label htmlFor="admin-name">Full name</Label>
                    <IconInput
                        icon={UserRound}
                        id="admin-name"
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                        autoComplete="off"
                        autoFocus
                    />
                    <InputError message={error?.field('name')} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="admin-email">Email address</Label>
                    <IconInput
                        icon={AtSign}
                        id="admin-email"
                        type="email"
                        value={email}
                        onChange={(event) => setEmail(event.target.value)}
                        autoComplete="off"
                    />
                    <InputError message={error?.field('email')} />
                </div>

                <fieldset className="grid gap-2" disabled={roleLocked}>
                    <legend className="mb-2 text-sm font-medium">Role</legend>
                    {ROLES.map((option) => (
                        <label
                            key={option.value}
                            className={cn(
                                'flex cursor-pointer items-start gap-3 rounded-lg border px-3 py-2.5 transition-colors has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50',
                                role === option.value
                                    ? 'border-[#0ABFBF] bg-[#0ABFBF]/6'
                                    : 'border-border hover:bg-muted/40',
                                roleLocked && 'cursor-not-allowed opacity-60',
                            )}
                        >
                            <input
                                type="radio"
                                name="role"
                                value={option.value}
                                checked={role === option.value}
                                onChange={() => setRole(option.value)}
                                className="mt-1 accent-[#0ABFBF]"
                            />
                            <span>
                                <span className="block text-sm font-medium">
                                    {option.label}
                                </span>
                                <span className="block text-xs text-muted-foreground">
                                    {option.description}
                                </span>
                            </span>
                        </label>
                    ))}
                    {roleLocked && (
                        <p className="text-xs text-muted-foreground">
                            Another super admin changes your role.
                        </p>
                    )}
                    <InputError message={error?.field('role')} />
                </fieldset>

                {general && <InputError message={general} />}
            </div>

            <SheetFooter className="flex-row justify-end gap-2 border-t border-border px-6 py-4">
                <Button
                    type="button"
                    variant="outline"
                    onClick={onCancel}
                    disabled={busy}
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    disabled={busy || name.trim() === '' || email.trim() === ''}
                >
                    {busy && <Spinner />}
                    {admin ? 'Save changes' : 'Add admin'}
                </Button>
            </SheetFooter>
        </form>
    );
}
