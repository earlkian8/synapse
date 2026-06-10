import { router } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';
import { notificationRoutes } from '@/features/notifications/routes';

/** Convert a base64url VAPID key into the Uint8Array the Push API expects. */
function urlBase64ToUint8Array(base64String: string): Uint8Array {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding)
        .replace(/-/g, '+')
        .replace(/_/g, '/');
    const raw = window.atob(base64);
    const output = new Uint8Array(raw.length);

    for (let i = 0; i < raw.length; ++i) {
        output[i] = raw.charCodeAt(i);
    }

    return output;
}

const isSupported =
    typeof window !== 'undefined' &&
    'serviceWorker' in navigator &&
    'PushManager' in window &&
    'Notification' in window;

type UseWebPush = {
    supported: boolean;
    permission: NotificationPermission | 'default';
    busy: boolean;
    subscribe: () => Promise<void>;
    unsubscribe: () => Promise<void>;
};

/**
 * Registers the service worker and manages the browser push subscription,
 * syncing it to the backend so the server can deliver web-push notifications.
 */
export function useWebPush(vapidPublicKey: string | null): UseWebPush {
    const [permission, setPermission] = useState<
        NotificationPermission | 'default'
    >(isSupported ? Notification.permission : 'default');
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (isSupported) {
            navigator.serviceWorker.register('/sw.js').catch(() => {
                // Registration failures are non-fatal; push simply stays off.
            });
        }
    }, []);

    const subscribe = useCallback(async () => {
        if (!isSupported) {
            toast.error(
                'This browser does not support push notifications, or the page is not on a secure (https/localhost) origin.',
            );

            return;
        }

        if (!vapidPublicKey) {
            toast.error(
                'Push is not configured: the VAPID public key is missing. Run `php artisan webpush:vapid` and restart the server.',
            );

            return;
        }

        setBusy(true);

        try {
            const permissionResult = await Notification.requestPermission();
            setPermission(permissionResult);

            if (permissionResult === 'denied') {
                toast.error(
                    'Notifications are blocked. Allow them for this site in your browser settings, then try again.',
                );

                return;
            }

            if (permissionResult !== 'granted') {
                toast.error('Permission to show notifications was dismissed.');

                return;
            }

            // Ensure the service worker is registered and active before we
            // subscribe (the install effect may not have finished yet).
            const registration =
                (await navigator.serviceWorker.getRegistration('/sw.js')) ??
                (await navigator.serviceWorker.register('/sw.js'));
            await navigator.serviceWorker.ready;

            // Drop any stale subscription first. On localhost a subscription
            // created with a different VAPID key lingers and makes a fresh
            // subscribe() throw InvalidStateError, so always start clean.
            const existing = await registration.pushManager.getSubscription();

            if (existing) {
                await existing.unsubscribe();
            }

            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(
                    vapidPublicKey,
                ) as BufferSource,
            });

            const json = subscription.toJSON();

            router.post(
                notificationRoutes.subscribe,
                {
                    endpoint: subscription.endpoint,
                    public_key: json.keys?.p256dh ?? '',
                    auth_token: json.keys?.auth ?? '',
                },
                {
                    preserveScroll: true,
                    onSuccess: () =>
                        toast.success('Desktop notifications enabled.'),
                },
            );
        } catch (error) {
            const message =
                error instanceof Error ? error.message : 'Unknown error';
            toast.error(`Could not enable desktop notifications: ${message}`);
        } finally {
            setBusy(false);
        }
    }, [vapidPublicKey]);

    const unsubscribe = useCallback(async () => {
        if (!isSupported) {
            return;
        }

        setBusy(true);

        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription =
                await registration.pushManager.getSubscription();

            if (subscription) {
                const endpoint = subscription.endpoint;
                await subscription.unsubscribe();

                router.delete(notificationRoutes.unsubscribe, {
                    data: { endpoint },
                    preserveScroll: true,
                    onSuccess: () =>
                        toast.success('Desktop notifications disabled.'),
                });
            }
        } catch {
            toast.error('Could not disable desktop notifications.');
        } finally {
            setBusy(false);
        }
    }, []);

    return { supported: isSupported, permission, busy, subscribe, unsubscribe };
}
