import { useForm } from '@inertiajs/react';
import { Check, RefreshCw, ShieldCheck, Trash2, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
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
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';
import { userRoutes } from '../routes';
import type { ManagedUser, UserRole } from '../types';

type Props = {
    user: ManagedUser | null;
    assignableRoles: UserRole[];
    canAssignRoles: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export function UserFormSheet({
    user,
    assignableRoles,
    canAssignRoles,
    open,
    onOpenChange,
}: Props) {
    const isEditing = Boolean(user);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-xl"
            >
                <SheetHeader className="border-b border-border px-6 py-4">
                    <SheetTitle>
                        {isEditing ? 'Edit user' : 'Add new user'}
                    </SheetTitle>
                    <SheetDescription>
                        {isEditing
                            ? 'Update the account details and access for this user.'
                            : 'Create a new account and configure their access.'}
                    </SheetDescription>
                </SheetHeader>

                {open && (
                    <UserFormBody
                        key={user?.id ?? 'new'}
                        user={user}
                        assignableRoles={assignableRoles}
                        canAssignRoles={canAssignRoles}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}

function generatePassword(): string {
    const chars =
        'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%';
    const bytes = new Uint32Array(16);
    crypto.getRandomValues(bytes);

    return Array.from(bytes, (n) => chars[n % chars.length]).join('');
}

function UserFormBody({
    user,
    assignableRoles,
    canAssignRoles,
    onDone,
}: {
    user: ManagedUser | null;
    assignableRoles: UserRole[];
    canAssignRoles: boolean;
    onDone: () => void;
}) {
    const isEditing = Boolean(user);
    const fileInput = useRef<HTMLInputElement>(null);
    const [photoPreview, setPhotoPreview] = useState<string | null>(
        user?.profile_photo ?? null,
    );

    const showRoles = canAssignRoles && assignableRoles.length > 0;

    const { data, setData, post, patch, processing, errors } = useForm({
        first_name: user?.first_name ?? '',
        middle_name: user?.middle_name ?? '',
        last_name: user?.last_name ?? '',
        suffix: user?.suffix ?? '',
        email: user?.email ?? '',
        phone_number: user?.phone_number ?? '',
        is_active: user?.is_active ?? true,
        email_verified: user?.email_verified ?? false,
        photo: null as File | null,
        remove_photo: false,
        password: '',
        password_confirmation: '',
        manage_roles: showRoles,
        roles: user?.roles?.map((role) => role.id) ?? ([] as number[]),
    });

    const toggleRole = (id: number) => {
        setData(
            'roles',
            data.roles.includes(id)
                ? data.roles.filter((roleId) => roleId !== id)
                : [...data.roles, id],
        );
    };

    const pickPhoto = (file: File | null) => {
        if (photoPreview?.startsWith('blob:')) {
            URL.revokeObjectURL(photoPreview);
        }

        if (file) {
            setData('photo', file);
            setData('remove_photo', false);
            setPhotoPreview(URL.createObjectURL(file));
        }
    };

    const clearPhoto = () => {
        if (photoPreview?.startsWith('blob:')) {
            URL.revokeObjectURL(photoPreview);
        }

        setData('photo', null);
        setData('remove_photo', true);
        setPhotoPreview(null);

        if (fileInput.current) {
            fileInput.current.value = '';
        }
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => onDone(),
        };

        if (isEditing && user) {
            patch(userRoutes.update(user.id), options);
        } else {
            post(userRoutes.store, options);
        }
    };

    const previewInitials =
        `${data.first_name.charAt(0)}${data.last_name.charAt(0)}`.toUpperCase() ||
        '?';

    const autofillPassword = () => {
        const generated = generatePassword();
        setData((current) => ({
            ...current,
            password: generated,
            password_confirmation: generated,
        }));
    };

    return (
        <form onSubmit={submit} className="flex h-full flex-col">
            <div className="flex-1 space-y-8 px-6 py-6">
                <div className="flex items-center gap-4">
                    <span className="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-full bg-[#0F2044] text-lg font-semibold text-white ring-1 ring-border">
                        {photoPreview ? (
                            <img
                                src={photoPreview}
                                alt="Profile preview"
                                className="size-full object-cover"
                            />
                        ) : (
                            previewInitials
                        )}
                    </span>
                    <div className="flex flex-col gap-1.5">
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => fileInput.current?.click()}
                            >
                                <Upload className="size-4" />
                                {photoPreview ? 'Change photo' : 'Upload photo'}
                            </Button>
                            {photoPreview && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={clearPhoto}
                                    className="text-destructive hover:text-destructive"
                                >
                                    <Trash2 className="size-4" />
                                    Remove
                                </Button>
                            )}
                        </div>
                        <p className="text-xs text-muted-foreground">
                            JPG, PNG or WEBP. Max 2&nbsp;MB.
                        </p>
                        <input
                            ref={fileInput}
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            className="hidden"
                            onChange={(e) => pickPhoto(e.target.files?.[0] ?? null)}
                        />
                        <InputError message={errors.photo} />
                    </div>
                </div>

                <Section
                    title="Personal information"
                    description="Legal name as it should appear across the system."
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="First name" htmlFor="first_name" error={errors.first_name} required>
                            <Input
                                id="first_name"
                                value={data.first_name}
                                onChange={(e) => setData('first_name', e.target.value)}
                                autoFocus
                                required
                            />
                        </Field>
                        <Field label="Last name" htmlFor="last_name" error={errors.last_name} required>
                            <Input
                                id="last_name"
                                value={data.last_name}
                                onChange={(e) => setData('last_name', e.target.value)}
                                required
                            />
                        </Field>
                        <Field label="Middle name" htmlFor="middle_name" error={errors.middle_name} optional>
                            <Input
                                id="middle_name"
                                value={data.middle_name}
                                onChange={(e) => setData('middle_name', e.target.value)}
                            />
                        </Field>
                        <Field label="Suffix" htmlFor="suffix" error={errors.suffix} optional>
                            <Input
                                id="suffix"
                                value={data.suffix}
                                onChange={(e) => setData('suffix', e.target.value)}
                                placeholder="Jr., Sr., III"
                            />
                        </Field>
                    </div>
                </Section>

                <Section
                    title="Contact"
                    description="How the organisation reaches this person."
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Email address" htmlFor="email" error={errors.email} required className="sm:col-span-2">
                            <Input
                                id="email"
                                type="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                required
                            />
                        </Field>
                        <Field label="Phone number" htmlFor="phone_number" error={errors.phone_number} optional className="sm:col-span-2">
                            <Input
                                id="phone_number"
                                value={data.phone_number}
                                onChange={(e) => setData('phone_number', e.target.value)}
                            />
                        </Field>
                    </div>
                </Section>

                <Section
                    title="Access"
                    description="Control whether this account can sign in."
                >
                    <div className="flex items-center justify-between rounded-lg border border-border bg-muted/30 px-4 py-3">
                        <div>
                            <p className="text-sm font-medium">Active account</p>
                            <p className="text-xs text-muted-foreground">
                                Inactive users keep their data but cannot sign in.
                            </p>
                        </div>
                        <Switch
                            checked={data.is_active}
                            onCheckedChange={(value) => setData('is_active', value)}
                        />
                    </div>

                    <div className="flex items-center justify-between rounded-lg border border-border bg-muted/30 px-4 py-3">
                        <div>
                            <p className="text-sm font-medium">Email verified</p>
                            <p className="text-xs text-muted-foreground">
                                Mark the email address as verified, bypassing the
                                confirmation email.
                            </p>
                        </div>
                        <Switch
                            checked={data.email_verified}
                            onCheckedChange={(value) =>
                                setData('email_verified', value)
                            }
                        />
                    </div>

                    {!isEditing && (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="Password"
                                htmlFor="password"
                                error={errors.password}
                                optional
                                className="sm:col-span-2"
                                action={
                                    <button
                                        type="button"
                                        onClick={autofillPassword}
                                        className="inline-flex items-center gap-1 text-xs font-medium text-[#0ABFBF] hover:underline"
                                    >
                                        <RefreshCw className="size-3" />
                                        Generate
                                    </button>
                                }
                            >
                                <PasswordInput
                                    id="password"
                                    value={data.password}
                                    onChange={(e) => setData('password', e.target.value)}
                                    autoComplete="new-password"
                                    placeholder="Leave blank to invite later"
                                />
                            </Field>
                            {data.password && (
                                <Field
                                    label="Confirm password"
                                    htmlFor="password_confirmation"
                                    className="sm:col-span-2"
                                >
                                    <PasswordInput
                                        id="password_confirmation"
                                        value={data.password_confirmation}
                                        onChange={(e) =>
                                            setData('password_confirmation', e.target.value)
                                        }
                                        autoComplete="new-password"
                                    />
                                </Field>
                            )}
                        </div>
                    )}
                </Section>

                {showRoles && (
                    <Section
                        title="Roles"
                        description="Roles determine what this user can access. Assign one or more."
                    >
                        <div className="grid gap-2 sm:grid-cols-2">
                            {assignableRoles.map((role) => {
                                const checked = data.roles.includes(role.id);

                                return (
                                    <button
                                        type="button"
                                        key={role.id}
                                        onClick={() => toggleRole(role.id)}
                                        aria-pressed={checked}
                                        className={cn(
                                            'flex items-center justify-between gap-2 rounded-lg border px-3 py-2.5 text-left transition-colors',
                                            checked
                                                ? 'border-[#0ABFBF]/50 bg-[#0ABFBF]/5'
                                                : 'border-border bg-muted/20 hover:bg-muted/40',
                                        )}
                                    >
                                        <span className="flex items-center gap-2 min-w-0">
                                            <ShieldCheck
                                                className={cn(
                                                    'size-4 shrink-0',
                                                    checked
                                                        ? 'text-[#0ABFBF]'
                                                        : 'text-muted-foreground',
                                                )}
                                            />
                                            <span className="min-w-0">
                                                <span className="block truncate text-sm font-medium">
                                                    {role.label}
                                                </span>
                                                <span className="block truncate font-mono text-[11px] text-muted-foreground">
                                                    {role.name}
                                                </span>
                                            </span>
                                        </span>
                                        <span
                                            className={cn(
                                                'flex size-4 shrink-0 items-center justify-center rounded-full border',
                                                checked
                                                    ? 'border-[#0ABFBF] bg-[#0ABFBF] text-white'
                                                    : 'border-muted-foreground/40',
                                            )}
                                        >
                                            {checked && <Check className="size-3" />}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                        <InputError message={errors.roles} className="mt-1.5" />
                    </Section>
                )}
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
                        {isEditing ? 'Save changes' : 'Create user'}
                    </Button>
                </div>
            </SheetFooter>
        </form>
    );
}

function Section({
    title,
    description,
    children,
}: {
    title: string;
    description: string;
    children: React.ReactNode;
}) {
    return (
        <section className="space-y-4">
            <div>
                <h3 className="text-sm font-semibold">{title}</h3>
                <p className="text-xs text-muted-foreground">{description}</p>
            </div>
            {children}
        </section>
    );
}

function Field({
    label,
    htmlFor,
    error,
    required,
    optional,
    action,
    className,
    children,
}: {
    label: string;
    htmlFor: string;
    error?: string;
    required?: boolean;
    optional?: boolean;
    action?: React.ReactNode;
    className?: string;
    children: React.ReactNode;
}) {
    return (
        <div className={className}>
            <div className="mb-1.5 flex items-center justify-between">
                <Label htmlFor={htmlFor}>
                    {label}
                    {required && <span className="ml-0.5 text-destructive">*</span>}
                    {optional && (
                        <span className="ml-1 text-xs font-normal text-muted-foreground">
                            optional
                        </span>
                    )}
                </Label>
                {action}
            </div>
            {children}
            <InputError message={error} className="mt-1.5" />
        </div>
    );
}
