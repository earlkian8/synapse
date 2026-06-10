import { Check, Minus } from 'lucide-react';
import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';
import { DEFAULT_GROUP_ACCENT, GROUP_ACCENTS } from '../constants';
import type { PermissionGroup } from '../types';

type Props = {
    groups: PermissionGroup[];
    value: string[];
    onChange?: (next: string[]) => void;
    readOnly?: boolean;
    /** When set, every permission renders as granted (for the super-admin role). */
    grantAll?: boolean;
};

/**
 * A grouped grid of permissions. Drives the create/edit form when interactive,
 * and renders a read-only summary in the detail drawer.
 */
export function PermissionMatrix({
    groups,
    value,
    onChange,
    readOnly = false,
    grantAll = false,
}: Props) {
    const selected = new Set(value);
    const isGranted = (name: string) => grantAll || selected.has(name);

    const setMany = (names: string[], granted: boolean) => {
        if (!onChange) {
            return;
        }

        const next = new Set(selected);
        names.forEach((name) => (granted ? next.add(name) : next.delete(name)));
        onChange([...next]);
    };

    return (
        <div className="space-y-4">
            {groups.map((group) => {
                const names = group.permissions.map(
                    (permission) => permission.name,
                );
                const grantedCount = names.filter(isGranted).length;
                const allGranted = grantedCount === names.length;
                const someGranted = grantedCount > 0 && !allGranted;
                const accent =
                    GROUP_ACCENTS[group.group] ?? DEFAULT_GROUP_ACCENT;

                return (
                    <div
                        key={group.group}
                        className="overflow-hidden rounded-xl border border-border bg-card"
                    >
                        <div className="flex items-center justify-between gap-3 border-b border-border bg-muted/40 px-4 py-2.5">
                            <div className="flex items-center gap-2">
                                <span
                                    className={cn(
                                        'flex size-6 items-center justify-center rounded-md text-[11px] font-semibold',
                                        accent,
                                    )}
                                >
                                    {grantedCount}
                                </span>
                                <span className="text-sm font-semibold">
                                    {group.group}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {grantedCount}/{names.length}
                                </span>
                            </div>

                            {!readOnly && !grantAll && (
                                <label className="flex cursor-pointer items-center gap-2 text-xs font-medium text-muted-foreground">
                                    <Checkbox
                                        checked={
                                            allGranted
                                                ? true
                                                : someGranted
                                                  ? 'indeterminate'
                                                  : false
                                        }
                                        onCheckedChange={(checked) =>
                                            setMany(names, checked === true)
                                        }
                                        aria-label={`Toggle all ${group.group} permissions`}
                                    />
                                    Select all
                                </label>
                            )}
                        </div>

                        <div className="grid gap-px bg-border sm:grid-cols-2">
                            {group.permissions.map((permission) => {
                                const granted = isGranted(permission.name);

                                return (
                                    <label
                                        key={permission.name}
                                        className={cn(
                                            'flex items-center gap-3 bg-card px-4 py-2.5 transition-colors',
                                            !readOnly &&
                                                !grantAll &&
                                                'cursor-pointer hover:bg-muted/40',
                                        )}
                                    >
                                        {readOnly || grantAll ? (
                                            <span
                                                className={cn(
                                                    'flex size-4 items-center justify-center rounded-full',
                                                    granted
                                                        ? 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400'
                                                        : 'bg-muted text-muted-foreground/50',
                                                )}
                                            >
                                                {granted ? (
                                                    <Check className="size-3" />
                                                ) : (
                                                    <Minus className="size-3" />
                                                )}
                                            </span>
                                        ) : (
                                            <Checkbox
                                                checked={granted}
                                                onCheckedChange={(checked) =>
                                                    setMany(
                                                        [permission.name],
                                                        checked === true,
                                                    )
                                                }
                                                aria-label={permission.label}
                                            />
                                        )}
                                        <span className="min-w-0">
                                            <span
                                                className={cn(
                                                    'block truncate text-sm',
                                                    granted
                                                        ? 'font-medium text-foreground'
                                                        : 'text-muted-foreground',
                                                )}
                                            >
                                                {permission.label}
                                            </span>
                                            <span className="block truncate font-mono text-[11px] text-muted-foreground/70">
                                                {permission.name}
                                            </span>
                                        </span>
                                    </label>
                                );
                            })}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
