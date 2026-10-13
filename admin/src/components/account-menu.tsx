import { ChevronDown, LogOut, ShieldCheck, UserRound } from 'lucide-react';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useSignOut } from '@/features/auth/use-sign-out';
import type { Admin } from '@/lib/types';

/**
 * The signed-in admin's menu. Named, not pictured: admin accounts carry no
 * photo, so the button shows the name itself (an icon on a phone).
 */
export function AccountMenu({ admin }: { admin: Admin }) {
    const signOut = useSignOut();

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="sm"
                    className="h-8 gap-1.5 px-2 text-sm font-medium"
                    aria-label={`Account menu for ${admin.name}`}
                >
                    <UserRound className="size-4 sm:hidden" />
                    <span className="hidden max-w-40 truncate sm:inline">
                        {admin.name}
                    </span>
                    <ChevronDown className="size-3.5 text-muted-foreground" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" sideOffset={8} className="w-60">
                <DropdownMenuLabel className="font-normal">
                    <span className="block truncate text-sm font-medium">
                        {admin.name}
                    </span>
                    <span className="block truncate text-xs text-muted-foreground">
                        {admin.email}
                    </span>
                    <span className="mt-1.5 inline-flex items-center gap-1 rounded-full border border-[#0ABFBF]/30 bg-[#0ABFBF]/10 px-2 py-0.5 text-[11px] font-medium text-[#08767c] dark:text-[#0ABFBF]">
                        <ShieldCheck className="size-3" />
                        {admin.role_label}
                    </span>
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem asChild>
                    <Link to="/account">
                        <UserRound />
                        Account and security
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={() => void signOut()}>
                    <LogOut />
                    Sign out
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
