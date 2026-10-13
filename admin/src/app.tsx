import { createBrowserRouter } from 'react-router';
import { crumbs } from '@/components/crumbs';
import { AppLayout } from '@/layouts/app-layout';
import { NotFound, RequireSession, RequireSuperAdmin } from '@/layouts/guards';

/**
 * The portal's screens, each loaded when first opened. Sign-in stands alone;
 * everything else needs a session (RequireSession), and admin accounts and the
 * log a super admin as well.
 */
export const router = createBrowserRouter([
    {
        path: '/sign-in',
        lazy: () =>
            import('@/features/auth/sign-in-page').then((module) => ({
                Component: module.SignInPage,
            })),
    },
    {
        element: <RequireSession />,
        children: [
            {
                path: '/change-password',
                lazy: () =>
                    import('@/features/auth/change-password-page').then(
                        (module) => ({
                            Component: module.ChangePasswordPage,
                        }),
                    ),
            },
            {
                element: <AppLayout />,
                children: [
                    {
                        index: true,
                        handle: crumbs({ title: 'Overview', to: '/' }),
                        lazy: () =>
                            import('@/features/overview/overview-page').then(
                                (module) => ({
                                    Component: module.OverviewPage,
                                }),
                            ),
                    },
                    {
                        path: 'organisations',
                        handle: crumbs({
                            title: 'Organisations',
                            to: '/organisations',
                        }),
                        lazy: () =>
                            import('@/features/organizations/organizations-page').then(
                                (module) => ({
                                    Component: module.OrganizationsPage,
                                }),
                            ),
                    },
                    {
                        path: 'accounts',
                        handle: crumbs({ title: 'Accounts', to: '/accounts' }),
                        lazy: () =>
                            import('@/features/accounts/accounts-page').then(
                                (module) => ({
                                    Component: module.AccountsPage,
                                }),
                            ),
                    },
                    {
                        element: <RequireSuperAdmin />,
                        children: [
                            {
                                path: 'admins',
                                handle: crumbs({
                                    title: 'Admin accounts',
                                    to: '/admins',
                                }),
                                lazy: () =>
                                    import('@/features/admins/admins-page').then(
                                        (module) => ({
                                            Component: module.AdminsPage,
                                        }),
                                    ),
                            },
                            {
                                path: 'activity',
                                handle: crumbs({
                                    title: 'Activity log',
                                    to: '/activity',
                                }),
                                lazy: () =>
                                    import('@/features/activity/activity-page').then(
                                        (module) => ({
                                            Component: module.ActivityPage,
                                        }),
                                    ),
                            },
                        ],
                    },
                    {
                        path: 'account',
                        handle: crumbs({
                            title: 'Account and security',
                            to: '/account',
                        }),
                        lazy: () =>
                            import('@/features/account/account-page').then(
                                (module) => ({ Component: module.AccountPage }),
                            ),
                    },
                    {
                        path: '*',
                        element: <NotFound />,
                        handle: crumbs({ title: 'Not found', to: '/' }),
                    },
                ],
            },
        ],
    },
]);
