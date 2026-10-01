import { Check } from 'lucide-react';
import { useId } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';
import type { LeaveTypeDraft } from '../types';

/** The palette the Leave Types screen offers, so a wizard-made type matches. */
const COLORS = [
    '#0ABFBF',
    '#6366F1',
    '#F59E0B',
    '#10B981',
    '#EF4444',
    '#EC4899',
    '#8B5CF6',
    '#64748B',
];

type Props = {
    index: number;
    row: LeaveTypeDraft;
    errors: Record<string, string>;
    onChange: (patch: Partial<LeaveTypeDraft>) => void;
};

/**
 * A kind of leave the company is defining for itself — everything the Leave
 * Types screen asks for, in the wizard's measure.
 *
 * The three policy switches are the whole reason this is more than a name and a
 * number: they decide what an employee can actually file, and a company that
 * grants, say, unpaid study leave has to be able to say so on day one rather
 * than adopt something close and fix it later.
 */
export default function LeaveTypeFields({
    index,
    row,
    errors,
    onChange,
}: Props) {
    const position = `Leave type ${index + 1}`;

    return (
        <>
            <div className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_6rem_6rem]">
                <div>
                    <Input
                        value={row.name}
                        onChange={(event) =>
                            onChange({ name: event.target.value })
                        }
                        placeholder="e.g. Study Leave"
                        aria-label={`${position} name`}
                        className="bg-background font-medium"
                    />
                    <InputError
                        message={errors[`custom.${index}.name`]}
                        className="mt-1"
                    />
                </div>

                <div>
                    <Input
                        value={row.code}
                        onChange={(event) =>
                            onChange({ code: event.target.value.toUpperCase() })
                        }
                        placeholder="Code"
                        aria-label={`${position} code`}
                        className="bg-background font-mono text-xs uppercase"
                    />
                    <InputError
                        message={errors[`custom.${index}.code`]}
                        className="mt-1"
                    />
                </div>

                <div>
                    <Input
                        type="number"
                        min={0}
                        max={365}
                        step="0.5"
                        inputMode="decimal"
                        value={row.default_days}
                        onChange={(event) =>
                            onChange({ default_days: event.target.value })
                        }
                        aria-label={`${position} days a year`}
                        className="bg-background text-right tabular-nums"
                    />
                    <p className="mt-1 text-center text-[10px] leading-tight text-muted-foreground">
                        days a year
                    </p>
                    <InputError
                        message={errors[`custom.${index}.default_days`]}
                        className="mt-1"
                    />
                </div>
            </div>

            <Input
                value={row.description}
                onChange={(event) =>
                    onChange({ description: event.target.value })
                }
                placeholder="When someone would file this (optional)"
                aria-label={`${position} description`}
                className="mt-2 bg-background"
            />

            <div className="mt-3 flex flex-wrap items-center gap-x-5 gap-y-3">
                <div className="flex items-center gap-1.5">
                    <span className="mr-1 text-[11px] text-muted-foreground">
                        Colour
                    </span>
                    {COLORS.map((color) => (
                        <button
                            key={color}
                            type="button"
                            onClick={() => onChange({ color })}
                            style={{ backgroundColor: color }}
                            aria-label={`Use ${color} for ${row.name || position}`}
                            aria-pressed={row.color === color}
                            className={cn(
                                'flex size-5 items-center justify-center rounded-full ring-offset-2 ring-offset-background transition',
                                row.color === color &&
                                    'ring-2 ring-foreground/40',
                            )}
                        >
                            {row.color === color && (
                                <Check
                                    className="size-3 text-white"
                                    strokeWidth={3}
                                />
                            )}
                        </button>
                    ))}
                </div>

                <div className="flex flex-wrap items-center gap-x-5 gap-y-2.5">
                    <Toggle
                        label="Paid"
                        checked={row.is_paid}
                        onChange={(is_paid) => onChange({ is_paid })}
                    />
                    <Toggle
                        label="Half-days"
                        checked={row.allow_half_day}
                        onChange={(allow_half_day) =>
                            onChange({ allow_half_day })
                        }
                    />
                    <Toggle
                        label="Needs approval"
                        checked={row.requires_approval}
                        onChange={(requires_approval) =>
                            onChange({ requires_approval })
                        }
                    />
                </div>
            </div>
        </>
    );
}

/** One policy switch, small enough to sit three-across under the fields. */
function Toggle({
    label,
    checked,
    onChange,
}: {
    label: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    const id = useId();

    return (
        <div className="flex items-center gap-2">
            <Switch id={id} checked={checked} onCheckedChange={onChange} />
            <Label
                htmlFor={id}
                className="cursor-pointer text-xs font-normal text-muted-foreground"
            >
                {label}
            </Label>
        </div>
    );
}
