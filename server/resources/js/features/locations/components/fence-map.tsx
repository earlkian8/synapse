import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { useEffect, useRef } from 'react';
import type { RefObject } from 'react';
import { cn } from '@/lib/utils';
import type { Corner, Fence } from '../types';

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

const corner = (first: boolean) =>
    L.divIcon({
        className: first ? 'fence-corner is-first' : 'fence-corner',
        html: '<span></span>',
        iconSize: [14, 14],
        iconAnchor: [7, 7],
    });

/**
 * A fence drawn as a shape (ADR 0079): each click on the map adds a corner
 * after the last; a corner is dragged to move it. The outline closes itself —
 * the last corner joins the first. `center` (a search result, "use my
 * location") only moves the view; the shape is what is drawn.
 */
export function ShapePicker({
    corners,
    onChange,
    center,
    recenter,
    className,
}: {
    corners: Corner[];
    onChange: (corners: Corner[]) => void;
    center: Fence | null;
    recenter: number;
    className?: string;
}) {
    const container = useRef<HTMLDivElement | null>(null);
    const map = useRef<L.Map | null>(null);
    const shape = useRef<L.Polygon | null>(null);
    const handles = useRef<L.LayerGroup | null>(null);
    const latest = useRef({ corners, onChange, center });

    useEffect(() => {
        latest.current = { corners, onChange, center };
    }, [corners, onChange, center]);

    useEffect(() => {
        if (!container.current) {
            return;
        }

        const instance = createMap(container.current);
        map.current = instance;
        handles.current = L.layerGroup().addTo(instance);

        instance.on('click', (event: L.LeafletMouseEvent) => {
            latest.current.onChange([
                ...latest.current.corners,
                [round(event.latlng.lat), round(event.latlng.lng)],
            ]);
        });

        const first = latest.current.corners;

        if (first.length > 0) {
            instance.fitBounds(L.latLngBounds(first), {
                maxZoom: 18,
                padding: [32, 32],
                animate: false,
            });
        } else if (latest.current.center) {
            instance.setView(
                [
                    latest.current.center.latitude,
                    latest.current.center.longitude,
                ],
                17,
                { animate: false },
            );
        }

        return () => {
            instance.remove();
            map.current = null;
            shape.current = null;
            handles.current = null;
        };
    }, []);

    useResizeInvalidation(container, map);

    // Draw the outline and a handle on every corner.
    useEffect(() => {
        const instance = map.current;
        const group = handles.current;

        if (!instance || !group) {
            return;
        }

        group.clearLayers();

        if (corners.length >= 2) {
            if (!shape.current) {
                shape.current = L.polygon(corners, {
                    color: EDGE,
                    weight: 2.5,
                    fillColor: ACCENT,
                    fillOpacity: corners.length >= 3 ? 0.2 : 0,
                    interactive: false,
                }).addTo(instance);
            } else {
                shape.current
                    .setLatLngs(corners)
                    .setStyle({ fillOpacity: corners.length >= 3 ? 0.2 : 0 });
            }
        } else {
            shape.current?.remove();
            shape.current = null;
        }

        corners.forEach((point, index) => {
            const handle = L.marker(point, {
                icon: corner(index === 0),
                draggable: true,
                keyboard: true,
                title: `Corner ${index + 1} — drag to move it`,
            });

            handle.on('dragend', () => {
                const at = handle.getLatLng();
                const next = [...latest.current.corners];
                next[index] = [round(at.lat), round(at.lng)];
                latest.current.onChange(next);
            });

            group.addLayer(handle);
        });
    }, [corners]);

    // Look where a search or "use my location" pointed.
    useEffect(() => {
        const instance = map.current;
        const target = latest.current.center;

        if (!instance || !target || recenter === 0) {
            return;
        }

        instance.setView([target.latitude, target.longitude], 17, {
            animate: false,
        });
    }, [recenter]);

    return (
        <div
            ref={container}
            className={cn('fence-map is-drawing isolate', className)}
            role="application"
            aria-label="Map. Click at each corner of the site to draw its outline; drag a corner to move it."
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
    /** A shape's corners (ADR 0079); null for a circle. */
    polygon?: Corner[] | null;
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

            const style = {
                color: EDGE,
                weight: selected ? 3.5 : 2.5,
                dashArray: site.is_active ? undefined : '5 5',
                fillColor: ACCENT,
                fillOpacity: selected ? 0.32 : 0.2,
            };
            const fence =
                site.polygon && site.polygon.length >= 3
                    ? L.polygon(site.polygon, style)
                    : L.circle([site.latitude, site.longitude], {
                          radius: site.radius_meters,
                          ...style,
                      });

            fence
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
