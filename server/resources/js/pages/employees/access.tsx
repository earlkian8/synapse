import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    DoorOpen,
    Inbox,
    MailX,
    Send,
    ShieldCheck,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/features/employees/components/confirm-dialog';
import { EmployeeAvatar } from '@/features/employees/components/employee-avatar';
import { JoinCodeCard } from '@/features/employees/components/join-code-card';
import { LinkEmployeeDialog } from '@/features/employees/components/link-employee-dialog';
import { employeeRoutes } from '@/features/employees/routes';
import type {
    EmployeeAccessPageProps,
    JoinRequest,
    OutstandingInvitation,
    UnlinkedEmployee,
} from '@/features/employees/types';

/**
 * App Access — the answer to "who can actually sign in?" (ADR 0026).
 *
 * Ordered by who is waiting on whom. Requests come first because somebody is
 * blocked on HR right now; invitations next because HR is waiting on them; the
 * un-invited backlog last because nobody is waiting at all. Each section
 * disappears entirely when empty rather than sitting there as a hollow frame.
 */
export default function EmployeeAccess() {
    const { requests, invitations, unlinked, joinCode, can } =
        usePage<EmployeeAccessPageProps>().props;

    const [linking, setLinking] = useState<JoinRequest | null>(null);
    const [linkOpen, setLinkOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [declining, setDeclining] = useState<JoinRequest | null>(null);
    const [revoking, setRevoking] = useState<OutstandingInvitation | null>(
        null,
    );

    const uninvited = useMemo(
        () => unlinked.filter((employee) => employee.app_access === 'none'),
        [unlinked],
    );

    const busy = {
        preserveScroll: true,
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
    };

    const approve = (employeeId: number) => {
        if (!linking) {
            return;
        }

        router.post(
            employeeRoutes.approveJoinRequest(linking.id),
            { employee_id: employeeId },
            {
                ...busy,
                onFinish: () => {
                    setProcessing(false);
                    setLinkOpen(false);
                    setLinking(null);
                },
            },
        );
    };

    const decline = () => {
        if (!declining) {
            return;
        }

        router.post(
            employeeRoutes.declineJoinRequest(declining.id),
            {},
            {
                ...busy,
                onFinish: () => {
                    setProcessing(false);
                    setDeclining(null);
                },
            },
        );
    };

    const invite = (employee: UnlinkedEmployee) =>
        router.post(
            employeeRoutes.invite(employee.id),
            {},
            { preserveScroll: true },
        );

    const revoke = () => {
        if (!revoking?.employee) {
            return;
        }

        router.delete(employeeRoutes.invite(revoking.employee.id), {
            ...busy,
            onFinish: () => {
                setProcessing(false);
                setRevoking(null);
            },
        });
    };

    const inviteEveryone = () =>
        router.post(
            employeeRoutes.bulk,
            {
                action: 'invite',
                ids: uninvited
                    .filter((employee) => employee.email)
                    .map((employee) => employee.id),
            },
            { preserveScroll: true },
        );

    const invitableCount = uninvited.filter(
        (employee) => employee.email,
    ).length;

    return (
        <>
            <Head title="App access" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-3">
                    <Button
                        variant="ghost"
                        size="sm"
                        asChild
                        className="-ml-2 w-fit text-muted-foreground"
                    >
                        <Link href={employeeRoutes.index}>
                            <ArrowLeft className="size-4" />
                            Employees
                        </Link>
                    </Button>

                    <div className="flex flex-col gap-1">
                        <h1 className="text-xl font-semibold tracking-tight">
                            App access
                        </h1>
                        <p className="max-w-2xl text-sm text-muted-foreground">
                            People create their own SYNAPSE accounts and connect
                            to your company from the app. You decide who gets
                            linked to which employee record.
                        </p>
                    </div>
                </div>

                <JoinCodeCard
                    code={joinCode.code}
                    enabled={joinCode.enabled}
                    canManage={can.manageJoinCode}
                />

                {/* ── Waiting on you ─────────────────────────────────────── */}
                {requests.length > 0 && (
                    <Section
                        icon={DoorOpen}
                        title="Waiting to join"
                        count={requests.length}
                        description="They used your join code but we couldn't match them to a record automatically. Link them to the right employee, or turn them away."
                        emphasised
                    >
                        <ul className="divide-y divide-border">
                            {requests.map((request) => (
                                <li
                                    key={request.id}
                                    className="flex flex-wrap items-center gap-3 px-4 py-3"
                                >
                                    <EmployeeAvatar
                                        name={request.user?.full_name ?? '—'}
                                        initials={(
                                            request.user?.full_name ?? '—'
                                        )
                                            .slice(0, 2)
                                            .toUpperCase()}
                                        photo={request.user?.avatar ?? null}
                                    />
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium">
                                            {request.user?.full_name}
                                        </p>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {request.user?.email} · asked{' '}
                                            {request.requested_human}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="text-muted-foreground"
                                            onClick={() =>
                                                setDeclining(request)
                                            }
                                        >
                                            Decline
                                        </Button>
                                        <Button
                                            size="sm"
                                            onClick={() => {
                                                setLinking(request);
                                                setLinkOpen(true);
                                            }}
                                        >
                                            Review
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </Section>
                )}

                {/* ── Waiting on them ────────────────────────────────────── */}
                {invitations.length > 0 && (
                    <Section
                        icon={Send}
                        title="Invitations sent"
                        count={invitations.length}
                        description="Waiting for these people to accept. Sending again issues a new code and retires the old one."
                    >
                        <ul className="divide-y divide-border">
                            {invitations.map((invitation) => (
                                <li
                                    key={invitation.id}
                                    className="flex flex-wrap items-center gap-3 px-4 py-3"
                                >
                                    <EmployeeAvatar
                                        name={
                                            invitation.employee?.full_name ??
                                            '—'
                                        }
                                        initials={
                                            invitation.employee?.initials ?? '—'
                                        }
                                        photo={
                                            invitation.employee?.photo ?? null
                                        }
                                    />
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium">
                                            {invitation.employee?.full_name}
                                        </p>
                                        <p className="truncate text-xs text-muted-foreground">
                                            Sent to {invitation.email} · expires{' '}
                                            {invitation.expires_human}
                                        </p>
                                    </div>
                                    <code className="rounded-md border border-sidebar-border/70 bg-muted/60 px-2 py-1 font-mono text-xs tracking-[0.16em] dark:border-sidebar-border">
                                        {invitation.code}
                                    </code>
                                    <div className="flex items-center gap-1">
                                        {invitation.employee && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    router.post(
                                                        employeeRoutes.invite(
                                                            invitation.employee!
                                                                .id,
                                                        ),
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Resend
                                            </Button>
                                        )}
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="size-8 text-muted-foreground"
                                            aria-label={`Revoke the invitation for ${invitation.employee?.full_name}`}
                                            onClick={() =>
                                                setRevoking(invitation)
                                            }
                                        >
                                            <MailX className="size-4" />
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </Section>
                )}

                {/* ── Waiting on nobody ──────────────────────────────────── */}
                <Section
                    icon={Inbox}
                    title="Not invited yet"
                    count={uninvited.length}
                    description="Employee records that nobody has been invited to claim."
                    action={
                        can.invite && invitableCount > 0 ? (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={inviteEveryone}
                            >
                                <Send className="size-4" />
                                Invite all {invitableCount}
                            </Button>
                        ) : null
                    }
                >
                    {uninvited.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 px-4 py-12 text-center">
                            <span className="flex size-12 items-center justify-center rounded-full bg-[#0ABFBF]/10">
                                <ShieldCheck className="size-6 text-[#0ABFBF]" />
                            </span>
                            <p className="text-sm font-medium">
                                Everyone has been invited
                            </p>
                            <p className="max-w-xs text-sm text-muted-foreground">
                                Every employee record either has app access or
                                an invitation on its way.
                            </p>
                        </div>
                    ) : (
                        <ul className="divide-y divide-border">
                            {uninvited.map((employee) => (
                                <li
                                    key={employee.id}
                                    className="flex flex-wrap items-center gap-3 px-4 py-3"
                                >
                                    <EmployeeAvatar
                                        name={employee.full_name}
                                        initials={employee.initials}
                                        photo={employee.photo}
                                    />
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium">
                                            {employee.full_name}
                                        </p>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {employee.employee_no}
                                            {employee.position
                                                ? ` · ${employee.position}`
                                                : ''}
                                        </p>
                                    </div>
                                    {employee.email ? (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={!can.invite}
                                            onClick={() => invite(employee)}
                                        >
                                            <Send className="size-4" />
                                            Invite
                                        </Button>
                                    ) : (
                                        <span className="text-xs text-muted-foreground">
                                            No email address on file
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>
            </div>

            <LinkEmployeeDialog
                request={linking}
                candidates={unlinked}
                open={linkOpen}
                processing={processing}
                onOpenChange={setLinkOpen}
                onConfirm={approve}
            />

            <ConfirmDialog
                open={declining !== null}
                onOpenChange={(open) => !open && setDeclining(null)}
                title={`Decline ${declining?.user?.full_name ?? 'this request'}?`}
                description="They'll be told the request wasn't approved. They can ask again with the join code if that was a mistake."
                confirmLabel="Decline request"
                destructive
                processing={processing}
                onConfirm={decline}
            />

            <ConfirmDialog
                open={revoking !== null}
                onOpenChange={(open) => !open && setRevoking(null)}
                title={`Revoke the invitation for ${revoking?.employee?.full_name ?? 'this employee'}?`}
                description="Their code stops working immediately. You can invite them again at any time."
                confirmLabel="Revoke invitation"
                destructive
                processing={processing}
                onConfirm={revoke}
            />
        </>
    );
}

function Section({
    icon: Icon,
    title,
    count,
    description,
    action,
    emphasised = false,
    children,
}: {
    icon: typeof Inbox;
    title: string;
    count: number;
    description: string;
    action?: React.ReactNode;
    emphasised?: boolean;
    children: React.ReactNode;
}) {
    return (
        <section
            className={`overflow-hidden rounded-xl border bg-card ${
                emphasised
                    ? 'border-[#0ABFBF]/40 shadow-sm'
                    : 'border-sidebar-border/70 dark:border-sidebar-border'
            }`}
        >
            <header className="flex flex-wrap items-center gap-3 border-b border-border bg-muted/30 px-4 py-3">
                <Icon
                    className={`size-4 ${emphasised ? 'text-[#0ABFBF]' : 'text-muted-foreground'}`}
                    aria-hidden
                />
                <h2 className="text-sm font-semibold">{title}</h2>
                <span className="rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground tabular-nums">
                    {count}
                </span>
                <p className="w-full text-xs text-muted-foreground sm:w-auto sm:flex-1">
                    {description}
                </p>
                {action}
            </header>
            {children}
        </section>
    );
}

EmployeeAccess.layout = {
    breadcrumbs: [
        { title: 'Employees', href: '/employees' },
        { title: 'App access', href: '/employees/access' },
    ],
};
