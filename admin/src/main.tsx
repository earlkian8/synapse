import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { RouterProvider } from 'react-router';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import type { MeResponse } from '@/features/auth/api';
import { initializeTheme } from '@/hooks/use-appearance';
import { ApiError, onPasswordChangeRequired } from '@/lib/api';
import { router } from './app';
import './index.css';

initializeTheme();

const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            // No background refetching: a request keeps a session alive, so the
            // portal makes one only when somebody does something.
            refetchOnWindowFocus: false,
            refetchOnReconnect: false,
            staleTime: 30_000,
            retry: (count, error) =>
                !(
                    error instanceof ApiError &&
                    error.status > 0 &&
                    error.status < 500
                ) && count < 2,
        },
    },
});

// The server can demand a new password at any request (another admin reset it
// mid-session ends the session instead, so this is the rarer path).
onPasswordChangeRequired(() => {
    queryClient.setQueriesData<MeResponse>({ queryKey: ['me'] }, (old) =>
        old
            ? { ...old, admin: { ...old.admin, must_change_password: true } }
            : old,
    );
});

createRoot(document.getElementById('root')!).render(
    <StrictMode>
        <QueryClientProvider client={queryClient}>
            <TooltipProvider delayDuration={200}>
                <RouterProvider router={router} />
                <Toaster />
            </TooltipProvider>
        </QueryClientProvider>
    </StrictMode>,
);
