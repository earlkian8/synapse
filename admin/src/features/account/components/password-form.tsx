import { useQueryClient } from '@tanstack/react-query';
import { Lock } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import PasswordRequirements from '@/components/password-requirements';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { changePassword } from '@/features/auth/api';
import type { MeResponse } from '@/features/auth/api';
import { ApiError } from '@/lib/api';
import { passwordMeetsRules } from '@/lib/password-rules';
import { getSession } from '@/lib/session';

/**
 * Choosing a new password. Changing it ends every other session of this admin,
 * so whoever knew the old one is shut out at once.
 */
export function PasswordForm({
    submitLabel = 'Change password',
}: {
    submitLabel?: string;
}) {
    const queryClient = useQueryClient();
    const [current, setCurrent] = useState('');
    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);

    const mismatch = confirmation !== '' && confirmation !== password;

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setBusy(true);
        setError(null);

        try {
            const result = await changePassword({
                current_password: current,
                password,
                password_confirmation: confirmation,
            });

            setCurrent('');
            setPassword('');
            setConfirmation('');
            toast.success('Password changed', {
                description: 'Your other sessions were signed out.',
            });
            queryClient.setQueryData<MeResponse>(
                ['me', getSession()?.token],
                (old) => (old ? { ...old, admin: result.admin } : old),
            );
        } catch (failure) {
            setError(failure instanceof ApiError ? failure : null);

            if (
                failure instanceof ApiError &&
                Object.keys(failure.errors).length === 0
            ) {
                toast.error(failure.message);
            }
        } finally {
            setBusy(false);
        }
    };

    return (
        <form onSubmit={submit} className="grid gap-5" noValidate>
            <div className="grid gap-2">
                <Label htmlFor="current_password">Current password</Label>
                <PasswordInput
                    icon={Lock}
                    id="current_password"
                    autoComplete="current-password"
                    value={current}
                    onChange={(event) => setCurrent(event.target.value)}
                    aria-invalid={Boolean(error?.field('current_password'))}
                />
                <InputError message={error?.field('current_password')} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="password">New password</Label>
                <PasswordInput
                    icon={Lock}
                    id="password"
                    autoComplete="new-password"
                    value={password}
                    onChange={(event) => setPassword(event.target.value)}
                    aria-invalid={Boolean(error?.field('password'))}
                />
                <PasswordRequirements password={password} />
                <InputError message={error?.field('password')} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="password_confirmation">Type it again</Label>
                <PasswordInput
                    icon={Lock}
                    id="password_confirmation"
                    autoComplete="new-password"
                    value={confirmation}
                    onChange={(event) => setConfirmation(event.target.value)}
                    aria-invalid={mismatch}
                />
                <InputError
                    message={
                        mismatch
                            ? "The two passwords don't match yet."
                            : undefined
                    }
                />
            </div>

            <Button
                type="submit"
                className="w-full sm:w-fit"
                disabled={
                    busy ||
                    current === '' ||
                    !passwordMeetsRules(password) ||
                    confirmation !== password
                }
            >
                {busy && <Spinner />}
                {submitLabel}
            </Button>
        </form>
    );
}
