import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { useEffect, useRef } from 'react';
import type { RefObject } from 'react';
import { cn } from '@/lib/utils';
import type { Fence } from '../types';

/**
 * The maps on Company Setup → Locations (ADR 0040), on Leaflet with
 * OpenStreetMap's tiles. The Content-Security-Policy names the tile host; the
 * library is bundled. Dark mode dims the tiles in CSS (`.fence-map` in app.css)
 * rather than swapping providers.
 *
 * Leaflet owns its own DOM, so the map lives in refs and follows its props in
 * effects — never during render.
 */

const TILES = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const ATTRIBUTION =
    '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';
const ACCENT = '#0ABFBF';
/** The fence's edge: darker than the fill, so it holds against busy streets. */
const EDGE = '#0a8b91';

/** Few enough sites to name on the map without them crowding it. */
const LABEL_LIMIT = 12;

/** Somewhere to look before any site exists: the whole world, not a guess. */
const WORLD: L.LatLngTuple = [15, 0];

function createMap(container: HTMLDivElement): L.Map {
    const map = L.map(container, {
        center: WORLD,
        zoom: 2,
        worldCopyJump: true,
        zoomControl: true,
    });

    L.tileLayer(TILES, { maxZoom: 19, attribution: ATTRIBUTION }).addTo(map);

    return map;
}

/** Keep Leaflet's idea of its size right while a dialog animates open. */
function useResizeInvalidation(
    container: RefObject<HTMLDivElement | null>,
    map: RefObject<L.Map | null>,
) {
    useEffect(() => {
        const element = container.current;

        if (!element) {
            return;
        }

        const observer = new ResizeObserver(() =>
            map.current?.invalidateSize(),
        );
        observer.observe(element);

        return () => observer.disconnect();
    }, [container, map]);
}

const pin = L.divIcon({
    className: 'fence-pin',
    html: '<span></span>',
    iconSize: [22, 22],
    iconAnchor: [11, 11],
});

// ── The picker ───────────────────────────────────────────────────────────────

/**
 * Where a site is and how far its fence reaches. Click the map to place it;
 * drag the pin to move it. The radius comes from outside (a slider beside it),
 * and `recenter` changing — a search result, "use my location" — brings the
 * fence into view.
 */
export function FencePicker({
    fence,
    onChange,
    recenter,
    className,
}: {
    fence: Fence | null;
    onChange: (fence: Fence) => void;
    recenter: number;
    className?: string;
}) {
    const container = useRef<HTMLDivElement | null>(null);
    const map = useRef<L.Map | null>(null);
    const marker = useRef<L.Marker | null>(null);
    const circle = useRef<L.Circle | null>(null);
    const latest = useRef({ fence, onChange });

    useEffect(() => {
        latest.current = { fence, onChange };
    }, [fence, onChange]);

    useEffect(() => {
        if (!container.current) {
            return;
        }

        const instance = createMap(container.current);
        map.current = instance;

        instance.on('click', (event: L.LeafletMouseEvent) => {
            latest.current.onChange({
                latitude: round(event.latlng.lat),
                longitude: round(event.latlng.lng),
                radius_meters: latest.current.fence?.radius_meters ?? 150,
            });
        });

        return () => {
            instance.remove();
            map.current = null;
            marker.current = null;
            circle.current = null;
        };
    }, []);

    useResizeInvalidation(container, map);

    // Draw (or move) the pin and the fence.
    useEffect(() => {
        const instance = map.current;

        if (!instance) {
            return;
        }

        if (!fence) {
            marker.current?.remove();
            circle.current?.remove();
            marker.current = null;
            circle.current = null;

            return;
        }

        const center: L.LatLngTuple = [fence.latitude, fence.longitude];

        if (!circle.current) {
            circle.current = L.circle(center, {
                radius: fence.radius_meters,
                color: EDGE,
                weight: 2.5,
                fillColor: ACCENT,
                fillOpacity: 0.2,
                interactive: false,
            }).addTo(instance);
        } else {
            circle.current.setLatLng(center).setRadius(fence.radius_meters);
        }

        if (!marker.current) {
            // The first time there is a fence — typed in, found, or clicked on a
            // map still showing the world — bring it close enough to adjust.
            instance.fitBounds(
                L.latLng(center).toBounds(
                    Math.max(fence.radius_meters * 3, 300),
                ),
                { maxZoom: 18, animate: false },
            );

            marker.current = L.marker(center, {
                icon: pin,
                draggable: true,
                keyboard: true,
                title: 'The site’s centre — drag to move it',
            }).addTo(instance);

            marker.current.on('dragend', () => {
                const at = marker.current?.getLatLng();

                if (at) {
                    latest.current.onChange({
                        latitude: round(at.lat),
                        longitude: round(at.lng),
                        radius_meters:
                            latest.current.fence?.radius_meters ?? 150,
                    });
                }
            });
        } else {
            marker.current.setLatLng(center);
        }
    }, [fence]);

    // Bring the fence into view when asked to, and when the picker opens on one.
    useEffect(() => {
        const instance = map.current;
        const current = latest.current.fence;

        if (!instance || !current) {
            return;
        }

        instance.fitBounds(
            L.latLng(current.latitude, current.longitude).toBounds(
                Math.max(current.radius_meters * 3, 300),
            ),
            { maxZoom: 18, animate: false },
        );
    }, [recenter]);

    return (
        <div
            ref={container}
            className={cn('fence-map isolate', className)}
            role="application"
            aria-label="Map. Click to place the site; drag its pin to move it."
        />
    );
}

// ── The overview ─────────────────────────────────────────────────────────────

export type SiteShape = {
    id: number;
    name: string;
    latitude: number;
    longitude: number;
    radius_meters: number;
    is_active: boolean;
};

/**
 * Every site the company has, each fence drawn where it is. A site that is not
 * checking punches is dashed. Clicking one selects it.
 */
export function SitesMap({
    sites,
    selectedId,
    onSelect,
    className,
}: {
    sites: SiteShape[];
    selectedId: number | null;
    onSelect: (id: number) => void;
    className?: string;
}) {
    const container = useRef<HTMLDivElement | null>(null);
    const map = useRef<L.Map | null>(null);
    const layer = useRef<L.LayerGroup | null>(null);
    const select = useRef(onSelect);

    useEffect(() => {
        select.current = onSelect;
    }, [onSelect]);

    useEffect(() => {
        if (!container.current) {
            return;
        }

        const instance = createMap(container.current);
        map.current = instance;
        layer.current = L.layerGroup().addTo(instance);

        return () => {
            instance.remove();
            map.current = null;
            layer.current = null;
        };
    }, []);

    useResizeInvalidation(container, map);

    useEffect(() => {
        const group = layer.current;

        if (!group) {
            return;
        }

        group.clearLayers();

        for (const site of sites) {
            const selected = site.id === selectedId;

            L.circle([site.latitude, site.longitude], {
                radius: site.radius_meters,
                color: EDGE,
                weight: selected ? 3.5 : 2.5,
                dashArray: site.is_active ? undefined : '5 5',
                fillColor: ACCENT,
                fillOpacity: selected ? 0.32 : 0.2,
            })
                .bindTooltip(site.name, {
                    direction: 'top',
                    className: 'fence-tooltip',
                    permanent: sites.length <= LABEL_LIMIT,
                })
                .on('click', () => select.current(site.id))
                .addTo(group);
        }
    }, [sites, selectedId]);

    // Frame every site when the set changes; frame the chosen one when chosen.
    const siteKey = sites
        .map((site) => `${site.id}:${site.latitude}:${site.longitude}`)
        .join('|');

    useEffect(() => {
        const instance = map.current;

        if (!instance || sites.length === 0) {
            return;
        }

        const bounds = L.latLngBounds(
            sites
                .map((site) =>
                    L.latLng(site.latitude, site.longitude).toBounds(
                        site.radius_meters * 2,
                    ),
                )
                .flatMap((b) => [b.getSouthWest(), b.getNorthEast()]),
        );

        instance.fitBounds(bounds, { maxZoom: 16, padding: [24, 24] });
        // Framed by the set of sites, not by every re-render of them.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [siteKey]);

    useEffect(() => {
        const instance = map.current;
        const site = sites.find((candidate) => candidate.id === selectedId);

        if (!instance || !site) {
            return;
        }

        instance.flyToBounds(
            L.latLng(site.latitude, site.longitude).toBounds(
                Math.max(site.radius_meters * 3, 300),
            ),
            { maxZoom: 17, duration: 0.6 },
        );
        // Only when the selection changes.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selectedId]);

    return (
        <div
            ref={container}
            className={cn('fence-map isolate', className)}
            role="application"
            aria-label="Map of the company’s sites"
        />
    );
}

/** Seven decimals — about a centimetre, which is what the database keeps. */
function round(value: number): number {
    return Math.round(value * 1e7) / 1e7;
}
