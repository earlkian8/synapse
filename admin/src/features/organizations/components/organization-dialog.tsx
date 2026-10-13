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
import { LoadError } from '@/components/page-states';
import {
    Fact,
    FactGrid,
    RecordDialog,
    RecordHeading,
    RecordList,
    RecordSection,
    RecordSkeleton,
} from '@/components/record-dialog';
import {
    formatDate,
    formatDateTime,
    formatNumber,
    formatRelative,
} from '@/lib/format';
import { useOrganization } from '../api';
import { OrganizationMark, OrganizationStatusPill } from './organization-name';

/** One organisation, read-only: its size, activity, contact and people in charge. */
export function OrganizationDialog({
    id,
    onClose,
}: {
    id: number | null;
    onClose: () => void;
}) {
    const organization = useOrganization(id);
    const record = organization.data?.data;

    return (
        <RecordDialog
            open={id !== null}
            onOpenChange={(open) => !open && onClose()}
            title={record?.name ?? 'Organisation'}
            description="The organisation's size, activity, contact details and people in charge."
            header={
                record ? (
                    <RecordHeading
                        leading={
                            <OrganizationMark
                                organization={record}
                                className="size-11 rounded-xl text-sm"
                            />
                        }
                        title={record.name}
                        subtitle={record.legal_name ?? record.slug}
                        badges={
                            <OrganizationStatusPill organization={record} />
                        }
                    />
                ) : undefined
            }
        >
            {organization.isError && (
                <LoadError
                    message={organization.error.message}
                    onRetry={() => void organization.refetch()}
                />
            )}

            {!record && !organization.isError && <RecordSkeleton />}

            {record && (
                <div className="space-y-4">
                    <RecordSection title="Size and activity">
                        <FactGrid>
                            <Fact
                                icon={UsersRound}
                                label="Members"
                                value={formatNumber(record.members_count)}
                            />
                            <Fact
                                icon={Briefcase}
                                label="Employees"
                                value={formatNumber(record.employees_count)}
                            />
                            <Fact
                                icon={Network}
                                label="Departments"
                                value={formatNumber(record.departments_count)}
                            />
                            <Fact
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
                            <Fact
                                icon={CalendarPlus}
                                label="Joined SYNAPSE"
                                value={formatDate(record.created_at)}
                                title={formatDateTime(record.created_at)}
                            />
                            <Fact
                                icon={CalendarCheck}
                                label="Finished setup"
                                value={
                                    record.setup_completed_at
                                        ? formatDate(record.setup_completed_at)
                                        : 'Not yet'
                                }
                                tone={
                                    record.setup_completed_at
                                        ? undefined
                                        : 'warning'
                                }
                            />
                            <Fact
                                icon={Clock}
                                label="Last activity"
                                value={formatRelative(
                                    record.last_activity_at,
                                    'Nothing yet',
                                )}
                                title={formatDateTime(record.last_activity_at)}
                            />
                            <Fact
                                icon={Activity}
                                label="Actions, 30 days"
                                value={formatNumber(record.activity_30d)}
                            />
                            {record.archived_at && (
                                <Fact
                                    icon={Archive}
                                    label="Archived"
                                    value={formatDate(record.archived_at)}
                                    tone="warning"
                                    wide
                                />
                            )}
                        </FactGrid>
                    </RecordSection>

                    <RecordSection title="Contact">
                        <FactGrid>
                            <Fact
                                icon={AtSign}
                                label="Email"
                                value={record.email}
                                title={record.email ?? undefined}
                            />
                            <Fact
                                icon={Phone}
                                label="Phone"
                                value={record.phone}
                            />
                            <Fact
                                icon={Globe}
                                label="Time zone"
                                value={record.timezone}
                            />
                            <Fact
                                icon={Hash}
                                label="Slug"
                                value={record.slug}
                                title={record.slug ?? undefined}
                            />
                            <Fact
                                icon={MapPin}
                                label="Address"
                                value={record.address}
                                wide
                            />
                        </FactGrid>
                    </RecordSection>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <RecordSection title="HR managers">
                            <RecordList empty="Nobody holds the HR Manager role.">
                                {record.owners.map((owner) => (
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
                                ))}
                            </RecordList>
                        </RecordSection>

                        <RecordSection
                            title="Newest members"
                            aside={
                                record.members_count > record.members.length
                                    ? `${record.members.length} of ${formatNumber(record.members_count)}`
                                    : undefined
                            }
                        >
                            <RecordList empty="No members yet.">
                                {record.members.map((member) => (
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
                                ))}
                            </RecordList>
                        </RecordSection>
                    </div>
                </div>
            )}
        </RecordDialog>
    );
}

/** A person in the organisation, opening their account. */
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
        <li>
            <Link
                to={`/accounts?id=${id}`}
                className="flex items-center gap-3 px-3 py-2 transition-colors hover:bg-muted/40 focus-visible:bg-muted/50 focus-visible:outline-none"
            >
                <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-medium">
                        {name}
                    </span>
                    <span className="block truncate text-xs text-muted-foreground">
                        {email}
                    </span>
                </span>
                <span className="max-w-[40%] shrink-0 truncate text-right text-xs text-muted-foreground">
                    {aside}
                </span>
            </Link>
        </li>
    );
}
