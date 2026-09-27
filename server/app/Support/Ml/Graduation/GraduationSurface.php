<?php

namespace App\Support\Ml\Graduation;

/**
 * One predictive surface's terms of graduation (ADR 0046): what its own model would
 * learn from, what must be true before it is trained, and which of the
 * organisation's records its scores draw on.
 */
interface GraduationSurface
{
    /** The surface — also the inference service's model name. */
    public function key(): string;

    /** The labelled examples this organisation's records hold today. */
    public function trainingSet(): TrainingSet;

    /**
     * Every requirement except the service's own readiness, which
     * {@see ModelGraduation} adds for all three.
     *
     * @return list<Requirement>
     */
    public function requirements(TrainingSet $set, FieldCounts $counts): array;

    /**
     * Whether the organisation has started recording what its own model would
     * learn from — the step from "general model" to "collecting".
     */
    public function started(TrainingSet $set): bool;

    /**
     * Every input field the surface's scores draw on, or could, with how many
     * active employees' records carry it.
     *
     * @return list<array{key: string, label: string, source: string, state: 'supplied'|'available'|'missing', covered: int, total: int, note: string}>
     */
    public function fields(FieldCounts $counts): array;
}
