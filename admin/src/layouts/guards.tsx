import { useEffect } from 'react';
import { Link, Navigate, Outlet, useLocation } from 'react-router';
import { IdleSession } from '@/components/idle-session';
import { FullPageSpinner } from '@/components/page-states';
import { Button } from '@/components/ui/button';
import { useMe } from '@/features/auth/api';
import { useSignOut } from '@/features/auth/use-sign-out';
import { useCurrentAdmin } from '@/hooks/use-current-admin';
import { useSession } from '@/lib/session';

/**
 * Everything but sign-in sits behind this: a session, and the admin it belongs
 * to. An admin who owes a new password is kept on that screen until they choose
 * one — the API refuses everything else meanwhile.
 */
export function RequireSession() {
    const session = useSession();
    const me = useMe();
    const location = useLocation();
    const signOut = useSignOut();

    const lost = session !== null && me.isError;

    useEffect(() => {
        // A 401 has already ended the session; anything else, sign in afresh.
        if (lost) {
            void signOut('expired');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [lost]);

    if (!session) {
        return (
            <Navigate
                to="/sign-in"
                replace
                state={{ from: location.pathname + location.search }}
            />
        );
    }

    if (!me.data) {
        return <FullPageSpinner />;
    }

    if (
        me.data.admin.must_change_password &&
        location.pathname !== '/change-password'
    ) {
        return <Navigate to="/change-password" replace />;
    }

    if (
        !me.data.admin.must_change_password &&
        location.pathname === '/change-password'
    ) {
        return <Navigate to="/" replace />;
    }

    return (
        <>
            <Outlet />
            <IdleSession />
        </>
    );
}

/** Admin accounts and the activity log: super admins only. */
export function RequireSuperAdmin() {
    const admin = useCurrentAdmin();

    return admin.role === 'super_admin' ? (
        <Outlet />
    ) : (
        <Navigate to="/" replace />
    );
}

/** An address the portal has no page for. */
export function NotFound() {
    return (
        <div className="flex flex-1 flex-col items-center justify-center gap-2 p-10 text-center">
            <p className="text-sm font-medium">There's no page here</p>
            <p className="text-sm text-muted-foreground">
                The address may be mistyped, or the page has moved.
            </p>
            <Button variant="outline" size="sm" className="mt-2" asChild>
                <Link to="/">Go to the overview</Link>
            </Button>
        </div>
    );
}
