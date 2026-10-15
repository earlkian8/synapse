import { useForm } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
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
import { workspaceRoutes } from '../routes';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/**
 * Start a new workspace (ADR 0089) — a second company kept under the same
 * sign-in. Opened from wherever one's workspaces are listed: the sidebar
 * switcher, the workspace picker, and Settings → Workspaces. Creating it steps
 * into it, on the setup wizard.
 */
export function CreateWorkspaceDialog({ open, onOpenChange }: Props) {
    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="sm">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <Building2 />
                        </ModalIcon>
                    }
                    title="Create a workspace"
                    description="A separate company with its own people, records and settings. You will be its HR Manager."
                />
                {open && <FormBody onDone={() => onOpenChange(false)} />}
            </ModalContent>
        </Modal>
    );
}

function FormBody({ onDone }: { onDone: () => void }) {
    const { data, setData, post, processing, errors } = useForm({ name: '' });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        post(workspaceRoutes.store, { onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <ModalBody className="space-y-4">
                <FormField
                    label="Company name"
                    required
                    error={errors.name}
                    hint="You can change it later in the company profile."
                >
                    <Input
                        value={data.name}
                        onChange={(event) =>
                            setData('name', event.target.value)
                        }
                        placeholder="e.g. Northwind Trading"
                        maxLength={255}
                        autoComplete="organization"
                        autoFocus
                        required
                    />
                </FormField>

                <p className="text-sm text-muted-foreground">
                    You keep the same sign-in. Switch between your companies
                    from the top of the sidebar.
                </p>
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
                <Button
                    type="submit"
                    disabled={processing || data.name.trim() === ''}
                >
                    {processing && <Spinner />}
                    Create workspace
                </Button>
            </ModalFooter>
        </form>
    );
}
