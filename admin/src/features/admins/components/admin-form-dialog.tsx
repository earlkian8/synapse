import { AtSign, UserRound } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import IconInput from '@/components/icon-input';
import InputError from '@/components/input-error';
import { RecordDialog } from '@/components/record-dialog';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
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
        description: 'Also manages admins and the log.',
    },
];

const FORM_ID = 'admin-form';

/**
 * Adding or editing an admin: name, email and role. No password field — the
 * portal issues a one-time one when the account is created.
 */
export function AdminFormDialog({
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
    // Each opening starts from the record, not the last draft: the form is
    // re-keyed when the dialog opens (not when it closes, so it can animate out).
    const [generation, setGeneration] = useState(0);
    const [wasOpen, setWasOpen] = useState(open);

    if (open !== wasOpen) {
        setWasOpen(open);

        if (open) {
            setGeneration((value) => value + 1);
        }
    }

    return (
        <AdminForm
            key={`${admin?.id ?? 'new'}-${generation}`}
            open={open}
            admin={admin}
            onOpenChange={onOpenChange}
            onSubmit={onSubmit}
        />
    );
}

function AdminForm({
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
        <RecordDialog
            open={open}
            onOpenChange={(next) => !busy && onOpenChange(next)}
            title={admin ? `Edit ${admin.name}` : 'Add an admin'}
            description={
                admin
                    ? 'A role change takes effect at their next request.'
                    : 'They get a one-time password, then choose their own and set up an authenticator.'
            }
            size="md"
            footer={
                <>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={busy}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        form={FORM_ID}
                        disabled={
                            busy || name.trim() === '' || email.trim() === ''
                        }
                    >
                        {busy && <Spinner />}
                        {admin ? 'Save changes' : 'Add admin'}
                    </Button>
                </>
            }
        >
            <form
                id={FORM_ID}
                onSubmit={submit}
                className="grid gap-4"
                noValidate
            >
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid content-start gap-1.5">
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

                    <div className="grid content-start gap-1.5">
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
                </div>

                <fieldset className="grid gap-1.5" disabled={roleLocked}>
                    <legend className="mb-1.5 text-sm font-medium">Role</legend>
                    <div className="grid gap-2 sm:grid-cols-2">
                        {ROLES.map((option) => (
                            <label
                                key={option.value}
                                className={cn(
                                    'flex cursor-pointer items-start gap-2.5 rounded-lg border px-3 py-2 transition-colors has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50',
                                    role === option.value
                                        ? 'border-[#0ABFBF] bg-[#0ABFBF]/6'
                                        : 'border-border hover:bg-muted/40',
                                    roleLocked &&
                                        'cursor-not-allowed opacity-60',
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
                    </div>
                    {roleLocked && (
                        <p className="text-xs text-muted-foreground">
                            Another super admin changes your role.
                        </p>
                    )}
                    <InputError message={error?.field('role')} />
                </fieldset>

                {general && <InputError message={general} />}
            </form>
        </RecordDialog>
    );
}
