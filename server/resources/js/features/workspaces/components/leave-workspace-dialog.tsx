import { useForm } from '@inertiajs/react';
import { LogOut } from 'lucide-react';
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
import type { MyWorkspace } from '../types';

type Props = {
    workspace: MyWorkspace | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/**
 * Leave a workspace (ADR 0089). Says what goes and what stays. When nobody else
 * is in it, leaving closes it for good, so its name has to be typed.
 */
export function LeaveWorkspaceDialog({ workspace, open, onOpenChange }: Props) {
    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="sm">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <LogOut />
                        </ModalIcon>
                    }
                    title={`Leave ${workspace?.name ?? 'workspace'}?`}
                    description="You will no longer be able to sign in to it."
                />
                {open && workspace && (
                    <FormBody
                        key={workspace.id}
                        workspace={workspace}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function FormBody({
    workspace,
    onDone,
}: {
    workspace: MyWorkspace;
    onDone: () => void;
}) {
    const alone = workspace.leaving?.alone ?? false;
    const {
        data,
        setData,
        delete: destroy,
        processing,
        errors,
    } = useForm({
        confirm_name: '',
    });

    const confirmed =
        !alone ||
        data.confirm_name.trim().toLowerCase() ===
            workspace.name.trim().toLowerCase();

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        destroy(workspaceRoutes.leave(workspace.id), {
            preserveScroll: true,
            onSuccess: onDone,
        });
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <ModalBody className="space-y-4 text-sm">
                {alone ? (
                    <p className="text-muted-foreground">
                        Nobody else is in {workspace.name}. Once you leave,
                        nobody can open it again: its records stay stored, but
                        out of reach.
                    </p>
                ) : (
                    <ul className="list-disc space-y-1.5 pl-5 text-muted-foreground">
                        <li>
                            Your roles there end, and the app signs out of it on
                            your phone.
                        </li>
                        <li>
                            Your employee record stays with the company. It is
                            their history, not your access.
                        </li>
                        <li>
                            To come back, somebody there has to invite you
                            again.
                        </li>
                    </ul>
                )}

                <p className="text-muted-foreground">
                    Your account and your other workspaces do not change.
                </p>

                {alone && (
                    <FormField
                        label={`Type ${workspace.name} to confirm`}
                        error={errors.confirm_name}
                    >
                        <Input
                            value={data.confirm_name}
                            onChange={(event) =>
                                setData('confirm_name', event.target.value)
                            }
                            autoComplete="off"
                            autoFocus
                        />
                    </FormField>
                )}
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
                    variant="destructive"
                    disabled={processing || !confirmed}
                >
                    {processing && <Spinner />}
                    Leave workspace
                </Button>
            </ModalFooter>
        </form>
    );
}
