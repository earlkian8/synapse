import { Link } from '@inertiajs/react';
import { CircleHelp, Compass, Signpost } from 'lucide-react';
import { useRef } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { usePermissions } from '@/hooks/use-permissions';
import { tourTarget } from '../targets';
import { useProductTour } from '../use-product-tour';

/**
 * Help & resources, in the top bar: where the tour is replayed from, and — for
 * whoever sets the company up — the way back into the Setup Guide.
 */
export function HelpMenu() {
    const { can } = usePermissions();
    const { start } = useProductTour();

    // The tour opens once the menu has finished closing: the menu hands focus
    // back to its button as it goes, and the tour's first card must not have
    // focus taken from it.
    const launching = useRef(false);

    return (
        <DropdownMenu>
            <Tooltip>
                <TooltipTrigger asChild>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label="Help & resources"
                            className="size-8 text-muted-foreground hover:text-foreground"
                            {...tourTarget('help')}
                        >
                            <CircleHelp className="size-[18px]" />
                        </Button>
                    </DropdownMenuTrigger>
                </TooltipTrigger>
                <TooltipContent side="bottom" className="text-xs">
                    Help &amp; resources
                </TooltipContent>
            </Tooltip>

            <DropdownMenuContent
                align="end"
                sideOffset={8}
                className="w-64"
                onCloseAutoFocus={(event) => {
                    if (!launching.current) {
                        return;
                    }

                    launching.current = false;
                    event.preventDefault();
                    start('manual');
                }}
            >
                <DropdownMenuLabel className="text-xs font-semibold text-muted-foreground">
                    Help &amp; resources
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    className="cursor-pointer items-start gap-2.5 py-2"
                    onSelect={() => {
                        launching.current = true;
                    }}
                >
                    <Signpost className="mt-0.5" />
                    <span className="flex flex-col">
                        <span className="font-medium">Take the tour</span>
                        <span className="text-xs text-muted-foreground">
                            A one-minute look around the app
                        </span>
                    </span>
                </DropdownMenuItem>
                {can('setup.company.manage') && (
                    <DropdownMenuItem
                        asChild
                        className="cursor-pointer items-start gap-2.5 py-2"
                    >
                        <Link href="/setup/wizard">
                            <Compass className="mt-0.5" />
                            <span className="flex flex-col">
                                <span className="font-medium">Setup Guide</span>
                                <span className="text-xs text-muted-foreground">
                                    Pick up a step you skipped
                                </span>
                            </span>
                        </Link>
                    </DropdownMenuItem>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
