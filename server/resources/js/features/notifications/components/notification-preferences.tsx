import { router } from '@inertiajs/react';
import { Mail, Monitor, MonitorCheck } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { useWebPush } from '@/hooks/use-web-push';
import { notificationRoutes } from '../routes';
import type { NotificationPreferences, WebPushInfo } from '../types';

type Props = {
    preferences: NotificationPreferences;
    webPush: WebPushInfo;
};

export function NotificationPreferencesPanel({ preferences, webPush }: Props) {
    const { supported, busy, subscribe, unsubscribe } = useWebPush(
        webPush.vapidPublicKey,
    );

    const savePrefs = (email: boolean, push: boolean) =>
        router.put(
            notificationRoutes.preferences,
            { email, push },
            { preserveScroll: true },
        );

    return (
        <div className="rounded-xl border border-sidebar-border/70 bg-card p-1 dark:border-sidebar-border">
            {/* In-app (always on) */}
            <Row
                icon={
                    <Mail
                        className="size-4 text-[#0ABFBF]"
                        aria-hidden="true"
                    />
                }
                title="Email notifications"
                description="Send important notifications to your email inbox."
            >
                <Switch
                    checked={preferences.email}
                    onCheckedChange={(checked) =>
                        savePrefs(checked, preferences.push)
                    }
                    aria-label="Toggle email notifications"
                />
            </Row>

            <div className="mx-4 h-px bg-border" />

            {/* Web push preference */}
            <Row
                icon={
                    <Monitor
                        className="size-4 text-[#0ABFBF]"
                        aria-hidden="true"
                    />
                }
                title="Desktop notifications"
                description="Get push alerts on your desktop, even when NEXO isn't open."
            >
                <Switch
                    checked={preferences.push}
                    onCheckedChange={(checked) =>
                        savePrefs(preferences.email, checked)
                    }
                    aria-label="Toggle desktop notifications"
                />
            </Row>

            {/* Per-device subscription */}
            {preferences.push && (
                <div className="mx-4 mb-1 flex items-center justify-between gap-4 rounded-lg bg-muted/40 px-4 py-3">
                    <div className="flex items-center gap-2 text-sm">
                        {webPush.subscribed ? (
                            <MonitorCheck className="size-4 text-emerald-500" />
                        ) : (
                            <Monitor className="size-4 text-muted-foreground" />
                        )}
                        <span className="text-muted-foreground">
                            {!supported
                                ? 'This browser does not support desktop notifications.'
                                : webPush.subscribed
                                  ? 'This device is receiving desktop notifications.'
                                  : 'Enable desktop notifications on this device.'}
                        </span>
                    </div>
                    {supported && (
                        <Button
                            type="button"
                            variant={webPush.subscribed ? 'outline' : 'default'}
                            size="sm"
                            disabled={busy}
                            onClick={() =>
                                webPush.subscribed ? unsubscribe() : subscribe()
                            }
                        >
                            {busy && <Spinner />}
                            {webPush.subscribed ? 'Disable' : 'Enable'}
                        </Button>
                    )}
                </div>
            )}
        </div>
    );
}

function Row({
    icon,
    title,
    description,
    children,
}: {
    icon: React.ReactNode;
    title: string;
    description: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex items-center justify-between gap-4 px-4 py-3.5">
            <div className="flex items-start gap-3">
                <span className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-[#0ABFBF]/10">
                    {icon}
                </span>
                <div>
                    <p className="text-sm font-medium">{title}</p>
                    <p className="text-xs text-muted-foreground">
                        {description}
                    </p>
                </div>
            </div>
            {children}
        </div>
    );
}
