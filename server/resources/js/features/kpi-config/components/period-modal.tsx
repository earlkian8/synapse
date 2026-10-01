import { useForm } from '@inertiajs/react';
import { CalendarRange } from 'lucide-react';
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
import { PERIOD_STATUS_LABELS } from '@/features/performance/constants';
import type {
    EvaluationPeriodOption,
    PeriodStatus,
} from '@/features/performance/types';
import { kpiConfigRoutes } from '../routes';

const STATUSES: PeriodStatus[] = ['draft', 'open', 'closed'];

type Props = {
    period: EvaluationPeriodOption | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/** A review cycle — the window appraisals are conducted in. */
export function PeriodModal({ period, open, onOpenChange }: Props) {
    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="md">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <CalendarRange />
                        </ModalIcon>
                    }
                    title={period ? 'Edit review cycle' : 'New review cycle'}
                    description="The window appraisals are conducted in. Appraisals can only be opened while a cycle is open."
                />
                {open && (
                    <FormBody
                        key={period?.id ?? 'new'}
                        period={period}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function FormBody({
    period,
    onDone,
}: {
    period: EvaluationPeriodOption | null;
    onDone: () => void;
}) {
    const { data, setData, post, processing, errors } = useForm({
        name: period?.name ?? '',
        start_date: period?.start_date ?? '',
        end_date: period?.end_date ?? '',
        status: (period?.status ?? 'draft') as PeriodStatus,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onDone };

        if (period) {
            post(kpiConfigRoutes.periods.update(period.hashid), opts);
        } else {
            post(kpiConfigRoutes.periods.store, opts);
        }
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <ModalBody className="space-y-5">
                <FormField label="Name" required error={errors.name}>
                    <Input
                        value={data.name}
                        onChange={(event) =>
                            setData('name', event.target.value)
                        }
                        placeholder="e.g. H1 2026 Review"
                        required
                    />
                </FormField>

                <div className="grid gap-4 sm:grid-cols-2">
                    <FormField
                        label="Start date"
                        required
                        error={errors.start_date}
                    >
                        <Input
                            type="date"
                            value={data.start_date ?? ''}
                            onChange={(event) =>
                                setData('start_date', event.target.value)
                            }
                            required
                        />
                    </FormField>
                    <FormField
                        label="End date"
                        required
                        error={errors.end_date}
                    >
                        <Input
                            type="date"
                            value={data.end_date ?? ''}
                            onChange={(event) =>
                                setData('end_date', event.target.value)
                            }
                            required
                        />
                    </FormField>
                </div>

                <FormField
                    label="Status"
                    hint="Appraisals can only be opened while the cycle is open."
                    error={errors.status}
                >
                    <FormSelect
                        value={data.status}
                        onChange={(value) =>
                            setData('status', value as PeriodStatus)
                        }
                        options={STATUSES.map((status) => ({
                            value: status,
                            label: PERIOD_STATUS_LABELS[status],
                        }))}
                    />
                </FormField>
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
                <Button type="submit" disabled={processing}>
                    {processing && <Spinner />}
                    {period ? 'Save changes' : 'Create cycle'}
                </Button>
            </ModalFooter>
        </form>
    );
}
