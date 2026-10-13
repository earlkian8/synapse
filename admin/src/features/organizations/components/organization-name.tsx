import { useState } from 'react';
import { StatusPill } from '@/components/status-pill';
import { cn } from '@/lib/utils';
import { organizationStatus } from '../api';
import type { Organization } from '../api';

/** An organisation as a row names it: its logo (or initials), name and email. */
export function OrganizationName({
    organization,
    compact = false,
}: {
    organization: Organization;
    compact?: boolean;
}) {
    return (
        <span className="flex min-w-0 items-center gap-3">
            <OrganizationMark
                organization={organization}
                className={compact ? 'size-7' : 'size-9'}
            />
            <span className="min-w-0">
                <span className="block truncate font-medium">
                    {organization.name}
                </span>
                {!compact && (
                    <span className="block truncate text-xs text-muted-foreground">
                        {organization.email ??
                            organization.slug ??
                            'No contact email'}
                    </span>
                )}
            </span>
        </span>
    );
}

/** The company's logo from the ERP, or its initials on the brand navy. */
export function OrganizationMark({
    organization,
    className,
}: {
    organization: Organization;
    className?: string;
}) {
    const [failed, setFailed] = useState(false);

    if (organization.logo_url && !failed) {
        return (
            <img
                src={organization.logo_url}
                alt=""
                referrerPolicy="no-referrer"
                onError={() => setFailed(true)}
                className={cn(
                    'shrink-0 rounded-lg bg-white object-contain ring-1 ring-border',
                    className,
                )}
            />
        );
    }

    return (
        <span
            aria-hidden
            className={cn(
                'flex shrink-0 items-center justify-center rounded-lg bg-[#0F2044] text-[11px] font-semibold text-white',
                className,
            )}
        >
            {organization.initials}
        </span>
    );
}

/** Ready, in setup, or archived. */
export function OrganizationStatusPill({
    organization,
}: {
    organization: Organization;
}) {
    const status = organizationStatus(organization);

    if (status === 'archived') {
        return <StatusPill tone="neutral">Archived</StatusPill>;
    }

    return status === 'ready' ? (
        <StatusPill tone="emerald">Ready</StatusPill>
    ) : (
        <StatusPill tone="amber">In setup</StatusPill>
    );
}
