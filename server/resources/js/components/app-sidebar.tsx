import { Link, usePage } from '@inertiajs/react';
import {
    Award,
    BarChart3,
    Bot,
    BriefcaseBusiness,
    Building,
    Building2,
    CalendarCheck,
    CalendarClock,
    CalendarDays,
    CalendarRange,
    DatabaseBackup,
    FileScan,
    Gauge,
    GraduationCap,
    HeartHandshake,
    LayoutDashboard,
    LayoutGrid,
    LineChart,
    Mail,
    Medal,
    Network,
    ScrollText,
    Settings,
    ShieldCheck,
    Target,
    TrendingDown,
    Trophy,
    UserCog,
    UserRoundCheck,
    UserRoundMinus,
    Users,
    Wallet,
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
import { usePermissions } from '@/hooks/use-permissions';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
];

const talentNavItems: NavItem[] = [
    { title: 'Recruitment', href: '/recruitment', icon: BriefcaseBusiness },
    { title: 'Onboarding', href: '/onboarding', icon: UserRoundCheck },
];

const workforceNavItems: NavItem[] = [
    { title: 'Employees', href: '/employees', icon: Users },
    { title: 'Departments', href: '/departments', icon: Building2 },
    { title: 'Attendance', href: '/attendance', icon: CalendarCheck },
    { title: 'Leave Management', href: '/leave', icon: CalendarDays },
    { title: 'Payroll', href: '/payroll', icon: Wallet },
    { title: 'Benefits Administration', href: '/benefits', icon: HeartHandshake },
    { title: 'Performance Management', href: '/performance', icon: Gauge },
    { title: 'Training & Development', href: '/training', icon: GraduationCap },
    { title: 'Awards & Recognition', href: '/awards', icon: Award },
    { title: 'Events & Meetings', href: '/events', icon: CalendarClock },
];

const offboardingNavItems: NavItem[] = [
    { title: 'Offboarding', href: '/offboarding', icon: UserRoundMinus },
];

const analyticsNavItems: NavItem[] = [
    { title: 'Workforce Dashboard', href: '/analytics/workforce', icon: LayoutDashboard },
    { title: 'Attrition Predictions', href: '/analytics/attrition', icon: TrendingDown },
    { title: 'Performance Forecast', href: '/analytics/performance-forecast', icon: LineChart },
    { title: 'Promotion Readiness', href: '/analytics/promotion-readiness', icon: Medal },
    { title: 'Reports', href: '/reports', icon: BarChart3 },
];

const assistantNavItems: NavItem[] = [
    { title: 'HR Assistant', href: '/assistant', icon: Bot },
    { title: 'Document Processor', href: '/assistant/documents', icon: FileScan },
];

const companySetupNavItems: NavItem[] = [
    { title: 'Company Profile', href: '/setup/company', icon: Building },
    { title: 'Departments', href: '/setup/departments', icon: Network },
    { title: 'Work Schedule & Holidays', href: '/setup/schedule', icon: CalendarClock },
    { title: 'Leave Types', href: '/setup/leave-types', icon: CalendarRange },
    { title: 'Award Types', href: '/setup/award-types', icon: Trophy },
    { title: 'KPI & Evaluation Criteria', href: '/setup/kpi', icon: Target },
    { title: 'Payroll Configuration', href: '/setup/payroll', icon: Settings },
    { title: 'Email & Notifications', href: '/setup/notifications', icon: Mail },
];

type GatedNavItem = NavItem & { permission?: string };

const systemNavItems: GatedNavItem[] = [
    { title: 'User Management', href: '/system/users', icon: UserCog, permission: 'users.view' },
    { title: 'Roles & Permissions', href: '/system/roles', icon: ShieldCheck, permission: 'roles.view' },
    { title: 'Activity Logs', href: '/system/activity-logs', icon: ScrollText, permission: 'activity-logs.view' },
    { title: 'Data Backup & Export', href: '/system/backup', icon: DatabaseBackup },
];

export function AppSidebar() {
    const { auth } = usePage().props;
    const { can } = usePermissions();

    const visibleSystemNavItems = systemNavItems.filter(
        (item) => !item.permission || can(item.permission),
    );

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
                <NavMain label="Main" items={mainNavItems} />

                {/* Talent Acquisition */}
                <NavMain label="Talent Acquisition" items={talentNavItems} />

                {/* Workforce */}
                <NavMain label="Workforce" items={workforceNavItems} />

                {/* Offboarding */}
                <NavMain label="Offboarding" items={offboardingNavItems} />

                {/* Analytics & AI */}
                <NavMain
                    label="Analytics & AI"
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

                {/* Assistant */}
                <NavMain
                    label="Assistant"
                    items={assistantNavItems}
                    badge={
                        <Badge
                            variant="outline"
                            className="ml-auto border-[#0ABFBF]/40 bg-[#0ABFBF]/10 text-[#0ABFBF] text-[9px] tracking-wider px-1.5 py-0 h-4 rounded-full font-semibold group-data-[collapsible=icon]:hidden"
                        >
                            AI
                        </Badge>
                    }
                />

                {/* Company Setup */}
                <NavMain label="Company Setup" items={companySetupNavItems} />

                {/* System */}
                {visibleSystemNavItems.length > 0 && (
                    <NavMain label="System" items={visibleSystemNavItems} />
                )}
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
