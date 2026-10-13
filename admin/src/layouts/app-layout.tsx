import { Outlet } from 'react-router';
import { AppHeader } from '@/components/app-header';
import { AppSidebar } from '@/components/app-sidebar';
import {
    SIDEBAR_STORAGE_KEY,
    SidebarInset,
    SidebarProvider,
} from '@/components/ui/sidebar';
import { useCurrentAdmin } from '@/hooks/use-current-admin';

const storedSidebarOpen = (): boolean => {
    try {
        return localStorage.getItem(SIDEBAR_STORAGE_KEY) !== 'false';
    } catch {
        return true;
    }
};

/** The ERP's shell (layouts/app/app-sidebar-layout.tsx), around every portal page. */
export function AppLayout() {
    const admin = useCurrentAdmin();

    return (
        <SidebarProvider defaultOpen={storedSidebarOpen()}>
            <AppSidebar admin={admin} />
            <SidebarInset className="min-w-0 overflow-x-clip">
                <AppHeader admin={admin} />
                <main className="flex flex-1 flex-col">
                    <Outlet />
                </main>
            </SidebarInset>
        </SidebarProvider>
    );
}
