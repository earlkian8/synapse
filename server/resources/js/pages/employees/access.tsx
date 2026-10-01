import { Head, router, usePage } from '@inertiajs/react';
import { Inbox, MailX, Send, ShieldCheck } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    DataTable,
    EmptyTableRow,
    PageBody,
    PageHeader,
    SearchInput,
    TableCard,
    TablePagination,
    useClientPagination,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
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

    // The backlog can be the whole workforce, so it is searched and paged.
    const [search, setSearch] = useState('');
    const matching = useMemo(() => {
        const needle = search.trim().toLowerCase();

        return needle === ''
            ? uninvited
            : uninvited.filter((employee) =>
                  [employee.full_name, employee.employee_no, employee.email]
                      .filter(Boolean)
                      .some((field) => field!.toLowerCase().includes(needle)),
              );
    }, [uninvited, search]);
    const backlog = useClientPagination(matching, search);

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

            <PageBody>
                <PageHeader
                    back={{
                        href: employeeRoutes.index,
                        label: 'Back to employees',
                    }}
                    title="App access"
                    description="People create their own SYNAPSE accounts and connect to your company from the app. You decide who gets linked to which employee record."
                />

                <JoinCodeCard
                    code={joinCode.code}
                    enabled={joinCode.enabled}
                    canManage={can.manageJoinCode}
                />

                {/* ── Waiting on you ─────────────────────────────────────── */}
                {requests.length > 0 && (
                    <TableCard
                        title="Waiting to join"
                        count={requests.length}
                        description="They used your join code but couldn't be matched to a record automatically. Link them to the right employee, or turn them away."
                        className="border-[#0ABFBF]/40 shadow-sm dark:border-[#0ABFBF]/40"
                    >
                        <DataTable>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Person</TableHead>
                                    <TableHead>Email</TableHead>
                                    <TableHead>Asked</TableHead>
                                    <TableHead className="text-right">
                                        Decision
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {requests.map((request) => (
                                    <TableRow key={request.id}>
                                        <TableCell>
                                            <Person
                                                name={
                                                    request.user?.full_name ??
                                                    '—'
                                                }
                                                initials={(
                                                    request.user?.full_name ??
                                                    '—'
                                                )
                                                    .slice(0, 2)
                                                    .toUpperCase()}
                                                photo={
                                                    request.user?.avatar ?? null
                                                }
                                            />
                                        </TableCell>
                                        <TableCell className="text-sm text-muted-foreground">
                                            {request.user?.email ?? '—'}
                                        </TableCell>
                                        <TableCell className="text-sm text-muted-foreground">
                                            {request.requested_human ?? '—'}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <div className="inline-flex items-center gap-1.5">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="h-8 text-muted-foreground"
                                                    onClick={() =>
                                                        setDeclining(request)
                                                    }
                                                >
                                                    Decline
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    className="h-8"
                                                    onClick={() => {
                                                        setLinking(request);
                                                        setLinkOpen(true);
                                                    }}
                                                >
                                                    Review
                                                </Button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </DataTable>
                    </TableCard>
                )}

                {/* ── Waiting on them ────────────────────────────────────── */}
                {invitations.length > 0 && (
                    <TableCard
                        title="Invitations sent"
                        count={invitations.length}
                        description="Waiting for these people to accept. Sending again issues a new code and retires the old one."
                    >
                        <DataTable>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Employee</TableHead>
                                    <TableHead>Sent to</TableHead>
                                    <TableHead>Expires</TableHead>
                                    <TableHead>Code</TableHead>
                                    <TableHead className="text-right">
                                        Actions
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {invitations.map((invitation) => (
                                    <TableRow key={invitation.id}>
                                        <TableCell>
                                            <Person
                                                name={
                                                    invitation.employee
                                                        ?.full_name ?? '—'
                                                }
                                                initials={
                                                    invitation.employee
                                                        ?.initials ?? '—'
                                                }
                                                photo={
                                                    invitation.employee
                                                        ?.photo ?? null
                                                }
                                                detail={
                                                    invitation.employee
                                                        ?.employee_no
                                                }
                                            />
                                        </TableCell>
                                        <TableCell className="text-sm text-muted-foreground">
                                            {invitation.email}
                                        </TableCell>
                                        <TableCell className="text-sm text-muted-foreground">
                                            {invitation.expires_human ?? '—'}
                                        </TableCell>
                                        <TableCell>
                                            <code className="rounded-md border border-sidebar-border/70 bg-muted/60 px-2 py-0.5 font-mono text-xs tracking-[0.16em] dark:border-sidebar-border">
                                                {invitation.code}
                                            </code>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <div className="inline-flex items-center gap-1">
                                                {invitation.employee && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="h-8"
                                                        onClick={() =>
                                                            router.post(
                                                                employeeRoutes.invite(
                                                                    invitation
                                                                        .employee!
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
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </DataTable>
                    </TableCard>
                )}

                {/* ── Waiting on nobody ──────────────────────────────────── */}
                <div className="flex flex-col gap-3">
                    <TableCard
                        title="Not invited yet"
                        count={uninvited.length}
                        description="Employee records that nobody has been invited to claim."
                        actions={
                            <>
                                {uninvited.length > 0 && (
                                    <SearchInput
                                        value={search}
                                        onSearch={setSearch}
                                        delay={0}
                                        placeholder="Search name, no., email…"
                                        label="Search employees not invited yet"
                                        className="sm:w-56"
                                    />
                                )}
                                {can.invite && invitableCount > 0 && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={inviteEveryone}
                                    >
                                        <Send className="size-4" />
                                        Invite all {invitableCount}
                                    </Button>
                                )}
                            </>
                        }
                    >
                        <DataTable>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Employee</TableHead>
                                    <TableHead>Position</TableHead>
                                    <TableHead>Email</TableHead>
                                    <TableHead className="text-right">
                                        Invitation
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {uninvited.length === 0 && (
                                    <EmptyTableRow
                                        colSpan={4}
                                        icon={ShieldCheck}
                                        title="Everyone has been invited"
                                        description="Every employee record either has app access or an invitation on its way."
                                    />
                                )}
                                {uninvited.length > 0 &&
                                    matching.length === 0 && (
                                        <EmptyTableRow
                                            colSpan={4}
                                            icon={Inbox}
                                            title="Nobody matches"
                                            description="Try another name, number or email."
                                        />
                                    )}
                                {backlog.rows.map((employee) => (
                                    <TableRow key={employee.id}>
                                        <TableCell>
                                            <Person
                                                name={employee.full_name}
                                                initials={employee.initials}
                                                photo={employee.photo}
                                                detail={employee.employee_no}
                                            />
                                        </TableCell>
                                        <TableCell className="text-sm text-muted-foreground">
                                            {employee.position ?? '—'}
                                        </TableCell>
                                        <TableCell className="text-sm text-muted-foreground">
                                            {employee.email ?? (
                                                <span className="text-xs">
                                                    No email address on file
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {employee.email ? (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    className="h-8"
                                                    disabled={!can.invite}
                                                    onClick={() =>
                                                        invite(employee)
                                                    }
                                                >
                                                    <Send className="size-4" />
                                                    Invite
                                                </Button>
                                            ) : (
                                                <span className="text-xs text-muted-foreground">
                                                    Needs an email
                                                </span>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </DataTable>
                    </TableCard>

                    {matching.length > 0 && (
                        <TablePagination
                            meta={backlog.meta}
                            perPage={backlog.perPage}
                            onPage={backlog.setPage}
                            onPerPage={backlog.setPerPage}
                        />
                    )}
                </div>
            </PageBody>

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

/** A person in a row: avatar, name, and a detail line. */
function Person({
    name,
    initials,
    photo,
    detail,
}: {
    name: string;
    initials: string;
    photo: string | null;
    detail?: string | null;
}) {
    return (
        <div className="flex min-w-0 items-center gap-2.5">
            <EmployeeAvatar name={name} initials={initials} photo={photo} />
            <div className="min-w-0">
                <p className="truncate text-sm font-medium">{name}</p>
                {detail && (
                    <p className="truncate font-mono text-[11px] text-muted-foreground">
                        {detail}
                    </p>
                )}
            </div>
        </div>
    );
}

EmployeeAccess.layout = {
    breadcrumbs: [
        { title: 'Employees', href: '/employees' },
        { title: 'App access', href: '/employees/access' },
    ],
};
