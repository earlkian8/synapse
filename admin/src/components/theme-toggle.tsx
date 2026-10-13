import { Monitor, Moon, Sun } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useAppearance } from '@/hooks/use-appearance';
import type { Appearance } from '@/hooks/use-appearance';

const options: { value: Appearance; label: string; icon: typeof Sun }[] = [
    { value: 'light', label: 'Light', icon: Sun },
    { value: 'dark', label: 'Dark', icon: Moon },
    { value: 'system', label: 'System', icon: Monitor },
];

export function ThemeToggle() {
    const { appearance, resolvedAppearance, updateAppearance } =
        useAppearance();

    return (
        <DropdownMenu>
            <Tooltip>
                <TooltipTrigger asChild>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label="Toggle theme"
                            className="size-8 text-muted-foreground hover:text-foreground"
                        >
                            {resolvedAppearance === 'dark' ? (
                                <Moon className="size-[18px]" />
                            ) : (
                                <Sun className="size-[18px]" />
                            )}
                        </Button>
                    </DropdownMenuTrigger>
                </TooltipTrigger>
                <TooltipContent side="bottom" className="text-xs">
                    Theme
                </TooltipContent>
            </Tooltip>

            <DropdownMenuContent align="end" sideOffset={8} className="w-36">
                <DropdownMenuRadioGroup
                    value={appearance}
                    onValueChange={(v) => updateAppearance(v as Appearance)}
                >
                    {options.map(({ value, label, icon: Icon }) => (
                        <DropdownMenuRadioItem
                            key={value}
                            value={value}
                            className="cursor-pointer gap-2 text-[13px]"
                        >
                            <Icon className="size-4 text-muted-foreground" />
                            {label}
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
