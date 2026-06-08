import { usePage } from '@inertiajs/react';
import { CircleHelp, Search } from 'lucide-react';
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
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { UserMenuContent } from '@/components/user-menu-content';
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
            <header className="sticky top-0 z-30 flex h-14 shrink-0 items-center justify-between gap-2 border-b border-sidebar-border/50 bg-background/80 px-4 backdrop-blur-md transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12">
                {/* Left: trigger + breadcrumbs */}
                <div className="flex min-w-0 items-center gap-2">
                    <SidebarTrigger className="-ml-1 text-muted-foreground hover:text-foreground" />
                    <Separator orientation="vertical" className="mx-1 h-4" />
                    <Breadcrumbs breadcrumbs={breadcrumbs} />
                </div>

                {/* Right: actions */}
                <div className="flex items-center gap-1.5">
                    {/* Search field */}
                    <div className="relative hidden md:block">
                        <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                        <input
                            type="search"
                            placeholder="Search employees, docs…"
                            aria-label="Search"
                            className="h-8 w-64 rounded-lg border border-input bg-muted/40 pr-3 pl-8 text-[13px] text-foreground transition-colors outline-none placeholder:text-muted-foreground hover:bg-muted focus:w-80 focus:border-ring focus:bg-background focus:ring-2 focus:ring-ring/30 lg:w-72"
                        />
                    </div>

                    {/* Search icon (mobile) */}
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="Search"
                                className="size-8 text-muted-foreground hover:text-foreground md:hidden"
                            >
                                <Search className="size-[18px]" />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent side="bottom" className="text-xs">
                            Search
                        </TooltipContent>
                    </Tooltip>

                    <Separator
                        orientation="vertical"
                        className="mx-0.5 hidden h-5 sm:block"
                    />

                    {/* Help */}
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="Help & resources"
                                className="hidden size-8 text-muted-foreground hover:text-foreground sm:inline-flex"
                            >
                                <CircleHelp className="size-[18px]" />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent side="bottom" className="text-xs">
                            Help &amp; resources
                        </TooltipContent>
                    </Tooltip>

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
                                    >
                                        <Avatar className="size-8 rounded-full ring-1 ring-border">
                                            <AvatarImage
                                                src={auth.user.avatar}
                                                alt={auth.user.name}
                                            />
                                            <AvatarFallback className="rounded-full bg-[#0F2044] text-[11px] font-semibold text-white">
                                                {getInitials(auth.user.name)}
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
