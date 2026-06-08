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
import type { NavItem } from '@/types';

type Props = {
    items: NavItem[];
    label?: string;
    badge?: ReactNode;
};

export function NavMain({ items = [], label, badge }: Props) {
    const { isCurrentUrl } = useCurrentUrl();

    return (
        <SidebarGroup className="px-2 py-1">
            {label && (
                <SidebarGroupLabel className="flex items-center justify-between text-[10px] tracking-[0.12em] uppercase text-sidebar-foreground/50 font-semibold px-2 mb-1">
                    {label}
                    {badge}
                </SidebarGroupLabel>
            )}
            <SidebarMenu>
                {items.map((item) => {
                    const active = isCurrentUrl(item.href);

                    return (
                        <SidebarMenuItem key={item.title}>
                            <SidebarMenuButton
                                asChild
                                isActive={active}
                                tooltip={{ children: item.title }}
                                className="relative text-sidebar-foreground/70 hover:text-sidebar-foreground hover:bg-sidebar-accent data-[active=true]:bg-[#0ABFBF]/12 data-[active=true]:text-[#0ABFBF] transition-colors duration-150"
                            >
                                <Link href={item.href} prefetch>
                                    {item.icon && (
                                        <item.icon className="shrink-0" />
                                    )}
                                    <span>{item.title}</span>
                                    {active && (
                                        <span className="ml-auto w-1 h-4 rounded-full bg-[#0ABFBF] opacity-80" />
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
