import { useForm } from '@inertiajs/react';
import { Scale } from 'lucide-react';
import {
    Modal,
    ModalBody,
    ModalContent,
    ModalFooter,
    ModalHeader,
    ModalIcon,
} from '@/components/modal';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { leaveRoutes } from '../routes';
import type { EmployeeBalance } from '../types';

type Props = {
    employee: EmployeeBalance | null;
    year: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/**
 * How many days one person is granted, per kind of leave, for one year.
 *
 * Only the entitlement is editable: used and pending days are derived from the
 * requests themselves, so they are shown beside each row as the context for the
 * number being set rather than as fields.
 */
export function AdjustBalanceDialog({
    employee,
    year,
    open,
    onOpenChange,
}: Props) {
    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="md">
                <ModalHeader
                    className="py-3.5"
                    icon={
                        <ModalIcon>
                            <Scale />
                        </ModalIcon>
                    }
                    title="Adjust entitlements"
                    description={
                        employee
                            ? `${employee.full_name} · ${year}`
                            : `Leave granted for ${year}`
                    }
                />

                {open && employee && (
                    <FormBody
                        key={`${employee.id}-${year}`}
                        employee={employee}
                        year={year}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
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
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <ModalBody className="space-y-3 py-4">
                {employee.balances.length === 0 ? (
                    <p className="rounded-lg border border-dashed border-border px-3 py-6 text-center text-sm text-muted-foreground">
                        This company grants no kinds of leave yet. Add them
                        under Company Setup → Leave Types and they will appear
                        here.
                    </p>
                ) : (
                    <>
                        <div className="flex items-center gap-3 px-3 text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                            <span className="flex-1">Kind of leave</span>
                            <span className="w-24 text-right">Days a year</span>
                        </div>

                        <ul className="divide-y divide-border overflow-hidden rounded-lg border border-border">
                            {employee.balances.map((balance) => (
                                <li
                                    key={balance.leave_type_id}
                                    className="flex items-center gap-3 px-3 py-2"
                                >
                                    <span
                                        aria-hidden
                                        className="size-2.5 shrink-0 rounded-full"
                                        style={{
                                            backgroundColor: balance.color,
                                        }}
                                    />
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium">
                                            {balance.name}
                                        </p>
                                        <p className="text-xs text-muted-foreground tabular-nums">
                                            {balance.used} used ·{' '}
                                            {balance.pending} pending
                                        </p>
                                    </div>
                                    <Input
                                        type="number"
                                        min="0"
                                        step="0.5"
                                        value={
                                            data.entitled[
                                                String(balance.leave_type_id)
                                            ] ?? ''
                                        }
                                        onChange={(e) =>
                                            setData('entitled', {
                                                ...data.entitled,
                                                [String(balance.leave_type_id)]:
                                                    e.target.value,
                                            })
                                        }
                                        className="h-9 w-24 text-right tabular-nums"
                                        aria-label={`${balance.name} days granted`}
                                    />
                                </li>
                            ))}
                        </ul>

                        <p className="text-xs text-muted-foreground">
                            These are the days granted for {year}. Used and
                            pending days come from the requests themselves and
                            cannot be edited here.
                        </p>
                    </>
                )}
            </ModalBody>

            <ModalFooter>
                <Button
                    type="button"
                    variant="outline"
                    onClick={onDone}
                    disabled={processing}
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    disabled={processing || employee.balances.length === 0}
                >
                    {processing && <Spinner />}
                    Save entitlements
                </Button>
            </ModalFooter>
        </form>
    );
}
