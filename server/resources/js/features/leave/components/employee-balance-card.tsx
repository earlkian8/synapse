import { SlidersHorizontal } from 'lucide-react';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import type { BalanceSnapshot, EmployeeBalance } from '../types';

type Props = {
    employee: EmployeeBalance;
    canManage: boolean;
    onAdjust: (employee: EmployeeBalance) => void;
};

export function EmployeeBalanceCard({ employee, canManage, onAdjust }: Props) {
    return (
        <div className="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm sm:flex-row sm:items-center dark:border-sidebar-border">
            <div className="flex min-w-0 items-center gap-3 sm:w-56 sm:shrink-0">
                <PersonAvatar
                    name={employee.full_name}
                    initials={employee.initials}
                    photo={employee.photo}
                />
                <div className="min-w-0">
                    <p className="truncate text-sm font-medium">
                        {employee.full_name}
                    </p>
                    <p className="truncate text-xs text-muted-foreground">
                        {employee.department ?? 'No department'}
                    </p>
                </div>
            </div>

            <div className="flex flex-1 flex-wrap gap-2">
                {employee.balances.map((balance) => (
                    <BalancePill
                        key={balance.leave_type_id}
                        balance={balance}
                    />
                ))}
            </div>

            {canManage && (
                <Button
                    variant="outline"
                    size="sm"
                    className="shrink-0"
                    onClick={() => onAdjust(employee)}
                >
                    <SlidersHorizontal className="size-4" />
                    Adjust
                </Button>
            )}
        </div>
    );
}

function BalancePill({ balance }: { balance: BalanceSnapshot }) {
    const low = balance.remaining <= 0 && balance.entitled > 0;

    return (
        <div
            className="rounded-lg border px-2.5 py-1.5"
            style={{ borderColor: `${balance.color}44` }}
            title={`${balance.name}: ${balance.used} used, ${balance.pending} pending`}
        >
            <div className="flex items-center gap-1.5 text-[11px] font-medium text-muted-foreground">
                <span
                    className="size-1.5 rounded-full"
                    style={{ backgroundColor: balance.color }}
                />
                {balance.code}
            </div>
            <div className="mt-0.5 text-sm font-semibold tabular-nums">
                <span className={low ? 'text-rose-600 dark:text-rose-400' : ''}>
                    {balance.remaining}
                </span>
                <span className="text-xs font-normal text-muted-foreground">
                    {' '}
                    / {balance.entitled}
                </span>
            </div>
        </div>
    );
}
