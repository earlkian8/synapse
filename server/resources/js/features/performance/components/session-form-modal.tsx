import { Scale } from 'lucide-react';
import { useState } from 'react';
import { FormField } from '@/components/form-field';
import { FormSelect } from '@/components/form-select';
import {
    Modal,
    ModalBody,
    ModalContent,
    ModalFooter,
    ModalHeader,
    ModalIcon,
} from '@/components/modal';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { createSession, updateSession } from '../api';
import { TEXTAREA } from '../constants';
import type { CalibrationSession, Calibrator } from '../types';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Editing an open session; omitted to open a new one. */
    session?: CalibrationSession;
    periods: { id: number; name: string }[];
    defaultPeriodId: number | null;
    departments: { id: number; name: string }[];
    calibrators: Calibrator[];
};

/**
 * Open a calibration session (ADR 0073) — over a whole cycle or some of its
 * departments, on a date, with the people who will sit in it — or change an
 * open one's name, date, notes and people. What it covers is fixed once it
 * opens, since appraisals are held back by it.
 */
export function SessionFormModal({
    open,
    onOpenChange,
    session,
    periods,
    defaultPeriodId,
    departments,
    calibrators,
}: Props) {
    const editing = session !== undefined;
    const [name, setName] = useState(session?.name ?? '');
    const [periodId, setPeriodId] = useState(
        String(
            periods.find((p) => p.id === defaultPeriodId)?.id ??
                periods[0]?.id ??
                '',
        ),
    );
    const [scope, setScope] = useState<'all' | 'departments'>(
        session?.department_ids ? 'departments' : 'all',
    );
    const [departmentIds, setDepartmentIds] = useState<number[]>(
        session?.department_ids ?? [],
    );
    const [date, setDate] = useState(session?.scheduled_for ?? '');
    const [notes, setNotes] = useState(session?.notes ?? '');
    const [participants, setParticipants] = useState<number[]>(
        session?.participants?.map((p) => p.id) ?? [],
    );
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const toggle = (
        list: number[],
        set: (next: number[]) => void,
        id: number,
    ) => set(list.includes(id) ? list.filter((v) => v !== id) : [...list, id]);

    const submit = () => {
        const handlers = {
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => onOpenChange(false),
            onError: setErrors,
        };
        const common = {
            name: name.trim(),
            scheduled_for: date || null,
            notes: notes.trim() || null,
            participant_ids: participants,
        };

        if (editing) {
            updateSession(session.hashid, common, handlers);
        } else {
            createSession(
                {
                    ...common,
                    evaluation_period_id: Number(periodId),
                    department_ids:
                        scope === 'departments' ? departmentIds : null,
                },
                handlers,
            );
        }
    };

    const blocked =
        name.trim() === '' ||
        (!editing && !periodId) ||
        (!editing && scope === 'departments' && departmentIds.length === 0);

    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="lg">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <Scale />
                        </ModalIcon>
                    }
                    title={editing ? 'Edit session' : 'New calibration session'}
                    description={
                        editing
                            ? `What it covers (${session.scope_label}) is fixed once a session opens.`
                            : 'While it is open, appraisals submitted inside it are held back from their employees, so nobody reads a rating that is about to move.'
                    }
                />

                <ModalBody className="space-y-4">
                    <FormField label="Name" required error={errors.name}>
                        <Input
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            placeholder="e.g. Sales and Operations, mid-year"
                            maxLength={120}
                        />
                    </FormField>

                    {!editing && (
                        <>
                            <FormField
                                label="Review cycle"
                                required
                                error={errors.evaluation_period_id}
                            >
                                <FormSelect
                                    value={periodId}
                                    onChange={setPeriodId}
                                    options={periods.map((p) => ({
                                        value: String(p.id),
                                        label: p.name,
                                    }))}
                                />
                            </FormField>

                            <FormField
                                label="Covers"
                                group
                                error={errors.department_ids}
                            >
                                <div className="flex flex-wrap gap-2">
                                    {(
                                        [
                                            ['all', 'The whole cycle'],
                                            ['departments', 'Some departments'],
                                        ] as const
                                    ).map(([value, label]) => (
                                        <button
                                            key={value}
                                            type="button"
                                            onClick={() => setScope(value)}
                                            aria-pressed={scope === value}
                                            className={cn(
                                                'min-h-9 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors',
                                                scope === value
                                                    ? 'border-[#0ABFBF] bg-[#0ABFBF]/10 text-foreground'
                                                    : 'border-border text-muted-foreground hover:text-foreground',
                                            )}
                                        >
                                            {label}
                                        </button>
                                    ))}
                                </div>
                                {scope === 'departments' && (
                                    <ul className="mt-3 max-h-48 divide-y divide-border overflow-y-auto rounded-lg border border-border">
                                        {departments.map((d) => (
                                            <li
                                                key={d.id}
                                                className="flex items-center gap-3 px-3 py-2"
                                            >
                                                <Checkbox
                                                    id={`session-dept-${d.id}`}
                                                    checked={departmentIds.includes(
                                                        d.id,
                                                    )}
                                                    onCheckedChange={() =>
                                                        toggle(
                                                            departmentIds,
                                                            setDepartmentIds,
                                                            d.id,
                                                        )
                                                    }
                                                />
                                                <label
                                                    htmlFor={`session-dept-${d.id}`}
                                                    className="flex-1 cursor-pointer text-sm"
                                                >
                                                    {d.name}
                                                </label>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </FormField>
                        </>
                    )}

                    <FormField
                        label="Meeting date"
                        error={errors.scheduled_for}
                    >
                        <Input
                            type="date"
                            value={date}
                            onChange={(e) => setDate(e.target.value)}
                            className="w-48"
                        />
                    </FormField>

                    <FormField
                        label="Who takes part"
                        group
                        hint="People who can see appraisals. They're told they've been added."
                    >
                        {calibrators.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nobody else can see appraisals yet.
                            </p>
                        ) : (
                            <ul className="max-h-48 divide-y divide-border overflow-y-auto rounded-lg border border-border">
                                {calibrators.map((person) => (
                                    <li
                                        key={person.id}
                                        className="flex items-center gap-3 px-3 py-2"
                                    >
                                        <Checkbox
                                            id={`calibrator-${person.id}`}
                                            checked={participants.includes(
                                                person.id,
                                            )}
                                            onCheckedChange={() =>
                                                toggle(
                                                    participants,
                                                    setParticipants,
                                                    person.id,
                                                )
                                            }
                                        />
                                        <label
                                            htmlFor={`calibrator-${person.id}`}
                                            className="flex-1 cursor-pointer text-sm"
                                        >
                                            {person.name}
                                        </label>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </FormField>

                    <FormField label="Notes" error={errors.notes}>
                        <textarea
                            value={notes}
                            onChange={(e) => setNotes(e.target.value)}
                            rows={3}
                            maxLength={2000}
                            placeholder="What to look at, the bar to hold everyone to…"
                            className={TEXTAREA}
                        />
                    </FormField>
                </ModalBody>

                <ModalFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={processing}
                    >
                        Cancel
                    </Button>
                    <Button onClick={submit} disabled={processing || blocked}>
                        {processing && <Spinner />}
                        {editing ? 'Save' : 'Open session'}
                    </Button>
                </ModalFooter>
            </ModalContent>
        </Modal>
    );
}
