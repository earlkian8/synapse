import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center overflow-hidden rounded-lg ring-1 ring-sidebar-border flex-shrink-0">
                <AppLogoIcon className="size-8 object-contain" />
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm leading-none">
                <span className="truncate font-bold tracking-[0.2em] text-xs uppercase text-sidebar-foreground">
                    STAFFA
                </span>
                <span className="truncate text-[10px] text-sidebar-foreground/50 mt-0.5 tracking-wide">
                    HR Management
                </span>
            </div>
        </>
    );
}
