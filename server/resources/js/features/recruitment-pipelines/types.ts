/** What a stage means to the business logic — never its (arbitrary) name. */
export type StageKind = 'open' | 'won' | 'lost';

export type PipelineStage = {
    id: number;
    name: string;
    kind: StageKind;
    position: number;
};

/**
 * A stage row while it's being edited. A saved stage keeps its `id` all the way
 * to the server, which is how a kept stage is told from a new one — without it
 * every save would drop and re-create every stage, and a stage candidates sit
 * on can't be dropped.
 */
export type StageDraft = { id?: number; name: string; kind: StageKind };

/** A tenant-defined hiring process a job posting can be assigned to. */
export type Pipeline = {
    id: number;
    hashid: string;
    name: string;
    is_default: boolean;
    stages: PipelineStage[];
    postings_count: number;
    created_human: string | null;
};

export type PipelinesPageProps = {
    pipelines: Pipeline[];
    can: { configure: boolean };
};
