import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-8 flex-shrink-0 items-center justify-center overflow-hidden rounded-lg ring-1 ring-sidebar-border">
                <AppLogoIcon className="size-8 object-contain" />
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm leading-none">
                <span className="truncate text-xs font-bold tracking-[0.2em] text-sidebar-foreground uppercase">
                    NEXO
                </span>
                <span className="mt-0.5 truncate text-[10px] tracking-wide text-sidebar-foreground/50">
                    HR Management
                </span>
            </div>
        </>
    );
}
