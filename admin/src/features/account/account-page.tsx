import { useQueryClient } from '@tanstack/react-query';
import {
    AtSign,
    CalendarPlus,
    Clock,
    Globe,
    Hourglass,
    KeyRound,
    LogOut,
    ShieldCheck,
    Smartphone,
    TimerOff,
    UserRound,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { PageBody, PageHeader } from '@/components/data-table';
import { DetailRow } from '@/components/detail-list';
import { Button } from '@/components/ui/button';
import { revokeOtherSessions } from '@/features/auth/api';
import { useCurrentAdmin } from '@/hooks/use-current-admin';
import { usePageTitle } from '@/hooks/use-page-title';
import { ApiError } from '@/lib/api';
import { formatDate, formatDateTime, formatRelative } from '@/lib/format';
import { useSession } from '@/lib/session';
import { PasswordForm } from './components/password-form';

/**
 * One's own account: who the portal thinks you are, your password, and your
 * sessions. Name, email, role and authenticator are changed by another super
 * admin — nobody grants themselves anything here.
 */
export function AccountPage() {
    usePageTitle('Account and security');

    const admin = useCurrentAdmin();
    const session = useSession();
    const queryClient = useQueryClient();
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);

    const endOthers = async () => {
        setProcessing(true);

        try {
            const result = await revokeOtherSessions();
            toast.success(result.message);
            setConfirming(false);
            await queryClient.invalidateQueries({ queryKey: ['activity'] });
        } catch (failure) {
            toast.error(
                failure instanceof ApiError
                    ? failure.message
                    : 'That did not work. Try again.',
            );
        } finally {
            setProcessing(false);
        }
    };

    return (
        <PageBody className="max-w-4xl">
            <PageHeader
                title="Account and security"
                description="Your admin account, your password and your sessions."
            />

            <Section
                title="Your account"
                description="To change your name, email or role, ask another super admin."
            >
                <DetailRow icon={UserRound} label="Name" value={admin.name} />
                <DetailRow icon={AtSign} label="Email" value={admin.email} />
                <DetailRow
                    icon={ShieldCheck}
                    label="Role"
                    value={admin.role_label}
                />
                <DetailRow
                    icon={Clock}
                    label="Last sign-in"
                    value={formatRelative(admin.last_login_at)}
                    title={formatDateTime(admin.last_login_at)}
                />
                <DetailRow
                    icon={Globe}
                    label="From"
                    value={
                        admin.last_login_ip ? (
                            <span className="font-mono text-xs">
                                {admin.last_login_ip}
                            </span>
                        ) : null
                    }
                />
                <DetailRow
                    icon={CalendarPlus}
                    label="Added"
                    value={formatDate(admin.created_at)}
                />
            </Section>

            <Section
                title="Password"
                description="Changing it signs you out of every other session."
            >
                <div className="px-2 pt-1">
                    <PasswordForm />
                </div>
            </Section>

            <Section
                title="Authenticator"
                description="A code from your phone is asked for at every sign-in."
            >
                <DetailRow
                    icon={Smartphone}
                    label="Status"
                    value="Set up"
                    tone="positive"
                />
                <p className="px-2 pt-1 text-sm text-muted-foreground">
                    New phone, or lost this one? Another super admin can reset
                    it from Admin accounts, and you set it up again at your next
                    sign-in.
                </p>
            </Section>

            <Section
                title="Sessions"
                description="A session lasts eight hours at most, and ends after a stretch with nothing happening."
                action={
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setConfirming(true)}
                    >
                        <LogOut className="size-4" />
                        Sign out other sessions
                    </Button>
                }
            >
                <DetailRow
                    icon={Hourglass}
                    label="This session ends"
                    value={formatDateTime(session?.expiresAt)}
                />
                <DetailRow
                    icon={TimerOff}
                    label="Idle limit"
                    value={session ? `${session.idleMinutes} minutes` : null}
                />
                <DetailRow
                    icon={KeyRound}
                    label="Password last changed"
                    value={formatRelative(admin.password_changed_at, 'Not yet')}
                    title={formatDateTime(admin.password_changed_at)}
                />
            </Section>

            <ConfirmDialog
                open={confirming}
                onOpenChange={(open) => !processing && setConfirming(open)}
                title="Sign out of other sessions?"
                description="Every other browser signed in as you is signed out at once. This one stays signed in."
                confirmLabel="Sign them out"
                processing={processing}
                onConfirm={() => void endOthers()}
            />
        </PageBody>
    );
}

function Section({
    title,
    description,
    action,
    children,
}: {
    title: string;
    description: string;
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
            <div className="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
                <div>
                    <h2 className="text-sm font-semibold">{title}</h2>
                    <p className="text-xs text-muted-foreground">
                        {description}
                    </p>
                </div>
                {action}
            </div>
            <div className="space-y-1 px-3 py-3">{children}</div>
        </section>
    );
}
