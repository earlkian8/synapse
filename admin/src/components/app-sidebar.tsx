import { Mail } from 'lucide-react';
import { Link, useMatch } from 'react-router';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useProblemReportCounts } from '@/features/problem-reports/api';
import type { Admin } from '@/lib/types';
import { AppLogo } from './app-logo';
import { NAVIGATION } from './navigation';
import type { NavItem } from './navigation';

/**
 * The ERP's inset sidebar (components/app-sidebar.tsx + nav-main.tsx): the
 * brand on top, labelled groups, the teal active marker, the email at the foot.
 */
export function AppSidebar({ admin }: { admin: Admin }) {
    const { isMobile, setOpenMobile } = useSidebar();
    const groups = NAVIGATION.filter(
        (group) => !group.superAdminOnly || admin.role === 'super_admin',
    );
    const closeOnPhone = () => isMobile && setOpenMobile(false);

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader className="border-b border-sidebar-border pb-3">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link to="/" onClick={closeOnPhone}>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent className="gap-0 py-2">
                {groups.map((group) => (
                    <SidebarGroup key={group.key} className="px-2 py-1">
                        <SidebarGroupLabel className="mb-1 px-2 text-[10px] font-semibold tracking-[0.12em] text-sidebar-foreground/50 uppercase">
                            {group.label}
                        </SidebarGroupLabel>
                        <SidebarMenu>
                            {group.items.map((item) => (
                                <SidebarMenuItem key={item.to}>
                                    <NavItemLink
                                        item={item}
                                        onNavigate={closeOnPhone}
                                    />
                                </SidebarMenuItem>
                            ))}
                        </SidebarMenu>
                    </SidebarGroup>
                ))}
            </SidebarContent>

            <SidebarFooter className="border-t border-sidebar-border pt-2">
                <div className="flex items-center gap-2 px-2 py-1.5 text-sidebar-foreground/70">
                    <Mail className="size-4 shrink-0" />
                    <span className="truncate text-xs group-data-[collapsible=icon]:hidden">
                        {admin.email}
                    </span>
                </div>
            </SidebarFooter>
        </Sidebar>
    );
}

/** One entry, lit while its page (or a page under it) is open. */
function NavItemLink({
    item,
    onNavigate,
}: {
    item: NavItem;
    onNavigate: () => void;
}) {
    const isActive = useMatch({ path: item.to, end: item.to === '/' }) !== null;

    return (
        <SidebarMenuButton
            asChild
            isActive={isActive}
            tooltip={{ children: item.title }}
            className="relative text-sidebar-foreground/70 transition-colors duration-150 hover:bg-sidebar-accent hover:text-sidebar-foreground data-[active=true]:bg-[#0ABFBF]/12 data-[active=true]:text-[#08767c] dark:data-[active=true]:text-[#0ABFBF]"
        >
            <Link
                to={item.to}
                onClick={onNavigate}
                aria-current={isActive ? 'page' : undefined}
            >
                <item.icon className="shrink-0" />
                <span>{item.title}</span>
                <span className="ml-auto flex items-center gap-1.5">
                    {item.count === 'problem-reports' && <NewReportsCount />}
                    {isActive && (
                        <span className="h-4 w-1 rounded-full bg-[#0ABFBF] opacity-80" />
                    )}
                </span>
            </Link>
        </SidebarMenuButton>
    );
}

/**
 * Problem reports nobody has picked up yet. Read when the sidebar mounts and
 * again whenever a follow-up is saved — never polled, since a request keeps
 * the session alive.
 */
function NewReportsCount() {
    const counts = useProblemReportCounts();
    const waiting = counts.data?.new ?? 0;

    if (waiting === 0) {
        return null;
    }

    return (
        <span
            className="flex h-5 min-w-5 items-center justify-center rounded-full bg-[#0ABFBF] px-1.5 text-[11px] font-semibold text-[#0F2044] tabular-nums group-data-[collapsible=icon]:hidden"
            aria-label={`${waiting} new`}
        >
            {waiting > 99 ? '99+' : waiting}
        </span>
    );
}
