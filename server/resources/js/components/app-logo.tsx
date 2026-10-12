import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    return (
        <>
            {/* The mark is sized by height and given no plate: it is its own shape, and a
                tile around it would only add a second outline. */}
            <AppLogoIcon className="h-7 w-auto flex-shrink-0" />
            <div className="ml-1 grid flex-1 text-left text-sm leading-none">
                <span className="truncate text-xs font-bold tracking-[0.2em] text-sidebar-foreground uppercase">
                    SYNAPSE
                </span>
                <span className="mt-0.5 truncate text-[10px] tracking-wide text-sidebar-foreground/50">
                    HR Management
                </span>
            </div>
        </>
    );
}
