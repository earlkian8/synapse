import { usePage } from '@inertiajs/react';
import { Building2, Pencil, Plus, RotateCcw, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import type { Auth } from '@/types/auth';
import { adjustPayslip, resetPayslip } from '../api';
import { formatDate, formatPeso, formatRange } from '../constants';
import type { PayItemType, PayrollCatalogue, Payslip } from '../types';
import { PayslipStatusBadge } from './payroll-status-badge';

const CUSTOM = '__custom__';

type EditLine = {
    key: string;
    label: string;
    amount: string;
    typeId: string;
};

/**
 * The payslip as a document — a clean letterhead-style layout with itemized
 * earnings and deductions and a net-pay footer. When the run is still open and the
 * user can adjust payroll, it switches into an **edit mode** where the allowance
 * earning lines and deduction lines can be hand-edited (basic & overtime pay stay
 * auto), with a live net preview. An adjusted payslip can be reset to auto.
 */
export function PayslipDocumentModal({
    payslip,
    open,
    canAdjust,
    catalogue,
    onOpenChange,
    onChanged,
}: {
    payslip: Payslip | null;
    open: boolean;
    canAdjust: boolean;
    catalogue: PayrollCatalogue;
    onOpenChange: (open: boolean) => void;
    onChanged: () => void;
}) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const company = auth.organization?.name ?? 'Company';

    const [editing, setEditing] = useState(false);
    const [earnings, setEarnings] = useState<EditLine[]>([]);
    const [deductions, setDeductions] = useState<EditLine[]>([]);
    const [busy, setBusy] = useState(false);
    const [trackedId, setTrackedId] = useState<number | null>(null);

    // Render-phase reset when the modal switches to a different payslip (avoids
    // state-syncing effects).
    if (payslip && payslip.id !== trackedId) {
        setTrackedId(payslip.id);
        setEditing(false);
        setBusy(false);
    }

    if (!payslip) {
        return null;
    }

    const locked =
        payslip.period?.status === 'finalized' ||
        payslip.period?.status === 'paid';
    const editable = canAdjust && !locked;

    const seed = (
        lines: { label: string; amount: number; type: number | null }[],
    ) =>
        lines.map((l, i) => ({
            key: `seed-${i}-${l.label}`,
            label: l.label,
            amount: String(l.amount),
            typeId: l.type !== null ? String(l.type) : '',
        }));

    const beginEdit = () => {
        setEarnings(
            seed(
                (payslip.earnings ?? []).map((e) => ({
                    label: e.label,
                    amount: e.amount,
                    type: e.allowance_type_id ?? null,
                })),
            ),
        );
        setDeductions(
            seed(
                (payslip.deductions ?? []).map((d) => ({
                    label: d.label,
                    amount: d.amount,
                    type: d.deduction_type_id ?? null,
                })),
            ),
        );
        setEditing(true);
    };

    // Auto earnings shown above the editable allowance lines.
    const autoEarnings = [
        { label: 'Basic Pay', amount: payslip.basic_pay },
        ...(payslip.overtime_pay > 0
            ? [{ label: 'Overtime Pay', amount: payslip.overtime_pay }]
            : []),
    ];
    const autoEarningsTotal = autoEarnings.reduce((s, l) => s + l.amount, 0);

    const sum = (lines: EditLine[]) =>
        lines.reduce((s, l) => s + (Number(l.amount) || 0), 0);

    const previewGross = autoEarningsTotal + sum(earnings);
    const previewDeductions = sum(deductions);
    const previewNet = previewGross - previewDeductions;

    const linesValid = (lines: EditLine[]) =>
        lines.every((l) => l.label.trim() !== '' && Number(l.amount) >= 0);
    const canSave = linesValid(earnings) && linesValid(deductions) && !busy;

    const toPayload = (lines: EditLine[], field: 'allowance' | 'deduction') =>
        lines.map((l) => ({
            label: l.label.trim(),
            amount: Number(l.amount) || 0,
            ...(field === 'allowance'
                ? { allowance_type_id: l.typeId ? Number(l.typeId) : null }
                : { deduction_type_id: l.typeId ? Number(l.typeId) : null }),
        }));

    const save = () => {
        adjustPayslip(
            payslip.hashid,
            {
                earnings: toPayload(earnings, 'allowance'),
                deductions: toPayload(deductions, 'deduction'),
            },
            {
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onSuccess: () => {
                    setEditing(false);
                    onChanged();
                },
            },
        );
    };

    const reset = () =>
        resetPayslip(payslip.hashid, {
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: () => {
                setEditing(false);
                onChanged();
            },
        });

    const viewEarnings = [
        ...autoEarnings,
        ...(payslip.earnings ?? []).map((e) => ({
            label: e.label,
            amount: e.amount,
        })),
    ];

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] gap-0 overflow-y-auto p-0 sm:max-w-2xl">
                <DialogHeader className="sr-only">
                    <DialogTitle>
                        Payslip — {payslip.employee?.full_name}
                    </DialogTitle>
                </DialogHeader>

                {/* Letterhead */}
                <div className="flex items-start justify-between gap-4 border-b border-border bg-muted/30 px-6 py-5">
                    <div className="flex items-center gap-3">
                        <span className="flex size-10 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[#0ABFBF]">
                            <Building2 className="size-5" />
                        </span>
                        <div>
                            <p className="font-semibold tracking-tight">
                                {company}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Payslip
                            </p>
                        </div>
                    </div>
                    <div className="text-right">
                        <p className="text-xs text-muted-foreground">
                            {payslip.period?.name}
                        </p>
                        {payslip.period && (
                            <p className="text-xs text-muted-foreground">
                                {formatRange(
                                    payslip.period.start_date,
                                    payslip.period.end_date,
                                )}
                            </p>
                        )}
                        <div className="mt-1 flex items-center justify-end gap-1.5">
                            {payslip.is_adjusted && (
                                <span className="rounded bg-amber-500/10 px-1.5 py-0.5 text-[10px] font-semibold text-amber-600 dark:text-amber-400">
                                    Adjusted
                                </span>
                            )}
                            <PayslipStatusBadge status={payslip.status} />
                        </div>
                    </div>
                </div>

                {/* Employee + pay meta */}
                <div className="grid grid-cols-2 gap-4 px-6 py-5 sm:grid-cols-4">
                    <Meta
                        label="Employee"
                        value={payslip.employee?.full_name ?? '—'}
                    />
                    <Meta
                        label="Employee no."
                        value={payslip.employee?.employee_no ?? '—'}
                    />
                    <Meta
                        label="Department"
                        value={payslip.employee?.department ?? '—'}
                    />
                    <Meta
                        label="Pay date"
                        value={formatDate(payslip.period?.pay_date ?? null)}
                    />
                </div>

                {/* Action bar */}
                {editable && (
                    <div className="flex items-center justify-end gap-2 px-6 pb-1">
                        {editing ? (
                            <>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setEditing(false)}
                                    disabled={busy}
                                >
                                    <X className="size-4" />
                                    Cancel
                                </Button>
                                <Button
                                    size="sm"
                                    onClick={save}
                                    disabled={!canSave}
                                >
                                    {busy ? <Spinner /> : null}
                                    Save adjustments
                                </Button>
                            </>
                        ) : (
                            <>
                                {payslip.is_adjusted && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={reset}
                                        disabled={busy}
                                    >
                                        <RotateCcw className="size-4" />
                                        Reset to auto
                                    </Button>
                                )}
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={beginEdit}
                                >
                                    <Pencil className="size-4" />
                                    Edit lines
                                </Button>
                            </>
                        )}
                    </div>
                )}

                {/* Earnings + deductions */}
                {editing ? (
                    <div className="grid gap-6 px-6 py-3 sm:grid-cols-2">
                        <EditSection
                            title="Earnings"
                            autoLines={autoEarnings}
                            lines={earnings}
                            types={catalogue.allowanceTypes}
                            onChange={setEarnings}
                            total={previewGross}
                            totalLabel="Gross pay"
                        />
                        <EditSection
                            title="Deductions"
                            lines={deductions}
                            types={catalogue.deductionTypes}
                            onChange={setDeductions}
                            total={previewDeductions}
                            totalLabel="Total deductions"
                            negative
                        />
                    </div>
                ) : (
                    <div className="grid gap-6 px-6 pb-2 sm:grid-cols-2">
                        <Section
                            title="Earnings"
                            lines={viewEarnings}
                            total={payslip.gross_pay}
                            totalLabel="Gross pay"
                        />
                        <Section
                            title="Deductions"
                            lines={payslip.deductions ?? []}
                            total={payslip.total_deductions}
                            totalLabel="Total deductions"
                            negative
                        />
                    </div>
                )}

                {/* Net pay footer */}
                <div className="mt-3 flex items-center justify-between border-t border-border bg-[#0ABFBF]/5 px-6 py-5">
                    <div>
                        <p className="text-xs tracking-wide text-muted-foreground uppercase">
                            Net pay
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {payslip.days_worked} days worked
                        </p>
                    </div>
                    <p className="text-2xl font-bold tracking-tight text-[#0a8b91] tabular-nums dark:text-[#0ABFBF]">
                        {formatPeso(editing ? previewNet : payslip.net_pay)}
                    </p>
                </div>
            </DialogContent>
        </Dialog>
    );
}

function Meta({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex flex-col">
            <span className="text-[11px] tracking-wide text-muted-foreground uppercase">
                {label}
            </span>
            <span className="truncate text-sm font-medium">{value}</span>
        </div>
    );
}

function Section({
    title,
    lines,
    total,
    totalLabel,
    negative = false,
}: {
    title: string;
    lines: { label: string; amount: number }[];
    total: number;
    totalLabel: string;
    negative?: boolean;
}) {
    return (
        <div className="flex flex-col gap-2">
            <p className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                {title}
            </p>
            <div className="flex flex-col gap-1.5">
                {lines.length === 0 ? (
                    <p className="text-sm text-muted-foreground">None</p>
                ) : (
                    lines.map((line, i) => (
                        <div
                            key={`${line.label}-${i}`}
                            className="flex items-center justify-between text-sm"
                        >
                            <span className="text-muted-foreground">
                                {line.label}
                            </span>
                            <span className="tabular-nums">
                                {negative && '−'}
                                {formatPeso(line.amount)}
                            </span>
                        </div>
                    ))
                )}
            </div>
            <div className="mt-auto flex items-center justify-between border-t border-border pt-2 text-sm font-semibold">
                <span>{totalLabel}</span>
                <span className="tabular-nums">
                    {negative && '−'}
                    {formatPeso(total)}
                </span>
            </div>
        </div>
    );
}

function EditSection({
    title,
    autoLines = [],
    lines,
    types,
    onChange,
    total,
    totalLabel,
    negative = false,
}: {
    title: string;
    autoLines?: { label: string; amount: number }[];
    lines: EditLine[];
    types: PayItemType[];
    onChange: (lines: EditLine[]) => void;
    total: number;
    totalLabel: string;
    negative?: boolean;
}) {
    const update = (key: string, patch: Partial<EditLine>) =>
        onChange(lines.map((l) => (l.key === key ? { ...l, ...patch } : l)));

    const remove = (key: string) =>
        onChange(lines.filter((l) => l.key !== key));

    const add = () =>
        onChange([
            ...lines,
            {
                key: `new-${Date.now()}-${Math.random().toString(36).slice(2)}`,
                label: '',
                amount: '',
                typeId: '',
            },
        ]);

    const pickType = (key: string, value: string) => {
        if (value === CUSTOM) {
            update(key, { typeId: '' });

            return;
        }

        const type = types.find((t) => String(t.id) === value);
        update(key, { typeId: value, label: type?.name ?? '' });
    };

    return (
        <div className="flex flex-col gap-2">
            <p className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                {title}
            </p>

            {/* Auto lines (read-only): basic & overtime pay. */}
            {autoLines.map((line) => (
                <div
                    key={line.label}
                    className="flex items-center justify-between text-sm text-muted-foreground"
                >
                    <span>{line.label}</span>
                    <span className="tabular-nums">
                        {formatPeso(line.amount)}
                    </span>
                </div>
            ))}

            <div className="flex flex-col gap-2">
                {lines.map((line) => (
                    <div key={line.key} className="flex flex-col gap-1.5">
                        <div className="flex items-center gap-1.5">
                            <Select
                                value={line.typeId || CUSTOM}
                                onValueChange={(v) => pickType(line.key, v)}
                            >
                                <SelectTrigger className="h-8 w-28 shrink-0 text-xs">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={CUSTOM}>
                                        Custom
                                    </SelectItem>
                                    {types.map((t) => (
                                        <SelectItem
                                            key={t.id}
                                            value={String(t.id)}
                                        >
                                            {t.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Input
                                value={line.label}
                                onChange={(ev) =>
                                    update(line.key, { label: ev.target.value })
                                }
                                placeholder="Label"
                                className="h-8 flex-1 text-xs"
                            />
                            <button
                                type="button"
                                onClick={() => remove(line.key)}
                                className="shrink-0 rounded-md p-1.5 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                aria-label="Remove line"
                            >
                                <Trash2 className="size-3.5" />
                            </button>
                        </div>
                        <Input
                            type="number"
                            min="0"
                            step="0.01"
                            inputMode="decimal"
                            value={line.amount}
                            onChange={(ev) =>
                                update(line.key, { amount: ev.target.value })
                            }
                            placeholder="0.00"
                            className="h-8 text-xs tabular-nums"
                        />
                    </div>
                ))}
            </div>

            <Button
                variant="ghost"
                size="sm"
                onClick={add}
                className="h-7 w-fit text-xs"
            >
                <Plus className="size-3.5" />
                Add line
            </Button>

            <div className="mt-auto flex items-center justify-between border-t border-border pt-2 text-sm font-semibold">
                <span>{totalLabel}</span>
                <span className="tabular-nums">
                    {negative && '−'}
                    {formatPeso(total)}
                </span>
            </div>
        </div>
    );
}
