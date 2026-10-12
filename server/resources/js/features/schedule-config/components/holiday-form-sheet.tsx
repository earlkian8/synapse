import { useForm } from '@inertiajs/react';
import { CalendarClock, MapPin } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { cn } from '@/lib/utils';
import {
    EASTER_PRESETS,
    formatLongDate,
    HOLIDAY_TYPE_OPTIONS,
    MONTH_NAMES,
    RECURRENCE_OPTIONS,
    upcomingDates,
    WEEK_OPTIONS,
    WEEKDAY_NAMES,
} from '../constants';
import { scheduleConfigRoutes } from '../routes';
import type {
    Holiday,
    HolidayRecurrence,
    HolidaySite,
    HolidayType,
} from '../types';

type Props = {
    holiday: Holiday | null;
    /** The active sites a local holiday can be kept at. */
    locations: HolidaySite[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/** The "Another day" choice of the Easter picker. */
const CUSTOM = 'custom';

export function HolidayFormSheet({
    holiday,
    locations,
    open,
    onOpenChange,
}: Props) {
    const isEditing = Boolean(holiday);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-md"
            >
                <SheetHeader className="border-b border-border px-6 py-4">
                    <SheetTitle>
                        {isEditing ? 'Edit holiday' : 'New holiday'}
                    </SheetTitle>
                    <SheetDescription>
                        A date in the company calendar. Non-working holidays are
                        not charged as leave.
                    </SheetDescription>
                </SheetHeader>

                {open && (
                    <FormBody
                        key={holiday?.id ?? 'new'}
                        holiday={holiday}
                        locations={locations}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}

function FormBody({
    holiday,
    locations,
    onDone,
}: {
    holiday: Holiday | null;
    locations: HolidaySite[];
    onDone: () => void;
}) {
    const isEditing = Boolean(holiday);
    const savedSites = holiday?.locations?.map((site) => site.id) ?? [];

    const { data, setData, transform, post, processing, errors } = useForm({
        name: holiday?.name ?? '',
        date: holiday?.date ?? '',
        type: (holiday?.type ?? 'regular') as HolidayType,
        recurrence: (holiday?.recurrence ?? 'none') as HolidayRecurrence,
        offset: holiday?.rule?.offset ?? -2,
        month: holiday?.rule?.month ?? 8,
        weekday: holiday?.rule?.weekday ?? 1,
        week: holiday?.rule?.week ?? -1,
        local: savedSites.length > 0,
        location_ids: savedSites,
    });

    const movable =
        data.recurrence === 'easter' || data.recurrence === 'weekday';
    const rule =
        data.recurrence === 'easter'
            ? { offset: data.offset }
            : data.recurrence === 'weekday'
              ? { month: data.month, weekday: data.weekday, week: data.week }
              : null;
    const upcoming = upcomingDates(
        data.recurrence,
        rule,
        data.date || null,
        data.recurrence === 'none' ? 1 : 2,
    );
    const [custom, setCustom] = useState(
        () => !EASTER_PRESETS.some((p) => p.offset === data.offset),
    );
    const ruleErrors = errors as Record<string, string | undefined>;

    // Only the rule's own numbers are sent; a site list only when it is local.
    transform((form) => ({
        name: form.name,
        type: form.type,
        recurrence: form.recurrence,
        date: movable ? null : form.date,
        rule,
        location_ids: form.local ? form.location_ids : [],
    }));

    const toggleSite = (id: number, checked: boolean) =>
        setData(
            'location_ids',
            checked
                ? [...data.location_ids, id]
                : data.location_ids.filter((site) => site !== id),
        );

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => onDone() };

        if (isEditing && holiday) {
            post(scheduleConfigRoutes.holidays.update(holiday.hashid), opts);
        } else {
            post(scheduleConfigRoutes.holidays.store, opts);
        }
    };

    return (
        <form onSubmit={submit} className="flex h-full flex-col">
            <div className="flex-1 space-y-5 px-6 py-6">
                <div>
                    <Label htmlFor="holiday-name" className="mb-1.5 block">
                        Name<span className="ml-0.5 text-destructive">*</span>
                    </Label>
                    <Input
                        id="holiday-name"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        placeholder="e.g. Cebu City Charter Day"
                        required
                    />
                    <InputError message={errors.name} className="mt-1.5" />
                </div>

                <div>
                    <Label className="mb-1.5 block">
                        Type<span className="ml-0.5 text-destructive">*</span>
                    </Label>
                    <Select
                        value={data.type}
                        onValueChange={(v) => setData('type', v as HolidayType)}
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {HOLIDAY_TYPE_OPTIONS.map((o) => (
                                <SelectItem key={o.value} value={o.value}>
                                    {o.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <p className="mt-1.5 text-xs text-muted-foreground">
                        Regular and special non-working days are days off; a
                        special working day is an ordinary working day.
                    </p>
                    <InputError message={errors.type} className="mt-1.5" />
                </div>

                <fieldset className="space-y-3 rounded-lg border border-sidebar-border/70 p-3 dark:border-sidebar-border">
                    <legend className="px-1 text-sm font-medium">When</legend>

                    <div>
                        <Label className="mb-1.5 block text-xs text-muted-foreground">
                            Repeats
                        </Label>
                        <Select
                            value={data.recurrence}
                            onValueChange={(v) =>
                                setData('recurrence', v as HolidayRecurrence)
                            }
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {RECURRENCE_OPTIONS.map((o) => (
                                    <SelectItem key={o.value} value={o.value}>
                                        {o.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="mt-1.5 text-xs text-muted-foreground">
                            {
                                RECURRENCE_OPTIONS.find(
                                    (o) => o.value === data.recurrence,
                                )?.hint
                            }
                        </p>
                        <InputError
                            message={errors.recurrence}
                            className="mt-1.5"
                        />
                    </div>

                    {!movable && (
                        <div>
                            <Label
                                htmlFor="holiday-date"
                                className="mb-1.5 block text-xs text-muted-foreground"
                            >
                                {data.recurrence === 'yearly'
                                    ? 'Date (the year is ignored)'
                                    : 'Date'}
                            </Label>
                            <Input
                                id="holiday-date"
                                type="date"
                                value={data.date ?? ''}
                                onChange={(e) =>
                                    setData('date', e.target.value)
                                }
                                required
                            />
                            <InputError
                                message={errors.date}
                                className="mt-1.5"
                            />
                        </div>
                    )}

                    {data.recurrence === 'easter' && (
                        <div className="grid gap-3">
                            <div>
                                <Label className="mb-1.5 block text-xs text-muted-foreground">
                                    Which day
                                </Label>
                                <Select
                                    value={
                                        custom ? CUSTOM : String(data.offset)
                                    }
                                    onValueChange={(v) => {
                                        setCustom(v === CUSTOM);

                                        if (v !== CUSTOM) {
                                            setData('offset', Number(v));
                                        }
                                    }}
                                >
                                    <SelectTrigger className="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {EASTER_PRESETS.map((p) => (
                                            <SelectItem
                                                key={p.offset}
                                                value={String(p.offset)}
                                            >
                                                {p.label}
                                            </SelectItem>
                                        ))}
                                        <SelectItem value={CUSTOM}>
                                            Another day…
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            {custom && (
                                <div>
                                    <Label
                                        htmlFor="holiday-offset"
                                        className="mb-1.5 block text-xs text-muted-foreground"
                                    >
                                        Days from Easter Sunday (before it is
                                        negative)
                                    </Label>
                                    <Input
                                        id="holiday-offset"
                                        type="number"
                                        min={-70}
                                        max={70}
                                        value={data.offset}
                                        onChange={(e) =>
                                            setData(
                                                'offset',
                                                Number(e.target.value),
                                            )
                                        }
                                    />
                                </div>
                            )}
                            <InputError message={ruleErrors['rule.offset']} />
                        </div>
                    )}

                    {data.recurrence === 'weekday' && (
                        <div className="grid grid-cols-3 gap-2">
                            <RuleSelect
                                label="Week"
                                value={data.week}
                                options={WEEK_OPTIONS}
                                onChange={(v) => setData('week', v)}
                            />
                            <RuleSelect
                                label="Day"
                                value={data.weekday}
                                options={WEEKDAY_NAMES.map((name, index) => ({
                                    value: index + 1,
                                    label: name,
                                }))}
                                onChange={(v) => setData('weekday', v)}
                            />
                            <RuleSelect
                                label="Month"
                                value={data.month}
                                options={MONTH_NAMES.map((name, index) => ({
                                    value: index + 1,
                                    label: name,
                                }))}
                                onChange={(v) => setData('month', v)}
                            />
                            <InputError
                                className="col-span-3"
                                message={
                                    ruleErrors['rule.week'] ??
                                    ruleErrors['rule.weekday'] ??
                                    ruleErrors['rule.month']
                                }
                            />
                        </div>
                    )}

                    {upcoming.length > 0 && (
                        <p className="flex items-start gap-1.5 rounded-md bg-[#0ABFBF]/8 px-2.5 py-2 text-xs text-foreground">
                            <CalendarClock className="mt-px size-3.5 shrink-0 text-[#0a8b91] dark:text-[#0ABFBF]" />
                            <span>
                                {data.recurrence === 'none' ? 'On ' : 'Next '}
                                <span className="font-medium tabular-nums">
                                    {formatLongDate(upcoming[0])}
                                </span>
                                {upcoming[1] && (
                                    <>
                                        , then{' '}
                                        <span className="font-medium tabular-nums">
                                            {formatLongDate(upcoming[1])}
                                        </span>
                                    </>
                                )}
                            </span>
                        </p>
                    )}
                </fieldset>

                <fieldset className="space-y-3 rounded-lg border border-sidebar-border/70 p-3 dark:border-sidebar-border">
                    <legend className="px-1 text-sm font-medium">
                        Who keeps it
                    </legend>

                    <div
                        role="radiogroup"
                        aria-label="Who keeps it"
                        className="grid grid-cols-2 gap-1 rounded-lg bg-muted p-1"
                    >
                        {[
                            { local: false, label: 'Everyone' },
                            { local: true, label: 'Some sites only' },
                        ].map((option) => (
                            <button
                                key={option.label}
                                type="button"
                                role="radio"
                                aria-checked={data.local === option.local}
                                disabled={
                                    option.local && locations.length === 0
                                }
                                onClick={() => setData('local', option.local)}
                                className={cn(
                                    'rounded-md px-2 py-1.5 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50',
                                    data.local === option.local
                                        ? 'bg-background text-foreground shadow-xs'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {option.label}
                            </button>
                        ))}
                    </div>

                    {locations.length === 0 ? (
                        <p className="text-xs text-muted-foreground">
                            A local holiday is kept by the people based at a
                            site. Add your sites under Company Setup → Locations
                            first.
                        </p>
                    ) : data.local ? (
                        <div className="space-y-2">
                            <p className="text-xs text-muted-foreground">
                                Kept by the people whose base is one of these.
                                Everyone else works as usual.
                            </p>
                            <ul className="max-h-44 space-y-1 overflow-y-auto">
                                {locations.map((site) => {
                                    const checked = data.location_ids.includes(
                                        site.id,
                                    );

                                    return (
                                        <li key={site.id}>
                                            <label className="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-muted/60">
                                                <Checkbox
                                                    checked={checked}
                                                    onCheckedChange={(v) =>
                                                        toggleSite(
                                                            site.id,
                                                            v === true,
                                                        )
                                                    }
                                                />
                                                <MapPin className="size-3.5 text-muted-foreground" />
                                                {site.name}
                                            </label>
                                        </li>
                                    );
                                })}
                            </ul>
                            {data.location_ids.length === 0 && (
                                <p className="text-xs text-amber-600 dark:text-amber-400">
                                    Tick at least one site, or it is kept by
                                    everyone.
                                </p>
                            )}
                        </div>
                    ) : (
                        <p className="text-xs text-muted-foreground">
                            Everyone in the company has the day.
                        </p>
                    )}
                    <InputError message={errors.location_ids} />
                </fieldset>
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
                        {isEditing ? 'Save changes' : 'Add holiday'}
                    </Button>
                </div>
            </SheetFooter>
        </form>
    );
}

function RuleSelect({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value: number;
    options: { value: number; label: string }[];
    onChange: (value: number) => void;
}) {
    return (
        <div className="min-w-0">
            <Label className="mb-1.5 block text-xs text-muted-foreground">
                {label}
            </Label>
            <Select
                value={String(value)}
                onValueChange={(v) => onChange(Number(v))}
            >
                <SelectTrigger className="w-full">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {options.map((o) => (
                        <SelectItem key={o.value} value={String(o.value)}>
                            {o.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}
