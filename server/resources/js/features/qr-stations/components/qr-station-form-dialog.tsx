import { Link, useForm } from '@inertiajs/react';
import { QrCode, ShieldCheck, Smartphone } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
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
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';
import { qrStationRoutes } from '../routes';
import type { QrScanMode, QrStation, QrStationsPageProps } from '../types';

/** The two ways a station can be scanned, in the terms HR decides between. */
const SCAN_MODES: {
    value: QrScanMode;
    icon: LucideIcon;
    title: string;
    body: string;
}[] = [
    {
        value: 'app',
        icon: ShieldCheck,
        title: 'With the SYNAPSE app',
        body: 'Strongest. The app knows whose phone it is and can tell when its location is faked.',
    },
    {
        value: 'any',
        icon: Smartphone,
        title: 'With any phone’s camera',
        body: 'No app needed. People open the link and type their employee number and PIN.',
    },
];

/** What each rotation buys, in the words HR weighs it in. */
const ROTATE_HINT: Record<number, string> = {
    10: 'Hardest to pass on. Phones far from the screen may need a second try.',
    15: 'Recommended. A photo of the code is useless within half a minute.',
    30: 'Easier for slow phones; a forwarded photo lasts up to a minute.',
    60: 'For a screen people queue at slowly. Lean on the location check.',
};

/**
 * Add a QR station, or change one (ADR 0103). The site is required: a scan is
 * accepted only from a phone inside it.
 */
export function QrStationFormDialog({
    station,
    open,
    onOpenChange,
    locations,
    rotateChoices,
    pins,
    canManagePins,
}: {
    station: QrStation | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    locations: { id: number; name: string }[];
    rotateChoices: number[];
    pins: QrStationsPageProps['pins'];
    canManagePins: boolean;
}) {
    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="md">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <QrCode />
                        </ModalIcon>
                    }
                    title={
                        station ? `Edit “${station.name}”` : 'Add a QR station'
                    }
                    description="A screen at a site showing a code that changes every few seconds. People scan it with their phone to clock in."
                />
                {open && (
                    <Body
                        key={station?.hashid ?? 'new'}
                        station={station}
                        locations={locations}
                        rotateChoices={rotateChoices}
                        pins={pins}
                        canManagePins={canManagePins}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function Body({
    station,
    locations,
    rotateChoices,
    pins,
    canManagePins,
    onDone,
}: {
    station: QrStation | null;
    locations: { id: number; name: string }[];
    rotateChoices: number[];
    pins: QrStationsPageProps['pins'];
    canManagePins: boolean;
    onDone: () => void;
}) {
    const { data, setData, post, transform, processing, errors } = useForm({
        name: station?.name ?? '',
        work_location_id:
            station?.location && !station.location.archived
                ? String(station.location.id)
                : locations.length === 1
                  ? String(locations[0].id)
                  : '',
        rotate_seconds: String(station?.rotate_seconds ?? 15),
        scan_mode: (station?.scan_mode ?? 'app') as QrScanMode,
        same_network: station?.same_network ?? false,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        transform((payload) => ({
            name: payload.name,
            work_location_id:
                payload.work_location_id === ''
                    ? null
                    : Number(payload.work_location_id),
            rotate_seconds: Number(payload.rotate_seconds),
            scan_mode: payload.scan_mode,
            same_network: payload.same_network,
        }));

        post(
            station
                ? qrStationRoutes.update(station.hashid)
                : qrStationRoutes.store,
            { preserveScroll: true, onSuccess: onDone },
        );
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <ModalBody className="space-y-5">
                <FormField
                    label="Name"
                    required
                    error={errors.name}
                    hint="Where the screen is, so people know which one it is."
                >
                    <Input
                        value={data.name}
                        onChange={(event) =>
                            setData('name', event.target.value)
                        }
                        placeholder="Front desk"
                        maxLength={80}
                        autoFocus
                    />
                </FormField>

                <FormField
                    label="Site"
                    required
                    error={errors.work_location_id}
                    hint={
                        locations.length === 0
                            ? 'Add a site under Company Setup → Locations first. A scan is accepted only from inside one.'
                            : 'A scan is accepted only from a phone inside this site’s fence.'
                    }
                >
                    <FormSelect
                        value={data.work_location_id}
                        onChange={(value) => setData('work_location_id', value)}
                        placeholder="Choose a site"
                        options={locations.map((location) => ({
                            value: String(location.id),
                            label: location.name,
                        }))}
                    />
                </FormField>

                <FormField
                    label="The code changes every"
                    error={errors.rotate_seconds}
                    hint={ROTATE_HINT[Number(data.rotate_seconds)]}
                >
                    <FormSelect
                        value={data.rotate_seconds}
                        onChange={(value) => setData('rotate_seconds', value)}
                        options={rotateChoices.map((seconds) => ({
                            value: String(seconds),
                            label: `${seconds} seconds`,
                        }))}
                    />
                </FormField>

                <fieldset>
                    <legend className="mb-2 text-sm font-medium">
                        How people scan it
                    </legend>
                    <div
                        role="radiogroup"
                        aria-label="How people scan it"
                        className="grid gap-2 sm:grid-cols-2"
                    >
                        {SCAN_MODES.map((mode) => {
                            const active = data.scan_mode === mode.value;
                            const Icon = mode.icon;

                            return (
                                <button
                                    key={mode.value}
                                    type="button"
                                    role="radio"
                                    aria-checked={active}
                                    onClick={() =>
                                        setData('scan_mode', mode.value)
                                    }
                                    className={cn(
                                        'flex flex-col items-start gap-1 rounded-xl border px-3.5 py-3 text-left transition-colors focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-none',
                                        active
                                            ? 'border-[#0a8b91] bg-[#0ABFBF]/[0.06] ring-1 ring-[#0a8b91]'
                                            : 'border-border hover:bg-muted/40',
                                    )}
                                >
                                    <span className="flex items-center gap-2 text-sm font-semibold">
                                        <Icon
                                            className={cn(
                                                'size-4',
                                                active
                                                    ? 'text-[#0a8b91]'
                                                    : 'text-muted-foreground',
                                            )}
                                        />
                                        {mode.title}
                                    </span>
                                    <span className="text-xs leading-snug text-muted-foreground">
                                        {mode.body}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                    {errors.scan_mode && (
                        <p className="mt-1.5 text-sm text-destructive">
                            {errors.scan_mode}
                        </p>
                    )}
                    {data.scan_mode === 'any' && (
                        <div className="mt-3 space-y-1.5 rounded-lg bg-amber-500/10 px-3.5 py-3 text-xs leading-relaxed text-amber-900 dark:text-amber-200">
                            <p>
                                People sign in with their employee number and
                                the PIN they use at kiosks
                                {pins
                                    ? ` (${pins.issued} of ${pins.employees} people have one)`
                                    : ''}
                                .{' '}
                                {canManagePins ? (
                                    <Link
                                        href="/attendance/kiosks"
                                        className="font-medium underline underline-offset-2"
                                    >
                                        Issue PINs under Kiosks
                                    </Link>
                                ) : (
                                    'HR issues PINs under Attendance → Kiosks.'
                                )}
                            </p>
                            <p>
                                A browser can’t tell when a location is faked,
                                and it’s easier to pass a PIN on than a phone.
                                Asking for the office Wi-Fi below closes most of
                                that gap.
                            </p>
                        </div>
                    )}
                </fieldset>

                <label className="flex items-start justify-between gap-4">
                    <span>
                        <span className="block text-sm font-medium">
                            Only on the office Wi-Fi
                        </span>
                        <span className="mt-0.5 block text-xs leading-relaxed text-muted-foreground">
                            A scan counts only from a phone on the same internet
                            connection as the screen. Someone at home can’t use
                            it, even with a faked location. Phones on mobile
                            data are turned away.
                        </span>
                    </span>
                    <Switch
                        checked={data.same_network}
                        onCheckedChange={(checked) =>
                            setData('same_network', checked)
                        }
                        aria-label="Only on the office Wi-Fi"
                    />
                </label>
            </ModalBody>

            <ModalFooter>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
                <Button
                    type="submit"
                    disabled={processing || locations.length === 0}
                >
                    {processing && <Spinner />}
                    {station ? 'Save station' : 'Add station'}
                </Button>
            </ModalFooter>
        </form>
    );
}
