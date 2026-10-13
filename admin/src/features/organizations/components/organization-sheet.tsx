import {
    Activity,
    Archive,
    AtSign,
    Briefcase,
    CalendarCheck,
    CalendarPlus,
    Clock,
    DoorOpen,
    Globe,
    Hash,
    MapPin,
    Network,
    Phone,
    UsersRound,
} from 'lucide-react';
import { Link } from 'react-router';
import { DetailGroup, DetailRow } from '@/components/detail-list';
import { LoadError } from '@/components/page-states';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import {
    formatDate,
    formatDateTime,
    formatNumber,
    formatRelative,
} from '@/lib/format';
import { useOrganization } from '../api';
import { OrganizationMark, OrganizationStatusPill } from './organization-name';

/** One organisation, read-only: its profile, its size, its people in charge. */
export function OrganizationSheet({
    id,
    onClose,
}: {
    id: number | null;
    onClose: () => void;
}) {
    const organization = useOrganization(id);
    const record = organization.data?.data;

    return (
        <Sheet open={id !== null} onOpenChange={(open) => !open && onClose()}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-md"
            >
                <SheetHeader className="sr-only">
                    <SheetTitle>{record?.name ?? 'Organisation'}</SheetTitle>
                    <SheetDescription>
                        The organisation's profile, size and people in charge.
                    </SheetDescription>
                </SheetHeader>

                {organization.isError && (
                    <div className="p-6">
                        <LoadError
                            message={organization.error.message}
                            onRetry={() => void organization.refetch()}
                        />
                    </div>
                )}

                {!record && !organization.isError && (
                    <div className="space-y-4 p-6">
                        <Skeleton className="h-14 w-full rounded-xl" />
                        <Skeleton className="h-40 w-full rounded-xl" />
                        <Skeleton className="h-40 w-full rounded-xl" />
                    </div>
                )}

                {record && (
                    <>
                        <div className="border-b border-border bg-muted/30 px-6 py-6">
                            <div className="flex items-start gap-4">
                                <OrganizationMark
                                    organization={record}
                                    className="size-14 rounded-xl text-base"
                                />
                                <div className="min-w-0 flex-1">
                                    <h2 className="truncate text-lg font-semibold">
                                        {record.name}
                                    </h2>
                                    <p className="truncate text-sm text-muted-foreground">
                                        {record.legal_name ?? record.slug}
                                    </p>
                                    <div className="mt-2">
                                        <OrganizationStatusPill
                                            organization={record}
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div className="space-y-6 px-6 py-6">
                            <DetailGroup title="Size">
                                <DetailRow
                                    icon={UsersRound}
                                    label="Members"
                                    value={formatNumber(record.members_count)}
                                />
                                <DetailRow
                                    icon={Briefcase}
                                    label="Employees"
                                    value={formatNumber(record.employees_count)}
                                />
                                <DetailRow
                                    icon={Network}
                                    label="Departments"
                                    value={formatNumber(
                                        record.departments_count,
                                    )}
                                />
                                <DetailRow
                                    icon={DoorOpen}
                                    label="Waiting to join"
                                    value={formatNumber(
                                        record.pending_join_requests,
                                    )}
                                    tone={
                                        record.pending_join_requests > 0
                                            ? 'warning'
                                            : undefined
                                    }
                                />
                            </DetailGroup>

                            <DetailGroup title="Activity">
                                <DetailRow
                                    icon={CalendarPlus}
                                    label="Joined SYNAPSE"
                                    value={formatDate(record.created_at)}
                                    title={formatDateTime(record.created_at)}
                                />
                                <DetailRow
                                    icon={CalendarCheck}
                                    label="Finished setup"
                                    value={
                                        record.setup_completed_at
                                            ? formatDate(
                                                  record.setup_completed_at,
                                              )
                                            : 'Not yet'
                                    }
                                    tone={
                                        record.setup_completed_at
                                            ? undefined
                                            : 'warning'
                                    }
                                />
                                <DetailRow
                                    icon={Clock}
                                    label="Last activity"
                                    value={formatRelative(
                                        record.last_activity_at,
                                        'Nothing yet',
                                    )}
                                    title={formatDateTime(
                                        record.last_activity_at,
                                    )}
                                />
                                <DetailRow
                                    icon={Activity}
                                    label="Actions, last 30 days"
                                    value={formatNumber(record.activity_30d)}
                                />
                                {record.archived_at && (
                                    <DetailRow
                                        icon={Archive}
                                        label="Archived"
                                        value={formatDate(record.archived_at)}
                                        tone="warning"
                                    />
                                )}
                            </DetailGroup>

                            <DetailGroup title="Contact">
                                <DetailRow
                                    icon={AtSign}
                                    label="Email"
                                    value={record.email}
                                />
                                <DetailRow
                                    icon={Phone}
                                    label="Phone"
                                    value={record.phone}
                                />
                                <DetailRow
                                    icon={MapPin}
                                    label="Address"
                                    value={record.address}
                                    title={record.address ?? undefined}
                                />
                                <DetailRow
                                    icon={Globe}
                                    label="Time zone"
                                    value={record.timezone}
                                />
                                <DetailRow
                                    icon={Hash}
                                    label="Slug"
                                    value={record.slug}
                                />
                            </DetailGroup>

                            <DetailGroup title="HR managers">
                                {record.owners.length === 0 ? (
                                    <p className="px-2 py-1 text-sm text-muted-foreground">
                                        Nobody holds the HR Manager role.
                                    </p>
                                ) : (
                                    record.owners.map((owner) => (
                                        <PersonRow
                                            key={owner.id}
                                            id={owner.id}
                                            name={owner.full_name}
                                            email={owner.email}
                                            aside={formatRelative(
                                                owner.last_login_at,
                                                'Never signed in',
                                            )}
                                        />
                                    ))
                                )}
                            </DetailGroup>

                            <DetailGroup
                                title={`Newest members${record.members_count > record.members.length ? ` (${record.members.length} of ${formatNumber(record.members_count)})` : ''}`}
                            >
                                {record.members.length === 0 ? (
                                    <p className="px-2 py-1 text-sm text-muted-foreground">
                                        No members yet.
                                    </p>
                                ) : (
                                    record.members.map((member) => (
                                        <PersonRow
                                            key={member.id}
                                            id={member.id}
                                            name={member.full_name}
                                            email={member.email}
                                            aside={
                                                member.is_active
                                                    ? member.roles.join(', ') ||
                                                      'No role'
                                                    : 'Deactivated'
                                            }
                                        />
                                    ))
                                )}
                            </DetailGroup>
                        </div>
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}

/** A person in the organisation, linking to their account. */
function PersonRow({
    id,
    name,
    email,
    aside,
}: {
    id: number;
    name: string;
    email: string;
    aside: string;
}) {
    return (
        <Link
            to={`/accounts?id=${id}`}
            className="flex items-center justify-between gap-3 rounded-lg px-2 py-2 hover:bg-muted/40 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
        >
            <span className="min-w-0">
                <span className="block truncate text-sm font-medium">
                    {name}
                </span>
                <span className="block truncate text-xs text-muted-foreground">
                    {email}
                </span>
            </span>
            <span className="shrink-0 text-right text-xs text-muted-foreground">
                {aside}
            </span>
        </Link>
    );
}
