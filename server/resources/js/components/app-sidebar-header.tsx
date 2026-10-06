import { usePage } from '@inertiajs/react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { NotificationsDropdown } from '@/components/notifications-dropdown';
import { ThemeToggle } from '@/components/theme-toggle';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Separator } from '@/components/ui/separator';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { TooltipProvider } from '@/components/ui/tooltip';
import { UserMenuContent } from '@/components/user-menu-content';
import { AssistantButton } from '@/features/assistant/components/assistant-button';
import { SearchTrigger } from '@/features/global-search/components/search-trigger';
import { HelpMenu } from '@/features/product-tour/components/help-menu';
import { tourTarget } from '@/features/product-tour/targets';
import { useInitials } from '@/hooks/use-initials';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { auth } = usePage().props;
    const getInitials = useInitials();

    return (
        <TooltipProvider delayDuration={200}>
            <header className="@container/header sticky top-0 z-30 flex h-14 shrink-0 items-center justify-between gap-2 border-b border-sidebar-border/50 bg-background/80 px-4 backdrop-blur-md transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12">
                {/* Left: trigger + breadcrumbs */}
                <div className="flex min-w-0 items-center gap-2">
                    <SidebarTrigger className="-ml-1 text-muted-foreground hover:text-foreground" />
                    <Separator orientation="vertical" className="mx-1 h-4" />
                    <Breadcrumbs breadcrumbs={breadcrumbs} />
                </div>

                {/* Right: actions */}
                <div className="flex items-center gap-1.5">
                    {/* Global search (ADR 0069) — a field where the bar is
                        wide enough, an icon where it is not; ⌘K / Ctrl+K anywhere */}
                    <SearchTrigger />

                    <Separator
                        orientation="vertical"
                        className="mx-0.5 hidden h-5 sm:block"
                    />

                    {/* The assistant — opens its panel; ⌘J / Ctrl+J anywhere */}
                    <AssistantButton />

                    {/* Help — the tour, and the Setup Guide for owners */}
                    <HelpMenu />

                    {/* Theme */}
                    <ThemeToggle />

                    {/* Notifications */}
                    <NotificationsDropdown />

                    {auth.user && (
                        <>
                            <Separator
                                orientation="vertical"
                                className="mx-0.5 hidden h-5 sm:block"
                            />

                            {/* User menu */}
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        variant="ghost"
                                        className="size-8 rounded-full p-0"
                                        aria-label="Account menu"
                                        {...tourTarget('account')}
                                    >
                                        <Avatar className="size-8 rounded-full ring-1 ring-border">
                                            <AvatarImage
                                                src={auth.user.avatar}
                                                alt={auth.user.full_name}
                                            />
                                            <AvatarFallback className="rounded-full bg-[#0F2044] text-[11px] font-semibold text-white">
                                                {getInitials(
                                                    auth.user.first_name,
                                                    auth.user.last_name,
                                                )}
                                            </AvatarFallback>
                                        </Avatar>
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent
                                    align="end"
                                    sideOffset={8}
                                    className="w-56"
                                >
                                    <UserMenuContent user={auth.user} />
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </>
                    )}
                </div>
            </header>
        </TooltipProvider>
    );
}
