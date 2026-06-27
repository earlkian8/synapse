import { useForm } from '@inertiajs/react';
import { Trash2, Upload } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
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

export function EmployeeFormSheet({
    employee,
    options,
    open,
    onOpenChange,
}: Props) {
    const isEditing = Boolean(employee);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-2xl"
            >
                <SheetHeader className="border-b border-border px-6 py-4">
                    <SheetTitle>
                        {isEditing ? 'Edit employee' : 'New employee'}
                    </SheetTitle>
                    <SheetDescription>
                        {isEditing
                            ? 'Update this employee record.'
                            : 'Add a new employee to the directory.'}
                    </SheetDescription>
                </SheetHeader>

                {open && (
                    <FormBody
                        key={employee?.id ?? 'new'}
                        employee={employee}
                        options={options}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </SheetContent>
        </Sheet>
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
        <form onSubmit={submit} className="flex h-full flex-col">
            <div className="flex-1 space-y-8 px-6 py-6">
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
                                    alt="Employee preview"
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
                                onChange={(e) =>
                                    pickPhoto(e.target.files?.[0] ?? null)
                                }
                            />
                            <InputError message={errors.photo} />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="First name"
                            required
                            error={errors.first_name}
                        >
                            <Input
                                value={data.first_name}
                                onChange={(e) =>
                                    setData('first_name', e.target.value)
                                }
                                required
                            />
                        </Field>
                        <Field
                            label="Last name"
                            required
                            error={errors.last_name}
                        >
                            <Input
                                value={data.last_name}
                                onChange={(e) =>
                                    setData('last_name', e.target.value)
                                }
                                required
                            />
                        </Field>
                        <Field label="Middle name" error={errors.middle_name}>
                            <Input
                                value={data.middle_name ?? ''}
                                onChange={(e) =>
                                    setData('middle_name', e.target.value)
                                }
                            />
                        </Field>
                        <Field label="Suffix" error={errors.suffix}>
                            <Input
                                value={data.suffix ?? ''}
                                onChange={(e) =>
                                    setData('suffix', e.target.value)
                                }
                                placeholder="Jr., III…"
                            />
                        </Field>
                        <Field label="Birth date" error={errors.birth_date}>
                            <Input
                                type="date"
                                value={data.birth_date ?? ''}
                                onChange={(e) =>
                                    setData('birth_date', e.target.value)
                                }
                            />
                        </Field>
                        <Field label="Gender" error={errors.gender}>
                            <FkSelect
                                value={data.gender || NONE}
                                placeholder="Select…"
                                onChange={(v) => setData('gender', v)}
                                options={GENDER_OPTIONS.map((o) => ({
                                    value: o.value,
                                    label: o.label,
                                }))}
                            />
                        </Field>
                        <Field label="Civil status" error={errors.civil_status}>
                            <FkSelect
                                value={data.civil_status || NONE}
                                placeholder="Select…"
                                onChange={(v) => setData('civil_status', v)}
                                options={CIVIL_STATUS_OPTIONS.map((o) => ({
                                    value: o.value,
                                    label: o.label,
                                }))}
                            />
                        </Field>
                        <Field label="Phone" error={errors.phone}>
                            <Input
                                value={data.phone ?? ''}
                                onChange={(e) =>
                                    setData('phone', e.target.value)
                                }
                            />
                        </Field>
                        <Field label="Email" error={errors.email}>
                            <Input
                                type="email"
                                value={data.email ?? ''}
                                onChange={(e) =>
                                    setData('email', e.target.value)
                                }
                            />
                        </Field>
                    </div>
                    <Field label="Address" error={errors.address}>
                        <textarea
                            value={data.address ?? ''}
                            onChange={(e) => setData('address', e.target.value)}
                            rows={2}
                            className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                        />
                    </Field>
                </Section>

                {/* Employment */}
                <Section
                    title="Employment"
                    subtitle="Placement, type and key dates."
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Employee No." error={errors.employee_no}>
                            <Input
                                value={data.employee_no}
                                onChange={(e) =>
                                    setData('employee_no', e.target.value)
                                }
                                placeholder="Auto-generated if blank"
                                className="font-mono text-sm"
                            />
                        </Field>
                        <Field label="Department" error={errors.department_id}>
                            <FkSelect
                                value={data.department_id || NONE}
                                placeholder="Select…"
                                onChange={(v) => {
                                    setData('department_id', v);
                                    setData('position_id', '');
                                }}
                                options={options.departments.map((d) => ({
                                    value: String(d.id),
                                    label: d.name,
                                }))}
                            />
                        </Field>
                        <Field label="Position" error={errors.position_id}>
                            <FkSelect
                                value={data.position_id || NONE}
                                placeholder="Select…"
                                onChange={(v) => setData('position_id', v)}
                                options={positions.map((p) => ({
                                    value: String(p.id),
                                    label: p.title,
                                }))}
                            />
                        </Field>
                        <Field label="Manager" error={errors.manager_id}>
                            <FkSelect
                                value={data.manager_id || NONE}
                                placeholder="Select…"
                                onChange={(v) => setData('manager_id', v)}
                                options={managers.map((m) => ({
                                    value: String(m.id),
                                    label: `${m.full_name} (${m.employee_no})`,
                                }))}
                            />
                        </Field>
                        <Field
                            label="Work schedule"
                            error={errors.work_schedule_id}
                        >
                            <FkSelect
                                value={data.work_schedule_id || NONE}
                                placeholder="Select…"
                                onChange={(v) => setData('work_schedule_id', v)}
                                options={options.schedules.map((s) => ({
                                    value: String(s.id),
                                    label: s.name,
                                }))}
                            />
                        </Field>
                        <Field
                            label="Employment type"
                            required
                            error={errors.employment_type}
                        >
                            <FkSelect
                                value={data.employment_type}
                                onChange={(v) =>
                                    setData(
                                        'employment_type',
                                        v as EmploymentType,
                                    )
                                }
                                options={EMPLOYMENT_TYPE_OPTIONS}
                            />
                        </Field>
                        <Field
                            label="Status"
                            required
                            error={errors.employment_status}
                        >
                            <FkSelect
                                value={data.employment_status}
                                onChange={(v) =>
                                    setData(
                                        'employment_status',
                                        v as EmploymentStatus,
                                    )
                                }
                                options={EMPLOYMENT_STATUS_OPTIONS}
                            />
                        </Field>
                        <Field
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
                        </Field>
                        <Field
                            label="Date regularized"
                            error={errors.date_regularized}
                        >
                            <Input
                                type="date"
                                value={data.date_regularized ?? ''}
                                onChange={(e) =>
                                    setData('date_regularized', e.target.value)
                                }
                            />
                        </Field>
                    </div>
                </Section>

                {/* Compensation */}
                <Section
                    title="Compensation"
                    subtitle="Salary and payout details."
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Basic salary" error={errors.basic_salary}>
                            <Input
                                type="number"
                                step="0.01"
                                min="0"
                                value={data.basic_salary}
                                onChange={(e) =>
                                    setData('basic_salary', e.target.value)
                                }
                            />
                        </Field>
                        <Field label="Bank name" error={errors.bank_name}>
                            <Input
                                value={data.bank_name ?? ''}
                                onChange={(e) =>
                                    setData('bank_name', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Bank account no."
                            error={errors.bank_account_no}
                        >
                            <Input
                                value={data.bank_account_no ?? ''}
                                onChange={(e) =>
                                    setData('bank_account_no', e.target.value)
                                }
                            />
                        </Field>
                    </div>
                </Section>

                {/* Government IDs */}
                <Section title="Government IDs" subtitle="Statutory numbers.">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="TIN" error={errors.tin}>
                            <Input
                                value={data.tin ?? ''}
                                onChange={(e) => setData('tin', e.target.value)}
                            />
                        </Field>
                        <Field label="SSS No." error={errors.sss_no}>
                            <Input
                                value={data.sss_no ?? ''}
                                onChange={(e) =>
                                    setData('sss_no', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="PhilHealth No."
                            error={errors.philhealth_no}
                        >
                            <Input
                                value={data.philhealth_no ?? ''}
                                onChange={(e) =>
                                    setData('philhealth_no', e.target.value)
                                }
                            />
                        </Field>
                        <Field label="Pag-IBIG No." error={errors.pagibig_no}>
                            <Input
                                value={data.pagibig_no ?? ''}
                                onChange={(e) =>
                                    setData('pagibig_no', e.target.value)
                                }
                            />
                        </Field>
                    </div>
                </Section>

                {/* Account link */}
                <Section
                    title="System account"
                    subtitle="Optionally link this employee to a login account."
                >
                    <Field label="Linked user" error={errors.user_id}>
                        <FkSelect
                            value={data.user_id || NONE}
                            placeholder="No account linked"
                            onChange={(v) => setData('user_id', v)}
                            options={options.users.map((u) => ({
                                value: String(u.id),
                                label: `${u.full_name} · ${u.email}`,
                            }))}
                        />
                    </Field>
                </Section>
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
                        {isEditing ? 'Save changes' : 'Add employee'}
                    </Button>
                </div>
            </SheetFooter>
        </form>
    );
}

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
            <div>
                <h3 className="text-sm font-semibold">{title}</h3>
                <p className="text-xs text-muted-foreground">{subtitle}</p>
            </div>
            {children}
        </section>
    );
}

function Field({
    label,
    required = false,
    error,
    children,
}: {
    label: string;
    required?: boolean;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <Label className="mb-1.5 block">
                {label}
                {required && <span className="ml-0.5 text-destructive">*</span>}
            </Label>
            {children}
            <InputError message={error} className="mt-1.5" />
        </div>
    );
}

function FkSelect({
    value,
    placeholder,
    onChange,
    options,
}: {
    value: string;
    placeholder?: string;
    onChange: (value: string) => void;
    options: { value: string; label: string }[];
}) {
    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger className="w-full">
                <SelectValue placeholder={placeholder} />
            </SelectTrigger>
            <SelectContent>
                {placeholder && (
                    <SelectItem value={NONE}>{placeholder}</SelectItem>
                )}
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
