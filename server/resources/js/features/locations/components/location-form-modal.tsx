import { useForm } from '@inertiajs/react';
import { LocateFixed, MapPinned, Search } from 'lucide-react';
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
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { GEOCODER_URL, locationRoutes } from '../routes';
import type { Fence, Ref, WorkLocation } from '../types';
import { FencePicker } from './fence-map';

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
};

/**
 * One site (ADR 0040): where it is, drawn on a map with its fence, and what the
 * people based there default to. The map is the quick way in; the coordinates
 * underneath it are the exact one, and the way in for anybody not using a
 * pointer.
 */
export function LocationFormModal({
    location,
    open,
    onOpenChange,
    schedules,
    policies,
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
                    description="A site people punch at. The circle is its fence: a punch counts as on site when the phone’s position touches it."
                />

                {open && (
                    <FormBody
                        key={location?.id ?? 'new'}
                        location={location}
                        schedules={schedules}
                        policies={policies}
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
    onDone,
}: {
    location: WorkLocation | null;
    schedules: Ref[];
    policies: Ref[];
    onDone: () => void;
}) {
    const { data, setData, post, processing, errors, transform } = useForm({
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

        transform((current) => ({
            ...current,
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

                    <div className="relative overflow-hidden rounded-xl border border-border">
                        <FencePicker
                            fence={fence}
                            onChange={(next) => place(next)}
                            recenter={recenter}
                            className="h-72 w-full sm:h-96"
                        />
                        {!fence && (
                            <p className="pointer-events-none absolute inset-x-3 bottom-3 z-[1000] rounded-lg bg-background/90 px-3 py-2 text-center text-xs text-muted-foreground shadow-sm backdrop-blur">
                                Search for the address, use your location, or
                                click the site on the map.
                            </p>
                        )}
                    </div>

                    <div className="flex items-center gap-3">
                        <label
                            htmlFor="fence-radius"
                            className="shrink-0 text-sm"
                        >
                            Fence
                        </label>
                        <input
                            id="fence-radius"
                            type="range"
                            min={25}
                            max={SLIDER_MAX}
                            step={5}
                            value={Math.min(data.radius_meters, SLIDER_MAX)}
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
                                onBlur={() => setRecenter((count) => count + 1)}
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
                                onBlur={() => setRecenter((count) => count + 1)}
                                className="tabular-nums"
                            />
                        </FormField>
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
                <Button type="submit" disabled={processing || !fence}>
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
