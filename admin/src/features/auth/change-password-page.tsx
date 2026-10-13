import { LogOut } from 'lucide-react';
import { PasswordForm } from '@/features/account/components/password-form';
import { useCurrentAdmin } from '@/hooks/use-current-admin';
import { usePageTitle } from '@/hooks/use-page-title';
import { AuthLayout } from './components/auth-layout';
import { useSignOut } from './use-sign-out';

/**
 * Where an admin lands while their password is one somebody else issued
 * (a new account, or a reset). Nothing else in the portal opens until they
 * choose their own; the API holds the same line.
 */
export function ChangePasswordPage() {
    usePageTitle('Choose a password');

    const admin = useCurrentAdmin();
    const signOut = useSignOut();

    return (
        <AuthLayout stage="session">
            <div className="flex flex-col gap-6">
                <div className="space-y-1.5">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Choose your own password
                    </h1>
                    <p className="text-sm text-balance text-muted-foreground">
                        {admin.name}, your password was set by another admin.
                        Choose one only you know to open the portal. Your
                        current password is the one you just signed in with.
                    </p>
                </div>

                <PasswordForm submitLabel="Save and continue" />

                <button
                    type="button"
                    onClick={() => void signOut()}
                    className="inline-flex items-center justify-center gap-1.5 self-center rounded-md px-2 py-1 text-sm text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                >
                    <LogOut className="size-4" />
                    Sign out
                </button>
            </div>
        </AuthLayout>
    );
}
