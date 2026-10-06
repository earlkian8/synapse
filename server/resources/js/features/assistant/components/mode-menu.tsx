import { ChevronDown, Hand, ShieldCheck, Zap } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { AssistantMode } from '../types';

const MODES: {
    value: AssistantMode;
    label: string;
    icon: typeof Hand;
    description: string;
}[] = [
    {
        value: 'manual',
        label: 'Manual',
        icon: Hand,
        description: 'You approve every change before it happens.',
    },
    {
        value: 'balanced',
        label: 'Balanced',
        icon: ShieldCheck,
        description:
            'Asks first for big changes, for changes on a question, and when a file is attached.',
    },
    {
        value: 'auto',
        label: 'Auto',
        icon: Zap,
        description:
            'Makes changes you ask for straight away, files included. A file you attach could steer an edit, so use Balanced for files you do not trust. Deleting, hiring, granting access and notifying people still ask first.',
    },
];

/**
 * Which changes wait for the person's OK (ADR 0068). It applies from the next
 * message; the server enforces it, and it grants nothing — every change is
 * still checked against the person's role.
 */
export function ModeMenu({
    mode,
    onChange,
}: {
    mode: AssistantMode;
    onChange: (mode: AssistantMode) => void;
}) {
    const current = MODES.find((m) => m.value === mode) ?? MODES[1];
    const Icon = current.icon;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                aria-label={`Changes: ${current.label}. Choose when the assistant asks first`}
                className="flex h-8 shrink-0 items-center gap-1 rounded-md px-1.5 text-xs font-medium text-muted-foreground transition-colors outline-none hover:bg-muted hover:text-foreground focus-visible:ring-3 focus-visible:ring-assistant-signal/25 data-[state=open]:bg-muted data-[state=open]:text-foreground"
            >
                <Icon className="size-3.5" />
                {current.label}
                <ChevronDown className="size-3 opacity-60" />
            </DropdownMenuTrigger>

            <DropdownMenuContent
                align="start"
                side="top"
                sideOffset={6}
                className="w-72"
            >
                <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">
                    When should the assistant ask first?
                </DropdownMenuLabel>
                <DropdownMenuRadioGroup
                    value={mode}
                    onValueChange={(value) => onChange(value as AssistantMode)}
                >
                    {MODES.map(
                        ({ value, label, icon: ItemIcon, description }) => (
                            <DropdownMenuRadioItem
                                key={value}
                                value={value}
                                className="cursor-pointer items-start py-2"
                            >
                                <ItemIcon className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                <span className="flex flex-col gap-0.5">
                                    <span className="text-[13px] font-medium">
                                        {label}
                                    </span>
                                    <span className="text-xs leading-4 text-muted-foreground">
                                        {description}
                                    </span>
                                </span>
                            </DropdownMenuRadioItem>
                        ),
                    )}
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
