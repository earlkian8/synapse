import { Link, usePage } from '@inertiajs/react';
import {
    BarChart3,
    BriefcaseBusiness,
    Building2,
    CalendarDays,
    FileText,
    LayoutGrid,
    Mail,
    Sparkles,
    UserRoundCheck,
    Users,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
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
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
];

const peopleNavItems: NavItem[] = [
    { title: 'Employees', href: '/employees', icon: Users },
    { title: 'Departments', href: '/departments', icon: Building2 },
    { title: 'Attendance', href: '/attendance', icon: CalendarDays },
    { title: 'Leave Management', href: '/leave', icon: FileText },
];

const talentNavItems: NavItem[] = [
    { title: 'Recruitment', href: '/recruitment', icon: BriefcaseBusiness },
    { title: 'Onboarding', href: '/onboarding', icon: UserRoundCheck },
];

const analyticsNavItems: NavItem[] = [
    { title: 'Reports', href: '/reports', icon: BarChart3 },
    { title: 'AI Insights', href: '/insights', icon: Sparkles },
];

export function AppSidebar() {
    const { auth } = usePage().props;

    return (
        <Sidebar collapsible="icon" variant="inset">
            {/* ── Logo / Brand ── */}
            <SidebarHeader className="border-b border-sidebar-border pb-3">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            {/* ── Navigation ── */}
            <SidebarContent className="gap-0 py-2">
                {/* Main */}
                <NavMain
                    label="Main"
                    items={mainNavItems}
                />

                {/* People */}
                <NavMain
                    label="People"
                    items={peopleNavItems}
                />

                {/* Talent */}
                <NavMain
                    label="Talent"
                    items={talentNavItems}
                />

                {/* Analytics */}
                <NavMain
                    label="Analytics"
                    items={analyticsNavItems}
                    badge={
                        <Badge
                            variant="outline"
                            className="ml-auto border-[#0ABFBF]/40 bg-[#0ABFBF]/10 text-[#0ABFBF] text-[9px] tracking-wider px-1.5 py-0 h-4 rounded-full font-semibold group-data-[collapsible=icon]:hidden"
                        >
                            AI
                        </Badge>
                    }
                />
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
