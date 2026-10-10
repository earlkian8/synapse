import { ReceiptText } from 'lucide-react';
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
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { saveCost } from '../api';
import {
    COST_CATEGORY_LABELS,
    COST_CATEGORY_ORDER,
    todayIso,
} from '../constants';
import type { CostCategory, TrainingCost } from '../types';

/**
 * Record what a program cost — what for, how much, and the day it was incurred,
 * which decides the year's budget it counts against (ADR 0075) — or change a
 * cost already recorded.
 */
export function CostFormModal({
    open,
    onOpenChange,
    programHashid,
    cost,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    programHashid: string;
    cost: TrainingCost | null;
}) {
    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="sm">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <ReceiptText />
                        </ModalIcon>
                    }
                    title={cost ? 'Edit cost' : 'Record a cost'}
                    description="Counted against the program’s budget, and the year it was incurred in."
                />
                {open && (
                    <CostForm
                        key={cost?.id ?? 'new'}
                        programHashid={programHashid}
                        cost={cost}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function CostForm({
    programHashid,
    cost,
    onDone,
}: {
    programHashid: string;
    cost: TrainingCost | null;
    onDone: () => void;
}) {
    const [category, setCategory] = useState<CostCategory>(
        cost?.category ?? 'trainer_fee',
    );
    const [description, setDescription] = useState(cost?.description ?? '');
    const [amount, setAmount] = useState(cost ? String(cost.amount) : '');
    const [date, setDate] = useState(cost?.incurred_on ?? todayIso());
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const submit = () =>
        saveCost(
            cost ? { costId: cost.id } : { programHashid },
            {
                category,
                description: description.trim() || null,
                amount: Number(amount),
                incurred_on: date,
            },
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: onDone,
                onError: setErrors,
            },
        );

    return (
        <>
            <ModalBody className="space-y-4">
                <FormField label="For" required error={errors.category}>
                    <FormSelect
                        value={category}
                        onChange={(value) => setCategory(value as CostCategory)}
                        options={COST_CATEGORY_ORDER.map((c) => ({
                            value: c,
                            label: COST_CATEGORY_LABELS[c],
                        }))}
                    />
                </FormField>
                <div className="grid grid-cols-2 gap-3">
                    <FormField
                        label="Amount (₱)"
                        required
                        error={errors.amount}
                    >
                        <Input
                            type="number"
                            min="0.01"
                            step="0.01"
                            inputMode="decimal"
                            value={amount}
                            onChange={(e) => setAmount(e.target.value)}
                        />
                    </FormField>
                    <FormField
                        label="Incurred on"
                        required
                        error={errors.incurred_on}
                    >
                        <Input
                            type="date"
                            value={date}
                            onChange={(e) => setDate(e.target.value)}
                        />
                    </FormField>
                </div>
                <FormField label="Details" error={errors.description}>
                    <Input
                        value={description}
                        onChange={(e) => setDescription(e.target.value)}
                        placeholder="e.g. Facilitator, 2 days"
                        maxLength={200}
                    />
                </FormField>
            </ModalBody>
            <ModalFooter>
                <Button
                    variant="outline"
                    onClick={onDone}
                    disabled={processing}
                >
                    Cancel
                </Button>
                <Button
                    onClick={submit}
                    disabled={
                        processing || !amount || Number(amount) <= 0 || !date
                    }
                >
                    {processing && <Spinner />}
                    {cost ? 'Save' : 'Record cost'}
                </Button>
            </ModalFooter>
        </>
    );
}
