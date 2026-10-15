import { KeyRound, Search } from 'lucide-react';
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
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';
import { issuePins } from '../api';
import type { IssuedPin, PinCandidate } from '../types';

const ALL = '__all__';

/** The most PINs issued at once — IssueKioskPinsRequest::MAX. */
const MAX = 100;

/**
 * Give people a kiosk PIN (ADR 0092) — usually a crew at once, whose PINs are
 * then printed and handed out. It opens on the people who don't use the app,
 * since they are who a kiosk is for. Somebody who already has a PIN can be
 * picked too: they get a fresh one, which also unlocks it.
 */
export function IssuePinsDialog({
    open,
    onOpenChange,
    employees,
    departments,
    preselect,
    onIssued,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employees: PinCandidate[];
    departments: { id: number; name: string }[];
    /** Opened from one person's row: just them. */
    preselect: number[];
    onIssued: (issued: IssuedPin[]) => void;
}) {
    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="lg">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <KeyRound />
                        </ModalIcon>
                    }
                    title="Give kiosk PINs"
                    description="Each person gets a PIN to use once, at any kiosk, to choose their own. You’ll see the PINs once, to print or hand over."
                />
                {open && (
                    <Body
                        employees={employees}
                        departments={departments}
                        preselect={preselect}
                        onCancel={() => onOpenChange(false)}
                        onIssued={onIssued}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function Body({
    employees,
    departments,
    preselect,
    onCancel,
    onIssued,
}: {
    employees: PinCandidate[];
    departments: { id: number; name: string }[];
    preselect: number[];
    onCancel: () => void;
    onIssued: (issued: IssuedPin[]) => void;
}) {
    const [chosen, setChosen] = useState<Set<number>>(() => new Set(preselect));
    const [term, setTerm] = useState('');
    const [department, setDepartment] = useState(ALL);
    const [withoutApp, setWithoutApp] = useState(preselect.length === 0);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const shown = useMemo(() => {
        const needle = term.trim().toLowerCase();

        return employees.filter(
            (employee) =>
                (!withoutApp ||
                    employee.app_access !== 'active' ||
                    chosen.has(employee.id)) &&
                (department === ALL ||
                    String(employee.department_id) === department) &&
                (needle === '' ||
                    employee.full_name.toLowerCase().includes(needle) ||
                    employee.employee_no.toLowerCase().includes(needle)),
        );
    }, [employees, term, department, withoutApp, chosen]);

    const toggle = (id: number, on: boolean) =>
        setChosen((current) => {
            const next = new Set(current);

            if (on) {
                next.add(id);
            } else {
                next.delete(id);
            }

            return next;
        });

    const addShown = () =>
        setChosen(
            (current) =>
                new Set([
                    ...current,
                    ...shown
                        .filter((employee) => !employee.has_pin)
                        .map((employee) => employee.id),
                ]),
        );

    const submit = async () => {
        setBusy(true);
        setError(null);
        const outcome = await issuePins([...chosen]);
        setBusy(false);

        if (outcome.ok) {
            onIssued(outcome.data.issued);
        } else {
            setError(outcome.message);
        }
    };

    const tooMany = chosen.size > MAX;
    const newOnes = shown.filter(
        (employee) => !employee.has_pin && !chosen.has(employee.id),
    ).length;

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
                        aria-label="Department"
                        options={[
                            { value: ALL, label: 'All departments' },
                            ...departments.map((d) => ({
                                value: String(d.id),
                                label: d.name,
                            })),
                        ]}
                    />
                </div>

                <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                    <label className="inline-flex cursor-pointer items-center gap-2">
                        <Switch
                            checked={withoutApp}
                            onCheckedChange={setWithoutApp}
                            aria-label="Only people who don’t use the app"
                        />
                        Only people who don’t use the app
                    </label>
                    <span className="flex items-center gap-3">
                        <span className="tabular-nums">
                            {chosen.size} chosen
                        </span>
                        {newOnes > 0 && (
                            <button
                                type="button"
                                onClick={addShown}
                                className="font-medium text-[#0a8b91] hover:underline dark:text-[#0ABFBF]"
                            >
                                Add {newOnes} without a PIN
                            </button>
                        )}
                    </span>
                </div>

                <ul className="max-h-[50dvh] divide-y divide-border overflow-y-auto rounded-lg border border-border">
                    {shown.length === 0 && (
                        <li className="px-3 py-6 text-center text-sm text-muted-foreground">
                            {withoutApp
                                ? 'Everybody here uses the app. Turn off “Only people who don’t use the app” to see them.'
                                : 'Nobody matches.'}
                        </li>
                    )}
                    {shown.map((employee) => (
                        <li
                            key={employee.id}
                            className="flex items-center gap-3 px-3 py-2"
                        >
                            <Checkbox
                                id={`pin-${employee.id}`}
                                checked={chosen.has(employee.id)}
                                onCheckedChange={(checked) =>
                                    toggle(employee.id, checked === true)
                                }
                            />
                            <label
                                htmlFor={`pin-${employee.id}`}
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
                            <span
                                className={cn(
                                    'shrink-0 rounded-full border px-2 py-0.5 text-[11px]',
                                    employee.has_pin
                                        ? 'border-[#0ABFBF]/40 bg-[#0ABFBF]/10 text-[#08767c] dark:text-[#0ABFBF]'
                                        : 'border-border text-muted-foreground',
                                )}
                            >
                                {employee.has_pin
                                    ? 'Has a PIN: gets a new one'
                                    : employee.app_access === 'active'
                                      ? 'Uses the app'
                                      : 'No app'}
                            </span>
                        </li>
                    ))}
                </ul>

                {tooMany && (
                    <p className="text-sm text-destructive">
                        Give up to {MAX} PINs at a time.
                    </p>
                )}
                {error && <p className="text-sm text-destructive">{error}</p>}
            </ModalBody>

            <ModalFooter>
                <Button type="button" variant="ghost" onClick={onCancel}>
                    Cancel
                </Button>
                <Button
                    type="button"
                    onClick={() => void submit()}
                    disabled={busy || chosen.size === 0 || tooMany}
                >
                    {busy && <Spinner />}
                    {chosen.size > 1 ? `Give ${chosen.size} PINs` : 'Give PIN'}
                </Button>
            </ModalFooter>
        </>
    );
}
