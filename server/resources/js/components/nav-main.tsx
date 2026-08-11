import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { toUrl } from '@/lib/utils';
import type { NavItem } from '@/types';

type Props = {
    items: NavItem[];
    label?: string;
    badge?: ReactNode;
};

export function NavMain({ items = [], label, badge }: Props) {
    const { currentUrl } = useCurrentUrl();

    return (
        <SidebarGroup className="px-2 py-1">
            {label && (
                <SidebarGroupLabel className="mb-1 flex items-center justify-between px-2 text-[10px] font-semibold tracking-[0.12em] text-sidebar-foreground/50 uppercase">
                    {label}
                    {badge}
                </SidebarGroupLabel>
            )}
            <SidebarMenu>
                {items.map((item) => {
                    // Active when the URL is the item's route or a child of it,
                    // so e.g. /recruitment/4 keeps "Recruitment" highlighted.
                    const href = toUrl(item.href);
                    const active =
                        currentUrl === href ||
                        currentUrl.startsWith(`${href}/`);

                    return (
                        <SidebarMenuItem key={item.title}>
                            <SidebarMenuButton
                                asChild
                                isActive={active}
                                tooltip={{ children: item.title }}
                                className="relative text-sidebar-foreground/70 transition-colors duration-150 hover:bg-sidebar-accent hover:text-sidebar-foreground data-[active=true]:bg-[#0ABFBF]/12 data-[active=true]:text-[#0ABFBF]"
                            >
                                <Link href={item.href} prefetch>
                                    {item.icon && (
                                        <item.icon className="shrink-0" />
                                    )}
                                    <span>{item.title}</span>
                                    {active && (
                                        <span className="ml-auto h-4 w-1 rounded-full bg-[#0ABFBF] opacity-80" />
                                    )}
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    );
                })}
            </SidebarMenu>
        </SidebarGroup>
    );
}
