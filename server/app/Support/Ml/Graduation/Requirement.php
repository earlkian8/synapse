<?php

namespace App\Support\Ml\Graduation;

/**
 * One condition an organisation's records must meet before a surface's own model
 * may be trained (ADR 0046), with everything the panel needs to explain it: what is
 * counted, what the reader can do about it, why the threshold is that number, and
 * where the count comes from.
 *
 * `derived` marks a count that follows from others rather than being collected on
 * its own (the not-promoted side fills as appraisals are completed). A derived
 * requirement is never named as the one furthest from ready — there is nothing to
 * do about it directly.
 */
final class Requirement
{
    /** Volumes of history, their quality, and the system around them. */
    public const GROUPS = ['volume', 'quality', 'system'];

    /**
     * @param  'volume'|'quality'|'system'  $group
     * @param  'count'|'percent'|'check'  $format
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $group,
        public readonly float $current,
        public readonly float $required,
        public readonly string $summary,
        public readonly string $action,
        public readonly string $basis,
        public readonly string $source,
        public readonly string $format = 'count',
        public readonly string $unit = '',
        public readonly string $unitOne = '',
        public readonly bool $derived = false,
        public readonly ?string $outlook = null,
        public readonly ?string $note = null,
    ) {}

    public function met(): bool
    {
        return $this->current >= $this->required;
    }

    /** @return 'met'|'progressing'|'waiting' */
    public function status(): string
    {
        return match (true) {
            $this->met() => 'met',
            $this->current > 0 => 'progressing',
            default => 'waiting',
        };
    }

    /** How far along it is, 0–1. */
    public function progress(): float
    {
        return $this->required <= 0 ? 1.0 : min(1.0, $this->current / $this->required);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'group' => $this->group,
            'format' => $this->format,
            'current' => $this->current,
            'required' => $this->required,
            'unit' => $this->unit,
            'unit_one' => $this->unitOne,
            'status' => $this->status(),
            'summary' => $this->summary,
            'action' => $this->action,
            'basis' => $this->basis,
            'source' => $this->source,
            'derived' => $this->derived,
            'outlook' => $this->outlook,
            'note' => $this->note,
        ];
    }
}
