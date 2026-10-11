import { useForm } from '@inertiajs/react';
import {
    Circle,
    Eraser,
    Hexagon,
    LocateFixed,
    MapPinned,
    Search,
    Undo2,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
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
import { TimezoneSelect } from '@/components/timezone-select';
import type { TimezoneOption } from '@/components/timezone-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';
import { GEOCODER_URL, locationRoutes } from '../routes';
import type { Corner, Fence, Ref, WorkLocation } from '../types';
import { FencePicker, ShapePicker } from './fence-map';

const NONE = '__none__';

/** The fence a new site starts with: a building and its car park. */
const DEFAULT_RADIUS = 150;

/** The slider's reach; the number box goes to the server's 5 km. */
const SLIDER_MAX = 1000;

type Props = {
    location: WorkLocation | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    schedules: Ref[];
    policies: Ref[];
    timezones: TimezoneOption[];
    organizationTimezone: string;
};

/**
 * One site (ADR 0040): where it is, drawn on a map with its fence, and what the
 * people based there default to. The map is the quick way in; the coordinates
 * underneath it are the exact one, and the way in for anybody not using a
 * pointer.
 *
 * The fence is a circle, or a shape drawn corner by corner for a site no circle
 * fits — a long warehouse, a campus along a road (ADR 0079). A site can also
 * keep its own clock, for people who work in another time zone.
 */
export function LocationFormModal({
    location,
    open,
    onOpenChange,
    schedules,
    policies,
    timezones,
    organizationTimezone,
}: Props) {
    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="2xl" className="lg:max-w-5xl">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <MapPinned />
                        </ModalIcon>
                    }
                    title={location ? location.name : 'New location'}
                    description="A site people punch at, and its fence: a punch counts as on site when the phone’s position touches it."
                />

                {open && (
                    <FormBody
                        key={location?.id ?? 'new'}
                        location={location}
                        schedules={schedules}
                        policies={policies}
                        timezones={timezones}
                        organizationTimezone={organizationTimezone}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

type SearchResult = { display_name: string; lat: string; lon: string };

function FormBody({
    location,
    schedules,
    policies,
    timezones,
    organizationTimezone,
    onDone,
}: {
    location: WorkLocation | null;
    schedules: Ref[];
    policies: Ref[];
    timezones: TimezoneOption[];
    organizationTimezone: string;
    onDone: () => void;
}) {
    const { data, setData, post, processing, errors, transform } = useForm({
        fence_type: (location?.fence_type ?? 'circle') as 'circle' | 'polygon',
        polygon: (location?.polygon ?? []) as Corner[],
        own_clock: Boolean(location?.timezone),
        timezone: location?.timezone ?? organizationTimezone,
        name: location?.name ?? '',
        address: location?.address ?? '',
        latitude: location ? String(location.latitude) : '',
        longitude: location ? String(location.longitude) : '',
        radius_meters: location?.radius_meters ?? DEFAULT_RADIUS,
        default_work_schedule_id: location?.default_work_schedule_id
            ? String(location.default_work_schedule_id)
            : '',
        attendance_policy_id: location?.attendance_policy_id
            ? String(location.attendance_policy_id)
            : '',
        is_active: location?.is_active ?? true,
    });

    // Bumped whenever the fence moves from outside the map — a search result,
    // "use my location" — so the map brings it into view.
    const [recenter, setRecenter] = useState(0);
    const [query, setQuery] = useState('');
    const [searching, setSearching] = useState(false);
    const [results, setResults] = useState<SearchResult[] | null>(null);
    const [searchError, setSearchError] = useState<string | null>(null);
    const [locating, setLocating] = useState(false);

    const latitude = Number.parseFloat(data.latitude);
    const longitude = Number.parseFloat(data.longitude);
    const fence: Fence | null =
        Number.isFinite(latitude) && Number.isFinite(longitude)
            ? { latitude, longitude, radius_meters: data.radius_meters }
            : null;
    const isShape = data.fence_type === 'polygon';
    const ready = isShape ? data.polygon.length >= 3 : fence !== null;
    const fieldErrors = errors as Record<string, string | undefined>;
    const shapeError =
        fieldErrors.polygon ??
        Object.entries(fieldErrors).find(([key]) =>
            key.startsWith('polygon.'),
        )?.[1];

    const place = (next: Fence, bringIntoView = false) => {
        setData((current) => ({
            ...current,
            latitude: String(next.latitude),
            longitude: String(next.longitude),
        }));

        if (bringIntoView) {
            setRecenter((count) => count + 1);
        }
    };

    const search = async (event: { preventDefault: () => void }) => {
        event.preventDefault();

        if (query.trim() === '') {
            return;
        }

        setSearching(true);
        setSearchError(null);

        try {
            const params = new URLSearchParams({
                q: query.trim(),
                format: 'jsonv2',
                limit: '5',
            });
            const response = await fetch(`${GEOCODER_URL}?${params}`, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            const found = (await response.json()) as SearchResult[];
            setResults(found);

            if (found.length === 0) {
                setSearchError(
                    'Nothing matched. Try the street and city, or click the site on the map.',
                );
            }
        } catch {
            setSearchError(
                'The address search didn’t answer. Click the site on the map instead.',
            );
        } finally {
            setSearching(false);
        }
    };

    const choose = (result: SearchResult) => {
        place(
            {
                latitude: round(Number.parseFloat(result.lat)),
                longitude: round(Number.parseFloat(result.lon)),
                radius_meters: data.radius_meters,
            },
            true,
        );

        if (data.address.trim() === '') {
            setData('address', result.display_name.slice(0, 255));
        }

        setResults(null);
    };

    const locateMe = () => {
        if (!('geolocation' in navigator)) {
            setSearchError('This browser can’t share its location.');

            return;
        }

        setLocating(true);
        setSearchError(null);

        navigator.geolocation.getCurrentPosition(
            (position) => {
                setLocating(false);
                place(
                    {
                        latitude: round(position.coords.latitude),
                        longitude: round(position.coords.longitude),
                        radius_meters: data.radius_meters,
                    },
                    true,
                );
            },
            () => {
                setLocating(false);
                setSearchError(
                    'Your location wasn’t shared. Allow it in the browser, or search for the address.',
                );
            },
            { enableHighAccuracy: true, timeout: 10000 },
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        transform(({ own_clock, ...current }) => ({
            ...current,
            polygon: current.fence_type === 'polygon' ? current.polygon : null,
            latitude:
                current.fence_type === 'polygon' ? null : current.latitude,
            longitude:
                current.fence_type === 'polygon' ? null : current.longitude,
            radius_meters:
                current.fence_type === 'polygon' ? null : current.radius_meters,
            timezone: own_clock ? current.timezone : null,
            default_work_schedule_id: current.default_work_schedule_id || null,
            attendance_policy_id: current.attendance_policy_id || null,
        }));

        post(
            location
                ? locationRoutes.update(location.hashid)
                : locationRoutes.store,
            {
                preserveScroll: true,
                onSuccess: onDone,
            },
        );
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <ModalBody className="grid gap-6 lg:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)]">
                {/* The map, and the two quick ways to find the place. */}
                <div className="flex min-w-0 flex-col gap-3">
                    <div className="flex gap-2">
                        <div className="relative min-w-0 flex-1">
                            <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter') {
                                        void search(event);
                                    }
                                }}
                                placeholder="Search for an address"
                                aria-label="Search for an address"
                                className="pl-8"
                            />
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={(event) => void search(event)}
                            disabled={searching || query.trim() === ''}
                        >
                            {searching ? <Spinner /> : 'Find'}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            onClick={locateMe}
                            disabled={locating}
                            aria-label="Use my location"
                            title="Use my location"
                        >
                            {locating ? (
                                <Spinner />
                            ) : (
                                <LocateFixed className="size-4" />
                            )}
                        </Button>
                    </div>

                    {results && results.length > 0 && (
                        <ul className="divide-y divide-border overflow-hidden rounded-lg border border-border text-sm">
                            {results.map((result) => (
                                <li key={`${result.lat},${result.lon}`}>
                                    <button
                                        type="button"
                                        onClick={() => choose(result)}
                                        className="w-full px-3 py-2 text-left transition-colors hover:bg-muted focus-visible:bg-muted focus-visible:outline-none"
                                    >
                                        {result.display_name}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                    {searchError && (
                        <p className="text-xs text-muted-foreground">
                            {searchError}
                        </p>
                    )}

                    <div
                        role="radiogroup"
                        aria-label="Fence"
                        className="grid grid-cols-2 gap-1 rounded-lg bg-muted p-1"
                    >
                        {(
                            [
                                {
                                    value: 'circle',
                                    label: 'Circle',
                                    icon: Circle,
                                },
                                {
                                    value: 'polygon',
                                    label: 'Draw a shape',
                                    icon: Hexagon,
                                },
                            ] as const
                        ).map((option) => (
                            <button
                                key={option.value}
                                type="button"
                                role="radio"
                                aria-checked={data.fence_type === option.value}
                                onClick={() =>
                                    setData('fence_type', option.value)
                                }
                                className={cn(
                                    'inline-flex items-center justify-center gap-1.5 rounded-md px-2 py-1.5 text-sm font-medium transition-colors',
                                    data.fence_type === option.value
                                        ? 'bg-background text-foreground shadow-xs'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                <option.icon className="size-4" />
                                {option.label}
                            </button>
                        ))}
                    </div>

                    <div className="relative overflow-hidden rounded-xl border border-border">
                        {isShape ? (
                            <ShapePicker
                                corners={data.polygon}
                                onChange={(corners) =>
                                    setData('polygon', corners)
                                }
                                center={fence}
                                recenter={recenter}
                                className="h-72 w-full sm:h-96"
                            />
                        ) : (
                            <FencePicker
                                fence={fence}
                                onChange={(next) => place(next)}
                                recenter={recenter}
                                className="h-72 w-full sm:h-96"
                            />
                        )}
                        {!ready && (
                            <p className="pointer-events-none absolute inset-x-3 bottom-3 z-[1000] rounded-lg bg-background/90 px-3 py-2 text-center text-xs text-muted-foreground shadow-sm backdrop-blur">
                                {isShape
                                    ? data.polygon.length === 0
                                        ? 'Click the map at each corner of the site, in order round it.'
                                        : `${data.polygon.length} of at least 3 corners. Keep clicking round the site.`
                                    : 'Search for the address, use your location, or click the site on the map.'}
                            </p>
                        )}
                    </div>

                    {isShape ? (
                        <div className="flex flex-wrap items-center gap-2">
                            <p className="mr-auto text-sm text-muted-foreground tabular-nums">
                                {data.polygon.length}{' '}
                                {data.polygon.length === 1
                                    ? 'corner'
                                    : 'corners'}
                                {data.polygon.length >= 3 &&
                                    ' · the last joins the first'}
                            </p>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={data.polygon.length === 0}
                                onClick={() =>
                                    setData(
                                        'polygon',
                                        data.polygon.slice(0, -1),
                                    )
                                }
                            >
                                <Undo2 className="size-4" />
                                Undo corner
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                disabled={data.polygon.length === 0}
                                onClick={() => setData('polygon', [])}
                            >
                                <Eraser className="size-4" />
                                Clear
                            </Button>
                            <InputError
                                className="basis-full"
                                message={shapeError}
                            />
                        </div>
                    ) : (
                        <>
                            <div className="flex items-center gap-3">
                                <label
                                    htmlFor="fence-radius"
                                    className="shrink-0 text-sm"
                                >
                                    Radius
                                </label>
                                <input
                                    id="fence-radius"
                                    type="range"
                                    min={25}
                                    max={SLIDER_MAX}
                                    step={5}
                                    value={Math.min(
                                        data.radius_meters,
                                        SLIDER_MAX,
                                    )}
                                    onChange={(event) =>
                                        setData(
                                            'radius_meters',
                                            Number(event.target.value),
                                        )
                                    }
                                    className="h-1.5 min-w-0 flex-1 cursor-pointer accent-[#0ABFBF]"
                                />
                                <div className="flex shrink-0 items-center gap-1.5">
                                    <Input
                                        type="number"
                                        inputMode="numeric"
                                        min={25}
                                        max={5000}
                                        value={data.radius_meters}
                                        onChange={(event) =>
                                            setData(
                                                'radius_meters',
                                                Number(event.target.value),
                                            )
                                        }
                                        aria-label="Fence radius in metres"
                                        className="h-8 w-20 tabular-nums"
                                    />
                                    <span className="text-xs text-muted-foreground">
                                        m
                                    </span>
                                </div>
                            </div>
                            <InputError message={errors.radius_meters} />
                        </>
                    )}
                </div>

                {/* What the site is called, exactly where, and its defaults. */}
                <div className="flex min-w-0 flex-col gap-4">
                    <FormField label="Name" required error={errors.name}>
                        <Input
                            value={data.name}
                            onChange={(event) =>
                                setData('name', event.target.value)
                            }
                            placeholder="Main Office"
                            autoFocus
                        />
                    </FormField>

                    <FormField label="Address" error={errors.address}>
                        <Input
                            value={data.address}
                            onChange={(event) =>
                                setData('address', event.target.value)
                            }
                        />
                    </FormField>

                    {!isShape && (
                        <div className="grid grid-cols-2 gap-3">
                            <FormField
                                label="Latitude"
                                required
                                error={errors.latitude}
                            >
                                <Input
                                    inputMode="decimal"
                                    value={data.latitude}
                                    onChange={(event) =>
                                        setData('latitude', event.target.value)
                                    }
                                    onBlur={() =>
                                        setRecenter((count) => count + 1)
                                    }
                                    className="tabular-nums"
                                />
                            </FormField>
                            <FormField
                                label="Longitude"
                                required
                                error={errors.longitude}
                            >
                                <Input
                                    inputMode="decimal"
                                    value={data.longitude}
                                    onChange={(event) =>
                                        setData('longitude', event.target.value)
                                    }
                                    onBlur={() =>
                                        setRecenter((count) => count + 1)
                                    }
                                    className="tabular-nums"
                                />
                            </FormField>
                        </div>
                    )}

                    <div className="flex flex-col gap-3 rounded-lg border border-border px-3 py-2.5">
                        <div className="flex items-start justify-between gap-4">
                            <label
                                htmlFor="location-own-clock"
                                className="min-w-0 cursor-pointer"
                            >
                                <span className="block text-sm">
                                    Keeps its own clock
                                </span>
                                <span className="mt-0.5 block text-xs text-muted-foreground">
                                    {data.own_clock
                                        ? 'The people based here are judged on this site’s time: late, today, and which day a punch belongs to.'
                                        : `Off: the company’s clock (${organizationTimezone.replace(/_/g, ' ')}).`}
                                </span>
                            </label>
                            <Switch
                                id="location-own-clock"
                                checked={data.own_clock}
                                onCheckedChange={(checked) =>
                                    setData('own_clock', checked)
                                }
                            />
                        </div>
                        {data.own_clock && (
                            <TimezoneSelect
                                id="location-timezone"
                                value={data.timezone}
                                options={timezones}
                                onChange={(zone) => setData('timezone', zone)}
                                invalid={Boolean(errors.timezone)}
                            />
                        )}
                        <InputError message={errors.timezone} />
                    </div>

                    <FormField
                        label="Default schedule"
                        hint="For people based here whose assignment and department name none."
                        error={errors.default_work_schedule_id}
                    >
                        <FormSelect
                            value={data.default_work_schedule_id || NONE}
                            placeholder="None"
                            noneValue={NONE}
                            onChange={(value) =>
                                setData(
                                    'default_work_schedule_id',
                                    value === NONE ? '' : value,
                                )
                            }
                            options={schedules.map((schedule) => ({
                                value: String(schedule.id),
                                label: schedule.name,
                            }))}
                        />
                    </FormField>

                    <FormField
                        label="Attendance policy"
                        hint="For people based here, after their assignment’s, schedule’s and department’s."
                        error={errors.attendance_policy_id}
                    >
                        <FormSelect
                            value={data.attendance_policy_id || NONE}
                            placeholder="None"
                            noneValue={NONE}
                            onChange={(value) =>
                                setData(
                                    'attendance_policy_id',
                                    value === NONE ? '' : value,
                                )
                            }
                            options={policies.map((policy) => ({
                                value: String(policy.id),
                                label: policy.name,
                            }))}
                        />
                    </FormField>

                    <div className="flex items-start justify-between gap-4 rounded-lg border border-border px-3 py-2.5">
                        <label
                            htmlFor="location-active"
                            className="min-w-0 cursor-pointer"
                        >
                            <span className="block text-sm">
                                Check punches against it
                            </span>
                            <span className="mt-0.5 block text-xs text-muted-foreground">
                                Off keeps the site on record without fencing
                                anybody in.
                            </span>
                        </label>
                        <Switch
                            id="location-active"
                            checked={data.is_active}
                            onCheckedChange={(checked) =>
                                setData('is_active', checked)
                            }
                        />
                    </div>
                </div>
            </ModalBody>

            <ModalFooter>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
                <Button type="submit" disabled={processing || !ready}>
                    {processing && <Spinner />}
                    {location ? 'Save location' : 'Create location'}
                </Button>
            </ModalFooter>
        </form>
    );
}

/** Seven decimals, as the database keeps them. */
function round(value: number): number {
    return Math.round(value * 1e7) / 1e7;
}
