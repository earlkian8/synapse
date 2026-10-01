import { Link, usePage } from '@inertiajs/react';
import { Mail } from 'lucide-react';
import CompanyLogo from '@/components/company-logo';
import { NavMain } from '@/components/nav-main';
import { Badge } from '@/components/ui/badge';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { WorkspaceSwitcher } from '@/components/workspace-switcher';
import { tourTarget } from '@/features/product-tour/targets';
import { useAppNavigation } from '@/hooks/use-app-navigation';
import { usePermissions } from '@/hooks/use-permissions';
import { dashboard } from '@/routes';

export function AppSidebar() {
    const { auth } = usePage().props;
    const { can } = usePermissions();

    // Every section the user can see, in sidebar order — see APP_NAVIGATION.
    const groups = useAppNavigation();

    // The brand links to the company profile when the user may view it, else home.
    const brandHref = can('setup.company.view')
        ? '/setup/company'
        : dashboard();

    return (
        <Sidebar collapsible="icon" variant="inset">
            {/* ── Company brand / workspace switcher ── */}
            <SidebarHeader className="border-b border-sidebar-border pb-3">
                <SidebarMenu>
                    <SidebarMenuItem {...tourTarget('workspace')}>
                        {auth.organizations.length > 1 ? (
                            <WorkspaceSwitcher />
                        ) : (
                            <SidebarMenuButton size="lg" asChild>
                                <Link href={brandHref} prefetch>
                                    <CompanyLogo />
                                </Link>
                            </SidebarMenuButton>
                        )}
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            {/* ── Navigation ── */}
            <SidebarContent className="gap-0 py-2">
                {groups.map((group) => (
                    <NavMain
                        key={group.key}
                        label={group.label}
                        items={group.items}
                        tourTarget={`nav-${group.key}`}
                        badge={
                            group.key === 'analytics' ? (
                                <Badge
                                    variant="outline"
                                    className="ml-auto h-4 rounded-full border-[#0ABFBF]/40 bg-[#0ABFBF]/10 px-1.5 py-0 text-[9px] font-semibold tracking-wider text-[#0ABFBF] group-data-[collapsible=icon]:hidden"
                                >
                                    AI
                                </Badge>
                            ) : undefined
                        }
                    />
                ))}
            </SidebarContent>

            {/* ── Footer ── */}
            <SidebarFooter className="border-t border-sidebar-border pt-2">
                {auth.user && (
                    <div className="flex items-center gap-2 px-2 py-1.5 text-sidebar-foreground/70">
                        <Mail className="size-4 shrink-0" />
                        <span className="truncate text-xs group-data-[collapsible=icon]:hidden">
                            {auth.user.email}
                        </span>
                    </div>
                )}
            </SidebarFooter>
        </Sidebar>
    );
}
