import { useForm } from '@inertiajs/react';
import { Settings2 } from 'lucide-react';
import { FormField } from '@/components/form-field';
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
import { onboardingRoutes } from '../routes';
import type { OnboardingCase } from '../types';

type Props = {
    case: OnboardingCase;
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
                    title="Onboarding details"
                    description={`Set a target completion date and internal notes for ${
                        c.employee?.full_name ?? 'this onboarding'
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
    case: OnboardingCase;
    onDone: () => void;
}) {
    const { data, setData, post, processing, errors, transform } = useForm({
        target_end_date: c.target_end_date ?? '',
        notes: c.notes ?? '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        transform((payload) => ({
            target_end_date: payload.target_end_date || null,
            notes: payload.notes || null,
        }));

        post(onboardingRoutes.update(c.hashid), {
            preserveScroll: true,
            onSuccess: () => onDone(),
        });
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <ModalBody className="space-y-5">
                <FormField
                    label="Target completion"
                    error={errors.target_end_date}
                    hint={
                        c.start_date
                            ? `This onboarding started ${formatDate(c.start_date)}.`
                            : 'When this onboarding should be finished.'
                    }
                >
                    <Input
                        type="date"
                        min={c.start_date ?? undefined}
                        value={data.target_end_date ?? ''}
                        onChange={(e) =>
                            setData('target_end_date', e.target.value)
                        }
                    />
                </FormField>

                <FormField
                    label="Notes"
                    error={errors.notes}
                    hint="Visible to anyone who can open this case."
                >
                    <textarea
                        value={data.notes ?? ''}
                        onChange={(e) => setData('notes', e.target.value)}
                        rows={5}
                        placeholder="Anything the team should know about this onboarding…"
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

function formatDate(date: string): string {
    const parsed = new Date(date);

    return Number.isNaN(parsed.getTime())
        ? date
        : parsed.toLocaleDateString(undefined, {
              month: 'short',
              day: 'numeric',
              year: 'numeric',
          });
}
