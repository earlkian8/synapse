import { Link } from '@inertiajs/react';
import {
    BookOpen,
    CircleHelp,
    Compass,
    FileQuestion,
    MessageSquareWarning,
    Signpost,
} from 'lucide-react';
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
import { helpRoutes } from '@/features/help-center/routes';
import { problemReportLauncher } from '@/features/problem-reports/launcher';
import { problemReportRoutes } from '@/features/problem-reports/routes';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { usePermissions } from '@/hooks/use-permissions';
import { tourTarget } from '../targets';
import { useProductTour } from '../use-product-tour';

/**
 * Help & resources, in the top bar: the Help Center (ADR 0062) and the article
 * for the page in view, where the tour is replayed from, and — for whoever
 * sets the company up — the way back into the Setup Guide. Last, telling the
 * SYNAPSE team something is wrong (ADR 0096), and one's reports to them.
 */
export function HelpMenu() {
    const { can } = usePermissions();
    const { start } = useProductTour();
    const { currentUrl } = useCurrentUrl();
    const inHelpCenter =
        currentUrl === helpRoutes.index ||
        currentUrl.startsWith(`${helpRoutes.index}/`);

    // The tour and the report dialog open once the menu has finished closing:
    // the menu hands focus back to its button as it goes, and neither may have
    // focus taken from it.
    const launching = useRef<'tour' | 'report' | null>(null);

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
                    const launch = launching.current;

                    if (!launch) {
                        return;
                    }

                    launching.current = null;
                    event.preventDefault();

                    if (launch === 'tour') {
                        start('manual');
                    } else {
                        problemReportLauncher.open();
                    }
                }}
            >
                <DropdownMenuLabel className="text-xs font-semibold text-muted-foreground">
                    Help &amp; resources
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    asChild
                    className="cursor-pointer items-start gap-2.5 py-2"
                >
                    <Link href={helpRoutes.index}>
                        <BookOpen className="mt-0.5" />
                        <span className="flex flex-col">
                            <span className="font-medium">Help Center</span>
                            <span className="text-xs text-muted-foreground">
                                Guides for everything you can use
                            </span>
                        </span>
                    </Link>
                </DropdownMenuItem>
                {!inHelpCenter && (
                    <DropdownMenuItem
                        asChild
                        className="cursor-pointer items-start gap-2.5 py-2"
                    >
                        <Link href={helpRoutes.forPage(currentUrl)}>
                            <FileQuestion className="mt-0.5" />
                            <span className="flex flex-col">
                                <span className="font-medium">
                                    Help for this page
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    The guide to the screen you are on
                                </span>
                            </span>
                        </Link>
                    </DropdownMenuItem>
                )}
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    className="cursor-pointer items-start gap-2.5 py-2"
                    onSelect={() => {
                        launching.current = 'tour';
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
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    className="cursor-pointer items-start gap-2.5 py-2"
                    onSelect={() => {
                        launching.current = 'report';
                    }}
                >
                    <MessageSquareWarning className="mt-0.5" />
                    <span className="flex flex-col">
                        <span className="font-medium">Report a problem</span>
                        <span className="text-xs text-muted-foreground">
                            When something isn’t working
                        </span>
                    </span>
                </DropdownMenuItem>
                <DropdownMenuItem
                    asChild
                    className="cursor-pointer py-1.5 pl-[2.125rem] text-xs text-muted-foreground"
                >
                    <Link href={problemReportRoutes.index}>Your reports</Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
