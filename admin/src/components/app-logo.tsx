import { useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';

/**
 * The SYNAPSE mark (the ERP's `synapse-mark*.svg`): the reversed colourway on
 * the navy field and in dark mode, where the deeper blues would sink.
 */
export function AppLogoIcon({
    surface = 'auto',
    className,
}: {
    surface?: 'auto' | 'dark';
    className?: string;
}) {
    const { resolvedAppearance } = useAppearance();
    const onDark = surface === 'dark' || resolvedAppearance === 'dark';

    return (
        <img
            src={onDark ? '/synapse-mark-reversed.svg' : '/synapse-mark.svg'}
            alt="SYNAPSE"
            className={cn('h-7 w-auto shrink-0', className)}
        />
    );
}

/** The sidebar's brand: the mark, the name, and which side of SYNAPSE this is. */
export function AppLogo() {
    return (
        <>
            <AppLogoIcon />
            <div className="ml-1 grid flex-1 text-left text-sm leading-none">
                <span className="truncate text-xs font-bold tracking-[0.2em] text-sidebar-foreground uppercase">
                    SYNAPSE
                </span>
                <span className="mt-0.5 truncate text-[10px] tracking-wide text-sidebar-foreground/50">
                    Admin portal
                </span>
            </div>
        </>
    );
}
