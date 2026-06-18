import { Link, usePage } from '@inertiajs/react';
import {
    Award,
    BarChart3,
    Bell,
    Bot,
    BriefcaseBusiness,
    Building,
    Building2,
    CalendarCheck,
    CalendarClock,
    CalendarDays,
    CalendarRange,
    Clock,
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
    Trash2,
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

const talentNavItems: GatedNavItem[] = [
    {
        title: 'Recruitment',
        href: '/recruitment',
        icon: BriefcaseBusiness,
        permission: 'recruitment.view',
    },
    {
        title: 'Onboarding',
        href: '/onboarding',
        icon: UserRoundCheck,
        permission: 'onboarding.view',
    },
];

const workforceNavItems: GatedNavItem[] = [
    {
        title: 'Employees',
        href: '/employees',
        icon: Users,
        permission: 'employees.view',
    },
    { title: 'Departments', href: '/departments', icon: Building2 },
    {
        title: 'Attendance',
        href: '/attendance',
        icon: CalendarCheck,
        permission: 'attendance.view',
    },
    {
        title: 'My Attendance',
        href: '/attendance/me',
        icon: Clock,
        permission: 'attendance.clock',
    },
    {
        title: 'Leave Management',
        href: '/leave',
        icon: CalendarDays,
        permission: 'leave.view',
    },
    {
        title: 'Payroll',
        href: '/payroll',
        icon: Wallet,
        permission: 'payroll.view',
    },
    {
        title: 'Benefits Administration',
        href: '/benefits',
        icon: HeartHandshake,
        permission: 'benefits.view',
    },
    {
        title: 'Performance Management',
        href: '/performance',
        icon: Gauge,
        permission: 'performance.view',
    },
    {
        title: 'Training & Development',
        href: '/training',
        icon: GraduationCap,
        permission: 'training.view',
    },
    {
        title: 'Awards & Recognition',
        href: '/awards',
        icon: Award,
        permission: 'awards.view',
    },
    { title: 'Events & Meetings', href: '/events', icon: CalendarClock },
];

const offboardingNavItems: NavItem[] = [
    { title: 'Offboarding', href: '/offboarding', icon: UserRoundMinus },
];

const analyticsNavItems: NavItem[] = [
    {
        title: 'Workforce Dashboard',
        href: '/analytics/workforce',
        icon: LayoutDashboard,
    },
    {
        title: 'Attrition Predictions',
        href: '/analytics/attrition',
        icon: TrendingDown,
    },
    {
        title: 'Performance Forecast',
        href: '/analytics/performance-forecast',
        icon: LineChart,
    },
    {
        title: 'Promotion Readiness',
        href: '/analytics/promotion-readiness',
        icon: Medal,
    },
    { title: 'Reports', href: '/reports', icon: BarChart3 },
];

const assistantNavItems: NavItem[] = [
    { title: 'HR Assistant', href: '/assistant', icon: Bot },
    {
        title: 'Document Processor',
        href: '/assistant/documents',
        icon: FileScan,
    },
];

const companySetupNavItems: GatedNavItem[] = [
    { title: 'Company Profile', href: '/setup/company', icon: Building },
    {
        title: 'Departments',
        href: '/setup/departments',
        icon: Network,
        permission: 'setup.departments.view',
    },
    {
        title: 'Work Schedule & Holidays',
        href: '/setup/schedule',
        icon: CalendarClock,
    },
    {
        title: 'Leave Types',
        href: '/setup/leave-types',
        icon: CalendarRange,
        permission: 'setup.leave-types.view',
    },
    {
        title: 'Award Types',
        href: '/setup/award-types',
        icon: Trophy,
        permission: 'setup.award-types.view',
    },
    {
        title: 'KPI & Evaluation Criteria',
        href: '/setup/kpi',
        icon: Target,
        permission: 'setup.kpi.view',
    },
    {
        title: 'Payroll Configuration',
        href: '/setup/payroll',
        icon: Settings,
        permission: 'setup.payroll.view',
    },
    {
        title: 'Benefits Configuration',
        href: '/setup/benefits',
        icon: HeartHandshake,
        permission: 'setup.benefits.view',
    },
    {
        title: 'Email & Notifications',
        href: '/setup/notifications',
        icon: Mail,
    },
];

type GatedNavItem = NavItem & {
    permission?: string;
    permissionAny?: string[];
};

const systemNavItems: GatedNavItem[] = [
    { title: 'Notifications', href: '/system/notifications', icon: Bell },
    {
        title: 'User Management',
        href: '/system/users',
        icon: UserCog,
        permission: 'users.view',
    },
    {
        title: 'Roles & Permissions',
        href: '/system/roles',
        icon: ShieldCheck,
        permission: 'roles.view',
    },
    {
        title: 'Activity Logs',
        href: '/system/activity-logs',
        icon: ScrollText,
        permission: 'activity-logs.view',
    },
    {
        title: 'Trash Bin',
        href: '/system/trash',
        icon: Trash2,
        // Visible to anyone who can view at least one archivable record type.
        permissionAny: [
            'users.view',
            'employees.view',
            'setup.departments.view',
            'setup.leave-types.view',
        ],
    },
    {
        title: 'Data Backup & Export',
        href: '/system/backup',
        icon: DatabaseBackup,
    },
];

export function AppSidebar() {
    const { auth } = usePage().props;
    const { can, canAny } = usePermissions();

    const isVisible = (item: GatedNavItem): boolean =>
        (!item.permission || can(item.permission)) &&
        (!item.permissionAny || canAny(...item.permissionAny));

    const visibleSystemNavItems = systemNavItems.filter(isVisible);

    const visibleWorkforceNavItems = workforceNavItems.filter(isVisible);

    const visibleTalentNavItems = talentNavItems.filter(isVisible);

    const visibleCompanySetupNavItems = companySetupNavItems.filter(isVisible);

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
                <NavMain
                    label="Talent Acquisition"
                    items={visibleTalentNavItems}
                />

                {/* Workforce */}
                <NavMain label="Workforce" items={visibleWorkforceNavItems} />

                {/* Offboarding */}
                <NavMain label="Offboarding" items={offboardingNavItems} />

                {/* Analytics & AI */}
                <NavMain
                    label="Analytics & AI"
                    items={analyticsNavItems}
                    badge={
                        <Badge
                            variant="outline"
                            className="ml-auto h-4 rounded-full border-[#0ABFBF]/40 bg-[#0ABFBF]/10 px-1.5 py-0 text-[9px] font-semibold tracking-wider text-[#0ABFBF] group-data-[collapsible=icon]:hidden"
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
                            className="ml-auto h-4 rounded-full border-[#0ABFBF]/40 bg-[#0ABFBF]/10 px-1.5 py-0 text-[9px] font-semibold tracking-wider text-[#0ABFBF] group-data-[collapsible=icon]:hidden"
                        >
                            AI
                        </Badge>
                    }
                />

                {/* Company Setup */}
                <NavMain
                    label="Company Setup"
                    items={visibleCompanySetupNavItems}
                />

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
