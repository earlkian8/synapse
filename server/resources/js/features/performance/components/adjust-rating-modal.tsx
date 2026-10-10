import { Scale } from 'lucide-react';
import { useState } from 'react';
import { FormField } from '@/components/form-field';
import {
    Modal,
    ModalBody,
    ModalContent,
    ModalFooter,
    ModalHeader,
    ModalIcon,
} from '@/components/modal';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { adjustRating } from '../api';
import { bandTone, formatPercent, orderedBands, TEXTAREA } from '../constants';
import type { CalibrationRow } from '../types';

/**
 * Move one appraisal's rating in a calibration session (ADR 0073): to another
 * band of the appraisal's own rating model, with why. The bands are listed top
 * down with where the scorecard put it and where it stands now, so the move is
 * read against the whole model. Attainment is not changed.
 */
export function AdjustRatingModal({
    sessionHashid,
    row,
    onOpenChange,
}: {
    sessionHashid: string;
    row: CalibrationRow | null;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Modal open={row !== null} onOpenChange={onOpenChange}>
            <ModalContent size="md">
                {row && (
                    <AdjustBody
                        key={row.id}
                        sessionHashid={sessionHashid}
                        row={row}
                        onClose={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function AdjustBody({
    sessionHashid,
    row,
    onClose,
}: {
    sessionHashid: string;
    row: CalibrationRow;
    onClose: () => void;
}) {
    const [band, setBand] = useState(row.current?.key ?? '');
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const submit = () =>
        adjustRating(
            sessionHashid,
            { evaluation_id: row.id, band, reason: reason.trim() },
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: onClose,
                onError: setErrors,
            },
        );

    const unchanged = band === row.current?.key;

    return (
        <>
            <ModalHeader
                icon={
                    <ModalIcon>
                        <Scale />
                    </ModalIcon>
                }
                title={`Move ${row.employee?.full_name ?? 'this person'}’s rating`}
                description={`${formatPercent(row.overall_percent)} attainment on ${row.template_name ?? 'their framework'}. The attainment stays as scored; only the rating moves.`}
            />

            <ModalBody className="space-y-4">
                <FormField label="Rating" group error={errors.band}>
                    <div
                        role="radiogroup"
                        aria-label="Rating"
                        className="flex flex-col gap-1.5"
                    >
                        {orderedBands(row.bands).map((option) => {
                            const tone = bandTone(option.tone);
                            const active = band === option.key;

                            return (
                                <button
                                    key={option.key}
                                    type="button"
                                    role="radio"
                                    aria-checked={active}
                                    onClick={() => setBand(option.key)}
                                    className={cn(
                                        'flex items-center gap-3 rounded-lg border px-3 py-2 text-left transition-colors',
                                        active
                                            ? cn(tone.border, tone.soft)
                                            : 'border-border hover:bg-muted/40',
                                    )}
                                >
                                    <span
                                        className={cn(
                                            'size-2.5 shrink-0 rounded-full',
                                            tone.fill,
                                        )}
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span
                                            className={cn(
                                                'block text-sm font-medium',
                                                active && tone.text,
                                            )}
                                        >
                                            {option.label}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            From {option.min_percent}%
                                            {option.key === row.scored?.key &&
                                                ' · what the scorecard gave'}
                                            {option.key === row.current?.key &&
                                                row.calibrated &&
                                                ' · rated now'}
                                        </span>
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                </FormField>

                <FormField
                    label="Why"
                    required
                    hint="It stays on the record with the move."
                    error={errors.reason}
                >
                    <textarea
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        rows={3}
                        maxLength={1000}
                        placeholder="e.g. Rated against a softer bar than the rest of Sales"
                        className={TEXTAREA}
                    />
                </FormField>

                {row.last_adjustment && (
                    <p className="rounded-lg border border-dashed border-border px-3 py-2 text-xs text-muted-foreground">
                        Last moved by {row.last_adjustment.by ?? 'someone'}
                        {row.last_adjustment.session &&
                            ` in “${row.last_adjustment.session}”`}
                        : “{row.last_adjustment.reason}”
                    </p>
                )}
            </ModalBody>

            <ModalFooter>
                <Button
                    variant="outline"
                    onClick={onClose}
                    disabled={processing}
                >
                    Cancel
                </Button>
                <Button
                    onClick={submit}
                    disabled={
                        processing || unchanged || reason.trim().length < 5
                    }
                >
                    {processing && <Spinner />}
                    Move rating
                </Button>
            </ModalFooter>
        </>
    );
}
