import { useForm } from '@inertiajs/react';
import { Settings2 } from 'lucide-react';
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
import { TYPE_OPTIONS } from '../constants';
import { offboardingRoutes } from '../routes';
import type { OffboardingCase, OffboardingType } from '../types';

type Props = {
    case: OffboardingCase;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export function CaseSettingsDialog({ case: c, open, onOpenChange }: Props) {
    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="md">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <Settings2 />
                        </ModalIcon>
                    }
                    title="Exit details"
                    description={`Update the exit type, key dates and reason for ${
                        c.employee?.full_name ?? 'this exit'
                    }.`}
                />

                {open && (
                    <FormBody case={c} onDone={() => onOpenChange(false)} />
                )}
            </ModalContent>
        </Modal>
    );
}

function FormBody({
    case: c,
    onDone,
}: {
    case: OffboardingCase;
    onDone: () => void;
}) {
    const { data, setData, post, processing, errors, transform } = useForm({
        type: c.type,
        notice_date: c.notice_date ?? '',
        last_working_day: c.last_working_day ?? '',
        reason: c.reason ?? '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        transform((payload) => ({
            ...payload,
            notice_date: payload.notice_date || null,
            last_working_day: payload.last_working_day || null,
            reason: payload.reason || null,
        }));

        post(offboardingRoutes.update(c.hashid), {
            preserveScroll: true,
            onSuccess: () => onDone(),
        });
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <ModalBody className="space-y-5">
                <FormField label="Exit type" required error={errors.type}>
                    <FormSelect
                        value={data.type}
                        onChange={(v) => setData('type', v as OffboardingType)}
                        options={TYPE_OPTIONS}
                    />
                </FormField>

                <div className="grid gap-4 sm:grid-cols-2">
                    <FormField label="Notice date" error={errors.notice_date}>
                        <Input
                            type="date"
                            value={data.notice_date ?? ''}
                            onChange={(e) =>
                                setData('notice_date', e.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        label="Last working day"
                        error={errors.last_working_day}
                    >
                        <Input
                            type="date"
                            value={data.last_working_day ?? ''}
                            onChange={(e) =>
                                setData('last_working_day', e.target.value)
                            }
                        />
                    </FormField>
                </div>

                <FormField
                    label="Reason"
                    error={errors.reason}
                    hint="Kept internal to the people who manage offboarding."
                >
                    <textarea
                        value={data.reason ?? ''}
                        onChange={(e) => setData('reason', e.target.value)}
                        rows={4}
                        placeholder="Context for the exit…"
                        className="flex w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 aria-invalid:border-destructive aria-invalid:ring-destructive/20"
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
                    Save changes
                </Button>
            </ModalFooter>
        </form>
    );
}
