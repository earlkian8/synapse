import { router } from '@inertiajs/react';
import { ListPlus } from 'lucide-react';
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
import { Spinner } from '@/components/ui/spinner';
import { offboardingRoutes } from '../routes';
import type { ProgramOption } from '../types';

type Props = {
    caseHashid: string;
    programs: ProgramOption[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/**
 * Bulk-add the items of a clearance template to a case's checklist. Items
 * already on the checklist (matched by label) are skipped server-side, so
 * applying a template twice is harmless.
 */
export function ApplyProgramDialog({
    caseHashid,
    programs,
    open,
    onOpenChange,
}: Props) {
    const [programId, setProgramId] = useState('');
    const [processing, setProcessing] = useState(false);

    const apply = () => {
        if (!programId) {
            return;
        }

        router.post(
            offboardingRoutes.applyProgram(caseHashid),
            { offboarding_program_id: Number(programId) },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    setProgramId('');
                    onOpenChange(false);
                },
            },
        );
    };

    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="sm">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <ListPlus />
                        </ModalIcon>
                    }
                    title="Add items from a template"
                    description="Every item in the template is appended to this checklist. Items already on it are skipped."
                />

                <ModalBody>
                    <FormField label="Clearance template" required>
                        <FormSelect
                            value={programId}
                            onChange={setProgramId}
                            placeholder="Select a template…"
                            options={programs.map((p) => ({
                                value: String(p.id),
                                label: `${p.name} · ${p.items_count} item${p.items_count === 1 ? '' : 's'}`,
                            }))}
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
                    <Button onClick={apply} disabled={processing || !programId}>
                        {processing && <Spinner />}
                        Add items
                    </Button>
                </ModalFooter>
            </ModalContent>
        </Modal>
    );
}
