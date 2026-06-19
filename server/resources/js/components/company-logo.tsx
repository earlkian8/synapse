import { usePage } from '@inertiajs/react';

/**
 * The sidebar brand: the organisation's logo (or an initials placeholder) and
 * its name, sourced from the shared `auth.organization` prop. Replaces the
 * static product logo so each tenant sees their own company profile.
 */
export default function CompanyLogo() {
    const { auth } = usePage().props;
    const organization = auth.organization;

    const name = organization?.name ?? 'Your Company';
    const logo = organization?.logo_url ?? null;
    const initials = organization?.initials ?? 'CO';

    return (
        <>
            <div className="flex aspect-square size-8 flex-shrink-0 items-center justify-center overflow-hidden rounded-lg bg-sidebar-accent ring-1 ring-sidebar-border">
                {logo ? (
                    <img
                        src={logo}
                        alt={`${name} logo`}
                        className="size-8 object-contain"
                    />
                ) : (
                    <span className="text-xs font-semibold tracking-wide text-sidebar-foreground/80 uppercase">
                        {initials}
                    </span>
                )}
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm leading-none">
                <span className="truncate text-sm font-semibold text-sidebar-foreground">
                    {name}
                </span>
                <span className="mt-0.5 truncate text-[10px] tracking-wide text-sidebar-foreground/50">
                    Company profile
                </span>
            </div>
        </>
    );
}
