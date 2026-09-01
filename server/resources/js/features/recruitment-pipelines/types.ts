/** What a stage means to the business logic — never its (arbitrary) name. */
export type StageKind = 'open' | 'won' | 'lost';

export type PipelineStage = {
    id: number;
    name: string;
    kind: StageKind;
    position: number;
};

/** A stage row while it's being edited — no id until it's saved. */
export type StageDraft = { name: string; kind: StageKind };

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
