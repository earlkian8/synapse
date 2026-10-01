import { Head, usePage } from '@inertiajs/react';
import { LocationsManager } from '@/features/locations/components/locations-manager';
import type { LocationsPageProps } from '@/features/locations/types';

/**
 * Company Setup → Locations (ADR 0040): the company's sites, each a fence on a
 * map. A punch from the web or the app is placed against them; whether being
 * off site is flagged or refused is each attendance policy's call.
 */
export default function SetupLocations() {
    const props = usePage<LocationsPageProps>().props;

    return (
        <>
            <Head title="Locations" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <LocationsManager
                    {...props}
                    heading={
                        <div className="flex max-w-2xl flex-col gap-1">
                            <h1 className="text-xl font-semibold tracking-tight">
                                Locations
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                Where your people work. Each site is a fence on
                                the map; web and app punches are placed against
                                it, and every punch keeps where it was made.
                            </p>
                        </div>
                    }
                />
            </div>
        </>
    );
}

SetupLocations.layout = {
    breadcrumbs: [
        { title: 'Company Setup', href: '/setup/departments' },
        { title: 'Locations', href: '/setup/locations' },
    ],
};
