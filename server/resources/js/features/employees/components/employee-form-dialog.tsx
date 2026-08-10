import { useForm } from '@inertiajs/react';
import { Trash2, UserRoundCog, Upload } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import { FormField } from '@/components/form-field';
import { FormSelect } from '@/components/form-select';
import InputError from '@/components/input-error';
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
import {
    CIVIL_STATUS_OPTIONS,
    EMPLOYMENT_STATUS_OPTIONS,
    EMPLOYMENT_TYPE_OPTIONS,
    GENDER_OPTIONS,
} from '../constants';
import { employeeRoutes } from '../routes';
import type {
    EmployeeOptions,
    EmploymentStatus,
    EmploymentType,
    ManagedEmployee,
} from '../types';

type Props = {
    employee: ManagedEmployee | null;
    options: EmployeeOptions;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

const NONE = '__none__';

export function EmployeeFormDialog({
    employee,
    options,
    open,
    onOpenChange,
}: Props) {
    const isEditing = Boolean(employee);

    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="2xl">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <UserRoundCog />
                        </ModalIcon>
                    }
                    title={isEditing ? 'Edit employee' : 'New employee'}
                    description={
                        isEditing
                            ? `Update the record for ${employee?.full_name ?? 'this employee'}.`
                            : 'Add a new employee to the directory.'
                    }
                />

                {open && (
                    <FormBody
                        key={employee?.id ?? 'new'}
                        employee={employee}
                        options={options}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function str(value: string | number | null | undefined): string {
    return value === null || value === undefined ? '' : String(value);
}

function FormBody({
    employee,
    options,
    onDone,
}: {
    employee: ManagedEmployee | null;
    options: EmployeeOptions;
    onDone: () => void;
}) {
    const isEditing = Boolean(employee);

    const { data, setData, post, processing, errors, transform } = useForm({
        employee_no: employee?.employee_no ?? '',
        first_name: employee?.first_name ?? '',
        middle_name: employee?.middle_name ?? '',
        last_name: employee?.last_name ?? '',
        suffix: employee?.suffix ?? '',
        birth_date: employee?.birth_date ?? '',
        gender: employee?.gender ?? '',
        civil_status: employee?.civil_status ?? '',
        email: employee?.email ?? '',
        phone: employee?.phone ?? '',
        address: employee?.address ?? '',
        photo: null as File | null,
        remove_photo: false,
        department_id: str(employee?.department_id),
        position_id: str(employee?.position_id),
        manager_id: str(employee?.manager_id),
        work_schedule_id: str(employee?.work_schedule_id),
        user_id: str(employee?.user_id),
        employment_type: employee?.employment_type ?? 'probationary',
        employment_status: employee?.employment_status ?? 'active',
        date_hired: employee?.date_hired ?? '',
        date_regularized: employee?.date_regularized ?? '',
        basic_salary: str(employee?.basic_salary),
        bank_name: employee?.bank_name ?? '',
        bank_account_no: employee?.bank_account_no ?? '',
        tin: employee?.tin ?? '',
        sss_no: employee?.sss_no ?? '',
        philhealth_no: employee?.philhealth_no ?? '',
        pagibig_no: employee?.pagibig_no ?? '',
    });

    const fileInput = useRef<HTMLInputElement>(null);
    const [photoPreview, setPhotoPreview] = useState<string | null>(
        employee?.photo ?? null,
    );

    const previewInitials =
        `${data.first_name.charAt(0)}${data.last_name.charAt(0)}`.toUpperCase() ||
        '?';

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

    // Positions are scoped to the chosen department (keeping any current pick).
    const positions = useMemo(() => {
        const deptId = data.department_id ? Number(data.department_id) : null;

        if (!deptId) {
            return options.positions;
        }

        return options.positions.filter(
            (p) =>
                p.department_id === deptId || String(p.id) === data.position_id,
        );
    }, [data.department_id, data.position_id, options.positions]);

    const managers = useMemo(
        () => options.managers.filter((m) => m.id !== employee?.id),
        [options.managers, employee?.id],
    );

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        // Empty optional values become null; selects use a sentinel for "none".
        transform((payload) => {
            const cleaned: Record<string, unknown> = { ...payload };
            const nullable = [
                'department_id',
                'position_id',
                'manager_id',
                'work_schedule_id',
                'user_id',
                'birth_date',
                'gender',
                'civil_status',
                'date_regularized',
                'basic_salary',
                'middle_name',
                'suffix',
                'email',
                'phone',
                'address',
                'bank_name',
                'bank_account_no',
                'tin',
                'sss_no',
                'philhealth_no',
                'pagibig_no',
            ];

            for (const key of nullable) {
                if (cleaned[key] === '' || cleaned[key] === NONE) {
                    cleaned[key] = null;
                }
            }

            return cleaned;
        });

        const options = { preserveScroll: true, onSuccess: () => onDone() };

        if (isEditing && employee) {
            post(employeeRoutes.update(employee.id), options);
        } else {
            post(employeeRoutes.store, options);
        }
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <ModalBody className="space-y-7">
                {/* Personal */}
                <Section
                    title="Personal information"
                    subtitle="Name and contact details."
                >
                    <div className="flex items-center gap-4">
                        <span className="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-[#0F2044] text-lg font-semibold text-white ring-1 ring-border">
                            {photoPreview ? (
                                <img
                                    src={photoPreview}
                                    alt=""
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
                                    {photoPreview
                                        ? 'Change photo'
                                        : 'Upload photo'}
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
                                aria-label="Employee photo"
                                onChange={(e) =>
                                    pickPhoto(e.target.files?.[0] ?? null)
                                }
                            />
                            <InputError message={errors.photo} role="alert" />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <FormField
                            label="First name"
                            required
                            error={errors.first_name}
                        >
                            <Input
                                value={data.first_name}
                                autoComplete="given-name"
                                onChange={(e) =>
                                    setData('first_name', e.target.value)
                                }
                                required
                            />
                        </FormField>
                        <FormField
                            label="Middle name"
                            error={errors.middle_name}
                        >
                            <Input
                                value={data.middle_name ?? ''}
                                autoComplete="additional-name"
                                onChange={(e) =>
                                    setData('middle_name', e.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            label="Last name"
                            required
                            error={errors.last_name}
                        >
                            <Input
                                value={data.last_name}
                                autoComplete="family-name"
                                onChange={(e) =>
                                    setData('last_name', e.target.value)
                                }
                                required
                            />
                        </FormField>
                        <FormField label="Suffix" error={errors.suffix}>
                            <Input
                                value={data.suffix ?? ''}
                                onChange={(e) =>
                                    setData('suffix', e.target.value)
                                }
                                placeholder="Jr., III…"
                            />
                        </FormField>
                        <FormField label="Birth date" error={errors.birth_date}>
                            <Input
                                type="date"
                                value={data.birth_date ?? ''}
                                onChange={(e) =>
                                    setData('birth_date', e.target.value)
                                }
                            />
                        </FormField>
                        <FormField label="Gender" error={errors.gender}>
                            <FormSelect
                                value={data.gender || NONE}
                                placeholder="Select…"
                                noneValue={NONE}
                                onChange={(v) => setData('gender', v)}
                                options={GENDER_OPTIONS}
                            />
                        </FormField>
                        <FormField
                            label="Civil status"
                            error={errors.civil_status}
                        >
                            <FormSelect
                                value={data.civil_status || NONE}
                                placeholder="Select…"
                                noneValue={NONE}
                                onChange={(v) => setData('civil_status', v)}
                                options={CIVIL_STATUS_OPTIONS}
                            />
                        </FormField>
                        <FormField label="Phone" error={errors.phone}>
                            <Input
                                type="tel"
                                value={data.phone ?? ''}
                                autoComplete="tel"
                                onChange={(e) =>
                                    setData('phone', e.target.value)
                                }
                            />
                        </FormField>
                        <FormField label="Email" error={errors.email}>
                            <Input
                                type="email"
                                value={data.email ?? ''}
                                autoComplete="email"
                                onChange={(e) =>
                                    setData('email', e.target.value)
                                }
                            />
                        </FormField>
                    </div>

                    <FormField label="Address" error={errors.address}>
                        <textarea
                            value={data.address ?? ''}
                            onChange={(e) => setData('address', e.target.value)}
                            rows={2}
                            className={TEXTAREA_CLASS}
                        />
                    </FormField>
                </Section>

                {/* Employment */}
                <Section
                    title="Employment"
                    subtitle="Placement, type and key dates."
                >
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <FormField
                            label="Employee No."
                            error={errors.employee_no}
                            hint="Auto-generated if left blank."
                        >
                            <Input
                                value={data.employee_no}
                                onChange={(e) =>
                                    setData('employee_no', e.target.value)
                                }
                                className="font-mono text-sm"
                            />
                        </FormField>
                        <FormField
                            label="Department"
                            error={errors.department_id}
                        >
                            <FormSelect
                                value={data.department_id || NONE}
                                placeholder="Select…"
                                noneValue={NONE}
                                onChange={(v) => {
                                    setData('department_id', v);
                                    setData('position_id', '');
                                }}
                                options={options.departments.map((d) => ({
                                    value: String(d.id),
                                    label: d.name,
                                }))}
                            />
                        </FormField>
                        <FormField label="Position" error={errors.position_id}>
                            <FormSelect
                                value={data.position_id || NONE}
                                placeholder="Select…"
                                noneValue={NONE}
                                onChange={(v) => setData('position_id', v)}
                                options={positions.map((p) => ({
                                    value: String(p.id),
                                    label: p.title,
                                }))}
                            />
                        </FormField>
                        <FormField label="Manager" error={errors.manager_id}>
                            <FormSelect
                                value={data.manager_id || NONE}
                                placeholder="Select…"
                                noneValue={NONE}
                                onChange={(v) => setData('manager_id', v)}
                                options={managers.map((m) => ({
                                    value: String(m.id),
                                    label: `${m.full_name} (${m.employee_no})`,
                                }))}
                            />
                        </FormField>
                        <FormField
                            label="Work schedule"
                            error={errors.work_schedule_id}
                        >
                            <FormSelect
                                value={data.work_schedule_id || NONE}
                                placeholder="Select…"
                                noneValue={NONE}
                                onChange={(v) => setData('work_schedule_id', v)}
                                options={options.schedules.map((s) => ({
                                    value: String(s.id),
                                    label: s.name,
                                }))}
                            />
                        </FormField>
                        <FormField
                            label="Employment type"
                            required
                            error={errors.employment_type}
                        >
                            <FormSelect
                                value={data.employment_type}
                                onChange={(v) =>
                                    setData(
                                        'employment_type',
                                        v as EmploymentType,
                                    )
                                }
                                options={EMPLOYMENT_TYPE_OPTIONS}
                            />
                        </FormField>
                        <FormField
                            label="Status"
                            required
                            error={errors.employment_status}
                        >
                            <FormSelect
                                value={data.employment_status}
                                onChange={(v) =>
                                    setData(
                                        'employment_status',
                                        v as EmploymentStatus,
                                    )
                                }
                                options={EMPLOYMENT_STATUS_OPTIONS}
                            />
                        </FormField>
                        <FormField
                            label="Date hired"
                            required
                            error={errors.date_hired}
                        >
                            <Input
                                type="date"
                                value={data.date_hired}
                                onChange={(e) =>
                                    setData('date_hired', e.target.value)
                                }
                                required
                            />
                        </FormField>
                        <FormField
                            label="Date regularized"
                            error={errors.date_regularized}
                            hint="Left empty until probation ends."
                        >
                            <Input
                                type="date"
                                min={data.date_hired || undefined}
                                value={data.date_regularized ?? ''}
                                onChange={(e) =>
                                    setData('date_regularized', e.target.value)
                                }
                            />
                        </FormField>
                    </div>
                </Section>

                {/* Compensation */}
                <Section
                    title="Compensation"
                    subtitle="Salary and payout details."
                >
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <FormField
                            label="Basic salary"
                            error={errors.basic_salary}
                            hint="Monthly, in pesos."
                        >
                            <Input
                                type="number"
                                step="0.01"
                                min="0"
                                inputMode="decimal"
                                value={data.basic_salary}
                                onChange={(e) =>
                                    setData('basic_salary', e.target.value)
                                }
                            />
                        </FormField>
                        <FormField label="Bank name" error={errors.bank_name}>
                            <Input
                                value={data.bank_name ?? ''}
                                onChange={(e) =>
                                    setData('bank_name', e.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            label="Bank account no."
                            error={errors.bank_account_no}
                        >
                            <Input
                                value={data.bank_account_no ?? ''}
                                onChange={(e) =>
                                    setData('bank_account_no', e.target.value)
                                }
                                className="font-mono text-sm"
                            />
                        </FormField>
                    </div>
                </Section>

                {/* Government IDs */}
                <Section
                    title="Government IDs"
                    subtitle="Statutory numbers, used for filing. Never surfaced by the assistant."
                >
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <FormField label="TIN" error={errors.tin}>
                            <Input
                                value={data.tin ?? ''}
                                onChange={(e) => setData('tin', e.target.value)}
                                className="font-mono text-sm"
                            />
                        </FormField>
                        <FormField label="SSS No." error={errors.sss_no}>
                            <Input
                                value={data.sss_no ?? ''}
                                onChange={(e) =>
                                    setData('sss_no', e.target.value)
                                }
                                className="font-mono text-sm"
                            />
                        </FormField>
                        <FormField
                            label="PhilHealth No."
                            error={errors.philhealth_no}
                        >
                            <Input
                                value={data.philhealth_no ?? ''}
                                onChange={(e) =>
                                    setData('philhealth_no', e.target.value)
                                }
                                className="font-mono text-sm"
                            />
                        </FormField>
                        <FormField
                            label="Pag-IBIG No."
                            error={errors.pagibig_no}
                        >
                            <Input
                                value={data.pagibig_no ?? ''}
                                onChange={(e) =>
                                    setData('pagibig_no', e.target.value)
                                }
                                className="font-mono text-sm"
                            />
                        </FormField>
                    </div>
                </Section>

                {/* Account link */}
                <Section
                    title="System account"
                    subtitle="Optionally link this employee to a login account."
                >
                    <FormField label="Linked user" error={errors.user_id}>
                        <FormSelect
                            value={data.user_id || NONE}
                            placeholder="No account linked"
                            noneValue={NONE}
                            onChange={(v) => setData('user_id', v)}
                            options={options.users.map((u) => ({
                                value: String(u.id),
                                label: `${u.full_name} · ${u.email}`,
                            }))}
                        />
                    </FormField>
                </Section>
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
                    {isEditing ? 'Save changes' : 'Add employee'}
                </Button>
            </ModalFooter>
        </form>
    );
}

const TEXTAREA_CLASS =
    'flex w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 aria-invalid:border-destructive aria-invalid:ring-destructive/20';

function Section({
    title,
    subtitle,
    children,
}: {
    title: string;
    subtitle: string;
    children: React.ReactNode;
}) {
    return (
        <section className="space-y-4">
            <div className="border-b border-border/70 pb-2.5">
                <h3 className="text-sm font-semibold tracking-tight">
                    {title}
                </h3>
                <p className="mt-0.5 text-xs text-muted-foreground">
                    {subtitle}
                </p>
            </div>
            {children}
        </section>
    );
}
