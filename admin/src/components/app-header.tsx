import { Separator } from '@/components/ui/separator';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { TooltipProvider } from '@/components/ui/tooltip';
import type { Admin } from '@/lib/types';
import { AccountMenu } from './account-menu';
import { Breadcrumbs } from './breadcrumbs';
import { ThemeToggle } from './theme-toggle';

/** The ERP's top bar: the sidebar trigger and the trail, then the admin's tools. */
export function AppHeader({ admin }: { admin: Admin }) {
    return (
        <TooltipProvider delayDuration={200}>
            <header className="sticky top-0 z-30 flex h-14 shrink-0 items-center justify-between gap-2 border-b border-sidebar-border/50 bg-background/80 px-4 backdrop-blur-md transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12">
                <div className="flex min-w-0 items-center gap-2">
                    <SidebarTrigger className="-ml-1 text-muted-foreground hover:text-foreground" />
                    <Separator orientation="vertical" className="mx-1 h-4" />
                    <Breadcrumbs />
                </div>

                <div className="flex items-center gap-1.5">
                    <ThemeToggle />
                    <Separator orientation="vertical" className="mx-0.5 h-5" />
                    <AccountMenu admin={admin} />
                </div>
            </header>
        </TooltipProvider>
    );
}
