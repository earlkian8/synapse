import { useForm } from '@inertiajs/react';
import { DoorOpen, TriangleAlert } from 'lucide-react';
import type { FormEvent } from 'react';
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
import { addDays, daysBetween, formatDay } from '../constants';
import { offboardingRoutes } from '../routes';

/**
 * Filing one's own resignation (ADR 0076): the last day they plan to work and,
 * if they want to say, why. A notice shorter than the Labor Code's month is
 * allowed, with a note that agreeing to it is HR's call. Nothing happens to
 * their employment until HR accepts it.
 */
export function ResignDialog({
    open,
    onOpenChange,
    today,
    noticeDays,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    today: string;
    noticeDays: number;
}) {
    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="md">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <DoorOpen />
                        </ModalIcon>
                    }
                    title="File your resignation"
                    description="HR reviews it and agrees your last day with you. You can withdraw it until they do."
                />
                {open && (
                    <ResignForm
                        today={today}
                        noticeDays={noticeDays}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function ResignForm({
    today,
    noticeDays,
    onDone,
}: {
    today: string;
    noticeDays: number;
    onDone: () => void;
}) {
    const suggested = addDays(today, noticeDays);
    const { data, setData, post, processing, errors } = useForm({
        proposed_last_day: suggested,
        reason: '',
    });

    const notice = data.proposed_last_day
        ? daysBetween(today, data.proposed_last_day)
        : null;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(offboardingRoutes.resign, {
            preserveScroll: true,
            onSuccess: onDone,
        });
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <ModalBody className="flex flex-col gap-4">
                <FormField
                    label="Your last working day"
                    required
                    error={errors.proposed_last_day}
                    hint={`${noticeDays} days from today is ${formatDay(suggested)}.`}
                >
                    <Input
                        type="date"
                        min={today}
                        value={data.proposed_last_day}
                        onChange={(event) =>
                            setData('proposed_last_day', event.target.value)
                        }
                    />
                </FormField>

                {notice !== null && notice >= 0 && notice < noticeDays && (
                    <p className="flex items-start gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-300">
                        <TriangleAlert className="mt-px size-3.5 shrink-0" />
                        <span>
                            That is {notice} {notice === 1 ? 'day' : 'days'}’
                            notice. The Labor Code asks for {noticeDays}; HR may
                            agree to an earlier day, or ask you to stay longer.
                        </span>
                    </p>
                )}

                <FormField
                    label="Why you are leaving"
                    error={errors.reason}
                    hint="Optional. Only HR sees it."
                >
                    <textarea
                        value={data.reason}
                        onChange={(event) =>
                            setData('reason', event.target.value)
                        }
                        rows={4}
                        maxLength={2000}
                        className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-colors placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                    />
                </FormField>
            </ModalBody>

            <ModalFooter>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
                <Button type="submit" disabled={processing}>
                    {processing && <Spinner />}
                    Send to HR
                </Button>
            </ModalFooter>
        </form>
    );
}
