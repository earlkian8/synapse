import { useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { leaveRoutes } from '../routes';
import type { EmployeeBalance } from '../types';

type Props = {
    employee: EmployeeBalance | null;
    year: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export function AdjustBalanceSheet({
    employee,
    year,
    open,
    onOpenChange,
}: Props) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-md"
            >
                <SheetHeader className="border-b border-border px-6 py-4">
                    <SheetTitle>Adjust entitlements</SheetTitle>
                    <SheetDescription>
                        {employee ? `${employee.full_name} · ${year}` : null}
                    </SheetDescription>
                </SheetHeader>

                {open && employee && (
                    <FormBody
                        key={`${employee.id}-${year}`}
                        employee={employee}
                        year={year}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}

function FormBody({
    employee,
    year,
    onDone,
}: {
    employee: EmployeeBalance;
    year: number;
    onDone: () => void;
}) {
    const { data, setData, post, processing, transform } = useForm<{
        entitled: Record<string, string>;
    }>({
        entitled: Object.fromEntries(
            employee.balances.map((b) => [
                String(b.leave_type_id),
                String(b.entitled),
            ]),
        ),
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        transform(() => ({
            employee_id: employee.id,
            year,
            balances: employee.balances.map((b) => ({
                leave_type_id: b.leave_type_id,
                entitled_days: Number(
                    data.entitled[String(b.leave_type_id)] ?? 0,
                ),
            })),
        }));

        post(leaveRoutes.balancesStore, {
            preserveScroll: true,
            onSuccess: () => onDone(),
        });
    };

    return (
        <form onSubmit={submit} className="flex h-full flex-col">
            <div className="flex-1 space-y-2 px-6 py-6">
                {employee.balances.map((balance) => (
                    <div
                        key={balance.leave_type_id}
                        className="flex items-center gap-3 rounded-lg border border-sidebar-border/70 px-3 py-2.5 dark:border-sidebar-border"
                    >
                        <span
                            className="size-2.5 shrink-0 rounded-full"
                            style={{ backgroundColor: balance.color }}
                        />
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-medium">
                                {balance.name}
                            </p>
                            <p className="text-xs text-muted-foreground tabular-nums">
                                {balance.used} used · {balance.pending} pending
                            </p>
                        </div>
                        <Input
                            type="number"
                            min="0"
                            step="0.5"
                            value={
                                data.entitled[String(balance.leave_type_id)] ??
                                ''
                            }
                            onChange={(e) =>
                                setData('entitled', {
                                    ...data.entitled,
                                    [String(balance.leave_type_id)]:
                                        e.target.value,
                                })
                            }
                            className="w-24 text-right tabular-nums"
                            aria-label={`${balance.name} entitled days`}
                        />
                    </div>
                ))}
                <p className="px-1 pt-2 text-xs text-muted-foreground">
                    These are the days granted for {year}. Used and pending days
                    are derived from approved and pending requests.
                </p>
            </div>

            <SheetFooter className="border-t border-border px-6 py-4">
                <div className="flex w-full items-center justify-end gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={onDone}
                        disabled={processing}
                    >
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {processing && <Spinner />}
                        Save entitlements
                    </Button>
                </div>
            </SheetFooter>
        </form>
    );
}
