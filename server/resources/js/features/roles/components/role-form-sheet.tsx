import { useForm } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { roleRoutes } from '../routes';
import type { ManagedRole, PermissionGroup } from '../types';
import { PermissionMatrix } from './permission-matrix';

type Props = {
    role: ManagedRole | null;
    groups: PermissionGroup[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export function RoleFormSheet({ role, groups, open, onOpenChange }: Props) {
    const isEditing = Boolean(role);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-2xl"
            >
                <SheetHeader className="border-b border-border px-6 py-4">
                    <SheetTitle>
                        {isEditing ? 'Edit role' : 'Create new role'}
                    </SheetTitle>
                    <SheetDescription>
                        {isEditing
                            ? 'Update the role and the permissions it grants.'
                            : 'Define a role and choose the permissions it grants.'}
                    </SheetDescription>
                </SheetHeader>

                {open && (
                    <RoleFormBody
                        key={role?.id ?? 'new'}
                        role={role}
                        groups={groups}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}

function slugify(value: string): string {
    return value
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

function RoleFormBody({
    role,
    groups,
    onDone,
}: {
    role: ManagedRole | null;
    groups: PermissionGroup[];
    onDone: () => void;
}) {
    const isEditing = Boolean(role);
    const totalPermissions = groups.reduce(
        (sum, group) => sum + group.permissions.length,
        0,
    );

    const { data, setData, post, patch, processing, errors } = useForm({
        label: role?.label ?? '',
        name: role?.name ?? '',
        description: role?.description ?? '',
        permissions: role?.permissions ?? ([] as string[]),
    });

    // Auto-derive the machine key from the label until the user edits it by hand.
    const [nameTouched, setNameTouched] = useState(isEditing);

    const onLabelChange = (label: string) => {
        setData((current) => ({
            ...current,
            label,
            name: nameTouched ? current.name : slugify(label),
        }));
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onDone() };

        if (isEditing && role) {
            patch(roleRoutes.update(role.id), options);
        } else {
            post(roleRoutes.store, options);
        }
    };

    return (
        <form onSubmit={submit} className="flex h-full flex-col">
            <div className="flex-1 space-y-8 px-6 py-6">
                <section className="space-y-4">
                    <div>
                        <h3 className="text-sm font-semibold">Details</h3>
                        <p className="text-xs text-muted-foreground">
                            How this role appears across the system.
                        </p>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <Label htmlFor="label">
                                Display name
                                <span className="ml-0.5 text-destructive">
                                    *
                                </span>
                            </Label>
                            <Input
                                id="label"
                                value={data.label}
                                onChange={(e) => onLabelChange(e.target.value)}
                                placeholder="e.g. HR Manager"
                                autoFocus
                                className="mt-1.5"
                                required
                            />
                            <InputError
                                message={errors.label}
                                className="mt-1.5"
                            />
                        </div>
                        <div>
                            <Label htmlFor="name">
                                Role key
                                <span className="ml-0.5 text-destructive">
                                    *
                                </span>
                            </Label>
                            <Input
                                id="name"
                                value={data.name}
                                onChange={(e) => {
                                    setNameTouched(true);
                                    setData('name', e.target.value);
                                }}
                                placeholder="hr-manager"
                                className="mt-1.5 font-mono text-sm"
                                disabled={isEditing}
                                required
                            />
                            <InputError
                                message={errors.name}
                                className="mt-1.5"
                            />
                            {isEditing ? (
                                <p className="mt-1 text-xs text-muted-foreground">
                                    The role key is permanent and cannot be
                                    changed.
                                </p>
                            ) : (
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Lowercase, hyphenated. Used internally.
                                </p>
                            )}
                        </div>
                    </div>

                    <div>
                        <Label htmlFor="description">
                            Description
                            <span className="ml-1 text-xs font-normal text-muted-foreground">
                                optional
                            </span>
                        </Label>
                        <Input
                            id="description"
                            value={data.description}
                            onChange={(e) =>
                                setData('description', e.target.value)
                            }
                            placeholder="What is this role responsible for?"
                            className="mt-1.5"
                        />
                        <InputError
                            message={errors.description}
                            className="mt-1.5"
                        />
                    </div>
                </section>

                <section className="space-y-4">
                    <div className="flex items-center justify-between">
                        <div>
                            <h3 className="text-sm font-semibold">
                                Permissions
                            </h3>
                            <p className="text-xs text-muted-foreground">
                                Choose exactly what this role can do.
                            </p>
                        </div>
                        <span className="inline-flex items-center gap-1.5 rounded-full bg-[#0ABFBF]/10 px-2.5 py-1 text-xs font-semibold text-[#0ABFBF]">
                            <ShieldCheck className="size-3.5" />
                            {data.permissions.length}/{totalPermissions}
                        </span>
                    </div>

                    <PermissionMatrix
                        groups={groups}
                        value={data.permissions}
                        onChange={(permissions) =>
                            setData('permissions', permissions)
                        }
                    />
                    <InputError message={errors.permissions} />
                </section>
            </div>

            <SheetFooter className="border-t border-border px-6 py-4">
                <div className="flex w-full items-center justify-end gap-2">
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
                        {isEditing ? 'Save changes' : 'Create role'}
                    </Button>
                </div>
            </SheetFooter>
        </form>
    );
}
