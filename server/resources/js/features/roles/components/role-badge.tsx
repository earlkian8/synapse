import { Lock, ShieldCheck, SlidersHorizontal } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { ManagedRole } from '../types';

type Props = {
    role: Pick<ManagedRole, 'is_system' | 'is_super_admin'>;
};

/**
 * Distinguishes the protected owner role (HR Manager), other built-in system
 * roles, and editable custom roles at a glance.
 */
export function RoleBadge({ role }: Props) {
    if (role.is_super_admin) {
        return (
            <span className="inline-flex items-center gap-1.5 rounded-full border border-indigo-200 bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 dark:border-indigo-500/20 dark:bg-indigo-500/10 dark:text-indigo-400">
                <ShieldCheck className="size-3" />
                Owner
            </span>
        );
    }

    const style = role.is_system
        ? 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-400'
        : 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-400';

    const Icon = role.is_system ? Lock : SlidersHorizontal;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs font-medium',
                style,
            )}
        >
            <Icon className="size-3" />
            {role.is_system ? 'System' : 'Custom'}
        </span>
    );
}
