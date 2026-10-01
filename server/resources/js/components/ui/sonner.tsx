import { useFlashToast } from '@/hooks/use-flash-toast';
import { useAppearance } from '@/hooks/use-appearance';
import { Toaster as Sonner, type ToasterProps } from 'sonner';

function Toaster({ ...props }: ToasterProps) {
    const { appearance } = useAppearance();

    useFlashToast();

    return (
        <Sonner
            theme={appearance}
            className="toaster group"
            position="bottom-right"
            // Toasts land bottom-right, which on almost every screen is empty
            // space. A page with a pinned action bar there (the setup wizard)
            // moves them up by setting these variables — see
            // `features/setup-wizard/components/toast-clearance.tsx`. The
            // fallbacks are sonner's own defaults, so every other page is
            // exactly where it was.
            offset={{ bottom: 'var(--app-toast-offset-bottom, 24px)' }}
            mobileOffset={{
                bottom: 'var(--app-toast-offset-bottom-mobile, 16px)',
            }}
            closeButton
            style={
                {
                    '--normal-bg': 'var(--popover)',
                    '--normal-text': 'var(--popover-foreground)',
                    '--normal-border': 'var(--border)',
                } as React.CSSProperties
            }
            {...props}
        />
    );
}

export { Toaster };
