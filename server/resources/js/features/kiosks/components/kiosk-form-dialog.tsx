import { useForm } from '@inertiajs/react';
import { Tablet } from 'lucide-react';
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
import { kioskAdminRoutes } from '../routes';
import type { ManagedKiosk } from '../types';

/** The select's value for "not at a site". */
const NO_SITE = 'none';

/**
 * Add a kiosk, or change where one stands (ADR 0092). The site matters: every
 * punch made at the kiosk is placed there, and checked against the person's own
 * sites by their policy.
 */
export function KioskFormDialog({
    kiosk,
    open,
    onOpenChange,
    locations,
}: {
    kiosk: ManagedKiosk | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    locations: { id: number; name: string }[];
}) {
    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="sm">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <Tablet />
                        </ModalIcon>
                    }
                    title={kiosk ? `Edit “${kiosk.name}”` : 'Add a kiosk'}
                    description="A shared tablet or computer at a site, where people without a phone clock in."
                />
                {open && (
                    <Body
                        key={kiosk?.hashid ?? 'new'}
                        kiosk={kiosk}
                        locations={locations}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function Body({
    kiosk,
    locations,
    onDone,
}: {
    kiosk: ManagedKiosk | null;
    locations: { id: number; name: string }[];
    onDone: () => void;
}) {
    const { data, setData, post, transform, processing, errors } = useForm({
        name: kiosk?.name ?? '',
        work_location_id: kiosk?.location ? String(kiosk.location.id) : NO_SITE,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        transform((payload) => ({
            name: payload.name,
            work_location_id:
                payload.work_location_id === NO_SITE
                    ? null
                    : Number(payload.work_location_id),
        }));

        post(
            kiosk
                ? kioskAdminRoutes.update(kiosk.hashid)
                : kioskAdminRoutes.store,
            {
                preserveScroll: true,
                onSuccess: onDone,
            },
        );
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <ModalBody className="space-y-5">
                <FormField
                    label="Name"
                    required
                    error={errors.name}
                    hint="Where it stands, so people know which one it is."
                >
                    <Input
                        value={data.name}
                        onChange={(event) =>
                            setData('name', event.target.value)
                        }
                        placeholder="Warehouse gate"
                        maxLength={80}
                        autoFocus
                    />
                </FormField>

                <FormField
                    label="Site"
                    error={errors.work_location_id}
                    hint={
                        locations.length === 0
                            ? 'You have no sites yet. Without one, punches here are placed nowhere.'
                            : 'Punches made here are placed at this site.'
                    }
                >
                    <FormSelect
                        value={data.work_location_id}
                        onChange={(value) => setData('work_location_id', value)}
                        options={[
                            { value: NO_SITE, label: 'Not at a site' },
                            ...locations.map((location) => ({
                                value: String(location.id),
                                label: location.name,
                            })),
                        ]}
                    />
                </FormField>
            </ModalBody>

            <ModalFooter>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
                <Button type="submit" disabled={processing}>
                    {processing && <Spinner />}
                    {kiosk ? 'Save kiosk' : 'Add kiosk'}
                </Button>
            </ModalFooter>
        </form>
    );
}
