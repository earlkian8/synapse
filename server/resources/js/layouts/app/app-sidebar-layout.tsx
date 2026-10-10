import { AppContent } from '@/components/app-content';
import { AppFooter } from '@/components/app-footer';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { Assistant } from '@/features/assistant/components/assistant';
import { ProductTour } from '@/features/product-tour/components/product-tour';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            {/* min-w-0 lets the page narrow when the assistant docks beside it. */}
            <AppContent variant="sidebar" className="min-w-0 overflow-x-clip">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                <div className="flex flex-1 flex-col">{children}</div>
                <AppFooter />
            </AppContent>
            {/* Persistent agentic assistant — mounted once for the whole app,
                docked at the right edge while open (its button is in the top bar). */}
            <Assistant />
            {/* The first-run tour (ADR 0060) — offered once, replayed from Help. */}
            <ProductTour />
        </AppShell>
    );
}
