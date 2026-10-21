import {
    Building2,
    LayoutGrid,
    MessageSquareWarning,
    ScrollText,
    ShieldCheck,
    UsersRound,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

export type NavItem = {
    title: string;
    to: string;
    icon: LucideIcon;
    /** A count beside the title: what is waiting there. */
    count?: 'problem-reports';
};

export type NavGroup = {
    key: string;
    label: string;
    /** Only super admins see this group (the API refuses the rest anyway). */
    superAdminOnly?: boolean;
    items: NavItem[];
};

/**
 * The sidebar, in order: what the portal watches, what people asked of it,
 * then who runs it.
 */
export const NAVIGATION: NavGroup[] = [
    {
        key: 'monitor',
        label: 'Monitor',
        items: [
            { title: 'Overview', to: '/', icon: LayoutGrid },
            { title: 'Organisations', to: '/organisations', icon: Building2 },
            { title: 'Accounts', to: '/accounts', icon: UsersRound },
        ],
    },
    {
        key: 'support',
        label: 'Support',
        items: [
            {
                title: 'Problem reports',
                to: '/problem-reports',
                icon: MessageSquareWarning,
                count: 'problem-reports',
            },
        ],
    },
    {
        key: 'administration',
        label: 'Administration',
        superAdminOnly: true,
        items: [
            { title: 'Admin accounts', to: '/admins', icon: ShieldCheck },
            { title: 'Activity log', to: '/activity', icon: ScrollText },
        ],
    },
];
