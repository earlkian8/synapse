import { Search, UserPlus } from 'lucide-react';
import { useMemo, useState } from 'react';
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
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { requestReviews } from '../api';
import { PILL, RELATIONSHIP_LABELS } from '../constants';
import type { ReviewCandidate, ReviewRelationship } from '../types';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    hashid: string;
    subject: string;
    candidates: ReviewCandidate[];
    defaultDue: string | null;
};

/** The order people are offered in: the person, their manager, their reports, then everyone else. */
const ORDER: ReviewRelationship[] = [
    'self',
    'manager',
    'direct_report',
    'peer',
];

/** Why someone can't be picked, in words shown on their row. */
function unavailable(candidate: ReviewCandidate): string | null {
    if (candidate.is_evaluator) {
        return 'Conducting this appraisal';
    }

    if (candidate.asked === 'submitted') {
        return 'Already answered';
    }

    if (candidate.asked === 'pending') {
        return 'Already asked';
    }

    if (!candidate.has_account) {
        return 'No account to answer with';
    }

    return null;
}

/**
 * Ask people to review an appraisal (ADR 0072). The person themselves, their
 * manager and their reports come first, each labelled by what they are to the
 * person — worked out from the reporting line, not chosen. Anyone who can't be
 * asked says why on their own row.
 */
export function RequestReviewsModal({
    open,
    onOpenChange,
    hashid,
    subject,
    candidates,
    defaultDue,
}: Props) {
    const [query, setQuery] = useState('');
    const [chosen, setChosen] = useState<number[]>([]);
    const [due, setDue] = useState(defaultDue ?? '');
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | undefined>();

    const ordered = useMemo(() => {
        const words = query.toLowerCase().split(/\s+/).filter(Boolean);

        return [...candidates]
            .filter((c) => {
                const haystack = [
                    c.full_name,
                    c.department,
                    RELATIONSHIP_LABELS[c.relationship].label,
                ]
                    .filter(Boolean)
                    .join(' ')
                    .toLowerCase();

                return words.every((word) => haystack.includes(word));
            })
            .sort(
                (a, b) =>
                    ORDER.indexOf(a.relationship) -
                        ORDER.indexOf(b.relationship) ||
                    a.full_name.localeCompare(b.full_name),
            );
    }, [candidates, query]);

    const toggle = (id: number) =>
        setChosen((prev) =>
            prev.includes(id) ? prev.filter((v) => v !== id) : [...prev, id],
        );

    const reports = candidates.filter(
        (c) => c.relationship === 'direct_report' && unavailable(c) === null,
    );

    const close = (next: boolean) => {
        if (!next) {
            setChosen([]);
            setQuery('');
            setError(undefined);
        }

        onOpenChange(next);
    };

    const submit = () =>
        requestReviews(
            hashid,
            { reviewer_ids: chosen, due_on: due || null },
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => close(false),
                onError: (errors) =>
                    setError(
                        errors.reviewer_ids ??
                            errors.due_on ??
                            Object.values(errors)[0],
                    ),
            },
        );

    return (
        <Modal open={open} onOpenChange={close}>
            <ModalContent size="lg">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <UserPlus />
                        </ModalIcon>
                    }
                    title="Ask for reviews"
                    description={`Each person rates ${subject} on the appraisal's own criteria and says what to keep and what to change. They're told where to answer.`}
                />

                <ModalBody className="space-y-4">
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <div className="relative flex-1">
                            <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                                placeholder="Search by name, team or relationship"
                                aria-label="Search colleagues"
                                className="pl-9"
                            />
                        </div>
                        {reports.length > 1 && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    setChosen((prev) => [
                                        ...new Set([
                                            ...prev,
                                            ...reports.map((r) => r.id),
                                        ]),
                                    ])
                                }
                            >
                                Add all {reports.length} reports
                            </Button>
                        )}
                    </div>

                    <ul
                        className="max-h-72 divide-y divide-border overflow-y-auto rounded-lg border border-border"
                        aria-label="Colleagues"
                    >
                        {ordered.length === 0 && (
                            <li className="px-3 py-6 text-center text-sm text-muted-foreground">
                                No one matches that search.
                            </li>
                        )}
                        {ordered.map((candidate) => {
                            const reason = unavailable(candidate);
                            const id = `reviewer-${candidate.id}`;

                            return (
                                <li
                                    key={candidate.id}
                                    className={cn(
                                        'flex items-center gap-3 px-3 py-2.5',
                                        reason && 'opacity-60',
                                    )}
                                >
                                    <Checkbox
                                        id={id}
                                        disabled={reason !== null}
                                        checked={chosen.includes(candidate.id)}
                                        onCheckedChange={() =>
                                            toggle(candidate.id)
                                        }
                                    />
                                    <label
                                        htmlFor={id}
                                        className={cn(
                                            'min-w-0 flex-1',
                                            reason
                                                ? 'cursor-default'
                                                : 'cursor-pointer',
                                        )}
                                    >
                                        <span className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm font-medium">
                                            {candidate.relationship === 'self'
                                                ? `${candidate.full_name} (self-review)`
                                                : candidate.full_name}
                                            {candidate.relationship !==
                                                'peer' &&
                                                candidate.relationship !==
                                                    'self' && (
                                                    <span
                                                        className={cn(
                                                            PILL,
                                                            'border-[#0ABFBF]/30 bg-[#0ABFBF]/10 text-[#0a7d82] dark:text-[#3fd6d6]',
                                                        )}
                                                    >
                                                        {
                                                            RELATIONSHIP_LABELS[
                                                                candidate
                                                                    .relationship
                                                            ].label
                                                        }
                                                    </span>
                                                )}
                                        </span>
                                        <span className="block truncate text-xs text-muted-foreground">
                                            {reason ??
                                                candidate.department ??
                                                'No department'}
                                        </span>
                                    </label>
                                </li>
                            );
                        })}
                    </ul>

                    <FormField
                        label="Due"
                        hint="Reviews close when the appraisal is submitted, whatever the date."
                        error={error}
                    >
                        <Input
                            type="date"
                            value={due}
                            onChange={(e) => setDue(e.target.value)}
                            className="w-48"
                        />
                    </FormField>
                </ModalBody>

                <ModalFooter>
                    <Button
                        variant="outline"
                        onClick={() => close(false)}
                        disabled={processing}
                    >
                        Cancel
                    </Button>
                    <Button
                        onClick={submit}
                        disabled={processing || chosen.length === 0}
                    >
                        {processing && <Spinner />}
                        {chosen.length === 0
                            ? 'Ask for reviews'
                            : `Ask ${chosen.length} ${chosen.length === 1 ? 'person' : 'people'}`}
                    </Button>
                </ModalFooter>
            </ModalContent>
        </Modal>
    );
}
