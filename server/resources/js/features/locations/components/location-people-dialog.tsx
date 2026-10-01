import { router } from '@inertiajs/react';
import { Search, Star, Users } from 'lucide-react';
import { useMemo, useState } from 'react';
import { FormSelect } from '@/components/form-select';
import {
    Modal,
    ModalBody,
    ModalContent,
    ModalFooter,
    ModalHeader,
    ModalIcon,
} from '@/components/modal';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { locationRoutes } from '../routes';
import type { LocationEmployee, Ref, WorkLocation } from '../types';

const ALL = '__all__';

type Props = {
    location: WorkLocation | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employees: LocationEmployee[];
    departments: Ref[];
};

/**
 * Who is based at a site (ADR 0040). Their punches are checked against its
 * fence; for whoever has it as their **primary** site, its schedule and policy
 * are their defaults. Somebody based nowhere is checked against every site.
 */
export function LocationPeopleDialog({
    location,
    open,
    onOpenChange,
    employees,
    departments,
}: Props) {
    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="lg">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <Users />
                        </ModalIcon>
                    }
                    title={location ? `Based at ${location.name}` : 'People'}
                    description="Their punches are checked against this fence. Star it as somebody’s primary site to give them its schedule and policy."
                />

                {open && location && (
                    <Body
                        key={location.id}
                        location={location}
                        employees={employees}
                        departments={departments}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function Body({
    location,
    employees,
    departments,
    onDone,
}: {
    location: WorkLocation;
    employees: LocationEmployee[];
    departments: Ref[];
    onDone: () => void;
}) {
    const [based, setBased] = useState<Set<number>>(
        () => new Set((location.people ?? []).map((person) => person.id)),
    );
    const [primary, setPrimary] = useState<Set<number>>(
        () =>
            new Set(
                (location.people ?? [])
                    .filter((person) => person.is_primary)
                    .map((person) => person.id),
            ),
    );
    const [term, setTerm] = useState('');
    const [department, setDepartment] = useState(ALL);
    const [saving, setSaving] = useState(false);
    // Who was based here when the dialog opened is listed first. Fixed for the
    // dialog's life, so a row never jumps away from the pointer that ticked it.
    const [basedAtOpen] = useState(() => new Set(based));

    const shown = useMemo(() => {
        const needle = term.trim().toLowerCase();

        return employees
            .filter(
                (employee) =>
                    (department === ALL ||
                        String(employee.department_id) === department) &&
                    (needle === '' ||
                        employee.full_name.toLowerCase().includes(needle) ||
                        employee.employee_no.toLowerCase().includes(needle)),
            )
            .sort(
                (a, b) =>
                    Number(basedAtOpen.has(b.id)) -
                    Number(basedAtOpen.has(a.id)),
            );
    }, [employees, term, department, basedAtOpen]);

    const toggle = (id: number, on: boolean) => {
        setBased((current) => {
            const next = new Set(current);

            if (on) {
                next.add(id);
            } else {
                next.delete(id);
            }

            return next;
        });

        if (!on) {
            setPrimary((current) => {
                const next = new Set(current);
                next.delete(id);

                return next;
            });
        }
    };

    const togglePrimary = (id: number) =>
        setPrimary((current) => {
            const next = new Set(current);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });

    const addAllShown = () =>
        setBased((current) => new Set([...current, ...shown.map((e) => e.id)]));

    const save = () =>
        router.put(
            locationRoutes.people(location.hashid),
            { employee_ids: [...based], primary_ids: [...primary] },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: onDone,
            },
        );

    return (
        <>
            <ModalBody className="flex flex-col gap-3">
                <div className="flex flex-col gap-2 sm:flex-row">
                    <div className="relative min-w-0 flex-1">
                        <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={term}
                            onChange={(event) => setTerm(event.target.value)}
                            placeholder="Search by name or number"
                            aria-label="Search people"
                            className="pl-8"
                        />
                    </div>
                    <FormSelect
                        value={department}
                        onChange={setDepartment}
                        className="w-full sm:w-48"
                        options={[
                            { value: ALL, label: 'All departments' },
                            ...departments.map((d) => ({
                                value: String(d.id),
                                label: d.name,
                            })),
                        ]}
                    />
                </div>

                <div className="flex items-center justify-between text-xs text-muted-foreground">
                    <span className="tabular-nums">
                        {based.size} based here · {primary.size} primary
                    </span>
                    {shown.some((employee) => !based.has(employee.id)) && (
                        <button
                            type="button"
                            onClick={addAllShown}
                            className="font-medium text-[#0a8b91] hover:underline dark:text-[#0ABFBF]"
                        >
                            Add all {shown.length} shown
                        </button>
                    )}
                </div>

                <ul className="max-h-[50dvh] divide-y divide-border overflow-y-auto rounded-lg border border-border">
                    {shown.length === 0 && (
                        <li className="px-3 py-6 text-center text-sm text-muted-foreground">
                            Nobody matches.
                        </li>
                    )}
                    {shown.map((employee) => {
                        const isBased = based.has(employee.id);
                        const isPrimary = primary.has(employee.id);

                        return (
                            <li
                                key={employee.id}
                                className="flex items-center gap-3 px-3 py-2"
                            >
                                <Checkbox
                                    id={`based-${employee.id}`}
                                    checked={isBased}
                                    onCheckedChange={(checked) =>
                                        toggle(employee.id, checked === true)
                                    }
                                />
                                <label
                                    htmlFor={`based-${employee.id}`}
                                    className="flex min-w-0 flex-1 cursor-pointer items-center gap-2.5"
                                >
                                    <PersonAvatar
                                        name={employee.full_name}
                                        initials={employee.initials}
                                        photo={employee.photo}
                                        className="size-7"
                                    />
                                    <span className="min-w-0">
                                        <span className="block truncate text-sm">
                                            {employee.full_name}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {employee.employee_no}
                                        </span>
                                    </span>
                                </label>
                                {isBased && (
                                    <button
                                        type="button"
                                        onClick={() =>
                                            togglePrimary(employee.id)
                                        }
                                        aria-pressed={isPrimary}
                                        className={cn(
                                            'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] transition-colors',
                                            isPrimary
                                                ? 'border-[#0ABFBF]/40 bg-[#0ABFBF]/10 font-medium text-[#0a8b91] dark:text-[#0ABFBF]'
                                                : 'border-border text-muted-foreground hover:bg-muted',
                                        )}
                                    >
                                        <Star
                                            className={cn(
                                                'size-3',
                                                isPrimary && 'fill-current',
                                            )}
                                        />
                                        Primary
                                    </button>
                                )}
                            </li>
                        );
                    })}
                </ul>
            </ModalBody>

            <ModalFooter>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
                <Button type="button" onClick={save} disabled={saving}>
                    {saving && <Spinner />}
                    Save people
                </Button>
            </ModalFooter>
        </>
    );
}
