import { Link, router, usePage } from '@inertiajs/react';
import {
    Building2,
    Check,
    ChevronsUpDown,
    LayoutGrid,
    Plus,
} from 'lucide-react';
import { useState } from 'react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarMenuButton, useSidebar } from '@/components/ui/sidebar';
import { CreateWorkspaceDialog } from '@/features/workspaces/components/create-workspace-dialog';
import { useIsMobile } from '@/hooks/use-mobile';
import { usePermissions } from '@/hooks/use-permissions';
import type { Organization } from '@/types/auth';

/**
 * The sidebar brand, as a workspace switcher. A user can belong to more than one
 * company (ADR 0023); this lists their organisations and switches the active one
 * by posting to `organization.switch`, which rebinds the tenant server-side.
 *
 * Companies read as rounded *squares* here, deliberately distinct from the round
 * user avatar in the footer menu.
 *
 * "Create workspace" sits under the list, where people look for it in tools
 * that work this way (ADR 0089): adding a company is something you do to the
 * list of companies, not to the one you are in.
 */
export function WorkspaceSwitcher() {
    const { auth } = usePage().props;
    const { state } = useSidebar();
    const isMobile = useIsMobile();
    const { can } = usePermissions();

    const [creating, setCreating] = useState(false);

    const active = auth.organization;
    const organizations = auth.organizations ?? [];

    const switchTo = (id: number) => {
        if (id === active?.id) {
            return;
        }

        router.post(
            '/organization/switch',
            { organization_id: id },
            { preserveScroll: false },
        );
    };

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <SidebarMenuButton
                        size="lg"
                        className="data-[state=open]:bg-sidebar-accent"
                        data-test="workspace-switcher"
                    >
                        <OrgMark organization={active} />
                        <div className="ml-1 grid flex-1 text-left leading-none">
                            <span className="truncate text-sm font-semibold text-sidebar-foreground">
                                {active?.name ?? 'No workspace'}
                            </span>
                            <span className="mt-0.5 truncate text-[10px] tracking-wide text-sidebar-foreground/50">
                                {organizations.length}{' '}
                                {organizations.length === 1
                                    ? 'workspace'
                                    : 'workspaces'}
                            </span>
                        </div>
                        <ChevronsUpDown className="ml-auto size-4" />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    className="w-(--radix-dropdown-menu-trigger-width) min-w-60 rounded-lg"
                    align="start"
                    side={
                        isMobile
                            ? 'bottom'
                            : state === 'collapsed'
                              ? 'right'
                              : 'bottom'
                    }
                >
                    <DropdownMenuLabel className="text-xs text-muted-foreground">
                        {organizations.length > 1
                            ? 'Switch workspace'
                            : 'Your workspace'}
                    </DropdownMenuLabel>
                    {organizations.map((organization) => (
                        <DropdownMenuItem
                            key={organization.id}
                            onClick={() => switchTo(organization.id)}
                            className="gap-2"
                        >
                            <OrgMark organization={organization} size="sm" />
                            <span className="flex-1 truncate">
                                {organization.name}
                            </span>
                            {organization.id === active?.id && (
                                <Check className="size-4 text-[#0ABFBF]" />
                            )}
                        </DropdownMenuItem>
                    ))}

                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        onSelect={() => setCreating(true)}
                        className="gap-2"
                        data-test="create-workspace"
                    >
                        <Plus className="size-4" />
                        <span>Create workspace</span>
                    </DropdownMenuItem>
                    {organizations.length > 1 && (
                        <DropdownMenuItem asChild className="gap-2">
                            <Link href="/workspaces">
                                <LayoutGrid className="size-4" />
                                <span>Browse all workspaces</span>
                            </Link>
                        </DropdownMenuItem>
                    )}

                    {can('setup.company.view') && (
                        <>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem asChild className="gap-2">
                                <Link href="/setup/company" prefetch>
                                    <Building2 className="size-4" />
                                    <span>Company profile</span>
                                </Link>
                            </DropdownMenuItem>
                        </>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>

            <CreateWorkspaceDialog open={creating} onOpenChange={setCreating} />
        </>
    );
}

/** A company mark — rounded square logo with an initials fallback. */
function OrgMark({
    organization,
    size = 'md',
}: {
    organization: Organization | null;
    size?: 'sm' | 'md';
}) {
    const logo = organization?.logo_url ?? null;
    const initials = organization?.initials ?? 'CO';
    const name = organization?.name ?? 'Your Company';
    const dimension = size === 'sm' ? 'size-6' : 'size-8';

    return (
        <div
            className={`flex aspect-square ${dimension} flex-shrink-0 items-center justify-center overflow-hidden rounded-lg bg-sidebar-accent ring-1 ring-sidebar-border`}
        >
            {logo ? (
                <img
                    src={logo}
                    alt={`${name} logo`}
                    className={`${dimension} object-contain`}
                />
            ) : (
                <span className="text-[10px] font-semibold tracking-wide text-sidebar-foreground/80 uppercase">
                    {initials}
                </span>
            )}
        </div>
    );
}
