import { useForm } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useEffect } from 'react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { userRoutes } from '../routes';
import type { ManagedUser } from '../types';

type Props = {
    user: ManagedUser | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

function generatePassword(): string {
    const chars =
        'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%';
    const bytes = new Uint32Array(16);
    crypto.getRandomValues(bytes);

    return Array.from(bytes, (n) => chars[n % chars.length]).join('');
}

export function ResetPasswordDialog({ user, open, onOpenChange }: Props) {
    const form = useForm({ password: '', password_confirmation: '' });
    const { data, setData, put, processing, errors, reset, clearErrors } = form;

    useEffect(() => {
        if (!open) {
            reset();
            clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    if (!user) {
        return null;
    }

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        put(userRoutes.password(user.id), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    const autofill = () => {
        const generated = generatePassword();
        setData({ password: generated, password_confirmation: generated });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Reset password</DialogTitle>
                        <DialogDescription>
                            Set a new password for{' '}
                            <span className="font-medium text-foreground">
                                {user.full_name}
                            </span>
                            . They should change it after signing in.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4 py-4">
                        <div className="grid gap-2">
                            <div className="flex items-center justify-between">
                                <Label htmlFor="reset_password">
                                    New password
                                </Label>
                                <button
                                    type="button"
                                    onClick={autofill}
                                    className="inline-flex items-center gap-1 text-xs font-medium text-[#0ABFBF] hover:underline"
                                >
                                    <RefreshCw className="size-3" />
                                    Generate
                                </button>
                            </div>
                            <PasswordInput
                                id="reset_password"
                                value={data.password}
                                onChange={(event) =>
                                    setData('password', event.target.value)
                                }
                                autoComplete="new-password"
                                required
                            />
                            <InputError message={errors.password} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="reset_password_confirmation">
                                Confirm password
                            </Label>
                            <PasswordInput
                                id="reset_password_confirmation"
                                value={data.password_confirmation}
                                onChange={(event) =>
                                    setData(
                                        'password_confirmation',
                                        event.target.value,
                                    )
                                }
                                autoComplete="new-password"
                                required
                            />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                            disabled={processing}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            Reset password
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
