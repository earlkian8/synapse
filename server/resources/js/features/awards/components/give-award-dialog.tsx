import { Sparkles } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { fetchCitation, giveAward, updateAward } from '../api';
import type {
    AwardableEmployee,
    AwardPreset,
    AwardTypeOption,
    EmployeeAward,
} from '../types';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    types: AwardTypeOption[];
    employees: AwardableEmployee[];
    award: EmployeeAward | null;
    /** Pre-selected employee + type when opened from the nomination board. */
    preset?: AwardPreset | null;
    /** Whether the AI citation draft button should show (key configured). */
    aiAvailable?: boolean;
};

/**
 * Give a recognition, or edit an existing one. The employee picker only shows when
 * giving a new award — on an existing one the recipient is fixed. Opened from the
 * nomination board it arrives pre-filled, and (when AI is configured) can draft
 * the citation from the nominee's real signals.
 */
export function GiveAwardDialog({
    open,
    onOpenChange,
    types,
    employees,
    award,
    preset = null,
    aiAvailable = false,
}: Props) {
    const isEditing = Boolean(award);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {isEditing ? 'Edit recognition' : 'Give recognition'}
                    </DialogTitle>
                    <DialogDescription>
                        {isEditing
                            ? `${award?.employee?.full_name ?? 'This employee'}'s recognition.`
                            : 'Recognise an employee with an award.'}
                    </DialogDescription>
                </DialogHeader>

                {open && (
                    <FormBody
                        key={
                            award?.id ??
                            (preset
                                ? `preset-${preset.employeeId}-${preset.typeId}`
                                : 'new')
                        }
                        types={types}
                        employees={employees}
                        award={award}
                        preset={preset}
                        aiAvailable={aiAvailable}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function FormBody({
    types,
    employees,
    award,
    preset,
    aiAvailable,
    onDone,
}: {
    types: AwardTypeOption[];
    employees: AwardableEmployee[];
    award: EmployeeAward | null;
    preset: AwardPreset | null;
    aiAvailable: boolean;
    onDone: () => void;
}) {
    const isEditing = Boolean(award);
    const today = new Date().toISOString().slice(0, 10);

    const [employeeId, setEmployeeId] = useState(
        preset ? String(preset.employeeId) : '',
    );
    const [typeId, setTypeId] = useState(
        award?.award_type
            ? String(award.award_type.id)
            : preset
              ? String(preset.typeId)
              : '',
    );
    const [awardedOn, setAwardedOn] = useState(award?.awarded_on ?? today);
    const [reason, setReason] = useState(award?.reason ?? '');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [drafting, setDrafting] = useState(false);
    const [draftError, setDraftError] = useState<string | null>(null);

    const canSubmit =
        typeId !== '' && awardedOn !== '' && (isEditing || employeeId !== '');

    const canDraft =
        aiAvailable && !isEditing && employeeId !== '' && typeId !== '';

    const handlers = {
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
        onError: setErrors,
        onSuccess: onDone,
    };

    const draft = async () => {
        setDrafting(true);
        setDraftError(null);

        try {
            const result = await fetchCitation(
                Number(employeeId),
                Number(typeId),
            );

            if (result.available) {
                setReason(result.citation);
            } else {
                setDraftError(result.reason);
            }
        } finally {
            setDrafting(false);
        }
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (!canSubmit) {
            return;
        }

        if (isEditing && award) {
            updateAward(
                award.id,
                {
                    award_type_id: Number(typeId),
                    awarded_on: awardedOn,
                    reason: reason || null,
                },
                handlers,
            );
        } else {
            giveAward(
                {
                    employee_id: Number(employeeId),
                    award_type_id: Number(typeId),
                    awarded_on: awardedOn,
                    reason: reason || null,
                },
                handlers,
            );
        }
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4 py-1">
            {!isEditing && (
                <Field label="Employee" error={errors.employee_id} required>
                    <Select value={employeeId} onValueChange={setEmployeeId}>
                        <SelectTrigger className="w-full">
                            <SelectValue placeholder="Select an employee" />
                        </SelectTrigger>
                        <SelectContent>
                            {employees.map((e) => (
                                <SelectItem key={e.id} value={String(e.id)}>
                                    {e.full_name}
                                    <span className="ml-1 text-muted-foreground">
                                        · {e.employee_no}
                                    </span>
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
            )}

            <Field label="Award" error={errors.award_type_id} required>
                {types.length === 0 ? (
                    <p className="rounded-md border border-dashed border-border px-3 py-2 text-xs text-muted-foreground">
                        No active award types. Add some under Company Setup →
                        Award Types.
                    </p>
                ) : (
                    <Select value={typeId} onValueChange={setTypeId}>
                        <SelectTrigger className="w-full">
                            <SelectValue placeholder="Select an award" />
                        </SelectTrigger>
                        <SelectContent>
                            {types.map((t) => (
                                <SelectItem key={t.id} value={String(t.id)}>
                                    {t.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                )}
            </Field>

            <Field label="Awarded on" error={errors.awarded_on} required>
                <Input
                    type="date"
                    max={today}
                    value={awardedOn}
                    onChange={(e) => setAwardedOn(e.target.value)}
                />
            </Field>

            <Field label="Reason" error={errors.reason}>
                <textarea
                    value={reason}
                    onChange={(e) => setReason(e.target.value)}
                    rows={3}
                    placeholder="What is this recognition for? (optional)"
                    className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-colors placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                />
                {canDraft && (
                    <div className="flex flex-col gap-1">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-7 w-fit px-2 text-xs text-[#0a8b91] hover:text-[#0a8b91] dark:text-[#0ABFBF] dark:hover:text-[#0ABFBF]"
                            onClick={draft}
                            disabled={drafting}
                        >
                            {drafting ? (
                                <Spinner className="size-3.5" />
                            ) : (
                                <Sparkles className="size-3.5" />
                            )}
                            {drafting
                                ? 'Drafting from their signals…'
                                : 'Draft with AI'}
                        </Button>
                        {draftError && (
                            <p className="text-xs text-amber-600 dark:text-amber-400">
                                {draftError}
                            </p>
                        )}
                    </div>
                )}
            </Field>

            <DialogFooter className="mt-1">
                <Button
                    type="button"
                    variant="outline"
                    onClick={onDone}
                    disabled={processing}
                >
                    Cancel
                </Button>
                <Button type="submit" disabled={processing || !canSubmit}>
                    {processing && <Spinner />}
                    {isEditing ? 'Save changes' : 'Give recognition'}
                </Button>
            </DialogFooter>
        </form>
    );
}

function Field({
    label,
    error,
    required = false,
    children,
}: {
    label: string;
    error?: string;
    required?: boolean;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-1.5">
            <Label className="text-xs">
                {label}
                {required && <span className="ml-0.5 text-destructive">*</span>}
            </Label>
            {children}
            {error && <p className="text-xs text-rose-600">{error}</p>}
        </div>
    );
}
