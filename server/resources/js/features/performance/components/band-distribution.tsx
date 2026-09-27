import { ChartNoAxesColumn } from 'lucide-react';
import { DataTable, EmptyTableRow, TableCard } from '@/components/data-table';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { bandTone } from '../constants';
import type { DistributionBand } from '../types';

type Props = {
    distribution: DistributionBand[];
    total: number;
};

/**
 * How this company rated itself, in its own bands: one row per band with the
 * headcount and a share meter. Rating inflation is invisible one appraisal at a
 * time and unmissable here — if four fifths of a company sits in the top band,
 * that is the picture, and the note under the table says so plainly rather
 * than editorialising.
 */
export function BandDistribution({ distribution, total }: Props) {
    const top = distribution[0] ?? null;

    return (
        <TableCard
            title="Result spread"
            count={total}
            description="Where this cycle's completed appraisals landed across your rating bands"
        >
            <DataTable>
                <TableHeader>
                    <TableRow>
                        <TableHead>Band</TableHead>
                        <TableHead className="text-right">People</TableHead>
                        <TableHead className="w-1/2">Share</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {distribution.length === 0 && (
                        <EmptyTableRow
                            colSpan={3}
                            icon={ChartNoAxesColumn}
                            title="Nothing to calibrate yet"
                            description="The spread appears once appraisals in this cycle are submitted."
                        />
                    )}

                    {distribution.map((band) => {
                        const tone = bandTone(band.tone);

                        return (
                            <TableRow key={band.label}>
                                <TableCell>
                                    <span className="flex min-w-0 items-center gap-2 text-sm">
                                        <span
                                            className={cn(
                                                'size-2.5 shrink-0 rounded-sm',
                                                tone.fill,
                                            )}
                                            aria-hidden="true"
                                        />
                                        <span className="max-w-48 truncate">
                                            {band.label}
                                        </span>
                                    </span>
                                </TableCell>
                                <TableCell className="text-right text-sm tabular-nums">
                                    {band.count}
                                </TableCell>
                                <TableCell>
                                    <div className="flex items-center gap-2.5">
                                        <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                                            <div
                                                className={cn(
                                                    'h-full rounded-full',
                                                    tone.fill,
                                                )}
                                                style={{
                                                    width: `${band.share}%`,
                                                }}
                                            />
                                        </div>
                                        <span className="w-9 shrink-0 text-right text-xs text-muted-foreground tabular-nums">
                                            {band.share}%
                                        </span>
                                    </div>
                                </TableCell>
                            </TableRow>
                        );
                    })}
                </TableBody>
            </DataTable>

            {top && top.share >= 50 && (
                <p className="border-t border-border px-4 py-2 text-xs text-muted-foreground">
                    {top.share}% of completed appraisals sit in the top band
                    (&ldquo;{top.label}&rdquo;). Worth a calibration
                    conversation before sign-off.
                </p>
            )}
        </TableCard>
    );
}
